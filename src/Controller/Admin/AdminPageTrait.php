<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Support\Version;

/**
 * Trait AdminPageTrait
 *
 * Provides shared rendering capabilities for all administrative controllers.
 * Expects the incorporating controller to define properties for the DI container ($c)
 * and the administrative session service ($session).
 *
 * @package OwnPay\Controller\Admin
 */
trait AdminPageTrait
{
    /**
     * @var \OwnPay\Container
     */
    private \OwnPay\Container $c;

    /**
     * @var \OwnPay\Service\Admin\AdminSession
     */
    private \OwnPay\Service\Admin\AdminSession $session;

    /**
     * Renders an administrative page template injecting default layouts, active context,
     * flash notifications, active brand parameters, and firing template resolution event filters.
     *
     * @param string               $tpl  Relative path or namespace identifier of the Twig template.
     * @param array<string, mixed> $data Associative array of template variables.
     *
     * @return \OwnPay\Http\Response The compiled HTML response object.
     */
    private function renderAdminPage(string $tpl, array $data = []): Response
    {
        $c = $this->c;
        $session = $this->session;

        $appConfig = $c->get('config.app');
        $appName = is_array($appConfig) && isset($appConfig['name']) && is_string($appConfig['name']) ? $appConfig['name'] : 'OwnPay';
        $appVersion = is_array($appConfig) && isset($appConfig['version']) && is_string($appConfig['version']) ? $appConfig['version'] : Version::CURRENT;

        $data['app_name']    = $appName;
        $data['app_version'] = $appVersion;
        $data['csrf_token']  = \OwnPay\Security\SecurityHelpers::csrfToken();
        $cspNonce = '';
        if ($c->has(\OwnPay\Security\CspNonce::class)) {
            $cspNonceObj = $c->get(\OwnPay\Security\CspNonce::class);
            if ($cspNonceObj instanceof \OwnPay\Security\CspNonce) {
                $cspNonce = $cspNonceObj->getNonce();
            }
        }
        $data['csp_nonce'] = $cspNonce;
        $data['current_user'] = $session->currentUser();
        $data['is_superadmin']   = $session->isSuperadmin();

        $flash = $session->consumeFlash();
        $data['flash_success'] = $flash['success'] ?? null;
        $data['flash_error']   = $flash['error'] ?? null;

        if ($c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $data['brands']          = $brandCtx->getAllBrands();
                $data['active_brand']    = $brandCtx->getActiveBrand();
                $data['active_brand_id'] = $brandCtx->getActiveBrandId();
            }
        }

        // Aggregate dynamic bell notifications
        $activeBrandId = $data['active_brand_id'] ?? null;
        $notifications = $this->resolveNotificationsBell(is_scalar($activeBrandId) ? (int)$activeBrandId : null);
        $data['notifications_bell'] = $notifications;
        
        $unreadCount = 0;
        foreach ($notifications as $n) {
            if (!$n['read']) {
                $unreadCount++;
            }
        }
        $data['unread_alerts'] = $unreadCount;

        // Inject branding settings (logo, favicon, site title)
        if ($c->has(\OwnPay\Repository\SettingsRepository::class)) {
            $sr = $c->get(\OwnPay\Repository\SettingsRepository::class);
            if ($sr instanceof \OwnPay\Repository\SettingsRepository) {
                $data['settings_logo']  = $sr->get('branding', 'site_logo', '');
                $data['site_favicon']   = $sr->get('branding', 'site_favicon', '');
                $data['site_title']     = $sr->get('branding', 'admin_panel_title', $appName);
                $data['footer_text']    = $sr->get('general', 'footer_text', '');
            }
        }

