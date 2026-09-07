<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Repository\PluginRepository;

/**
 * Class SettingsRenderer
 *
 * Provides automated form rendering logic to dynamically build administrative setting controls
 * based on configuration fields defined by individual gateway or addon plugins.
 * Enforces secure request flows by automatically injecting CSRF protection tokens resolved via the
 * system-wide `SecurityHelpers::csrfToken()` helper and guarantees output sanitization across all input types.
 *
 * @package OwnPay\View
 */
final class SettingsRenderer
{
    /**
     * Render the HTML settings form using settings fields declared by the target plugin.
     *
     * Iterates over field definitions and maps them to their respective HTML templates
     * (e.g. text, textarea, select, toggle, checkbox) while pre-populating existing saved configurations.
     *
     * @param \OwnPay\Plugin\PluginInterface $plugin The target plugin instance to extract fields from.
     * @param array<string, string> $currentValues The current saved setting values from storage.
     * @param string $action The target URL endpoint for the form post action.
     * @param bool $isGateway Whether this plugin is a payment gateway - adds the Display
     *                        Customization fields and Test Connection button when true.
     * @param array<string, string> $displayValues Currently-stored display_name/display_logo overrides.
     * @param string $cspNonce Per-request CSP nonce - required for any injected <script> tag to
     *                         run at all, since admin pages send a strict `script-src 'nonce-...'`
     *                         policy with no 'unsafe-inline'.
     * @return string The generated HTML form string.
     */
    public static function render(PluginInterface $plugin, array $currentValues, string $action, bool $isGateway = false, array $displayValues = [], string $cspNonce = ''): string
    {
        $fields = $plugin->fields();
        if (empty($fields) && !$isGateway) {
            return '<p class="op-settings-empty">This plugin has no configurable settings.</p>';
        }

        $html = '<form method="POST" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
            . '" class="op-settings-form" id="op-settings-form" enctype="multipart/form-data">';
        $csrfToken = \OwnPay\Security\SecurityHelpers::csrfToken();
        $html .= '<input type="hidden" name="_csrf_token" value="' . self::e($csrfToken) . '">';

        foreach ($fields as $field) {
            /** @phpstan-ignore-next-line */
            $name = $field['name'] ?? '';
            /** @phpstan-ignore-next-line */
            $label = $field['label'] ?? $name;
            /** @phpstan-ignore-next-line */
            $type = $field['type'] ?? 'text';
            /** @phpstan-ignore-next-line */
            $default = $field['default'] ?? '';
            /** @phpstan-ignore-next-line */
            $options = $field['options'] ?? [];
            /** @phpstan-ignore-next-line */
            $required = $field['required'] ?? false;
            /** @phpstan-ignore-next-line */
            $help = $field['help'] ?? '';
            /** @phpstan-ignore-next-line */
            $rawDefault = $field['default'] ?? '';
            
            /**
             * Safe fallback parsing: if the default parameter is configured as an array,
             * serialize it to a JSON-compliant string to avoid scalar-to-array type conversions.
             */
            if (is_array($rawDefault)) {
                $defaultStr = json_encode($rawDefault, JSON_UNESCAPED_UNICODE) ?: '';
            } elseif (is_scalar($rawDefault)) {
                $defaultStr = (string) $rawDefault;
            } else {
                $defaultStr = '';
            }
            $value = $currentValues[$name] ?? $defaultStr;

            $html .= '<div class="op-field-group field-group-' . self::e($name) . '">';
            $html .= '<label for="setting_' . self::e($name) . '">' . self::e($label) . '</label>';

            $html .= match ($type) {
                'textarea' => self::textarea($name, $value, $required),
                'select'   => self::select($name, $value, $options, $required),
                'checkbox', 'toggle' => self::toggle($name, $value),
                'password' => self::passwordInput($name, $value, $required),
                'number'   => self::input($name, $value, 'number', $required),
                'email'    => self::input($name, $value, 'email', $required),
                'color'    => self::input($name, $value, 'color', $required),
                default    => self::input($name, $value, 'text', $required),
            };

            if ($help !== '' /** @phpstan-ignore notIdentical.alwaysFalse */) {
                $html .= '<small class="op-field-help">' . self::e($help) . '</small>';
            }

            $html .= '</div>';
        }

        $meta = get_class($plugin)::metadata();
        $slug = $meta['slug'];
        $defaultName = $meta['name'];

        if ($isGateway) {
            $html .= self::renderDisplayCustomization($displayValues, $defaultName);
        }

        $html .= '<div class="op-form-actions">';
        $html .= '<button type="submit" class="op-btn op-btn-primary">Save Settings</button>';
        if ($isGateway) {
            $html .= '<button type="button" class="op-btn op-btn-outline" id="op-test-connection-btn" '
                . 'data-url="/admin/plugins/' . self::e($slug) . '/test-connection">Test Connection</button>';
        }
        $html .= '</div>';
        if ($isGateway) {
            $html .= '<div id="op-test-connection-result" class="op-test-connection-result" role="status"></div>';
        }
        $html .= '</form>';

        if ($isGateway) {
            $html .= self::injectTestConnectionScript($cspNonce);
        }

        if ($slug === 'sms-gateway') {
            $html .= self::injectSmsGatewayToggleScript($cspNonce);
        }

        return $html;
    }

