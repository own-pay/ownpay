<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware handling Role-Based Access Control (RBAC) checks.
 *
 * Verifies that the authenticated user possesses the permission token associated
 * with the target route path and HTTP request method. Bypasses checks for superadmins.
 */
final class PermissionMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new PermissionMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles authorization verification for incoming HTTP requests to admin routes.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        $userId = $request->getAttribute('auth_user_id') ?? ($_SESSION['auth_user_id'] ?? null);

        if ($userId === null) {
            if ($request->expectsJson()) {
                return Response::json(['success' => false, 'message' => 'Authentication required'], 401);
            }
            // Use dynamic login slug instead of hardcoded /login.
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect("/{$loginSlug}");
        }

        // Active brand context check to block global-only routes in brand view
        $brandCtx = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->resolveFromRequest($request);
            $activeBrandId = $brandCtx->getActiveBrandId();

            // Security: cross-brand privilege-escalation guard (audit finding
            // BRD-2 / issue #238). Public checkout controllers call
            // BrandContext::setActiveBrandId($mid) to set the rendering brand
            // context, which writes $mid into $_SESSION['active_brand_id']. If
            // a non-superadmin brand-A staff member visits a public checkout
            // URL for brand B (on the master domain, where DomainMiddleware
            // does not set the merchant_id request attribute), their session's
            // active_brand_id is silently overwritten to brand B - and every
            // subsequent /admin/* request is then scoped to brand B, leaking
            // cross-brand data.
            //
            // BrandController::switchBrand already enforces that non-superadmins
            // can only switch to their own auth_merchant_id. This middleware
            // enforces the same invariant on every /admin/* request: if the
            // active_brand_id does not match the caller's auth_merchant_id (and
            // the caller is not a superadmin), reset the session back to the
            // caller's home brand, log the event to the audit trail, and flash
            // a security warning.
            //
            // This check runs BEFORE the global-only-route block below so that
            // a poisoned session is reset on the first /admin/* request rather
            // than after a misleading "only accessible in Global View" redirect.
            if ($activeBrandId !== null && $activeBrandId > 0 && session_status() === PHP_SESSION_ACTIVE) {
                $authMerchantIdVal = $_SESSION['auth_merchant_id'] ?? null;
                $authMerchantId = is_scalar($authMerchantIdVal) ? (int) $authMerchantIdVal : 0;
                $isSuperadmin = !empty($_SESSION['is_superadmin']);
                if (!$isSuperadmin && $authMerchantId > 0 && $activeBrandId !== $authMerchantId) {
                    $userId = is_scalar($userId) ? (int) $userId : null;
                    $audit = $this->container->get(\OwnPay\Service\System\AuditService::class);
                    if ($audit instanceof \OwnPay\Service\System\AuditService) {
                        $audit->log(
                            'security.brand_context_reset',
                            'session',
                            $userId,
                            ['active_brand_id' => $activeBrandId],
                            ['active_brand_id' => $authMerchantId]
                        );
                    }
                    // Reset to the caller's legitimate home brand.
                    $brandCtx->setActiveBrandId($authMerchantId);
                    $session = $this->container->get(\OwnPay\Service\Admin\AdminSession::class);
                    if ($session instanceof \OwnPay\Service\Admin\AdminSession) {
                        $session->flashError('Brand context was reset to your home brand due to a security check.');
                    }
                    if ($request->expectsJson()) {
                        return Response::json(['success' => false, 'message' => 'Brand context was reset to your home brand due to a security check.'], 403);
                    }
                    return Response::redirect('/admin');
                }
            }

            if ($activeBrandId !== null && $activeBrandId > 0) {
                $path = $request->path();
                $globalOnlyPrefixes = [
                    '/admin/brands',
                    '/admin/themes',
                    '/admin/system-update',
                    '/admin/balance-verification',
                    '/admin/audit-integrity',
                ];

                if ($path !== '/admin/brands/switch') {
                    foreach ($globalOnlyPrefixes as $prefix) {
                        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                            // Allow a brand staff/owner to view and edit their OWN brand profile in brand view
                            if ($prefix === '/admin/brands' && (
                                $path === "/admin/brands/{$activeBrandId}" ||
                                $path === "/admin/brands/{$activeBrandId}/edit" ||
                                $path === "/admin/brands/{$activeBrandId}/update"
                            )) {
                                continue;
                            }

                            $session = $this->container->get(\OwnPay\Service\Admin\AdminSession::class);
                            if ($session instanceof \OwnPay\Service\Admin\AdminSession) {
                                $session->flashError('This page is only accessible in Global View.');
                            }
                            if ($request->expectsJson()) {
                                return Response::json(['success' => false, 'message' => 'This page is only accessible in Global View.'], 403);
                            }
                            return Response::redirect('/admin');
                        }
                    }
                }
            }
        }

        // Lazy load user from DB if not passed in attributes
        $user = $request->getAttribute('auth_user');
        if ($user === null) {
            $db = $this->container->get(\OwnPay\Core\Database::class);
            if (!$db instanceof \OwnPay\Core\Database) {
                throw new \RuntimeException("Database not found in container");
            }
            $userVal = $db->fetchOne("SELECT * FROM op_merchant_users WHERE id = :id AND status = 'active'", ['id' => $userId]);
            if (!is_array($userVal)) {
                // Wipe the full session on invalid user
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $loginSlug = $this->resolveLoginSlug();
                return Response::redirect("/{$loginSlug}");
            }
            $user = $userVal;
            $request->setAttribute('auth_user', $user);
        } else {
            if (!is_array($user)) {
                $user = [];
            }
        }

        // Superadmin bypass - MySQL returns "1" (string), not true (bool)
        if (!empty($user['is_superadmin'])) {
            return $next($request);
        }

        // Get required permission from route path
        $requiredPermission = $this->resolvePermission($request->path(), $request->method());

        if ($requiredPermission === null) {
            // No permission required for this route
            return $next($request);
        }

        $userPermissionsVal = $request->getAttribute('user_permissions');
        if (!is_array($userPermissionsVal)) {
            $roleId = 0;
            if (isset($user['role_id']) && (is_int($user['role_id']) || is_string($user['role_id']) || is_numeric($user['role_id']))) {
                $roleId = (int) $user['role_id'];
            }
            $userPermissions = $this->loadPermissions($roleId);
            $request->setAttribute('user_permissions', $userPermissions);
        } else {
            $userPermissions = [];
            foreach ($userPermissionsVal as $permVal) {
                if (is_string($permVal)) {
                    $userPermissions[] = $permVal;
                }
            }
        }

        $allowed = in_array($requiredPermission, $userPermissions, true);

        if (!$allowed) {
            if ($request->expectsJson()) {
                return Response::json(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            return Response::html('<h1>403 Forbidden</h1><p>You do not have permission to access this resource.</p>', 403);
        }

        return $next($request);
    }

    /**
     * Load permission slugs for a given role ID.
     *
     * @param int $roleId The role identifier.
     * @return string[] Array of permission slugs.
     */
    private function loadPermissions(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }

        try {
            $db = $this->container->get(\OwnPay\Core\Database::class);
            if (!$db instanceof \OwnPay\Core\Database) {
                return [];
            }
            $rows = $db->fetchAll(
                "SELECT p.slug FROM op_role_permissions rp
                 JOIN op_permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = :rid",
                ['rid' => $roleId]
            );
            $slugs = [];
            foreach ($rows as $row) {
                if (isset($row['slug']) && is_string($row['slug'])) {
                    $slugs[] = $row['slug'];
                }
            }
            return $slugs;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Map route path to required permission slug.
     *
     * All POST operations consistently use '.manage' suffix.
     *
     * @param string $path The request path to evaluate.
     * @param string $method The HTTP request method.
     * @return string|null The required permission token slug or null if public.
     */
    private function resolvePermission(string $path, string $method): ?string
    {
        $map = [
            // Dashboard routes mapped to permission map
            '/admin/transactions'         => 'transactions.view',
            '/admin/payment-intents'      => 'transactions.view',
            '/admin/refunds'              => 'transactions.view',
            '/admin/invoices'             => 'invoices.view',
            '/admin/payment-links'        => 'payment_links.view',
            '/admin/disputes'             => 'transactions.view',
            '/admin/customers'            => 'customers.view',
            '/admin/gateways'             => 'gateways.view',
            '/admin/staff'                => 'staff.view',
            '/admin/brands'               => 'brands.view',
            '/admin/settings'             => 'settings.view',
            '/admin/fee-rules'            => 'settings.view',
            '/admin/api-keys'             => 'api_keys.view',
            '/admin/sms-center'           => 'sms.view',
            '/admin/sms-data'             => 'sms.view',
            '/admin/devices'              => 'devices.view',
            '/admin/plugins'              => 'plugins.view',
            '/admin/themes'               => 'plugins.view',
            '/admin/appearance'           => 'settings.view',
            '/admin/system-update'        => 'system.update',
            '/admin/activities'           => 'system.audit',
            '/admin/audit-integrity'      => 'system.audit',
            '/admin/login-attempts'       => 'system.audit',
            '/admin/reports'              => 'system.reports',
            '/admin/domains'              => 'domains.view',
            '/admin/balance-verification' => 'system.balance',
            '/admin/roles'                => 'staff.view',
            '/admin/developer'            => 'api_keys.view',
            '/admin/gateway-webhooks'     => 'api_keys.view',
            '/admin/webhooks'             => 'webhooks.view',
            '/admin/notifications'        => 'admin.access',
            '/admin/ledger'               => 'system.reports',
            '/admin/currencies'           => 'settings.view',
            '/admin/my-account'           => 'admin.access',
            '/admin/contributors'         => 'dashboard.view',
            '/admin'                      => 'dashboard.view',
        ];

        // Brand switching is read-only, not brand management
        if ($path === '/admin/brands/switch') {
            return 'brands.view';
        }

        // SEC-5: Treat every non-safe HTTP method as state-changing. The
        // previous implementation only upgraded .view -> .manage for POST,
        // which left PUT/PATCH/DELETE authorized at .view level - a user
        // granted only read-only access to a resource could DELETE or PUT it.
        // RFC 9110 §9.2.1 defines GET/HEAD/OPTIONS as "safe" (no state
        // change); every other method is treated as state-changing here.
        $stateChanging = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        // A brand owner/staff viewing or updating their own brand profile uses settings.view / settings.manage
        $activeBrandId = null;
        if ($this->container->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
            $activeBrandId = $brandCtx instanceof \OwnPay\Service\Brand\BrandContext ? $brandCtx->getActiveBrandId() : null;
        }
        if ($activeBrandId !== null && $activeBrandId > 0) {
            if ($path === "/admin/brands/{$activeBrandId}" || $path === "/admin/brands/{$activeBrandId}/edit" || $path === "/admin/brands/{$activeBrandId}/update") {
                return $stateChanging ? 'settings.manage' : 'settings.view';
            }
        }

        // Check exact match first
        if (isset($map[$path])) {
            $perm = $map[$path];
            if ($stateChanging) {
                $perm = str_replace('.view', '.manage', $perm);
            }
            return $perm;
        }

        // Check prefix match - state-changing methods use .manage
        foreach ($map as $prefix => $perm) {
            // Do not match the base '/admin' as a dynamic prefix.
            // This prevents unmapped paths under /admin/ from falling back to dashboard.view/manage
            if ($prefix === '/admin') {
                continue;
            }
            if (str_starts_with($path, $prefix . '/')) {
                if ($stateChanging) {
                    return str_replace('.view', '.manage', $perm);
                }
                return $perm;
            }
        }

        // Default-deny for unmapped /admin/* routes
        if (str_starts_with($path, '/admin')) {
            return 'system.unmapped';
        }

        return null;
    }

    /**
     * Resolve the dynamic login slug from settings database.
     *
     * Avoids hardcoded '/login' which fails when slug is customized.
     *
     * @return string The resolved login URI component.
     */
    private function resolveLoginSlug(): string
    {
        $cacheFile = dirname(__DIR__, 2) . '/storage/cache/login_slug.cache';
        if (file_exists($cacheFile)) {
            $slug = @file_get_contents($cacheFile);
            if ($slug !== false && $slug !== '') {
                $slug = trim($slug);
                if (preg_match('/^[a-z0-9\-]+$/', $slug)) {
                    return $slug;
                }
            }
        }

        try {
            $settings = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
            if ($settings instanceof \OwnPay\Repository\SettingsRepository) {
                $slug = $settings->get('landing', 'admin_login_slug', 'login');
                if (is_string($slug)) {
                    return $slug;
                }
            }
            return 'login';
        } catch (\Throwable) {
            return 'login';
        }
    }
}