        // Apply brand-scoped visual customization if we are contextually in an active brand
        $activeBrandId = $data['active_brand_id'] ?? null;
        if (!empty($activeBrandId) && (is_int($activeBrandId) || is_string($activeBrandId)) && (int)$activeBrandId > 0) {
            if ($c->has(\OwnPay\Service\Brand\BrandThemeService::class)) {
                $themeSvc = $c->get(\OwnPay\Service\Brand\BrandThemeService::class);
                if ($themeSvc instanceof \OwnPay\Service\Brand\BrandThemeService) {
                    $brandTheme = $themeSvc->getBrandTheme((int)$activeBrandId);
                    if ($brandTheme['logo'] !== '') {
                        $data['settings_logo'] = $brandTheme['logo'];
                    }
                    if ($brandTheme['favicon'] !== '') {
                        $data['site_favicon'] = $brandTheme['favicon'];
                    }
                    if ($brandTheme['name'] !== '') {
                        $data['site_title'] = $brandTheme['name'];
                    }
                }
            }
        }

        // Plugin template override system
        // Plugins can modify template name (e.g. replace admin/dashboard.twig with custom version)
        // and inject/modify template data (add widgets, custom variables, etc.)
        if ($c->has(\OwnPay\Event\EventManager::class)) {
            $events = $c->get(\OwnPay\Event\EventManager::class);
            if ($events instanceof \OwnPay\Event\EventManager) {
                $tplFilter = $events->applyFilter('admin.template.resolve', $tpl, $data);
                $tpl = is_string($tplFilter) ? $tplFilter : $tpl;
                $dataFilter = $events->applyFilter('admin.template.data', $data, $tpl);
                $data = is_array($dataFilter) ? $dataFilter : $data;
            }
        }

        // Contextual Documentation URL Mapping:
        // Maps the active administrative view or feature domain to its corresponding
        // section in the official OwnPay Documentation (https://ownpay.org/docs).
        // If an unmapped page is accessed, gracefully falls back to the documentation index.
        $activePage = is_string($data['active_page'] ?? null) ? (string) $data['active_page'] : '';
        $docMap = [
            'dashboard'            => 'dashboard',
            'transactions'         => 'payments',
            'payment-intents'      => 'payments',
            'refunds'              => 'refunds',
            'invoices'             => 'invoices',
            'payment_links'        => 'payment-links',
            'payment-links'        => 'payment-links',
            'disputes'             => 'disputes',
            'customers'            => 'customers',
            'gateways'             => 'gateways',
            'fee-rules'            => 'gateways',
            'staff'                => 'staff',
            'roles'                => 'roles',
            'brands'               => 'brands',
            'domains'              => 'domains',
            'settings'             => 'settings',
            'developer'            => 'developers',
            'api_keys'             => 'api-keys',
            'api-keys'             => 'api-keys',
            'webhooks'             => 'webhooks',
            'webhook_events'       => 'webhooks',
            'gateway_webhooks'     => 'webhooks',
            'sms_center'           => 'sms',
            'sms-center'           => 'sms',
            'sms-data'             => 'sms',
            'devices'              => 'devices',
            'push-logs'            => 'devices',
            'plugins'              => 'plugins',
            'themes'               => 'themes',
            'appearance'           => 'themes',
            'reports'              => 'reports',
            'ledger'               => 'reports',
            'activities'           => 'audit',
            'audit_log'            => 'audit',
            'my_account'           => 'account',
            'profile'              => 'account',
            'system-update'        => 'system',
        ];
        $docPath = $docMap[$activePage] ?? '';
        $data['doc_url'] = 'https://ownpay.org/docs' . ($docPath !== '' ? '/' . $docPath : '');

        $registry = $c->has('admin.renderer_registry') ? $c->get('admin.renderer_registry') : null;
        if (!$registry instanceof \OwnPay\View\Theme\ThemeRendererRegistry) {
            throw new \RuntimeException('Admin renderer registry not found');
        }

        $engineName = '';
        if ($c->has(\OwnPay\Repository\SettingsRepository::class)) {
            $settingsRepo = $c->get(\OwnPay\Repository\SettingsRepository::class);
            if ($settingsRepo instanceof \OwnPay\Repository\SettingsRepository) {
                $engineName = (string) $settingsRepo->get('appearance', 'admin_engine', '');
            }
        }