    /**
     * Render a standard HTML input field element.
     *
     * @param string $name The name attribute of the input control.
     * @param string $value The pre-populated value.
     * @param string $type The specific HTML5 input type (e.g. text, password, number, email, color).
     * @param bool $required Flag indicating whether the field must be completed.
     * @return string The generated input element HTML markup.
     */
    private static function input(string $name, string $value, string $type, bool $required): string
    {
        $req = $required ? ' required' : '';
        return '<input type="' . $type . '" name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'value="' . self::e($value) . '" '
            . 'class="op-input"' . $req . '>';
    }

    /**
     * Render a password input that never echoes its stored secret back into the page.
     *
     * The real value is only ever known server-side; the field renders blank with a hint
     * indicating whether a value is already configured. A blank submission on save is treated
     * as "leave unchanged" by the controller, so this never forces re-entry of a secret.
     *
     * @param string $name The name attribute of the input control.
     * @param string $value The currently-stored value (used only to decide the placeholder, never rendered).
     * @param bool $required Flag indicating whether the field must be completed.
     * @return string The generated input element HTML markup.
     */
    private static function passwordInput(string $name, string $value, bool $required): string
    {
        // A blank submission is valid once a value already exists (means "keep unchanged"), so
        // the HTML5 required constraint only applies for first-time, not-yet-configured fields -
        // otherwise the browser would block re-saving the form without re-entering every secret.
        $req = ($required && $value === '') ? ' required' : '';
        $placeholder = $value !== '' ? 'Already set - leave blank to keep unchanged' : 'Enter value';
        return '<input type="password" name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'value="" placeholder="' . self::e($placeholder) . '" '
            . 'autocomplete="new-password" '
            . 'class="op-input"' . $req . '>';
    }

    /**
     * Render an HTML textarea element.
     *
     * @param string $name The name attribute of the textarea control.
     * @param string $value The pre-populated content.
     * @param bool $required Flag indicating whether the field must be completed.
     * @return string The generated textarea element HTML markup.
     */
    private static function textarea(string $name, string $value, bool $required): string
    {
        $req = $required ? ' required' : '';
        return '<textarea name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'class="op-textarea" rows="4"' . $req . '>'
            . self::e($value) . '</textarea>';
    }

