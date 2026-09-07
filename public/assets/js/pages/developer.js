/**
 * OwnPay Admin - Developer Hub Page JS
 * Handles: tab switching, copy buttons, webhook test, secret generator.
 */
(function () {
    "use strict";

    var csrf = window.OP_CSRF || "";

    // --- Tab Switching (scoped to #dev-tabs) ---------------------------------
    document.querySelectorAll("#dev-tabs .op-tab").forEach(function (t) {
        t.addEventListener("click", function () {
            document.querySelectorAll("#dev-tabs .op-tab, .op-tab-panel").forEach(function (e) {
                e.classList.remove("active");
            });
            this.classList.add("active");
            var panel = document.getElementById("tab-" + this.dataset.tab);
            if (panel) { panel.classList.add("active"); }
            history.replaceState(null, null, "#" + this.dataset.tab);
        });
    });

    // Hash-based tab activation
    if (window.location.hash) {
        var hashTab = document.querySelector('#dev-tabs .op-tab[data-tab="' + window.location.hash.slice(1) + '"]');
        if (hashTab) {
            hashTab.click();
        } else {
            var firstTab = document.querySelector("#dev-tabs .op-tab");
            if (firstTab) { firstTab.click(); }
        }
    }

    // Listen for hash changes (e.g. sidebar links clicked while on this page)
    if (!window.opDevHashRegistered) {
        window.opDevHashRegistered = true;
        window.addEventListener("hashchange", function () {
            if (window.location.hash) {
                var devTabs = document.getElementById("dev-tabs");
                if (!devTabs) { return; }
                var tab = devTabs.querySelector('.op-tab[data-tab="' + window.location.hash.slice(1) + '"]');
                if (tab) { tab.click(); }
            }
        });
    }

    // --- Reference Sub-Nav (scoped to #ref-subnav, independent of #dev-tabs) -
    document.querySelectorAll("#ref-subnav .op-subnav-item").forEach(function (item) {
        item.addEventListener("click", function () {
            document.querySelectorAll("#ref-subnav .op-subnav-item").forEach(function (e) {
                e.classList.remove("active");
            });
            document.querySelectorAll(".op-subnav-panel").forEach(function (e) {
                e.classList.remove("active");
            });
            this.classList.add("active");
            var panel = document.getElementById("subnav-" + this.dataset.subnav);
            if (panel) { panel.classList.add("active"); }
        });
    });

    // --- Copy Buttons (.op-copy-btn) -----------------------------------------
    document.querySelectorAll(".op-copy-btn").forEach(function (btn) {
        btn.addEventListener("click", function (e) {
            e.stopPropagation();
            var target = document.getElementById(this.dataset.copy);
            if (!target) { return; }
            var self = this;
            window.opCopyText(target.textContent.trim(), self, function () {
                var orig = self.textContent;
                self.textContent = "✓ Copied";
                setTimeout(function () { self.textContent = orig; }, 1500);
            });
        });
    });

    // --- Inline Copy Buttons (.op-copy-inline) --------------------------------
    document.querySelectorAll(".op-copy-inline").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var text = this.dataset.copyText ||
                (this.closest("td") && this.closest("td").querySelector("code") && this.closest("td").querySelector("code").textContent.trim());
            if (!text) { return; }
            var self = this;
            var origHTML = self.innerHTML;
            window.opCopyText(text, self, function () {
                self.innerHTML = "✓";
                setTimeout(function () { self.innerHTML = origHTML; }, 1500);
            });
        });
    });

    // --- Webhook Test ---------------------------------------------------------
    var testBtn = document.getElementById("test-webhook-btn");
    if (testBtn) {
        testBtn.addEventListener("click", function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = "Sending…";
            fetch("/admin/developer/webhook-test", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify({})
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var code = data.response_code || data.http_status || 0;
                    alert(data.success
                        ? "✓ Webhook delivered! HTTP " + code
                        : "✗ Failed: " + (data.error || "Unknown error"));
                })
                .catch(function () { alert("Network error"); })
                .finally(function () { btn.disabled = false; btn.textContent = "Send Test Event"; });
        });
    }

    // Single Webhook Endpoint Test Button in Webhooks Table
    document.addEventListener("click", function (e) {
        var btn = e.target.closest(".test-single-webhook-btn");
        if (!btn) { return; }
        var whId = btn.dataset.webhookId;
        if (!whId) { return; }
        var origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = "Testing…";

        fetch("/admin/developer/webhook-test", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ webhook_id: parseInt(whId, 10) })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var code = data.response_code || data.http_status || 0;
                alert(data.success
                    ? "✓ Webhook delivered successfully! HTTP " + code
                    : "✗ Delivery failed: " + (data.error || ("HTTP " + code)));
            })
            .catch(function () { alert("Network error"); })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = origText;
            });
    });

    // --- Webhook Secret Generator ---------------------------------------------
    window.generateSecret = function () {
        var chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
        var result = "whsec_";
        var arr = new Uint32Array(32);
        crypto.getRandomValues(arr);
        arr.forEach(function (v) { result += chars[v % chars.length]; });
        var field = document.getElementById("webhook-secret-field");
        if (field) { field.value = result; }
    };

    var genSecretBtn = document.getElementById("btn-generate-secret");
    if (genSecretBtn) {
        genSecretBtn.addEventListener("click", function () {
            window.generateSecret();
        });
    }

    // --- Generated Key Visibility & Copying -------------------
    var toggleKeyBtn = document.getElementById("toggle-key-vis");
    var generatedKeyInput = document.getElementById("generated-key-input");
    if (toggleKeyBtn && generatedKeyInput) {
        toggleKeyBtn.addEventListener("click", function () {
            if (generatedKeyInput.type === "password") {
                generatedKeyInput.type = "text";
            } else {
                generatedKeyInput.type = "password";
            }
        });
    }

    var copyKeyBtn = document.getElementById("copy-key-btn");
    if (copyKeyBtn && generatedKeyInput) {
        copyKeyBtn.addEventListener("click", function () {
            var btn = copyKeyBtn;
            var origHTML = btn.innerHTML;
            window.opCopyText(generatedKeyInput.value, btn, function () {
                btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-2px;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Copied!';
                btn.classList.add("op-btn-success");
                var fb = document.getElementById("copy-feedback");
                if (fb) {
                    fb.textContent = "✓ Key copied to clipboard";
                    fb.style.opacity = "1";
                }
                setTimeout(function () {
                    btn.innerHTML = origHTML;
                    btn.classList.remove("op-btn-success");
                    if (fb) { fb.style.opacity = "0"; }
                }, 3000);
            });
        });
    }

    // Scroll reveal new key if exists
    var newKeyReveal = document.getElementById("new-key-reveal");
    if (newKeyReveal) {
        newKeyReveal.scrollIntoView({ behavior: "smooth", block: "center" });
    }

    // Webhook form modal pre-population.
    window.prepareWebhookForm = function (webhook) {
        var idField = document.getElementById("webhook-id-field");
        var urlField = document.getElementById("webhook-url-field");
        var secretField = document.getElementById("webhook-secret-field");
        var merchantField = document.getElementById("webhook-merchant-field");
        var titleEl = document.getElementById("webhook-modal-title");
        var checkboxes = document.querySelectorAll(".webhook-event-checkbox");

        checkboxes.forEach(function (cb) { cb.checked = false; });

        if (webhook) {
            if (titleEl) { titleEl.textContent = "Edit Webhook Endpoint"; }
            if (idField) { idField.value = webhook.id; }
            if (urlField) { urlField.value = webhook.url; }
            if (secretField) { secretField.value = webhook.secret || ""; }
            if (merchantField) { merchantField.value = webhook.merchant_id || ""; }

            var events = webhook.decoded_events || [];
            checkboxes.forEach(function (cb) {
                if (events.indexOf(cb.value) !== -1) {
                    cb.checked = true;
                }
            });
        } else {
            if (titleEl) { titleEl.textContent = "Add Webhook Endpoint"; }
            if (idField) { idField.value = ""; }
            if (urlField) { urlField.value = ""; }
            if (secretField) { secretField.value = ""; }
            if (merchantField) { merchantField.value = ""; }
        }
    };

    // Delegated pre-population: any element opening the webhook modal via
    // data-open-modal="webhook-modal" may also carry a data-wh attribute with
    // the webhook's JSON. Empty/absent => Add Endpoint mode.
    document.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-open-modal=\"webhook-modal\"]");
        if (!btn) { return; }
        var raw = btn.getAttribute("data-wh");
        if (!raw) {
            window.prepareWebhookForm(null);
            return;
        }
        try {
            window.prepareWebhookForm(JSON.parse(raw));
        } catch {
            window.prepareWebhookForm(null);
        }
    });

}());
