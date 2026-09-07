<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/amazon-pay/AmazonPayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/easypaisa/EasypaisaGateway.php';

use OwnPay\Modules\Gateways\AmazonPay\AmazonPayGateway;
use OwnPay\Modules\Gateways\Easypaisa\EasypaisaGateway;

final class GatewayWebhookBypassTest extends TestCase
{
    public function testAmazonPayRejectsArbitraryWrongSignature(): void
    {
        $gw = new AmazonPayGateway();
        $credentials = ['store_id' => 'amzn_store_456', 'mode' => 'test'];
        $rawBody = '{"checkoutSessionId":"session_100","status":"PAID"}';

        $valid = hash_hmac('sha256', $rawBody, 'amzn_store_456');
        $this->assertTrue($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => $valid], $credentials));

        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => 'deadbeef'], $credentials));
        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => str_repeat('a', 64)], $credentials));
        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => 'invalid_signature'], $credentials));
        $this->assertFalse($gw->verifyWebhook($rawBody, [], $credentials));

        $this->assertFalse($gw->verifyWebhook('{"checkoutSessionId":"session_100","status":"PAID","amount":"99999"}', ['x-amz-pay-signature' => $valid], $credentials));
    }

    public function testAmazonPayFailsClosedWithoutSecret(): void
    {
        $gw = new AmazonPayGateway();
        $rawBody = '{"x":"y"}';
        $sig = hash_hmac('sha256', $rawBody, '');
        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => $sig], []));
    }

    public function testEasypaisaRejectsForgedWebhook(): void
    {
        $gw = new EasypaisaGateway();
        $credentials = ['hash_key' => 'secret_hash_key_123', 'mode' => 'live'];

        $params = ['orderRefNum' => 'OP-1', 'responseCode' => '0000', 'amount' => '100.00'];
        ksort($params);
        $sigString = '';
        foreach ($params as $k => $v) {
            $sigString .= $k . '=' . $v . '&';
        }
        $sigString = rtrim($sigString, '&');
        $validHash = hash_hmac('sha256', $sigString, 'secret_hash_key_123');

        $validBody = json_encode(array_merge($params, ['secureHash' => $validHash]));
        $this->assertIsString($validBody);
        $this->assertTrue($gw->verifyWebhook($validBody, [], $credentials));

        $forgedBody = json_encode(array_merge($params, ['secureHash' => 'forged_hash']));
        $this->assertIsString($forgedBody);
        $this->assertFalse($gw->verifyWebhook($forgedBody, [], $credentials));

        $noHashBody = json_encode($params);
        $this->assertIsString($noHashBody);
        $this->assertFalse($gw->verifyWebhook($noHashBody, [], $credentials));
    }

    public function testEasypaisaLiveModeRequiresConfiguredKey(): void
    {
        $oldEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'testing';
        try {
            $gw = new EasypaisaGateway();
            $this->assertFalse($gw->verifyWebhook('{"secureHash":"x"}', [], ['mode' => 'live']));
            $this->assertTrue($gw->verifyWebhook('{"secureHash":"x"}', [], ['mode' => 'sandbox']));
        } finally {
            if ($oldEnv !== null) {
                $_ENV['APP_ENV'] = $oldEnv;
            } else {
                unset($_ENV['APP_ENV']);
            }
        }
    }
}