        try {
            $renderer = $registry->get($engineName === '' ? 'twig' : $engineName);
        } catch (\InvalidArgumentException) {
            if ($c->has(\OwnPay\Service\System\Logger::class)) {
                $logger = $c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->warning("Admin render engine '{$engineName}' not registered; falling back to twig.");
                }
            }
            $renderer = $registry->get('twig');
        }

        return Response::html($renderer->render($tpl, $data));
    }

    /**
     * Guards brand-scoped create operations against the global ("All Brands") view.
     *
     * A new brand-scoped record (invoice, customer, payment link, role, manual gateway, ...)
     * cannot be attached to merchant_id 0: the FK to op_merchants would fail with an unhandled
     * PDOException (HTTP 500). When no concrete brand is selected, flash a helpful message and
     * redirect instead of letting the insert blow up.
     *
     * @param int|null $mid        The resolved active brand/merchant id.
     * @param string   $redirectTo Where to send the user (typically the resource index).
     * @return \OwnPay\Http\Response|null Redirect response to return immediately, or null to proceed.
     */
    private function requireActiveBrand(?int $mid, string $redirectTo): ?Response
    {
        if ($mid === null || $mid <= 0) {
            $this->session->flashError('Select a specific brand from the brand switcher before creating this record.');
            return Response::redirect($redirectTo);
        }
        return null;
    }

    /**
     * Whether the current request operates in the global "All Brands" (platform) view.
     *
     * All-Brands view = the super-admin/platform scope (active_brand_id 0 / brand_view_mode 'global').
     * Defaults to true when no BrandContext is bound (single-tenant / install).
     */
    private function isGlobalBrandView(): bool
    {
        if (!$this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            return true;
        }
        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        return !($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) || $brandCtx->isGlobalView();
    }

    /**
     * Guards platform-level actions (plugin/theme upload, install, uninstall, ...) that are only
     * permitted from the global "All Brands" view. A brand cannot perform these - it must switch
     * to All Brands. Returns a redirect Response (+flash) when in a brand view, else null.
     *
     * @param string $redirectTo Where to send the user (typically the resource index).
     * @param string $action     Human phrase for the flash message, e.g. 'upload a plugin'.
     */
    private function requireGlobalView(string $redirectTo, string $action = 'perform this action'): ?Response
    {
        if (!$this->isGlobalBrandView()) {
            $this->session->flashError("Switch to \"All Brands\" to {$action}. Brands cannot perform platform-level actions.");
            return Response::redirect($redirectTo);
        }
        return null;
    }

    /**
     * Resolves dynamic bell notifications from transactions and disputes.
     *
     * @param int|null $merchantId
     * @return array<int, array<string, mixed>>
     */
    private function resolveNotificationsBell(?int $merchantId): array
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return [];
        }

        // 1. Query recent transactions (latest 5)
        $txParams = [];
        $txWhere = "1=1";
        if ($merchantId !== null && $merchantId > 0) {
            $txWhere = "merchant_id = :mid";
            $txParams['mid'] = $merchantId;
        }
        $txQuery = "SELECT id, status, amount, currency, sender_account, gateway_slug, created_at 
                    FROM op_transactions 
                    WHERE {$txWhere} AND status IN ('completed', 'failed')
                    ORDER BY created_at DESC LIMIT 5";
        
        $txs = [];
        try {
            $txs = $db->fetchAll($txQuery, $txParams);
        } catch (\Throwable $e) {
            // DB not ready or column missing
        }

        // 2. Query recent disputes (latest 5)
        $dspParams = [];
        $dspWhere = "1=1";
        if ($merchantId !== null && $merchantId > 0) {
            $dspWhere = "merchant_id = :mid";
            $dspParams['mid'] = $merchantId;
        }
        $dspQuery = "SELECT id, status, amount, reason, created_at 
                     FROM op_disputes 
                     WHERE {$dspWhere}
                     ORDER BY created_at DESC LIMIT 5";
        
        $dsps = [];
        try {
            $dsps = $db->fetchAll($dspQuery, $dspParams);
        } catch (\Throwable $e) {
            // DB not ready or column missing
        }

        // 3. Map & Combine
        $notifs = [];
        $userId = $this->session->userId() ?? 0;
        $readScope = 'admin_notifications_read_at_' . $userId . '_' . ($merchantId ?? 'all');
        $readAtValue = $this->session->get($readScope, 0);
        $readAt = is_numeric($readAtValue) ? (int) $readAtValue : 0;
        foreach ($txs as $tx) {
            $createdAt = is_string($tx['created_at'] ?? null) ? $tx['created_at'] : '';
            $status = is_scalar($tx['status'] ?? null) ? (string)$tx['status'] : 'pending';
            $currency = is_scalar($tx['currency'] ?? null) ? (string)$tx['currency'] : 'USD';
            $amount = is_scalar($tx['amount'] ?? null) ? (string)$tx['amount'] : '0.00';
            $sender = is_scalar($tx['sender_account'] ?? null) ? (string)$tx['sender_account'] : 'customer';
            $gw = is_scalar($tx['gateway_slug'] ?? null) ? (string)$tx['gateway_slug'] : 'api';
            $txId = is_scalar($tx['id'] ?? null) ? (string)$tx['id'] : '';
            $relativeTime = \OwnPay\Support\DateHelper::secondsSince($createdAt);

            if ($status === 'completed') {
                $notifs[] = [
                    'id' => 'tx_' . $txId,
                    'type' => 'payment',
                    'title' => 'Payment Received',
                    'message' => $currency . ' ' . $amount . ' from ' . $sender . ' via ' . $gw,
                    'time' => $this->formatRelativeTime($relativeTime),
                    'read' => strtotime($createdAt) <= $readAt,
                    'icon' => 'payment',
                    'timestamp' => strtotime($createdAt),
                ];
            } else {
                $notifs[] = [
                    'id' => 'tx_' . $txId,
                    'type' => 'payment',
                    'title' => 'Payment Failed',
                    'message' => $currency . ' ' . $amount . ' from ' . $sender . ' via ' . $gw . ' failed',
                    'time' => $this->formatRelativeTime($relativeTime),
                    'read' => strtotime($createdAt) <= $readAt,
                    'icon' => 'failed',
                    'timestamp' => strtotime($createdAt),
                ];
            }
        }

        foreach ($dsps as $dsp) {
            $createdAt = is_string($dsp['created_at'] ?? null) ? $dsp['created_at'] : '';
            $amount = is_scalar($dsp['amount'] ?? null) ? (string)$dsp['amount'] : '0.00';
            $reason =  is_scalar($dsp['reason'] ?? null) ? (string)$dsp['reason'] : 'Dispute';
            $dspId = is_scalar($dsp['id'] ?? null) ? (string)$dsp['id'] : '';
            $relativeTime = \OwnPay\Support\DateHelper::secondsSince($createdAt);

            $notifs[] = [
                'id' => 'dsp_' . $dspId,
                'type' => 'dispute',
                'title' => 'Dispute Opened',
                'message' => 'Dispute opened: ' . $reason . ' (' . $amount . ')',
                'time' => $this->formatRelativeTime($relativeTime),
                'read' => strtotime($createdAt) <= $readAt,
                'icon' => 'dispute',
                'timestamp' => strtotime($createdAt),
            ];
        }

        // Sort by timestamp DESC
        usort($notifs, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        // Slice to latest 5
        return array_slice($notifs, 0, 5);
    }

    /**
     * Helper to format relative seconds into a string.
     */
    private function formatRelativeTime(int $diff): string
    {
        if ($diff < 60) {
            return 'just now';
        }
        $minutes = (int) ($diff / 60);
        if ($minutes < 60) {
            return $minutes === 1 ? '1 min ago' : "{$minutes} mins ago";
        }
        $hours = (int) ($minutes / 60);
        if ($hours < 24) {
            return $hours === 1 ? '1 hour ago' : "{$hours} hours ago";
        }
        $days = (int) ($hours / 24);
        return $days === 1 ? 'yesterday' : "{$days} days ago";
    }
}
