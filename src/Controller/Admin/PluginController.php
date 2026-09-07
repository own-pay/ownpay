<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\PluginManager;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\GatewayRepository;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\View\SettingsRenderer;

/**
 * Class PluginController
 *
 * Administrative portal controller managing plugins (addons, gateways, and themes),
 * providing interfaces for discovery, upload/installation, activation/deactivation,
 * uninstallation, and brand-scoped custom configurations.
 *
 * @package OwnPay\Controller\Admin
 */
final class PluginController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var PluginManager The core plugin manager.
     */
    private PluginManager $manager;

    /**
     * @var PluginRepository The database repository for installed plugins.
     */
    private PluginRepository $repo;

    /**
     * @var PluginRegistry The runtime registry holding active plugin instances.
     */
    private PluginRegistry $registry;

    /**
     * @var EventManager The hooks and actions event manager.
     */
    private EventManager $events;

    /**
     * PluginController constructor.
     *
     * @param Container        $c        The dependency injection container.
     * @param AdminSession     $session  The administrative session service.
     * @param PluginManager    $manager  The core plugin manager.
     * @param PluginRepository $repo     The database repository for installed plugins.
     * @param PluginRegistry   $registry The runtime registry holding active plugin instances.
     * @param EventManager     $events   The hooks and actions event manager.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        PluginManager $manager,
        PluginRepository $repo,
        PluginRegistry $registry,
        EventManager $events
    ) {
        $this->c        = $c;
        $this->session  = $session;
        $this->manager  = $manager;
        $this->repo     = $repo;
        $this->registry = $registry;
        $this->events   = $events;
    }

    /**
     * Renders a list of all plugins, combining database records with discovered filesystem plugins.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The plugin dashboard overview page.
     *
     * @phpstan-ignore-next-line
     */
    public function index(Request $request): Response
    {
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        $plugins = $this->repo->paginate(1, 200)['items'];

        // Discover filesystem plugins
        /** @var \OwnPay\Plugin\PluginLoader $loader */
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        $discovered = $loader->discover();

        // Enrich DB rows with manifest name (DB may have slug as name)
        foreach ($plugins as &$p) {
            $slugVal = $p['slug'] ?? '';
            $slug = is_string($slugVal) ? $slugVal : '';

            $manifestVal = $p['manifest'] ?? '{}';
            $m = json_decode(is_string($manifestVal) ? $manifestVal : '{}', true);
            $m = is_array($m) ? $m : [];
            if (!empty($m['name'])) {
                $p['name'] = $m['name'];
            }
            $p['description'] = $p['description'] ?? ($m['description'] ?? '');

            // Second-pass: Align with discovered filesystem manifest attributes (prefer FS names)
            if ($slug !== '' && isset($discovered[$slug])) {
                $fsManifest = $discovered[$slug];
                $p['name']        = $fsManifest->name;
                $p['description'] = $p['description'] ?: ($fsManifest->description ?? '');
                $p['author']      = $p['author'] ?? ($fsManifest->author ?? 'Unknown');
                $p['version']     = $fsManifest->version;
                
                $p['logo_path'] = $this->manager->resolveIconPath($slug, $p, $fsManifest);
            } else {
                $p['logo_path'] = null;
            }

            // Local active/inactive status override if brand context is active
            if ($brandId !== null && $brandId > 0 && !in_array($p['status'], ['uninstalled', 'trashed'], true)) {
                if ($slug !== '') {
                    $p['status'] = $this->repo->isPluginActiveForBrand($slug, $brandId) ? 'active' : 'inactive';
                }
            }
        }
        unset($p);

        foreach ($discovered as $manifest) {
            if (in_array($manifest->type, ['addon', 'gateway', 'plugin', 'theme'], true)) {
                $found = false;
                foreach ($plugins as $p) {
                    if ($p['slug'] === $manifest->slug) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $plugins[] = [
                        'slug'        => $manifest->slug,
                        'name'        => $manifest->name,
                        'description' => $manifest->description,
                        'version'     => $manifest->version,
                        'status'      => 'uninstalled',
                        'author'      => $manifest->author,
                        'type'        => $manifest->type,
                        'logo_path'   => $this->manager->resolveIconPath($manifest->slug, ['type' => $manifest->type], $manifest),
                    ];
                }
            }
        }

        return $this->renderAdminPage('admin/plugins/index.twig', [
            'plugins'        => $plugins,
            'active_page'    => 'plugins',
            'is_global_view' => $this->isGlobalBrandView(),
        ]);
    }

    /**
     * Renders the ZIP installation upload form page.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The plugin upload form page.
     */
    public function installForm(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'upload a plugin')) {
            return $guard;
        }

        $maxUpload = min(
            $this->parseSize(ini_get('upload_max_filesize') ?: '2M'),
            $this->parseSize(ini_get('post_max_size') ?: '8M')
        );

        return $this->renderAdminPage('admin/plugins/install.twig', [
            'max_upload_size' => $this->formatSize($maxUpload),
            'active_page'     => 'plugins',
        ]);
    }

    /**
     * Handles processing uploaded plugin ZIP files, installing them to the modules folder.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The redirect response back to index or error.
     */
    public function upload(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'upload a plugin')) {
            return $guard;
        }

        // 1. Check if this is a confirmed update request
        $confirmUpdatePost = $request->post('confirm_update');
        $confirmUpdate = is_scalar($confirmUpdatePost) ? (int) $confirmUpdatePost : 0;
        $tempZipPost = $request->post('temp_zip');
        $tempZip = is_string($tempZipPost) ? $tempZipPost : '';

        if ($confirmUpdate === 1 && $tempZip !== '') {
            $configApp = $this->c->get('config.app');
            $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
            $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
            $tempUploadsDir = realpath($storagePath . '/temp_uploads');
            $realTempZip = realpath($tempZip);

            if ($tempUploadsDir === false || $realTempZip === false || !str_starts_with($realTempZip, $tempUploadsDir)) {
                return $this->redirectBack($request, 'Invalid temporary ZIP path');
            }

            $result = $this->manager->update($realTempZip);
            @unlink($realTempZip);

            if (!$result['success']) {
                return $this->redirectBack($request, $result['error'] ?? 'Update failed');
            }

            $slug = $result['slug'] ?? 'unknown';
            $this->session->flashSuccess("Plugin '{$slug}' updated successfully!");
            return Response::redirect('/admin/plugins');
        }

        // 2. Standard upload flow
        $file = $request->file('plugin_zip');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->redirectBack($request, 'No file uploaded or upload failed');
        }

        $fileName = $file['name'] ?? '';
        if (!is_string($fileName) || !str_ends_with(strtolower($fileName), '.zip')) {
            return $this->redirectBack($request, 'Only .zip files are allowed');
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '') {
            return $this->redirectBack($request, 'Upload failed');
        }
        $result = $this->manager->install($tmpName);

        if (!$result['success']) {
            if (isset($result['code']) && $result['code'] === 'already_installed') {
                $configApp = $this->c->get('config.app');
                $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
                $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
                $tempUploadsDir = $storagePath . '/temp_uploads';
                if (!is_dir($tempUploadsDir)) {
                    @mkdir($tempUploadsDir, 0755, true);
                }
                $slug = is_string($result['slug'] ?? null) ? $result['slug'] : 'unknown';
                $tempZipName = $slug . '_' . bin2hex(random_bytes(8)) . '.zip';
                $persistentTempZip = $tempUploadsDir . '/' . $tempZipName;
                copy($tmpName, $persistentTempZip);

                $newVersion = is_string($result['new_version'] ?? null) ? $result['new_version'] : '0.0.0';
                $existingVersion = is_string($result['existing_version'] ?? null) ? $result['existing_version'] : '0.0.0';
                $compare = version_compare($newVersion, $existingVersion);
                $versionRelation = 'new';
                if ($compare === 0) {
                    $versionRelation = 'same';
                } elseif ($compare < 0) {
                    $versionRelation = 'older';
                }

                return $this->renderAdminPage('admin/plugins/confirm_update.twig', [
                    'slug'             => $slug,
                    'existing_version' => $existingVersion,
                    'new_version'      => $newVersion,
                    'version_relation' => $versionRelation,
                    'has_migrations'   => !empty($result['has_migrations']),
                    'temp_zip'         => $persistentTempZip,
                    'active_page'      => 'plugins',
                ]);
            }
            return $this->redirectBack($request, $result['error'] ?? 'Installation failed');
        }

        $slug = $result['slug'] ?? 'unknown';
        $this->session->flashSuccess("Plugin '{$slug}' installed successfully!");
        return Response::redirect('/admin/plugins');
    }

    /**
     * Cancels the update flow and deletes the temporary ZIP file.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response The redirect response.
     */
    public function cancelUpload(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'upload a plugin')) {
            return $guard;
        }

        $tempZipPost = $request->post('temp_zip');
        $tempZip = is_string($tempZipPost) ? $tempZipPost : '';
        if ($tempZip !== '') {
            $configApp = $this->c->get('config.app');
            $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
            $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
            $tempUploadsDir = realpath($storagePath . '/temp_uploads');
            $realTempZip = realpath($tempZip);

            if ($tempUploadsDir !== false && $realTempZip !== false && str_starts_with($realTempZip, $tempUploadsDir)) {
                @unlink($realTempZip);
            }
        }
        return Response::redirect('/admin/plugins');
    }

    /**
     * Activates an installed plugin and executes its migrations.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function activate(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }
        $result = $this->manager->activate($slug, $brandId);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Activation failed');
        } else {
            $msg = ($brandId !== null && $brandId > 0)
                ? "Plugin '{$slug}' activated for this brand!"
                : "Plugin '{$slug}' activated! (" . ($result['migrations_run'] ?? 0) . " migrations run)";
            $this->session->flashSuccess($msg);
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Deactivates an active plugin without purging its data records.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function deactivate(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }
        $result = $this->manager->deactivate($slug, $brandId);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Deactivation failed');
        } else {
            $msg = ($brandId !== null && $brandId > 0)
                ? "Plugin '{$slug}' deactivated for this brand."
                : "Plugin '{$slug}' deactivated.";
            $this->session->flashSuccess($msg);
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Deactivates and completely uninstalls a plugin, purging its files and database traces.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function uninstall(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'uninstall a plugin')) {
            return $guard;
        }

        $slug = (string) $request->param('slug');
        try {
            $result = $this->manager->uninstall($slug);

            if (!$result['success']) {
                $this->session->flashError($result['error'] ?? 'Uninstall failed');
            } else {
                $this->session->flashSuccess("Plugin '{$slug}' uninstalled.");
            }
        } catch (\OwnPay\Plugin\Exception\PluginInUseException $e) {
            $this->session->flashError($e->getMessage());
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Moves an inactive plugin to the trash folder.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function trash(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'manage plugin files')) {
            return $guard;
        }

        $slug = (string) $request->param('slug');
        $result = $this->manager->trash($slug);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Failed to move plugin to trash');
        } else {
            $this->session->flashSuccess("Plugin '{$slug}' moved to trash.");
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Restores a trashed plugin back to the modules directory.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function restore(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'manage plugin files')) {
            return $guard;
        }

        $slug = (string) $request->param('slug');
        $result = $this->manager->restore($slug);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Failed to restore plugin');
        } else {
            $this->session->flashSuccess("Plugin '{$slug}' restored successfully.");
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Renders settings fields form provided by the plugin, supporting brand-level configuration scoping.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The plugin configuration page response.
     */
    public function settings(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return Response::redirect('/admin/plugins');
        }

        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        if ($brandId !== null && $brandId > 0) {
            if (!$this->repo->isPluginActiveForBrand($slug, $brandId)) {
                $this->session->flashError('This plugin is not active for the current brand.');
                return Response::redirect('/admin/plugins');
            }
        }

        $manifestVal = $plugin['manifest'] ?? '{}';
        $manifestJson = json_decode(is_string($manifestVal) ? $manifestVal : '{}', true);
        $manifestJson = is_array($manifestJson) ? $manifestJson : [];
        $plugin['author'] = $manifestJson['author'] ?? 'Unknown';
        $plugin['description'] = $manifestJson['description'] ?? '';

        $instance = $this->resolvePluginInstance($slug);

        $settingsHtml = '';
        if ($instance !== null) {
            $isGateway = ($plugin['type'] ?? '') === 'gateway';
            $displayValues = [];
            if ($isGateway) {
                $currentValues = $this->readGatewayCredentials($slug, $brandId);
                $displayValues = $this->readGatewayDisplaySettings($slug, $brandId);
            } else {
                $settingsRepo = $this->c->get(SettingsRepository::class);
                $currentValues = [];
                if ($settingsRepo instanceof SettingsRepository) {
                    $currentValues = ($brandId !== null && $brandId > 0)
                        ? $settingsRepo->getGroupScoped("plugin.{$slug}", $brandId)
                        : $settingsRepo->getGroup("plugin.{$slug}");
                }
            }
            $action = "/admin/plugins/{$slug}/settings";
            $cspNonce = '';
            if ($this->c->has(\OwnPay\Security\CspNonce::class)) {
                $cspNonceObj = $this->c->get(\OwnPay\Security\CspNonce::class);
                if ($cspNonceObj instanceof \OwnPay\Security\CspNonce) {
                    $cspNonce = $cspNonceObj->getNonce();
                }
            }
            if ($cspNonce === '') {
                $reqNonce = $request->getAttribute('csp_nonce');
                if (is_string($reqNonce)) {
                    $cspNonce = $reqNonce;
                }
            }
            $settingsHtml = SettingsRenderer::render($instance, $currentValues, $action, $isGateway, $displayValues, $cspNonce);
        }

        $activePage = match ($plugin['type'] ?? 'plugin') {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            'addon'   => 'addons',
            default   => 'plugins',
        };

        return $this->renderAdminPage('admin/plugins/settings.twig', [
            'plugin'        => $plugin,
            'settings_html' => $settingsHtml,
            'active_page'   => $activePage,
        ]);
    }

    /**
     * Saves configuration parameters for the plugin, scoped optionally by brand ID context.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function saveSettings(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $settings = $request->post('settings') ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        /** @var SettingsRepository $settingsRepo */
        $settingsRepo = $this->c->get(SettingsRepository::class);

        // Save brand-scoped plugin settings
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        $plugin = $this->repo->findBySlug($slug);
        $isGateway = $plugin !== null && ($plugin['type'] ?? '') === 'gateway';
        $instance = $this->resolvePluginInstance($slug);
        $passwordFields = $this->passwordFieldNames($instance);

        if ($instance !== null) {
            $existingValues = $isGateway
                ? $this->readGatewayCredentials($slug, $brandId)
                : (($brandId !== null && $brandId > 0)
                    ? $settingsRepo->getGroupScoped("plugin.{$slug}", $brandId)
                    : $settingsRepo->getGroup("plugin.{$slug}"));
            $missingFields = self::validateRequiredFields($instance, $settings, $existingValues);
            if (!empty($missingFields)) {
                $this->session->flashError('Missing required field(s): ' . implode(', ', $missingFields) . '.');
                return Response::redirect("/admin/plugins/{$slug}/settings");
            }
        }

        if ($isGateway) {
            $displaySettings = $this->extractGatewayDisplaySettings($request, $slug, $brandId);
            $this->saveGatewayCredentials($slug, $brandId, $settings, $passwordFields, $displaySettings);
        } elseif ($brandId !== null && $brandId > 0) {
            $this->mergeUnblankedPasswordFields($settings, $passwordFields, $settingsRepo->getGroupScoped("plugin.{$slug}", $brandId));
            $settingsRepo->bulkSetScoped("plugin.{$slug}", $settings, $brandId);
        } else {
            $this->mergeUnblankedPasswordFields($settings, $passwordFields, $settingsRepo->getGroup("plugin.{$slug}"));
            $settingsRepo->bulkSet("plugin.{$slug}", $settings);
        }

        $this->events->doAction('plugin.settings.saved', $slug, $settings, $brandId);

        $this->session->flashSuccess('Settings saved.');
        return Response::redirect("/admin/plugins/{$slug}/settings");
    }

    /**
     * Tests whether a gateway's credentials actually authenticate against its provider's API,
     * without saving anything. Uses whatever is currently typed in the settings form for each
     * field, falling back to the already-saved value for any field left blank - so testing never
     * requires re-typing every secret first, but also reflects an in-progress edit immediately.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response JSON {success: bool, message: string}.
     */
    public function testConnection(Request $request): Response
    {
        $slug = (string) $request->param('slug');

        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        $newCsrf = $request->getAttribute('_new_csrf_token');
        if (!is_string($newCsrf) || $newCsrf === '') {
            $newCsrf = $_SESSION['_csrf_token'] ?? null;
        }

        $instance = $this->resolvePluginInstance($slug);
        if (!$instance instanceof \OwnPay\Gateway\TestableConnectionInterface) {
            return Response::json([
                'success'     => false,
                'message'     => 'Connection testing isn\'t supported for this gateway yet.',
                '_csrf_token' => $newCsrf,
            ]);
        }

        try {
            $submitted = $request->post('settings') ?? [];
            if (!is_array($submitted)) {
                $submitted = [];
            }
            $passwordFields = $this->passwordFieldNames($instance);
            $existingCreds = $this->readGatewayCredentials($slug, $brandId);
            $credentials = self::mergeGatewayFieldValues($existingCreds, $submitted, $passwordFields);

            $result = $instance->testConnection($credentials);
        } catch (\Throwable $e) {
            error_log('Gateway testConnection error: ' . $e->getMessage());
            return Response::json([
                'success'     => false,
                'message'     => 'Unexpected error, please check the server logs.',
                '_csrf_token' => $newCsrf,
            ]);
        }

        return Response::json([
            'success'     => $result['success'],
            'message'     => $result['message'],
            '_csrf_token' => $newCsrf,
        ]);
    }

    /**
     * Resolves a plugin's runtime instance, falling back to a fresh filesystem load if the
     * plugin is registered in the database but not booted in the current request.
     *
     * @param string $slug Unique plugin identifier.
     * @return PluginInterface|null The plugin instance, or null if it cannot be resolved.
     */
    private function resolvePluginInstance(string $slug): ?PluginInterface
    {
        $instance = $this->registry->get($slug);
        if ($instance !== null) {
            return $instance;
        }

        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        if (!$loader instanceof \OwnPay\Plugin\PluginLoader) {
            return null;
        }
        $manifests = $loader->discover();
        $manifest = $manifests[$slug] ?? null;
        if ($manifest === null) {
            return null;
        }
        $entrypointFile = $manifest->path . '/' . $manifest->entrypoint;
        if (!file_exists($entrypointFile)) {
            return null;
        }
        require_once $entrypointFile;
        $rawManifestJson = file_get_contents($manifest->path . '/manifest.json');
        $rawManifest = json_decode(is_string($rawManifestJson) ? $rawManifestJson : '{}', true);
        $rawManifest = is_array($rawManifest) ? $rawManifest : [];
        if (!empty($rawManifest['namespace']) && is_string($rawManifest['namespace'])) {
            $className = rtrim($rawManifest['namespace'], '\\') . '\\' . pathinfo($manifest->entrypoint, PATHINFO_FILENAME);
        } else {
            $pascal = str_replace('-', '', ucwords($manifest->slug, '-'));
            $className = "OwnPay\\Plugins\\{$pascal}\\" . pathinfo($manifest->entrypoint, PATHINFO_FILENAME);
        }
        if (class_exists($className) && is_subclass_of($className, PluginInterface::class)) {
            return new $className();
        }
        return null;
    }

    /**
     * Extracts the names of password-type settings fields a plugin declares.
     *
     * Used to keep secrets out of both persisted plaintext storage and rendered HTML.
     *
     * @param PluginInterface|null $instance The plugin instance, or null if unresolved.
     * @return array<int, string> Field names declared with type 'password'.
     */
    private function passwordFieldNames(?PluginInterface $instance): array
    {
        if ($instance === null) {
            return [];
        }
        $names = [];
        foreach ($instance->fields() as $field) {
            if ($field['type'] === 'password' && $field['name'] !== '') {
                $names[] = $field['name'];
            }
        }
        return $names;
    }

    /**
     * Server-side re-check of each field a plugin declares required - the HTML5
     * `required` attribute SettingsRenderer renders only stops a normal browser
     * submission, not a scripted/bypassed POST.
     *
     * @param PluginInterface $instance The plugin instance to validate against.
     * @param array<string, mixed> $settings The submitted settings values.
     * @param array<string, string> $existingValues Currently-stored values keyed by field name.
     * @return array<int, string> Labels of any required fields that are missing.
     */
    private static function validateRequiredFields(PluginInterface $instance, array $settings, array $existingValues): array
    {
        $missing = [];
        foreach ($instance->fields() as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }
            $name = $field['name'];
            if ($name === '') {
                continue;
            }
            $submitted = isset($settings[$name]) && is_scalar($settings[$name]) ? (string) $settings[$name] : '';
            if ($submitted !== '') {
                continue;
            }
            if ($field['type'] === 'password') {
                $existing = $existingValues[$name] ?? '';
                if ($existing !== '') {
                    continue;
                }
            }
            $label = ($field['label'] ?? '') !== '' ? $field['label'] : $name;
            $missing[] = $label;
        }
        return $missing;
    }

    /**
     * Restores a previously-saved value for any password-type field submitted blank.
     *
     * The settings form never round-trips a real secret into its HTML (see SettingsRenderer),
     * so a blank password field on submit means "not changed", not "clear this value".
     * Mutates $settings in place.
     *
     * @param array<string, mixed> $settings Submitted settings, modified in place.
     * @param array<int, string> $passwordFields Field names declared with type 'password'.
     * @param array<string, string> $existingValues Currently-stored values keyed by field name.
     * @return void
     */
    private function mergeUnblankedPasswordFields(array &$settings, array $passwordFields, array $existingValues): void
    {
        foreach ($passwordFields as $name) {
            $submitted = isset($settings[$name]) && is_scalar($settings[$name]) ? (string) $settings[$name] : '';
            if ($submitted === '' && isset($existingValues[$name]) && $existingValues[$name] !== '') {
                $settings[$name] = $existingValues[$name];
            }
        }
    }



    /**
     * Decrypts the currently-stored credentials for a gateway-type plugin under a brand or global scope.
     *
     * Falls back to legacy plaintext plugin settings if no encrypted credentials exist yet
     * (pre-migration data), matching GatewayBridge::decryptCredentials()'s own fallback.
     *
     * @param string $slug Gateway adapter slug.
     * @param int|null $brandId Active brand/merchant ID (null for global scope).
     * @return array<string, string> Decrypted credential key-value pairs.
     */
    private function readGatewayCredentials(string $slug, ?int $brandId): array
    {
        $gwRepo = $this->c->get(GatewayRepository::class);
        if (!$gwRepo instanceof GatewayRepository) {
            return [];
        }
        $gw = $gwRepo->findBySlug($slug);
        $gwId = is_numeric($gw['id'] ?? null) ? (int) $gw['id'] : 0;
        if ($gwId <= 0) {
            return [];
        }
        $gwConfigRepo = $this->c->get(GatewayConfigRepository::class);
        if (!$gwConfigRepo instanceof GatewayConfigRepository) {
            return [];
        }

        $existing = ($brandId !== null && $brandId > 0)
            ? $gwConfigRepo->forTenant($brandId)->findForGateway($gwId)
            : $gwConfigRepo->findForGateway($gwId);

        $encCreds = is_scalar($existing['credentials_enc'] ?? null) ? (string) $existing['credentials_enc'] : '';
        if ($encCreds === '') {
            $settingsRepo = $this->c->get(SettingsRepository::class);
            if (!$settingsRepo instanceof SettingsRepository) {
                return [];
            }
            return ($brandId !== null && $brandId > 0)
                ? $settingsRepo->getGroupScoped("plugin.{$slug}", $brandId)
                : $settingsRepo->getGroup("plugin.{$slug}");
        }
        $encryptor = $this->c->get(FieldEncryptor::class);
        if (!$encryptor instanceof FieldEncryptor) {
            return [];
        }
        try {
            $decrypted = $encryptor->decrypt($encCreds);
        } catch (\RuntimeException $e) {
            // Stored ciphertext can't be decrypted with any known key (e.g. it was
            // encrypted under a different key that's since rotated away or lost).
            // Treat as "nothing to pre-fill" rather than blocking the whole settings
            // page - the admin can still re-enter and re-save the credentials.
            return [];
        }
        $decoded = json_decode($decrypted, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $result[$k] = (string) $v;
            }
        }
        return $result;
    }

    /**
     * Reads the currently-stored display customization (display_name/display_logo overrides) for
     * a gateway-type plugin under a brand or global scope. Unlike credentials, this lives in the
     * gateway config's plaintext `settings` column - no decryption needed, since none of it is a secret.
     *
     * @param string $slug Gateway adapter slug.
     * @param int|null $brandId Active brand/merchant ID (null for global scope).
     * @return array<string, string> Currently-stored display_name/display_logo values, if set.
     */
    private function readGatewayDisplaySettings(string $slug, ?int $brandId): array
    {
        $gwRepo = $this->c->get(GatewayRepository::class);
        if (!$gwRepo instanceof GatewayRepository) {
            return [];
        }
        $gw = $gwRepo->findBySlug($slug);
        $gwId = is_numeric($gw['id'] ?? null) ? (int) $gw['id'] : 0;
        if ($gwId <= 0) {
            return [];
        }
        $gwConfigRepo = $this->c->get(GatewayConfigRepository::class);
        if (!$gwConfigRepo instanceof GatewayConfigRepository) {
            return [];
        }

        $existing = ($brandId !== null && $brandId > 0)
            ? $gwConfigRepo->forTenant($brandId)->findForGateway($gwId)
            : $gwConfigRepo->findForGateway($gwId);

        $settingsRaw = is_array($existing) ? ($existing['settings'] ?? null) : null;
        $decoded = is_string($settingsRaw) ? json_decode($settingsRaw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $result[$k] = (string) $v;
            }
        }
        return $result;
    }

    /**
     * Builds the display_name/display_logo settings to persist from the current request, merged
     * over whatever is already stored. A blank submitted name clears the override back to the
     * gateway's default (unlike credential fields, blank here means "use default", not "keep
     * unchanged"). A new logo upload replaces the stored path; the "remove custom logo" checkbox
     * clears it; leaving both alone keeps whatever logo is already stored.
     *
     * @param Request $request The incoming HTTP request.
     * @param string $slug Gateway adapter slug.
     * @param int|null $brandId Active brand/merchant ID (null for global scope).
     * @return array<string, string> The merged display settings to persist.
     */
    private function extractGatewayDisplaySettings(Request $request, string $slug, ?int $brandId): array
    {
        $displaySettings = $this->readGatewayDisplaySettings($slug, $brandId);

        $displayPost = $request->post('display');
        $customName = is_array($displayPost) && isset($displayPost['name']) && is_string($displayPost['name'])
            ? trim($displayPost['name'])
            : '';
        if ($customName !== '') {
            $displaySettings['display_name'] = $customName;
        } else {
            unset($displaySettings['display_name']);
        }

        $logoFile = $request->file('display_logo');
        if ($logoFile !== null && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                if (isset($logoFile['name'], $logoFile['tmp_name']) && is_string($logoFile['name']) && is_string($logoFile['tmp_name'])) {
                    $fs = new \OwnPay\Service\System\FilesystemService();
                    $displaySettings['display_logo'] = $fs->storePublicUpload($logoFile, 'gateways');
                }
            } catch (\Throwable $e) {
                $this->session->flashError('Invalid file for gateway logo: ' . $e->getMessage());
            }
        } elseif ($request->post('remove_display_logo') === '1') {
            unset($displaySettings['display_logo']);
        }

        return $displaySettings;
    }

    /**
     * Merges freshly-submitted field values over an existing credential set, leaving a
     * password-type field unchanged when submitted blank (its stored value already exists) -
     * shared by saveGatewayCredentials() and testConnection() so "test with what's on screen,
     * fall back to what's saved" behaves identically in both places.
     *
     * @param array<string, string> $existing Currently-stored values.
     * @param array<string, mixed> $submitted Freshly-submitted form values.
     * @param array<int, string> $passwordFields Field names declared with type 'password'.
     * @return array<string, string> Merged values.
     */
    private static function mergeGatewayFieldValues(array $existing, array $submitted, array $passwordFields): array
    {
        $merged = $existing;
        foreach ($submitted as $key => $value) {
            $valueStr = is_scalar($value) ? (string) $value : '';
            if ($valueStr === '' && in_array($key, $passwordFields, true)
                && isset($existing[$key]) && $existing[$key] !== '') {
                continue;
            }
            $merged[$key] = $valueStr;
        }
        return $merged;
    }

    /**
     * Encrypts and persists submitted settings as a gateway's credentials_enc payload.
     *
     * Payment gateway credentials (API keys, secrets, usernames, passwords) are never written
     * to plaintext op_system_settings - the full field set is encrypted together, mirroring
     * DashboardController::setupOnboardingGateway()'s already-correct pattern.
     *
     * When $brandId is null or <= 0 (Global/All Brands view), it persists to the global
     * op_gateway_configs row (merchant_id IS NULL) and synchronizes legacy system settings,
     * ensuring no individual merchant's live credentials are ever modified from global scope.
     *
     * @param string $slug Gateway adapter slug.
     * @param int|null $brandId Active brand/merchant ID (null for global scope).
     * @param array<string, mixed> $settings Submitted settings fields.
     * @param array<int, string> $passwordFields Field names declared with type 'password'.
     * @param array<string, string>|null $displaySettings Display name/logo override to persist
     *                                    into the config's plaintext `settings` column, or null to
     *                                    leave it untouched (e.g. when only credentials changed).
     * @return void
     */
    private function saveGatewayCredentials(string $slug, ?int $brandId, array $settings, array $passwordFields, ?array $displaySettings = null): void
    {
        $gwRepo = $this->c->get(GatewayRepository::class);
        if (!$gwRepo instanceof GatewayRepository) {
            return;
        }
        $gw = $gwRepo->findBySlug($slug);
        $gwId = is_numeric($gw['id'] ?? null) ? (int) $gw['id'] : 0;
        if ($gwId <= 0) {
            return;
        }
        $gwConfigRepo = $this->c->get(GatewayConfigRepository::class);
        if (!$gwConfigRepo instanceof GatewayConfigRepository) {
            return;
        }

        $existingCreds = $this->readGatewayCredentials($slug, $brandId);
        $merged = self::mergeGatewayFieldValues($existingCreds, $settings, $passwordFields);

        $encryptor = $this->c->get(FieldEncryptor::class);
        $encCreds = $encryptor instanceof FieldEncryptor ? $encryptor->encrypt(json_encode($merged) ?: '{}') : '';

        $payload = [
            'credentials_enc' => $encCreds,
            'status'          => 'active',
        ];
        if ($displaySettings !== null) {
            $payload['settings'] = json_encode($displaySettings) ?: '{}';
        }

        $db = $this->c->get(\OwnPay\Core\Database::class);

        if ($brandId !== null && $brandId > 0) {
            $scopedConfigRepo = $gwConfigRepo->forTenant($brandId);
            $existing = ($db instanceof \OwnPay\Core\Database)
                ? $db->fetchOne(
                    "SELECT id FROM op_gateway_configs WHERE gateway_id = :gid AND merchant_id = :mid LIMIT 1",
                    ['gid' => $gwId, 'mid' => $brandId]
                )
                : null;

            $existingId = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
            if ($existingId > 0) {
                $scopedConfigRepo->updateScoped($existingId, $payload);
            } else {
                $scopedConfigRepo->createScoped($payload + [
                    'merchant_id' => $brandId,
                    'gateway_id'  => $gwId,
                    'mode'        => 'sandbox',
                ]);
            }
        } else {
            // Global superadmin scope: merchant_id IS NULL
            $existing = ($db instanceof \OwnPay\Core\Database)
                ? $db->fetchOne(
                    "SELECT id FROM op_gateway_configs WHERE gateway_id = :gid AND merchant_id IS NULL LIMIT 1",
                    ['gid' => $gwId]
                )
                : null;

            $existingId = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
            if ($existingId > 0 && $db instanceof \OwnPay\Core\Database) {
                $configId = $existingId;
                $sets = ['credentials_enc = :credentials_enc', 'status = :status', 'updated_at = NOW(6)'];
                $params = [
                    'id'              => $configId,
                    'credentials_enc' => $encCreds,
                    'status'          => 'active',
                ];
                if ($displaySettings !== null) {
                    $sets[] = 'settings = :settings';
                    $params['settings'] = json_encode($displaySettings) ?: '{}';
                }
                $db->execute("UPDATE op_gateway_configs SET " . implode(', ', $sets) . " WHERE id = :id", $params);
            } elseif ($db instanceof \OwnPay\Core\Database) {
                $db->execute(
                    "INSERT INTO op_gateway_configs (merchant_id, gateway_id, credentials_enc, settings, mode, status, created_at, updated_at)
                     VALUES (NULL, :gid, :credentials_enc, :settings, 'sandbox', 'active', NOW(6), NOW(6))",
                    [
                        'gid'             => $gwId,
                        'credentials_enc' => $encCreds,
                        'settings'        => $displaySettings !== null ? (json_encode($displaySettings) ?: '{}') : '{}',
                    ]
                );
            }

            // Also synchronize with legacy system settings for backward compatibility
            $settingsRepo = $this->c->get(SettingsRepository::class);
            if ($settingsRepo instanceof SettingsRepository) {
                $this->mergeUnblankedPasswordFields($settings, $passwordFields, $settingsRepo->getGroup("plugin.{$slug}"));
                $settingsRepo->bulkSet("plugin.{$slug}", $settings);
            }
        }
    }

    /**
     * Safe internal helper redirecting users back to their previous page with error context.
     *
     * @param Request $request The incoming HTTP request.
     * @param string  $error   The flash error message to register in the session.
     *
     * @return Response The HTTP redirect response.
     */
    private function redirectBack(Request $request, string $error): Response
    {
        $this->session->flashError($error);
        $referer = $request->header('Referer');
        // Prevent open redirect: only use Referer if it's a relative path
        $path = parse_url($referer, PHP_URL_PATH) ?: '/admin/plugins/install';
        if (!str_starts_with($path, '/admin/')) {
            $path = '/admin/plugins/install';
        }
        return Response::redirect($path);
    }

    /**
     * Smart redirect helper determining the original category page (gateways, addons, etc.).
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return string Redirect landing page target string.
     */
    private function redirectTarget(Request $request): string
    {
        $referer = $request->header('Referer');
        foreach (['/admin/gateways', '/admin/themes'] as $path) {
            if (str_contains($referer, $path)) {
                return $path;
            }
        }
        return '/admin/plugins';
    }

    /**
     * Parses byte size representations (e.g. '8M') to integer bytes values.
     *
     * @param string $size Format size descriptor string.
     *
     * @return int Resolved bytes representation.
     */
    private function parseSize(string $size): int
    {
        $unit = strtolower(substr($size, -1));
        $value = (int) $size;
        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Format size integer bytes values to readable representations (e.g. '8 MB').
     *
     * @param int $bytes Integer bytes count.
     *
     * @return string Formatted representation.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
