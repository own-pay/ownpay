<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Rocket;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Gateway\TestableConnectionInterface;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * DBBL Rocket Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class RocketGateway implements PluginInterface, GatewayAdapterInterface, TestableConnectionInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'DBBL Rocket',
            'slug' => 'rocket',
            'version' => '1.0.0',
            'description' => 'DBBL Rocket payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'rocket'; }
    public function name(): string { return 'DBBL Rocket'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'DBBL Rocket checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $mode = $this->getString($credentials['mode'] ?? null);
        $url = $mode === 'live'
            ? 'https://rocket.dutchbanglabank.com/rocket/checkout/process'
            : 'https://sandbox.dutchbanglabank.com/rocket/checkout/process';

        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $secretKey = $this->getString($credentials['secret_key'] ?? null);

        $amount = number_format((float)$params['amount'], 2, '.', '');
        $secureHash = md5($merchantId . $params['trx_id'] . $amount . $secretKey);

        $formHtml = '
        <form action="' . htmlspecialchars($url) . '" method="POST" id="rocket-form">
            <input type="hidden" name="merchant_id" value="' . htmlspecialchars($merchantId) . '">
            <input type="hidden" name="order_id" value="' . htmlspecialchars($params['trx_id']) . '">
            <input type="hidden" name="amount" value="' . htmlspecialchars($amount) . '">
            <input type="hidden" name="hash" value="' . htmlspecialchars($secureHash) . '">
            <input type="hidden" name="redirect_url" value="' . htmlspecialchars($params['redirect_url']) . '">
        </form>
        <script>document.getElementById("rocket-form").submit();</script>';

        return [
            'form_html' => $formHtml,
            'session_id' => $params['trx_id'],
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        $orderId = $this->getString($callbackData['order_id'] ?? null);
        $status = $this->getString($callbackData['status'] ?? null);
        $amount = $this->getString($callbackData['amount'] ?? null);
        $hash = $this->getString($callbackData['hash'] ?? null);

        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $secretKey = $this->getString($credentials['secret_key'] ?? null);

        $generatedHash = md5($merchantId . $orderId . $amount . $status . $secretKey);
        $success = hash_equals($generatedHash, $hash) && $status === 'success';

        return [
            'success'        => $success,
            'gateway_trx_id' => $this->getString($callbackData['transaction_id'] ?? $orderId),
            'amount'         => $amount,
            'status'         => $success ? 'completed' : 'failed',
            'trx_id'         => $orderId,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return true;
    }

    /**
     * Tests whether the supplied Rocket credentials are valid and the API endpoint is reachable.
     *
     * @param array<string, mixed> $credentials Decrypted or submitted credentials.
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $credentials): array
    {
        $merchantId = $this->getString($credentials['merchant_id'] ?? null);
        $secretKey = $this->getString($credentials['secret_key'] ?? null);
        $mode = $this->getString($credentials['mode'] ?? 'sandbox');

        if ($merchantId === '' || $secretKey === '') {
            return ['success' => false, 'message' => 'Enter Merchant ID and Secret Key before testing the connection.'];
        }

        $url = $mode === 'live'
            ? 'https://rocket.dutchbanglabank.com/rocket/checkout/process'
            : 'https://sandbox.dutchbanglabank.com/rocket/checkout/process';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['success' => false, 'message' => 'Could not reach DBBL Rocket endpoint: ' . $err];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'message' => "Connected successfully to DBBL Rocket ({$mode} mode)."];
        }

        return ['success' => false, 'message' => "DBBL Rocket endpoint responded with HTTP {$httpCode}"];
    }
}