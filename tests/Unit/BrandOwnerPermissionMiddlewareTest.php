<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Middleware\PermissionMiddleware;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\Brand\BrandRoleSeeder;
use PHPUnit\Framework\TestCase;

final class BrandOwnerPermissionMiddlewareTest extends TestCase
{
    private Container $container;
    private PermissionMiddleware $middleware;
    private array $ownerPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();

        // AdminSession
        $session = new AdminSession();
        $this->container->instance(AdminSession::class, $session);

        // BrandContext with active_brand_id = 3 (Scale With Nurul)
        $db = $this->createMock(\OwnPay\Core\Database::class);
        $db->method('fetchOne')->willReturn(['id' => 3]);
        $brandContext = new BrandContext($db);
        $brandContext->setActiveBrandId(3);
        $this->container->instance(BrandContext::class, $brandContext);

        $this->middleware = new PermissionMiddleware($this->container);

        // Standard Brand Owner permissions (43 brand-safe permissions)
        $this->ownerPermissions = [
            'dashboard.view', 'dashboard.stats',
            'transactions.view', 'transactions.manage', 'transactions.update', 'transactions.export',
            'invoices.view', 'invoices.manage', 'invoices.create', 'invoices.update', 'invoices.delete',
            'payment_links.view', 'payment_links.manage', 'payment_links.create', 'payment_links.update', 'payment_links.delete',
            'customers.view', 'customers.manage', 'customers.create', 'customers.update',
            'gateways.view', 'gateways.manage',
            'domains.view', 'domains.manage',
            'api_keys.view', 'api_keys.manage',
            'webhooks.view', 'webhooks.manage',
            'sms.view', 'sms.manage',
            'devices.view', 'devices.manage',
            'staff.view', 'staff.manage', 'staff.create', 'staff.update', 'staff.delete',
            'settings.view', 'settings.manage', 'settings.update',
            'system.reports',
            'system.audit',
            'admin.access',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brandOwnerPermittedRoutesProvider')]
    public function testBrandOwnerCanAccessPermittedRoutes(string $path, string $method): void
    {
        $request = new Request([], [], [
            'REQUEST_URI'    => $path,
            'REQUEST_METHOD' => $method,
        ]);
        $request->setAttribute('merchant_id', 3);
        $request->setAttribute('auth_user_id', 2);
        $request->setAttribute('auth_user', [
            'id' => 2,
            'merchant_id' => 3,
            'role_id' => 2,
            'is_superadmin' => 0,
        ]);
        $request->setAttribute('user_permissions', $this->ownerPermissions);

        $executed = false;
        $next = function (Request $req) use (&$executed): Response {
            $executed = true;
            return Response::html('OK', 200);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertNotSame(403, $response->getStatusCode(), "Path {$method} {$path} should NOT return 403 for Brand Owner.");
        $this->assertTrue($executed, "Path {$method} {$path} should execute next pipeline.");
    }

    public static function brandOwnerPermittedRoutesProvider(): array
    {
        return [
            'Dashboard'               => ['/admin', 'GET'],
            'Transactions'            => ['/admin/transactions', 'GET'],
            'Payment Intents'         => ['/admin/payment-intents', 'GET'],
            'Invoices'                => ['/admin/invoices', 'GET'],
            'Payment Links'           => ['/admin/payment-links', 'GET'],
            'Disputes'                => ['/admin/disputes', 'GET'],
            'Customers'               => ['/admin/customers', 'GET'],
            'Gateways'                => ['/admin/gateways', 'GET'],
            'Own Brand Profile GET'   => ['/admin/brands/3', 'GET'],
            'Own Brand Profile Edit'  => ['/admin/brands/3/edit', 'GET'],
            'Own Brand Profile POST'  => ['/admin/brands/3/update', 'POST'],
            'Staff'                   => ['/admin/staff', 'GET'],
            'Roles'                   => ['/admin/roles', 'GET'],
            'SMS Center'              => ['/admin/sms-center', 'GET'],
            'SMS Data'                => ['/admin/sms-data', 'GET'],
            'Devices'                 => ['/admin/devices', 'GET'],
            'Device Notifications'    => ['/admin/devices/notifications', 'GET'],
            'Reports'                 => ['/admin/reports', 'GET'],
            'Reports Export'          => ['/admin/reports/export', 'GET'],
            'Ledger'                  => ['/admin/ledger', 'GET'],
            'Developer'               => ['/admin/developer', 'GET'],
            'Gateway Webhooks'        => ['/admin/gateway-webhooks', 'GET'],
            'Webhook Events'          => ['/admin/webhooks/events', 'GET'],
            'Appearance'              => ['/admin/appearance', 'GET'],
            'Brand Settings'          => ['/admin/settings', 'GET'],
            'Audit Log'               => ['/admin/activities', 'GET'],
            'Login Attempts'          => ['/admin/login-attempts', 'GET'],
            'Unlock Login Attempt'    => ['/admin/login-attempts/unlock', 'POST'],
            'My Account'              => ['/admin/my-account', 'GET'],
            'Notifications Read'      => ['/admin/notifications/mark-all-read', 'POST'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('platformRoutesProvider')]
    public function testBrandOwnerIsDeniedAccessToPlatformRoutes(string $path, string $method): void
    {
        $request = new Request([], [], [
            'REQUEST_URI'    => $path,
            'REQUEST_METHOD' => $method,
        ]);
        $request->setAttribute('merchant_id', 3);
        $request->setAttribute('auth_user_id', 2);
        $request->setAttribute('auth_user', [
            'id' => 2,
            'merchant_id' => 3,
            'role_id' => 2,
            'is_superadmin' => 0,
        ]);
        $request->setAttribute('user_permissions', $this->ownerPermissions);

        $executed = false;
        $next = function (Request $req) use (&$executed): Response {
            $executed = true;
            return Response::html('OK', 200);
        };

        $response = $this->middleware->handle($request, $next);

        // Platform routes should either return 403 or redirect back to /admin (from global-only guard)
        $statusCode = $response->getStatusCode();
        $isBlocked = $statusCode === 403 || ($statusCode >= 300 && $statusCode < 400);
        $this->assertTrue($isBlocked, "Platform route {$method} {$path} should be blocked for Brand Owner (got status {$statusCode}).");
        $this->assertFalse($executed, "Platform route {$method} {$path} must not execute the inner pipeline.");
    }

    public static function platformRoutesProvider(): array
    {
        return [
            'System Update'         => ['/admin/system-update', 'GET'],
            'Balance Verification'  => ['/admin/balance-verification', 'GET'],
            'Plugins'               => ['/admin/plugins', 'GET'],
            'Themes'                => ['/admin/themes', 'GET'],
            'All Brands Listing'    => ['/admin/brands', 'GET'],
            'Other Brand Profile'   => ['/admin/brands/1', 'GET'],
        ];
    }
}
