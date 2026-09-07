<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Controller\Admin\LoginAttemptController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\System\AuditService;
use OwnPay\View\Theme\ThemeRendererRegistry;
use OwnPay\View\Theme\ThemeRendererInterface;
use PHPUnit\Framework\TestCase;

final class LoginAttemptControllerTest extends TestCase
{
    private Container $container;
    private Database $db;
    private LoginAttemptRepository $repo;
    private AuditService $audit;
    private AdminSession $session;
    private BrandContext $brandContext;

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $this->container = new Container();

        $this->db = $this->createMock(Database::class);
        $this->container->instance(Database::class, $this->db);

        $this->repo = new LoginAttemptRepository($this->db);
        $this->container->instance(LoginAttemptRepository::class, $this->repo);

        $auditLogRepo = new \OwnPay\Repository\AuditLogRepository($this->db);
        $this->session = new AdminSession();
        $this->container->instance(AdminSession::class, $this->session);

        $this->audit = new AuditService($auditLogRepo, $this->session);
        $this->container->instance(AuditService::class, $this->audit);

        $this->brandContext = new BrandContext($this->db);
        $this->container->instance(BrandContext::class, $this->brandContext);

        $this->container->instance('config.app', ['name' => 'OwnPay', 'version' => '1.0.0']);

        // Dummy theme renderer registry so renderAdminPage works
        $renderer = $this->createMock(ThemeRendererInterface::class);
        $renderer->method('render')->willReturn('<html>Login Attempts</html>');
        $registry = new ThemeRendererRegistry(['twig' => $renderer]);
        $this->container->instance('admin.renderer_registry', $registry);
    }

    public function testSuperadminCanViewGlobalLoginAttempts(): void
    {
        $_SESSION['is_superadmin'] = true;
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['brand_view_mode'] = 'global';
        $_SESSION['active_brand_id'] = 0;

        $this->db->method('fetchColumn')->willReturn(0);
        $this->db->method('fetchAll')->willReturn([]);

        $controller = new LoginAttemptController($this->container, $this->session, $this->repo, $this->audit);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/login-attempts']);

        $res = $controller->index($req);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testBrandOwnerViewsScopedLoginAttempts(): void
    {
        $_SESSION['is_superadmin'] = false;
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['auth_merchant_id'] = 3;
        $_SESSION['active_brand_id'] = 3;
        $_SESSION['auth_email'] = 'owner@brand.test';
        $this->brandContext->setActiveBrandId(3);

        $this->db->method('fetchColumn')->willReturn(0);
        $this->db->method('fetchAll')->willReturnCallback(function (string $sql, array $params = []) {
            if (str_contains($sql, 'op_merchant_users')) {
                return [
                    ['email' => 'owner@brand.test'],
                    ['email' => 'staff1@brand.test'],
                ];
            }
            return [];
        });

        $controller = new LoginAttemptController($this->container, $this->session, $this->repo, $this->audit);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/login-attempts']);

        $res = $controller->index($req);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testBrandOwnerCannotUnlockEmailOfAnotherBrand(): void
    {
        $_SESSION['is_superadmin'] = false;
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['auth_merchant_id'] = 3;
        $_SESSION['active_brand_id'] = 3;
        $_SESSION['auth_email'] = 'owner@brand.test';
        $this->brandContext->setActiveBrandId(3);

        // Database says other@otherbrand.test does NOT belong to brand 3
        $this->db->expects($this->once())
            ->method('fetchColumn')
            ->with(
                "SELECT COUNT(*) FROM op_merchant_users WHERE email = :email AND merchant_id = :mid",
                ['email' => 'other@otherbrand.test', 'mid' => 3]
            )
            ->willReturn(0);

        // repo db should NOT delete anything
        $this->db->expects($this->never())->method('delete');

        $controller = new LoginAttemptController($this->container, $this->session, $this->repo, $this->audit);
        $req = new Request([], ['email' => 'other@otherbrand.test'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login-attempts/unlock']);

        $res = $controller->unlock($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/login-attempts', $res->getHeaders()['Location']);
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function testBrandOwnerCanUnlockStaffEmailOfOwnBrand(): void
    {
        $_SESSION['is_superadmin'] = false;
        $_SESSION['auth_user_id'] = 2;
        $_SESSION['auth_merchant_id'] = 3;
        $_SESSION['active_brand_id'] = 3;
        $_SESSION['auth_email'] = 'owner@brand.test';
        $this->brandContext->setActiveBrandId(3);

        // Database says staff1@brand.test belongs to brand 3
        $this->db->expects($this->once())
            ->method('fetchColumn')
            ->with(
                "SELECT COUNT(*) FROM op_merchant_users WHERE email = :email AND merchant_id = :mid",
                ['email' => 'staff1@brand.test', 'mid' => 3]
            )
            ->willReturn(1);

        $this->db->expects($this->once())
            ->method('delete')
            ->with(
                "DELETE FROM op_login_attempts WHERE email = :email AND success = 0",
                ['email' => 'staff1@brand.test']
            )
            ->willReturn(2);

        $controller = new LoginAttemptController($this->container, $this->session, $this->repo, $this->audit);
        $req = new Request([], ['email' => 'staff1@brand.test'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login-attempts/unlock']);

        $res = $controller->unlock($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/login-attempts', $res->getHeaders()['Location']);
        $this->assertNotEmpty($_SESSION['flash_success']);
    }
}
