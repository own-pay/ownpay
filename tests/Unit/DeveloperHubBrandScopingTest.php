<?php

declare(strict_types=1);

namespace OwnPay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Container;
use OwnPay\Controller\Admin\DeveloperController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;

class DeveloperHubBrandScopingTest extends TestCase
{
    private Container $container;
    private AdminSession $session;
    private Database $db;
    private SettingsRepository $settings;
    private WebhookRepository $webhookRepo;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [
            'admin_logged_in' => true,
            'is_superadmin' => true,
            'admin_role' => 'superadmin',
            'admin_user_id' => 1,
            'admin_permissions' => ['*']
        ];

        $this->container = new Container();

        $this->session = new AdminSession();
        $this->container->instance(AdminSession::class, $this->session);

        $this->db = $this->createMock(Database::class);
        $this->container->instance(Database::class, $this->db);

        $this->settings = new SettingsRepository($this->db);
        $this->container->instance(SettingsRepository::class, $this->settings);

        $this->webhookRepo = new WebhookRepository($this->db);
        $this->container->instance(WebhookRepository::class, $this->webhookRepo);

        $rateLimitRepo = new \OwnPay\Repository\RateLimitRepository($this->db);
        $this->container->instance(\OwnPay\Repository\RateLimitRepository::class, $rateLimitRepo);

        $brandContext = new BrandContext($this->db);
        $this->container->instance(BrandContext::class, $brandContext);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRateLimitResetForbiddenWhenInSpecificBrandView(): void
    {
        $_SESSION['active_brand_id'] = 5;
        $this->db->method('fetchOne')->willReturn(['id' => 5]);

        $controller = new DeveloperController($this->container, $this->session);

        $req = new Request([], ['key' => 'test_key'], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->resetLimit($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/admin/developer', $resp->getHeaders()['Location'] ?? null);
        $flash = $this->session->consumeFlash();
        $this->assertNotNull($flash['error']);
        $this->assertStringContainsString('Rate limits can only be managed in Global View by Super Admins', $flash['error']);
    }

    public function testRateLimitSaveForbiddenWhenInSpecificBrandView(): void
    {
        $_SESSION['active_brand_id'] = 5;
        $this->db->method('fetchOne')->willReturn(['id' => 5]);

        $controller = new DeveloperController($this->container, $this->session);

        $req = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->saveSettings($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/admin/developer', $resp->getHeaders()['Location'] ?? null);
        $flash = $this->session->consumeFlash();
        $this->assertNotNull($flash['error']);
        $this->assertStringContainsString('Rate limits can only be configured in Global View by Super Admins', $flash['error']);
    }

    public function testRateLimitResetAllowedInGlobalPlatformView(): void
    {
        $_SESSION['active_brand_id'] = 0; // 0 = Global View

        $this->db->expects($this->once())
            ->method('delete')
            ->with('DELETE FROM op_rate_limits WHERE key_name = :key', ['key' => 'test_bucket']);

        $controller = new DeveloperController($this->container, $this->session);

        $req = new Request([], ['key' => 'test_bucket'], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->resetLimit($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/admin/developer', $resp->getHeaders()['Location'] ?? null);
        $flash = $this->session->consumeFlash();
        $this->assertNotNull($flash['success']);
        $this->assertStringContainsString('Rate limit bucket for \'test_bucket\' reset successfully', $flash['success']);
    }

    public function testWebhookTestFallsBackToBrandWebhookEndpoint(): void
    {
        $_SESSION['active_brand_id'] = 7;
        $this->db->method('fetchOne')->willReturnCallback(function (string $sql, array $params = []) {
            if (str_contains($sql, 'WHERE id = :id')) {
                return ['id' => 7];
            }
            if (str_contains($sql, 'FROM op_webhooks')) {
                return [
                    'id' => 12,
                    'merchant_id' => 7,
                    'url' => 'https://example.com/webhook',
                    'secret' => 'whsec_merchant7',
                    'status' => 'active'
                ];
            }
            return null;
        });

        \OwnPay\Service\System\HttpClient::$mockResponses = [
            'https://example.com/webhook' => [
                'status' => 200,
                'body' => 'OK',
                'headers' => ['Content-Type' => 'text/plain']
            ]
        ];

        $controller = new DeveloperController($this->container, $this->session);

        $req = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $resp = $controller->webhookTest($req);

        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getBody(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('http_status', $data);
        $this->assertArrayHasKey('response_code', $data);
        $this->assertSame($data['http_status'], $data['response_code']);
        $this->assertArrayHasKey('response', $data);
    }
}