    /**
     * Render an HTML select dropdown element.
     *
     * @param string $name The name attribute of the select control.
     * @param string $value The currently active option key.
     * @param array<string|int, string> $options An associative mapping of option values to option labels.
     * @param bool $required Flag indicating whether the field must be completed.
     * @return string The generated select element HTML markup.
     */
    private static function select(string $name, string $value, array $options, bool $required): string
    {
        $req = $required ? ' required' : '';
        $html = '<select name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'class="op-select"' . $req . '>';

        foreach ($options as $optValue => $optLabel) {
            $selected = ((string) $optValue === $value) ? ' selected' : '';
            $html .= '<option value="' . self::e((string) $optValue) . '"' . $selected . '>'
                . self::e($optLabel) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * Render a custom boolean checkbox/toggle element.
     *
     * Includes a hidden input fallback to ensure standard form submissions submit a zero-value
     * when the toggle switch is not active.
     *
     * @param string $name The name attribute of the toggle control.
     * @param string $value The current string state representing the boolean toggle.
     * @return string The generated checkbox toggle HTML markup.
     */
    private static function toggle(string $name, string $value): string
    {
        $checked = ($value === '1' || $value === 'true') ? ' checked' : '';
        return '<label class="op-toggle">'
            . '<input type="hidden" name="settings[' . self::e($name) . ']" value="0">'
            . '<input type="checkbox" name="settings[' . self::e($name) . ']" value="1"' . $checked . '>'
            . '<span class="op-toggle-slider"></span>'
            . '</label>';
    }

    /**
     * Renders the "Display Customization" fields shown on every gateway's settings page: an
     * optional name override (e.g. rename "Stripe" to "Card" on checkout) and an optional logo
     * upload, submitted in the same form/POST as the credential fields. Blank means "use default".
     *
     * @param array<string, string> $displayValues Currently-stored display_name/display_logo values.
     * @param string $defaultName The gateway's built-in default display name, shown as a hint.
     * @return string The generated HTML markup.
     */
    private static function renderDisplayCustomization(array $displayValues, string $defaultName): string
    {
        $currentName = $displayValues['display_name'] ?? '';
        $currentLogo = $displayValues['display_logo'] ?? '';

        $html = '<hr class="op-settings-divider">';
        $html .= '<h4 class="op-settings-subheading">Display Customization</h4>';
        $html .= '<p class="op-field-help op-mb-2">Shown to customers on the checkout page. Leave blank to use the default.</p>';

        $html .= '<div class="op-field-group">';
        $html .= '<label for="display_name">Custom Name</label>';
        $html .= '<input type="text" name="display[name]" id="display_name" value="' . self::e($currentName) . '" '
            . 'placeholder="' . self::e($defaultName) . '" class="op-input">';
        $html .= '</div>';

        $html .= '<div class="op-field-group">';
        $html .= '<label for="display_logo">Custom Logo</label>';
        if ($currentLogo !== '') {
            $html .= '<div class="op-current-logo-preview">'
                . '<img src="' . self::e($currentLogo) . '" alt="" class="op-gw-logo-preview">'
                . '<label class="op-toggle-label op-text-sm">'
                . '<input type="checkbox" name="remove_display_logo" value="1"> Remove custom logo'
                . '</label></div>';
        }
        $html .= '<input type="file" name="display_logo" id="display_logo" accept="image/*" class="op-input">';
        $html .= '<small class="op-field-help">PNG, JPG, or SVG. Leave blank to keep the current logo.</small>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Injects the "Test Connection" button's client-side handler.
     *
     * Sends the form's CURRENT values (whatever the admin has typed, even if not yet saved) via
     * the same multipart body as a normal submission - the server merges any blank field back to
     * its already-saved value, so testing never requires re-typing every credential first.
     *
     * @param string $cspNonce Per-request CSP nonce - without it, admin pages' strict CSP
     *                         (`script-src 'self' 'nonce-...'`, no 'unsafe-inline') silently
     *                         blocks this whole block from ever running.
     * @return string The inline HTML script block.
     */
    private static function injectTestConnectionScript(string $cspNonce): string
    {
        $nonceAttr = $cspNonce !== '' ? ' nonce="' . self::e($cspNonce) . '"' : '';
        return '
<script' . $nonceAttr . '>
(function() {
    function initTestConnection() {
        var btn = document.getElementById("op-test-connection-btn");
        var form = document.getElementById("op-settings-form");
        var resultEl = document.getElementById("op-test-connection-result");
        if (!btn || !form || !resultEl) return;

        btn.addEventListener("click", function() {
            resultEl.className = "op-test-connection-result op-test-connection-pending";
            resultEl.textContent = "Testing connection…";
            btn.disabled = true;

            var formData = new FormData(form);

            fetch(btn.getAttribute("data-url"), {
                method: "POST",
                body: formData,
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
                .then(function(res) {
                    return res.json().then(function(data) {
                        return { ok: res.ok, data: data };
                    }).catch(function() {
                        return { ok: res.ok, data: { success: false, message: "Invalid server response (" + res.status + ")" } };
                    });
                })
                .then(function(result) {
                    var data = result.data || {};
                    var ok = !!data.success;
                    if (data._csrf_token) {
                        var csrfInput = form.querySelector(\'input[name="_csrf_token"]\');
                        if (csrfInput) { csrfInput.value = data._csrf_token; }
                    }
                    resultEl.className = "op-test-connection-result " + (ok ? "op-test-connection-ok" : "op-test-connection-fail");
                    resultEl.textContent = (ok ? "✓ " : "✗ ") + (data.message || (ok ? "Connected." : "Connection failed."));
                })
                .catch(function() {
                    resultEl.className = "op-test-connection-result op-test-connection-fail";
                    resultEl.textContent = "✗ Could not reach the server. Please try again.";
                })
                .finally(function() { btn.disabled = false; });
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initTestConnection);
    } else {
        initTestConnection();
    }
})();
</script>
';
    }

    /**
     * Securely escapes content for inclusion in HTML attributes.
     *
     * Uses htmlspecialchars with ENT_QUOTES and UTF-8 encoding.
     *
     * @param string $value The raw content string.
     * @return string The escaped string safe for HTML output.
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Injects the dynamic client-side Javascript block to toggle provider field visibilities.
     *
     * @param string $cspNonce Per-request CSP nonce - without it, admin pages' strict CSP
     *                         (`script-src 'self' 'nonce-...'`, no 'unsafe-inline') silently
     *                         blocks this whole block from ever running.
     * @return string The inline HTML script block.
     */
    private static function injectSmsGatewayToggleScript(string $cspNonce): string
    {
        $nonceAttr = $cspNonce !== '' ? ' nonce="' . self::e($cspNonce) . '"' : '';
        return '
<script' . $nonceAttr . '>
document.addEventListener("DOMContentLoaded", function() {
    var providerSelect = document.getElementById("setting_provider");
    if (!providerSelect) return;

    var twilioFields = [".field-group-twilio_sid", ".field-group-twilio_token", ".field-group-twilio_from"];
    var vonageFields = [".field-group-vonage_key", ".field-group-vonage_secret", ".field-group-vonage_from"];
    var customFields = [".field-group-custom_api_url", ".field-group-custom_api_key", ".field-group-custom_api_method", ".field-group-custom_api_body_template"];

    function updateVisibility() {
        var selected = providerSelect.value;

        // Helper to toggle a list of fields
        function toggleFields(fields, show) {
            fields.forEach(function(selector) {
                var el = document.querySelector(selector);
                if (el) {
                    el.style.display = show ? "block" : "none";
                }
            });
        }

        // Hide all first
        toggleFields(twilioFields, false);
        toggleFields(vonageFields, false);
        toggleFields(customFields, false);

        // Show selected
        if (selected === "twilio") {
            toggleFields(twilioFields, true);
        } else if (selected === "vonage") {
            toggleFields(vonageFields, true);
        } else if (selected === "custom") {
            toggleFields(customFields, true);
        }
    }

    providerSelect.addEventListener("change", updateVisibility);
    updateVisibility();
});
</script>
';
    }
}
