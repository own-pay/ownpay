<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Kernel;
use OwnPay\Http\Request;
use OwnPay\Security\CspNonce;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Modules\Gateways\Rocket\RocketGateway;
use OwnPay\Controller\Admin\PluginController;

require_once dirname(__DIR__, 2) . '/modules/gateways/rocket/RocketGateway.php';

class CheckoutGatewayTabsAndTestConnectionTest extends TestCase
{
    private \Twig\Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = new Kernel();
        $ref = new \ReflectionMethod($kernel, 'boot');
        $ref->setAccessible(true);
        $ref->invoke($kernel);

        $cRef = new \ReflectionProperty($kernel, 'container');
        $cRef->setAccessible(true);
        $container = $cRef->getValue($kernel);

        $this->twig = $container->get(\Twig\Environment::class);
    }

    public function testCheckoutGatewayGridVisibilityWhenOnlyMfsPresent(): void
    {
        $gateways = [
            'global' => [],
            'mfs' => [
                ['slug' => 'bkash-api', 'name' => 'bKash API', 'mode' => 'api', 'color' => '#E2136E']
            ],
            'bank' => [],
            'express' => []
        ];
        $brand = [
            'name' => 'CZPay',
            'color' => '#0D9488',
            'logo' => '/assets/img/logo.svg',
            'favicon' => '',
            'support_email' => 'support@czbd.app',
            'show_faq' => false,
        ];
        $html = $this->twig->render('checkout/checkout.twig', [
            'brand' => $brand,
            'gateways' => $gateways,
            'txn' => ['trx_id' => 'test1234', 'amount' => '100.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'ref' => 'test1234'],
            'items' => [],
            'faqs' => [],
            'show_faq' => false,
            'config' => ['timeoutEnabled' => false],
            'checkout_hash' => 'hash',
            'manual_gateways' => '{}',
            'csrf_token' => 'csrf',
            'csp_nonce' => 'nonce123'
        ]);

        // When only MFS is present, tabs should not be rendered
        $this->assertStringNotContainsString('class="ck-tabs ck-fi"', $html);

        // t-cards should be hidden
        $this->assertMatchesRegularExpression('/<div id="t-cards" class="ck-tc ck-hidden"/', $html);

        // t-mfs should NOT have ck-hidden
        $this->assertMatchesRegularExpression('/<div id="t-mfs" class="ck-tc ">/', $html);

        // bkash-api should be present in HTML
        $this->assertStringContainsString('data-slug="bkash-api"', $html);
    }

    public function testCheckoutGatewayGridVisibilityWhenCardsAndMfsPresent(): void
    {
        $gateways = [
            'global' => [
                ['slug' => 'stripe', 'name' => 'Stripe', 'mode' => 'api', 'color' => '#635BFF']
            ],
            'mfs' => [
                ['slug' => 'bkash-api', 'name' => 'bKash API', 'mode' => 'api', 'color' => '#E2136E']
            ],
            'bank' => [],
            'express' => []
        ];
        $brand = [
            'name' => 'CZPay',
            'color' => '#0D9488',
            'logo' => '/assets/img/logo.svg',
            'favicon' => '',
            'support_email' => 'support@czbd.app',
            'show_faq' => false,
        ];
        $html = $this->twig->render('checkout/checkout.twig', [
            'brand' => $brand,
            'gateways' => $gateways,
            'txn' => ['trx_id' => 'test1234', 'amount' => '100.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'ref' => 'test1234'],
            'items' => [],
            'faqs' => [],
            'show_faq' => false,
            'config' => ['timeoutEnabled' => false],
            'checkout_hash' => 'hash',
            'manual_gateways' => '{}',
            'csrf_token' => 'csrf',
            'csp_nonce' => 'nonce123'
        ]);

        // When multiple categories are present, tabs should be rendered
        $this->assertStringContainsString('class="ck-tabs ck-fi"', $html);

        // Default tab is cards: t-cards is visible, t-mfs is hidden
        $this->assertMatchesRegularExpression('/<div id="t-cards" class="ck-tc ">/', $html);
        $this->assertMatchesRegularExpression('/<div id="t-mfs" class="ck-tc ck-hidden"/', $html);
    }

    public function testCheckoutGatewayGridVisibilityWhenNoGatewaysConfigured(): void
    {
        $gateways = [
            'global' => [],
            'mfs' => [],
            'bank' => [],
            'express' => []
        ];
        $brand = [
            'name' => 'CZPay',
            'color' => '#0D9488',
            'logo' => '/assets/img/logo.svg',
            'favicon' => '',
            'support_email' => 'support@czbd.app',
            'show_faq' => false,
        ];
        $html = $this->twig->render('checkout/checkout.twig', [
            'brand' => $brand,
            'gateways' => $gateways,
            'txn' => ['trx_id' => 'test1234', 'amount' => '100.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'ref' => 'test1234'],
            'items' => [],
            'faqs' => [],
            'show_faq' => false,
            'config' => ['timeoutEnabled' => false],
            'checkout_hash' => 'hash',
            'manual_gateways' => '{}',
            'csrf_token' => 'csrf',
            'csp_nonce' => 'nonce123'
        ]);

        $this->assertStringContainsString('No payment methods currently available.', $html);
    }

    public function testRocketGatewayImplementsTestableConnection(): void
    {
        $rocket = new RocketGateway();
        $this->assertInstanceOf(TestableConnectionInterface::class, $rocket);

        $emptyRes = $rocket->testConnection([]);
        $this->assertFalse($emptyRes['success']);
        $this->assertStringContainsString('Enter Merchant ID and Secret Key', $emptyRes['message']);
    }

    public function testPluginControllerTestConnectionCsrfAndFallback(): void
    {
        \OwnPay\Service\System\HttpClient::$mockResponses = [
            'https://tokenized.sandbox.bka.sh/v2/tokenized/checkout/token/grant' => [
                'status' => 200,
                'body' => json_encode(['id_token' => 'mock_token', 'token_type' => 'Bearer']),
                'headers' => ['Content-Type' => 'application/json']
            ]
        ];

        $kernel = new Kernel();
        $ref = new \ReflectionMethod($kernel, 'boot');
        $ref->setAccessible(true);
        $ref->invoke($kernel);

        $cRef = new \ReflectionProperty($kernel, 'container');
        $cRef->setAccessible(true);
        $container = $cRef->getValue($kernel);

        $controller = $container->get(PluginController::class);

        $req = new Request([], [
            'settings' => [
                'app_key' => 'test_app_key',
                'app_secret' => 'test_app_secret',
                'username' => 'test_user',
                'password' => 'test_pass',
                'sandbox' => '1',
            ]
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/plugins/bkash-api/test-connection']);
        $req->setRouteParams(['slug' => 'bkash-api']);
        $req->setAttribute('_new_csrf_token', 'test_csrf_token_12345');

        $resp = $controller->testConnection($req);
        $data = json_decode($resp->getBody(), true);

        $this->assertIsArray($data);
        $this->assertSame('test_csrf_token_12345', $data['_csrf_token'] ?? null);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
    }
}

