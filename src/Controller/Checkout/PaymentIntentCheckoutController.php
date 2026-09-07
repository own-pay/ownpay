<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\ManualGatewayRepository;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\PaymentIntentRepository;
use OwnPay\Service\Payment\PaymentService;
use OwnPay\Service\Payment\CurrencyService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Support\DateHelper;

/**
 * Payment Intent Checkout Controller
 * 
 * Manages the checkout lifecycle, including customer presentation pages, currency 
 * conversion workflows for regional processors, integration handshake redirects, 
 * double-capture idempotency checks, and status confirmation templates.
 */
final class PaymentIntentCheckoutController
{
    use CheckoutPresentationTrait;
    use \OwnPay\View\Theme\RendersThemedResponsesTrait;

    /**
     * @var Container The service container instance.
     */
    private Container $c;

    /**
     * @var EventManager The system-wide event manager.
     */
    private EventManager $events;

    /**
     * @var TransactionRepository Repository mapping transactions.
     */
    private TransactionRepository $txnRepo;

    /**
     * @var ManualGatewayRepository Repository handling manual payment configurations.
     */
    private ManualGatewayRepository $manualGw;

    /**
     * @var GatewayConfigRepository Repository handling API payment configurations.
     */
    private GatewayConfigRepository $apiGw;

    /**
     * @var MerchantRepository Repository handling brand/merchant operations.
     */
    private MerchantRepository $merchants;

    /**
     * @var SettingsRepository Repository handling site configuration variables.
     */
    private SettingsRepository $settings;

    /**
     * @var PaymentIntentRepository Repository handling intent records.
     */
    private PaymentIntentRepository $intents;

    /**
     * @var PaymentService Service layer managing payment intents.
     */
    private PaymentService $paymentService;

    /**
     * @var CurrencyService Service handling currency rates and conversion.
     */
    private CurrencyService $currencyService;

    /**
     * @var TransactionService Service managing transaction states.
     */
    private TransactionService $transactionService;

    /**
     * @var \OwnPay\Core\Database Database driver wrapper.
     */
    private \OwnPay\Core\Database $db;

    /**
     * Constructor.
     *
     * @param Container $c The service container instance.
     * @param EventManager $events The system-wide event manager.
     * @param TransactionRepository $txnRepo Repository mapping transactions.
     * @param ManualGatewayRepository $manualGw Repository handling manual payment configurations.
     * @param GatewayConfigRepository $apiGw Repository handling API payment configurations.
     * @param MerchantRepository $merchants Repository handling brand/merchant operations.
     * @param SettingsRepository $settings Repository handling site configuration variables.
     * @param PaymentIntentRepository $intents Repository handling intent records.
     * @param PaymentService $paymentService Service layer managing payment intents.
     * @param CurrencyService $currencyService Service handling currency rates and conversion.
     * @param TransactionService $transactionService Service managing transaction states.
     * @param \OwnPay\Core\Database $db Database driver wrapper.
     */
    public function __construct(
        Container $c,
        EventManager $events,
        TransactionRepository $txnRepo,
        ManualGatewayRepository $manualGw,
        GatewayConfigRepository $apiGw,
        MerchantRepository $merchants,
        SettingsRepository $settings,
        PaymentIntentRepository $intents,
        PaymentService $paymentService,
        CurrencyService $currencyService,
        TransactionService $transactionService,
        \OwnPay\Core\Database $db
    ) {
        $this->c = $c;
        $this->events = $events;
        $this->txnRepo = $txnRepo;
        $this->manualGw = $manualGw;
        $this->apiGw = $apiGw;
        $this->merchants = $merchants;
        $this->settings = $settings;
        $this->intents = $intents;
        $this->paymentService = $paymentService;
        $this->currencyService = $currencyService;
        $this->transactionService = $transactionService;
        $this->db = $db;
    }

    /**
     * Display the checkout page for a specific payment intent.
     *
     * GET /checkout/intent/{token}
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered checkout interface or status redirect.
     * @throws \RuntimeException If the required secure signature keys are missing from environmental config.
     */
    public function show(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'expired');
        }

        $intentStatusVal = $intent['status'] ?? '';
        $intentStatus = is_string($intentStatusVal) ? $intentStatusVal : '';

        // The customer returned to the plain checkout URL (browser back, either right after
        // picking a gateway or from the external gateway's own page) rather than the gateway's
        // own return URL (which always targets /status). Nothing was confirmed at the gateway,
        // so let them pick again instead of getting stuck on a permanent "processing" screen.
        if ($intentStatus === 'processing') {
            $intentIdVal = $intent['id'] ?? 0;
            $intentId = (is_int($intentIdVal) || is_string($intentIdVal)) ? (int) $intentIdVal : 0;
            // REPO-6 (issue #461): reactivateForRetry now requires merchant_id for tenant scoping.
            $reactivateMidVal = $intent['merchant_id'] ?? 0;
            $reactivateMid = (is_int($reactivateMidVal) || is_string($reactivateMidVal)) ? (int) $reactivateMidVal : 0;
            if ($reactivateMid > 0 && $this->intents->reactivateForRetry($token, $reactivateMid)) {
                $this->txnRepo->reactivateForRetryByIntentId($intentId, $reactivateMid);
                $intent = $this->paymentService->findByToken($token);
                if (!$intent) {
                    return $this->renderStatus($token, 'expired');
                }
                $intentStatusVal = $intent['status'] ?? '';
                $intentStatus = is_string($intentStatusVal) ? $intentStatusVal : '';
            }
        }

        if ($intentStatus !== 'pending') {
            return $this->renderStatus($token, $intentStatus, $intent);
        }

        $intentAmountVal = $intent['amount'] ?? '0';
        $intentAmount = (is_string($intentAmountVal) || is_int($intentAmountVal) || is_float($intentAmountVal)) ? (string) $intentAmountVal : '0';
        $intentCurrencyVal = $intent['currency'] ?? 'BDT';
        $intentCurrency = is_string($intentCurrencyVal) ? $intentCurrencyVal : 'BDT';

        // Verify active brand status
        $midVal = $intent['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        // Verify that the intent merchant matches the resolved host domain merchant (prevent Cross-Brand Leakage)
        $domainMidVal = $req->getAttribute('merchant_id');
        if (is_int($domainMidVal) || is_string($domainMidVal)) {
            $domainMid = (int) $domainMidVal;
            if ($domainMid !== $mid) {
                return $this->renderStatus($token, 'expired');
            }
        }

        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($mid);
        }

        $merchant = $this->merchants->find($mid);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return $this->renderStatus($token, 'expired');
        }

        $platformId = ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) ? $brandCtx->getPlatformId() : 0;
        $manualGateways = $this->manualGw->listActiveForCheckout($mid, $platformId);
        $apiGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();

        // Retrieve the localized symbol representing the invoice's original currency.
        $intent['currency_symbol'] = $this->currencyService->getSymbol($intentCurrency);

        // Discover active plugins to extract metadata and theme assets for MFS/gateways.
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        if (!$loader instanceof \OwnPay\Plugin\PluginLoader) {
            throw new \RuntimeException('PluginLoader not found');
        }
        $manifests = $loader->discover();
        $categoryMap = [];
        $manifestMeta = [];
        foreach ($manifests as $m) {
            $categoryMap[$m->slug] = $m->category;
            $manifestMeta[$m->slug] = [
                'color' => $m->color,
                'icon'  => $m->icon,
            ];
        }

        $gateways = ['mfs' => [], 'bank' => [], 'global' => [], 'express' => []];

        // In-line closure helper to evaluate gateway requirements and execute cross-currency conversion.
        $checkAndConvert = function(array $gw, string $slug) use ($intentAmount, $intentCurrency): array {
            $supportedCurrencies = ['BDT'];
            if ($slug === 'bkash-api' || $slug === 'nagad' || $slug === 'rocket' || $slug === 'sslcommerz') {
                $supportedCurrencies = ['BDT'];
            } else {
                return $gw;
            }

            if ($intentCurrency !== 'BDT') {
                try {
                    $converted = $this->currencyService->convert(
                        $intentAmount,
                        $intentCurrency,
                        'BDT'
                    );
                    $gw['converted_amount'] = $converted;
                    $gw['converted_currency'] = 'BDT';
                    $gw['converted_symbol'] = $this->currencyService->getSymbol('BDT');
                } catch (\Throwable $e) {
                    $gw['conversion_error'] = true;
                }
            }
            return $gw;
        };

        foreach ($manualGateways as $gw) {
            $catVal = $gw['category'] ?? 'mfs';
            $cat = is_string($catVal) ? $catVal : 'mfs';
            if (!isset($gateways[$cat])) {
                $cat = 'mfs';
            }
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gwColorsRaw = $gw['colors'] ?? '{}';
            $gwColorsStr = is_string($gwColorsRaw) ? $gwColorsRaw : '{}';
            $colorsDecoded = json_decode($gwColorsStr, true);
            $gw['color'] = (is_array($colorsDecoded) && isset($colorsDecoded['primary']) && is_string($colorsDecoded['primary'])) ? $colorsDecoded['primary'] : '#0D9488';
            $gw = array_merge($gw, ['mode' => 'manual']);
            
            // Perform cross-currency translation for manual Mobile Financial Services (MFS) processing BDT.
            $gwSlugVal = $gw['slug'] ?? $gw['name'] ?? '';
            $gwSlug = is_string($gwSlugVal) ? $gwSlugVal : '';
            $gwSlugLower = strtolower($gwSlug);
            if (str_contains($gwSlugLower, 'bkash') || str_contains($gwSlugLower, 'nagad') || str_contains($gwSlugLower, 'rocket') || str_contains($gwSlugLower, 'upay')) {
                $gw = $checkAndConvert($gw, 'bkash-api');
            }

            $gateways[$cat][] = $gw;
        }

        $bridge = $this->c->has(\OwnPay\Gateway\GatewayBridge::class) ? $this->c->get(\OwnPay\Gateway\GatewayBridge::class) : null;
        $bridge = $bridge instanceof \OwnPay\Gateway\GatewayBridge ? $bridge : null;
        foreach ($apiGateways as $gw) {
            $gw = $this->applyGatewayDisplayOverride($gw);
            $slugVal = $gw['slug'] ?? '';
            $slug = is_string($slugVal) ? $slugVal : '';

            // Gateways that would reject this amount outright (e.g. Stripe's per-currency floor)
            // stay visible but disabled/faded with an explanatory note, instead of either being
            // hidden entirely or letting the customer hit a raw API error after selecting one -
            // see StripeGateway::minimumChargeAmount().
            if ($bridge !== null) {
                $minimum = $bridge->minimumChargeAmount($slug, $mid, $intentCurrency);
                if ($minimum !== null && is_numeric($minimum) && is_numeric($intentAmount) && bccomp($intentAmount, $minimum, 8) < 0) {
                    $gw['disabled'] = true;
                    $gw['disabled_reason'] = sprintf('Amount is below the %s %s minimum for this gateway', $minimum, strtoupper($intentCurrency));
                }
            }

            $cat = $categoryMap[$slug] ?? 'global';
            if (!isset($gateways[$cat])) {
                $cat = 'global';
            }
            $gw['logo'] = $gw['logo_path'] ?? '';
            $gw['color'] = isset($manifestMeta[$slug]) ? $manifestMeta[$slug]['color'] : '#0D9488';
            $gw = array_merge($gw, ['mode' => 'api']);
            
            // Evaluate and perform cross-currency translation for regional API gateways.
            $gw = $checkAndConvert($gw, $slug);

            $gateways[$cat][] = $gw;
        }

        $brand = $this->loadBrand($mid);
        $faqsVal = $this->settings->get('general', 'faqs', '[]');
        $faqsStr = is_string($faqsVal) ? $faqsVal : '[]';
        $faqs = json_decode($faqsStr, true);
        $faqs = is_array($faqs) ? $faqs : [];

        // Generate HMAC signature securing amount, currency, and token details against checkout tampering.
        // Security check: ensure HMAC_KEY or APP_KEY is properly configured; fallback or throw if missing.
        $hmacKeyVal = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        $hmacKey = is_string($hmacKeyVal) ? $hmacKeyVal : '';
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $checkoutHash = hash_hmac('sha256', $intentAmount . '|' . $intentCurrency . '|' . $token, $hmacKey);

        // Compile details mapping for manual gateway instructions shown in the checkout modal.
        $manualDetails = [];
        foreach ($manualGateways as $gw) {
            $slugVal = $gw['slug'] ?? $gw['name'] ?? '';
            $slug = is_string($slugVal) ? $slugVal : '';
            
            $inputFieldsRaw = $gw['input_fields'] ?? '[]';
            $inputFieldsStr = is_string($inputFieldsRaw) ? $inputFieldsRaw : '[]';
            $inputFields = json_decode($inputFieldsStr, true) ?: [];
            $inputFields = is_array($inputFields) ? $inputFields : [];
            
            $instructionsRaw = $gw['instructions'] ?? '[]';
            $instructionsStr = is_string($instructionsRaw) ? $instructionsRaw : '[]';
            $instructionsObj = json_decode($instructionsStr, true) ?: [];
            if (is_array($instructionsObj) && isset($instructionsObj['steps'])) {
                $instructions = $instructionsObj['steps'];
            } elseif (is_array($instructionsObj)) {
                $instructions = $instructionsObj;
            } else {
                $instructions = [$instructionsObj];
            }

            $paymentNumberVal = $gw['payment_number'] ?? '';
            $paymentNumber = is_string($paymentNumberVal) ? trim($paymentNumberVal) : '';
            if ($paymentNumber === '') {
                foreach ($inputFields as $field) {
                    if (is_array($field)) {
                        if (($field['type'] ?? '') === 'payment_number' || ($field['name'] ?? '') === 'payment_number') {
                            $fieldVal = $field['value'] ?? $field['default'] ?? '';
                            $paymentNumber = is_string($fieldVal) ? trim($fieldVal) : '';
                            break;
                        }
                    }
                }
            }
            if ($paymentNumber === '') {
                $instrRaw = is_string($instructionsRaw) ? $instructionsRaw : '';
                if (preg_match('/(?:01[3-9]\d{8}|01[3-9]\d{2}-\d{6})/i', $instrRaw, $matches)) {
                    $paymentNumber = $matches[0];
                }
            }

            // Calculate the equivalent BDT amount for manual payment prompt visualization.
            $gwSlugVal = $gw['slug'] ?? $gw['name'] ?? '';
            $gwSlug = is_string($gwSlugVal) ? $gwSlugVal : '';
            $gwSlugLower = strtolower($gwSlug);
            $convAmount = null;
            $convCurrency = null;
            if (str_contains($gwSlugLower, 'bkash') || str_contains($gwSlugLower, 'nagad') || str_contains($gwSlugLower, 'rocket') || str_contains($gwSlugLower, 'upay')) {
                if ($intentCurrency !== 'BDT') {
                    try {
                        $convAmount = $this->currencyService->convert($intentAmount, $intentCurrency, 'BDT');
                        $convCurrency = 'BDT';
                    } catch (\Throwable $e) {
                        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                        if ($logger instanceof \OwnPay\Service\System\Logger) {
                            $logger->error('Manual gateway BDT currency conversion failed for intent: ' . $e->getMessage());
                        }
                    }
                }
            }

            $gwColorsRaw = $gw['colors'] ?? '{}';
            $gwColorsStr = is_string($gwColorsRaw) ? $gwColorsRaw : '{}';
            $gwColors = json_decode($gwColorsStr, true);

            $logoPathVal = $gw['logo_path'] ?? null;
            $qrCodePathVal = $gw['qr_code_path'] ?? null;

            $manualDetails[$slug] = [
                'name'               => is_string($gw['name'] ?? null) ? $gw['name'] : '',
                'input_fields'       => $inputFields,
                'instructions'       => $instructions,
                'colors'             => is_array($gwColors) ? $gwColors : [],
                'payment_number'     => $paymentNumber,
                'converted_amount'   => $convAmount,
                'converted_currency' => $convCurrency,
                // Issue #58: classic checkout already includes these keys; the
                // PaymentIntent flow omitted them, so the shared checkout.js
                // renderer never found logo_path/qr_code_path and silently
                // dropped both the logo and the QR code on API-initiated
                // checkouts. Mirroring the classic payload shape fixes both.
                'logo_path'          => is_string($logoPathVal) ? $logoPathVal : null,
                'qr_code_path'       => is_string($qrCodePathVal) ? $qrCodePathVal : null,
            ];
        }

        // Calculate remaining session time bounds for checkout expiration timer.
        $timerEnabledVal = $this->settings->get('checkout', 'timer_enabled', '1');
        $timerEnabled = is_string($timerEnabledVal) ? $timerEnabledVal : '1';
        $timerSecondsVal = $this->settings->get('checkout', 'timer_seconds', '600');
        $timerSeconds = (is_string($timerSecondsVal) && is_numeric($timerSecondsVal)) ? (int) $timerSecondsVal : 600;
        $remaining = $timerSeconds;
        $createdAtVal = $intent['created_at'] ?? '';
        $createdAtStr = is_string($createdAtVal) ? $createdAtVal : '';
        if ($createdAtStr !== '') {
            $createdAt = strtotime($createdAtStr);
            if ($createdAt !== false) {
                $elapsed = time() - $createdAt;
                $remaining = max(0, $timerSeconds - $elapsed);
            }
        }

        $gatewayMeta = [];
        foreach ($manifests as $m) {
            if ($m->type === 'gateway') {
                $gatewayMeta[$m->slug] = [
                    'color'    => $m->color,
                    'type'     => $m->category === 'mfs' ? 'Send Money' : 'Pay Online',
                    'logoText' => mb_strtoupper(mb_substr($m->name, 0, 2)),
                ];
            }
        }

        $intentTokenVal = $intent['token'] ?? '';
        $intentToken = is_string($intentTokenVal) ? $intentTokenVal : '';

        $intentSymbol = $intent['currency_symbol'];

        $brandNameVal = $brand['name'] ?? null;
        $brandName = (is_string($brandNameVal) && $brandNameVal !== '') ? $brandNameVal : 'OwnPay';

        $jsConfig = [
            'txnRef'                => $intentToken,
            'checkoutBasePath'      => '/checkout/intent/' . $token,
            'originalAmount'        => $intentAmount,
            'originalCurrency'      => $intentCurrency,
            'originalCurrencySymbol'=> $intentSymbol,
            'timeoutEnabled'        => $timerEnabled === '1',
            'timeoutSeconds'        => $timerSeconds,
            'timeoutRemaining'      => $remaining,
            'gatewayMeta'           => $gatewayMeta,
            'brandName'             => $brandName,
        ];

        // Extract and decode payment invoice line items from intent metadata.
        $items = [];
        $metaRaw = $intent['metadata'] ?? '{}';
        $metaStr = is_string($metaRaw) ? $metaRaw : '{}';
        $metaObj = json_decode($metaStr, true);
        $metaObj = is_array($metaObj) ? $metaObj : [];
        if (isset($metaObj['items']) && is_array($metaObj['items'])) {
            $items = $metaObj['items'];
        }

        $intentDescVal = $intent['description'] ?? null;
        $intentDesc = is_string($intentDescVal) ? $intentDescVal : null;

        // Build the transaction view-model the checkout template expects.
        $txnMock = [
            'trx_id'            => $intentToken,
            'amount'            => $intentAmount,
            'currency'          => $intentCurrency,
            'currency_symbol'   => $intentSymbol,
            'customer_name'     => isset($metaObj['customer_name']) && is_string($metaObj['customer_name']) ? $metaObj['customer_name'] : null,
            'customer_email'    => isset($metaObj['customer_email']) && is_string($metaObj['customer_email']) ? $metaObj['customer_email'] : null,
            'customer_phone'    => isset($metaObj['customer_phone']) && is_string($metaObj['customer_phone']) ? $metaObj['customer_phone'] : null,
            'reference'         => $intentDesc ?? (isset($metaObj['reference']) && is_string($metaObj['reference']) ? $metaObj['reference'] : null),
            'merchant_id'       => $mid,
        ];

        $data = [
            'txn'             => $txnMock,
            'intent'          => $intent,
            'gateways'        => $gateways,
            'brand'           => $brand,
            'items'           => $items,
            'faqs'            => $faqs,
            'show_faq'        => (bool) ($brand['show_faq'] ?? true),
            'config'          => $jsConfig,
            'checkout_hash'   => $checkoutHash,
            'manual_gateways' => json_encode($manualDetails, JSON_HEX_TAG | JSON_HEX_AMP),
            'is_intent'       => true,
        ];

        $dataFilter = $this->events->applyFilter('checkout.intent.render', $data);
        $data = is_array($dataFilter) ? $dataFilter : $data;

        $brandId = $mid > 0 ? $mid : null;
        return $this->renderThemed('checkout/checkout.twig', $brandId, $data);
    }

    /**
     * Process checkout form submission for a payment intent.
     *
     * POST /checkout/intent/{token}/pay
     *
     * @param Request $req The incoming HTTP request.
     * @return Response Redirection, gateway form html, or JSON state payload.
     * @throws \RuntimeException If the required secure signature keys are missing from environmental config.
     */
    public function pay(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
            }
            return $this->renderStatus($token, 'expired');
        }

        $intentStatusVal = $intent['status'] ?? '';
        $intentStatus = is_string($intentStatusVal) ? $intentStatusVal : '';
        if ($intentStatus !== 'pending') {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
            }
            return $this->renderStatus($token, $intentStatus, $intent);
        }

        $intentAmountVal = $intent['amount'] ?? '0';
        $intentAmount = (is_string($intentAmountVal) || is_int($intentAmountVal) || is_float($intentAmountVal)) ? (string) $intentAmountVal : '0';
        $intentCurrencyVal = $intent['currency'] ?? 'BDT';
        $intentCurrency = is_string($intentCurrencyVal) ? $intentCurrencyVal : 'BDT';
        $intentIdVal = $intent['id'] ?? 0;
        $intentId = (is_int($intentIdVal) || is_string($intentIdVal)) ? (int) $intentIdVal : 0;

        // Verify active brand status
        $midVal = $intent['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($mid);
        }

        $merchant = $this->merchants->find($mid);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Merchant account is suspended.'], 403);
            }
            return $this->renderStatus($token, 'expired');
        }

        // Verify checkout integrity via HMAC signature validation.
        $submittedHashVal = $req->input('checkout_hash', '');
        $submittedHash = is_string($submittedHashVal) ? $submittedHashVal : '';
        // Security check: ensure HMAC key is present and validate parameters against tampering.
        $hmacKeyVal = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        $hmacKey = is_string($hmacKeyVal) ? $hmacKeyVal : '';
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $intentAmount . '|' . $intentCurrency . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
            }
            return $this->renderStatus($token, 'expired');
        }

        $gatewayVal = $req->input('gateway', '');
        $gateway = is_string($gatewayVal) ? $gatewayVal : '';
        $gatewayModeVal = $req->input('gateway_mode', 'manual');
        $gatewayMode = is_string($gatewayModeVal) ? $gatewayModeVal : 'manual';

        $amount = $intentAmount;
        $currency = $intentCurrency;

        // Resolve and apply currency translation rules if the gateway dictates localized currency.
        if ($gateway === 'bkash-api' || $gateway === 'nagad' || $gateway === 'rocket' || $gateway === 'sslcommerz' || str_contains(strtolower($gateway), 'bkash') || str_contains(strtolower($gateway), 'nagad')) {
            if ($currency !== 'BDT') {
                try {
                    $amount = $this->currencyService->convert($intentAmount, $currency, 'BDT');
                    $currency = 'BDT';
                } catch (\Throwable $e) {
                    if ($req->isAjax()) {
                        return Response::json(['success' => false, 'error' => 'Exchange rate translation error.'], 422);
                    }
                    return $this->renderStatus($token, 'failed');
                }
            }
        }

        // Initialize and write a database transaction record mapped to this intent.
        $txnData = [
            'merchant_id'       => $mid,
            'payment_intent_id' => $intentId,
            'customer_id'       => $intent['customer_id'],
            'gateway_slug'      => $gateway,
            'amount'            => $amount,
            'currency'          => $currency,
            'method'            => $gatewayMode === 'api' ? 'api' : 'manual',
            'status'            => 'pending',
            'reference'         => $intent['description'] ?? null,
            'metadata'          => !empty($intent['metadata']) ? $intent['metadata'] : '{}',
        ];

        $db = \OwnPay\Core\Database::getInstance();
        $txn = null;
        $errorResponse = null;

        try {
            $db->transaction(function () use ($db, $token, $mid, $intentId, $gateway, $gatewayMode, $amount, $currency, $txnData, &$txn, &$errorResponse, $req) {
                // 1. SELECT FOR UPDATE on the payment intent by token to block concurrent checkout pay requests.
                $lockedIntent = $db->fetchOne(
                    "SELECT * FROM op_payment_intents WHERE token = :t FOR UPDATE",
                    ['t' => $token]
                );

                if (!$lockedIntent) {
                    $errorResponse = $req->isAjax()
                        ? Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404)
                        : $this->renderStatus($token, 'expired');
                    return;
                }

                $intentStatusVal = $lockedIntent['status'] ?? '';
                $intentStatus = is_string($intentStatusVal) ? $intentStatusVal : '';
                if ($intentStatus !== 'pending') {
                    $errorResponse = $req->isAjax()
                        ? Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409)
                        : $this->renderStatus($token, $intentStatus, $lockedIntent);
                    return;
                }

                // Check expiry
                $expiresAtVal = $lockedIntent['expires_at'] ?? '';
                $expiresAt = is_scalar($expiresAtVal) ? (string) $expiresAtVal : '';
                if (DateHelper::isPast($expiresAt)) {
                    $db->execute("UPDATE op_payment_intents SET status = 'expired' WHERE id = :id", ['id' => $lockedIntent['id']]);
                    $errorResponse = $req->isAjax()
                        ? Response::json(['success' => false, 'error' => 'Transaction expired.'], 410)
                        : $this->renderStatus($token, 'expired');
                    return;
                }

                // Check for existing pending/processing transaction to reuse (with FOR UPDATE lock)
                $existingTxn = $db->fetchOne(
                    "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    ['pi' => $intentId, 'mid' => $mid]
                );

                if ($existingTxn !== null) {
                    $existingTxnIdVal = $existingTxn['id'] ?? 0;
                    $existingTxnId = (is_int($existingTxnIdVal) || is_string($existingTxnIdVal)) ? (int) $existingTxnIdVal : 0;
                    
                    $existingTxnStatusVal = $existingTxn['status'] ?? '';
                    $existingTxnStatus = is_string($existingTxnStatusVal) ? $existingTxnStatusVal : '';
                    $statusObj = \OwnPay\Enum\TransactionStatus::tryFrom($existingTxnStatus);
                    if ($statusObj !== null && !$statusObj->isTerminal()) {
                        $db->execute(
                            "UPDATE op_transactions SET gateway_slug = :gw, amount = :amt, currency = :cur, method = :method, reference = :ref, metadata = :meta WHERE id = :id",
                            [
                                'gw' => $gateway,
                                'amt' => $amount,
                                'cur' => $currency,
                                'method' => $gatewayMode === 'api' ? 'api' : 'manual',
                                'ref' => $lockedIntent['description'] ?? null,
                                'meta' => !empty($lockedIntent['metadata']) ? $lockedIntent['metadata'] : '{}',
                                'id' => $existingTxnId
                            ]
                        );
                        $txn = $this->txnRepo->forTenant($mid)->findScoped($existingTxnId);
                    }
                }

                if ($txn === null) {
                    $txn = $this->transactionService->create($mid, $txnData);
                }
            });
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n[EX] Atomic pay transaction setup failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
            if ($logger instanceof \OwnPay\Service\System\Logger) {
                $logger->error('Atomic pay transaction setup failed: ' . $e->getMessage());
            }
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
            }
            return $this->renderStatus($token, 'failed');
        }

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        if ($txn === null) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
            }
            return $this->renderStatus($token, 'failed');
        }

        $txnIdVal = $txn['id'] ?? 0;
        $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;

        if ($gatewayMode === 'manual') {
            // Assert the submitted gateway is one of this merchant's actually-configured
            // active manual gateways and enforce configured min_amount/max_amount limits
            // (issue #57). Without this guard, an arbitrary client-supplied string would
            // be stored as gateway_slug and configured amount limits would be silently
            // ignored - matching the gap that existed in CheckoutController::pay().
            $platformId = ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) ? $brandCtx->getPlatformId() : 0;
            $activeManualGateways = $this->manualGw->listActiveForCheckout($mid, $platformId);
            $selectedGw = null;
            foreach ($activeManualGateways as $gw) {
                if (($gw['slug'] ?? '') === $gateway) {
                    $selectedGw = $gw;
                    break;
                }
            }
            if ($selectedGw === null) {
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => 'Selected gateway is not active.'], 422);
                }
                return $this->renderStatus($token, 'expired', $intent);
            }

            // Enforce configured min_amount / max_amount using the post-conversion
            // amount the gateway will actually see (matches the currency the limits
            // were configured in - admin sets limits in the gateway's home currency).
            $limitError = $this->validateManualGatewayAmount($selectedGw, $amount);
            if ($limitError !== null) {
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => $limitError], 422);
                }
                return $this->renderStatus($token, 'expired', $intent);
            }

            $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'awaiting_verification', $mid);
            $details = $req->post('payment_details', []);
            if (!empty($details) && is_array($details)) {
                $updateFields = [];
                if (!empty($details['transaction_id']) && is_string($details['transaction_id'])) {
                    $updateFields['gateway_trx_id'] = trim($details['transaction_id']);
                }
                if (!empty($details['sender_number']) && is_string($details['sender_number'])) {
                    $updateFields['sender_account'] = trim($details['sender_number']);
                }
                if (!empty($updateFields)) {
                    $this->txnRepo->forTenant($mid)->updateScoped($txnId, $updateFields);
                }

                $metaRaw = $intent['metadata'] ?? '{}';
                $metaStr = is_string($metaRaw) ? $metaRaw : '{}';
                $metaObj = json_decode($metaStr, true);
                $metaObj = is_array($metaObj) ? $metaObj : [];
                $metaObj['payment_details'] = $details;
                $metaObj['submitted_at'] = DateHelper::now();
                $this->txnRepo->updateMetadata($txnId, $metaObj, $mid);
            }

            // Update intent to processing.
            $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'processing']);

            if ($req->isAjax()) {
                return Response::json([
                    'success'      => true,
                    'redirect_url' => "/checkout/intent/{$token}/status",
                ]);
            }
            return Response::redirect("/checkout/intent/{$token}/status");
        }

        // Atomically claim the transaction (status must still be 'pending') before calling out
        // to the gateway. The FOR UPDATE lock above prevents duplicate/corrupted transaction
        // rows, but is released before the (slow) external gateway call - without this claim, a
        // second concurrent request could still reach the gateway and create a second live
        // payment session for the same transaction.
        if (!$this->txnRepo->claimPendingForPay($txnId, $gateway, 'processing', $mid)) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
            }
            return $this->renderStatus($token, 'processing', $intent);
        }

        // Initialize gateway integration handshake and resolve external redirection parameters.
        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                if ($svc instanceof \OwnPay\Service\Payment\GatewayApiService) {
                    // Resolve the white-labeled payment callback URL bound to the merchant domain context.
                    $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                    if ($urlService instanceof \OwnPay\Service\Domain\DomainUrlService) {
                        $callbackUrl = $urlService->buildCallbackUrl($mid, $token, $req);
                    } else {
                        $callbackUrl = '';
                    }

                    // Enforce dynamic cross-currency conversion if gateway requirements differ from request currency.
                    $payAmountVal = $txn['amount'] ?? '0';
                    $payAmount = (is_string($payAmountVal) || is_int($payAmountVal) || is_float($payAmountVal)) ? (string) $payAmountVal : '0';
                    $payCurrencyVal = $txn['currency'] ?? 'BDT';
                    $payCurrency = is_string($payCurrencyVal) ? $payCurrencyVal : 'BDT';
                    $txnTrxIdVal = $txn['trx_id'] ?? '';
                    $txnTrxId = is_string($txnTrxIdVal) ? $txnTrxIdVal : '';
                    
                    if ($this->c->has(\OwnPay\Gateway\GatewayBridge::class)) {
                        $bridge = $this->c->get(\OwnPay\Gateway\GatewayBridge::class);
                        if ($bridge instanceof \OwnPay\Gateway\GatewayBridge) {
                            $supported = $bridge->getSupportedCurrencies($gateway);
                            if (!empty($supported) && !in_array($payCurrency, $supported, true)) {
                                // Apply currency conversion using exchange rate service and update metadata audits.
                                $targetCurrency = $supported[0];
                                if ($this->c->has(\OwnPay\Service\Payment\CurrencyService::class)) {
                                    $currSvc = $this->c->get(\OwnPay\Service\Payment\CurrencyService::class);
                                    if ($currSvc instanceof \OwnPay\Service\Payment\CurrencyService) {
                                        $converted = $currSvc->convert($payAmount, $payCurrency, $targetCurrency);
                                        if ($converted !== '0') {
                                            // PAY-18: record pre-conversion amount, the effective cross
                                            // rate (target-rate / source-rate, both relative to the system
                                            // base) and the post-conversion amount/currency for audit. The
                                            // previous code stored getRate($targetCurrency) which is the
                                            // rate of the TARGET relative to the system BASE, not relative
                                            // to the source currency - misleading for reconciliation when
                                            // neither leg is the system base (e.g. EUR->BDT when the base
                                            // is USD). The write is routed through
                                            // TransactionRepository::updateMetadata() so it merges with
                                            // concurrent metadata writers via the same array_merge contract
                                            // used everywhere else, instead of a one-off direct SQL write.
                                            $sourceRate = $currSvc->getRate($payCurrency);
                                            $targetRate = $currSvc->getRate($targetCurrency);
                                            $effectiveRate = $sourceRate !== '0'
                                                ? bcdiv($targetRate, $sourceRate, 8)
                                                : $targetRate;
                                            $this->txnRepo->updateMetadata($txnId, [
                                                'original_amount'    => $payAmount,
                                                'original_currency'  => $payCurrency,
                                                'exchange_rate'      => $effectiveRate,
                                                'converted_amount'   => $converted,
                                                'converted_currency' => $targetCurrency,
                                            ], $mid);
                                            $payAmount = $converted;
                                            $payCurrency = $targetCurrency;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $result = $svc->initiatePayment($mid, $gateway, [
                        'amount'       => $payAmount,
                        'currency'     => $payCurrency,
                        'trx_id'       => $txnTrxId,
                        'redirect_url' => $callbackUrl,
                        'cancel_url'   => $callbackUrl,
                        'existing_txn' => true,
                    ]);

                    if ($result['success'] && !empty($result['redirect_url'])) {
                        // Track external gateway initiation stage on the parent intent.
                        $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'processing']);

                        if ($req->isAjax()) {
                            return Response::json([
                                'success'      => true,
                                'redirect_url' => $result['redirect_url'],
                            ]);
                        }
                        return Response::redirect($result['redirect_url']);
                    } elseif ($result['success'] && !empty($result['form_html'])) {
                        // Track external gateway initiation stage on the parent intent.
                        $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'processing']);

                        if ($req->isAjax()) {
                            return Response::json([
                                'success'   => true,
                                'form_html' => $result['form_html'],
                            ]);
                        }
                        return Response::html($result['form_html']);
                    }

                    // Gateway did not return a usable session - release the claim so the
                    // customer can retry instead of getting stuck on "processing" with no
                    // in-flight payment.
                    $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'pending', $mid);

                    $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                    if ($req->isAjax()) {
                        return Response::json(['success' => false, 'error' => $errorMsg], 422);
                    }
                } else {
                    $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'pending', $mid);
                }
            } catch (\Throwable $e) {
                $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'pending', $mid);

                $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->error(
                        "Gateway {$gateway} initiation failed: " . $e->getMessage()
                    );
                }
                // Return standardized exception feedback for presentation to client.
                if ($req->isAjax()) {
                    return Response::json(['success' => false, 'error' => 'Gateway connection error. Please try again.'], 422);
                }
            }
        } else {
            $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'pending', $mid);

            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Payment service is not configured.'], 500);
            }
        }

        return Response::redirect("/checkout/intent/{$token}/status");
    }

    /**
     * Display status page for the payment intent, and handle regional callback captures.
     *
     * GET /checkout/intent/{token}/status
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The rendered transaction status view.
     */
    public function status(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'expired');
        }

        $midVal = $intent['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
            $brandCtx->setActiveBrandId($mid);
        }

        $intentIdVal = $intent['id'] ?? 0;
        $intentId = (is_int($intentIdVal) || is_string($intentIdVal)) ? (int) $intentIdVal : 0;
        $intentStatusVal = $intent['status'] ?? '';
        $intentStatus = is_string($intentStatusVal) ? $intentStatusVal : '';
        if ($intentStatus === 'pending') {
            return Response::redirect("/checkout/intent/{$token}");
        }

        // See CheckoutController::status() for why this triggers on any query params rather than
        // a hardcoded param name - different gateways (bKash's paymentID, etc.) redirect back
        // with different identifier params, and hardcoding one leaves every other gateway's
        // successful payment stuck in 'processing' forever.
        //
        // Stripe is excluded: its redirect param (session_id) is customer-browser-suppliable, and
        // StripeGateway::verify() only checks amount + gateway match before completing - it does
        // NOT check that the session's own metadata.trx_id matches the transaction being
        // completed, and gateway_trx_id has no uniqueness constraint. A customer could otherwise
        // pay for one checkout and replay that session_id against a second, same-amount
        // 'processing' checkout to complete it for free. Stripe must only ever complete via the
        // signed, Stripe-initiated webhook, where the session referenced is never customer-chosen.
        $reqQuery = $req->query();
        $hasCallbackParams = is_array($reqQuery) && $reqQuery !== [];
        $callbackPaymentIdVal = $req->query('paymentID') ?? $req->query('payment_id') ?? $req->query('session_id') ?? '';
        $callbackPaymentId = is_string($callbackPaymentIdVal) ? $callbackPaymentIdVal : '';
        $callbackStatusVal = $req->query('status') ?? '';
        $callbackStatus = is_string($callbackStatusVal) ? $callbackStatusVal : '';

        if ($hasCallbackParams && $intentStatus === 'processing') {
            // Retrieve corresponding active transaction context record.
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid AND status = 'processing' ORDER BY id DESC LIMIT 1",
                ['pi' => $intentId, 'mid' => $mid]
            );
            if (is_array($txn) && ($txn['gateway_slug'] ?? '') === 'stripe') {
                $txn = null;
            }

            if ($txn && $this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
                $txnIdVal = $txn['id'] ?? 0;
                $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;
                
                // Acquire database-level transaction status lock to prevent concurrent double-capture callback processing.
                $claimed = $this->db->update(
                    "UPDATE op_transactions SET status = 'callback_processing' WHERE id = :id AND merchant_id = :mid AND status = 'processing'",
                    ['id' => $txnId, 'mid' => $mid]
                );
                if ($claimed === 0) {
                    // Already being processed or completed - skip duplicate callback
                    return $this->renderStatus($token, $intentStatus, $intent);
                }

                $leaseReleased = false;
                try {
                    $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                    if ($svc instanceof \OwnPay\Service\Payment\GatewayApiService) {
                        $callbackData = array_merge($reqQuery, [
                            'paymentID' => $callbackPaymentId,
                            'trx_id'    => is_string($txn['trx_id'] ?? null) ? $txn['trx_id'] : '',
                        ]);
                        $gatewayVal = $txn['gateway_slug'] ?? '';
                        $gateway = is_string($gatewayVal) ? $gatewayVal : '';

                        if ($gateway !== '') {
                            $result = $svc->handleCallback($mid, $gateway, $callbackData);

                            if ($result['success']) {
                                $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'completed']);
                                $intent['status'] = 'completed';
                                $intentStatus = 'completed';
                                $leaseReleased = true;
                            } else {
                                // Handle explicit rejection status by updating database records to failed.
                                if (in_array($callbackStatus, ['cancel', 'failure', 'failed'], true)) {
                                    $this->txnRepo->setGatewayAndStatus($txnId, $gateway, 'failed', $mid);
                                    $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'failed']);
                                    $intent['status'] = 'failed';
                                    $intentStatus = 'failed';
                                    $leaseReleased = true;
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Log settlement capture exceptions without disrupting presentation page rendering.
                    $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                    if ($logger instanceof \OwnPay\Service\System\Logger) {
                        $logger->error(
                            "Gateway callback execution failed for intent {$token}: " . $e->getMessage()
                        );
                    }
                }

                if (!$leaseReleased) {
                    $this->db->update(
                        "UPDATE op_transactions SET status = 'processing' WHERE id = :id AND merchant_id = :mid AND status = 'callback_processing'",
                        ['id' => $txnId, 'mid' => $mid]
                    );
                }
            }
        }

        return $this->renderStatus($token, $intentStatus, $intent);
    }

    /**
     * Terminate / cancel a payment intent session manually.
     *
     * POST /checkout/intent/{token}/cancel
     *
     * @param Request $req The incoming HTTP request.
     * @return Response Redirect to external merchant landing URL or local status screen.
     * @throws \RuntimeException If the secure signature keys are missing from environmental config.
     */
    public function cancel(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'cancelled');
        }

        $submittedHashVal = $req->input('checkout_hash', '');
        $submittedHash = is_string($submittedHashVal) ? $submittedHashVal : '';
        if (empty($submittedHash)) {
            return $this->renderStatus($token, 'expired');
        }
        // Validate signature integrity protecting status state against manipulation.
        $hmacKeyVal = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        $hmacKey = is_string($hmacKeyVal) ? $hmacKeyVal : '';
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $intentAmountVal = $intent['amount'] ?? '0';
        $intentAmount = (is_string($intentAmountVal) || is_int($intentAmountVal) || is_float($intentAmountVal)) ? (string) $intentAmountVal : '0';
        $intentCurrencyVal = $intent['currency'] ?? 'BDT';
        $intentCurrency = is_string($intentCurrencyVal) ? $intentCurrencyVal : 'BDT';
        $intentIdVal = $intent['id'] ?? 0;
        $intentId = (is_int($intentIdVal) || is_string($intentIdVal)) ? (int) $intentIdVal : 0;

        $expectedHash = hash_hmac('sha256', $intentAmount . '|' . $intentCurrency . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            return $this->renderStatus($token, 'expired');
        }

        $midVal = $intent['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;

        // PAY-3: Guard the cancel against non-cancellable intent states. The
        // previous implementation unconditionally set status='cancelled'
        // whether the intent was pending, processing (gateway in-flight),
        // completed, paid, or expired. A customer holding the intent token
        // (exposed in the checkout URL /checkout/intent/{token}) could POST
        // to /cancel after a successful payment and mark the intent
        // cancelled, producing state inconsistency: txn completed but intent
        // cancelled. For `processing` intents we additionally gate on the
        // absence of an in-flight transaction - if a txn has already moved
        // past `pending` (e.g. `processing` / `awaiting_verification` /
        // `completed`) the gateway callback is in flight and we must not
        // flip the intent to cancelled.
        $currentStatusVal = $intent['status'] ?? '';
        $currentStatus = is_string($currentStatusVal) ? $currentStatusVal : '';
        $cancellable = ['pending', 'processing'];
        if (!in_array($currentStatus, $cancellable, true)) {
            // Render the actual current status so the customer sees the real
            // state (paid / expired / etc.) instead of a misleading cancelled.
            return $this->renderStatus($token, $currentStatus !== '' ? $currentStatus : 'expired', $intent);
        }

        if ($currentStatus === 'processing') {
            // Refuse to cancel if any child transaction has progressed past
            // `pending` - the gateway is in flight and the cancel would race
            // the success callback.
            $inFlightTxn = $this->db->fetchOne(
                "SELECT id FROM op_transactions
                 WHERE payment_intent_id = :pi AND merchant_id = :mid
                   AND status NOT IN ('pending','cancelled')
                 LIMIT 1",
                ['pi' => $intentId, 'mid' => $mid]
            );
            if (is_array($inFlightTxn) && isset($inFlightTxn['id'])) {
                return $this->renderStatus($token, 'processing', $intent);
            }
        }

        $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'cancelled']);

        // Terminate active child transactions mapped to the cancelled checkout intent.
        $this->db->execute(
            "UPDATE op_transactions SET status = 'cancelled' WHERE payment_intent_id = :pi AND merchant_id = :mid AND status = 'pending'",
            ['pi' => $intentId, 'mid' => $mid]
        );

        $cancelUrlVal = $intent['cancel_url'] ?: $intent['redirect_url'];
        $cancelUrl = is_string($cancelUrlVal) ? $cancelUrlVal : '';
        if ($cancelUrl !== '') {
            $separator = str_contains($cancelUrl, '?') ? '&' : '?';
            $intentUuidVal = $intent['uuid'] ?? '';
            $intentUuid = is_string($intentUuidVal) ? $intentUuidVal : '';
            $redirectTarget = $cancelUrl . $separator . 'payment_id=' . urlencode($intentUuid) . '&status=cancelled';
            return Response::redirect($redirectTarget);
        }

        return $this->renderStatus($token, 'cancelled', $intent);
    }

    /**
     * Submit verification details for manual payment gateway processing.
     *
     * POST /checkout/intent/{token}/manual-verify
     *
     * @param Request $req The incoming HTTP request.
     * @return Response Redirect status response object.
     */
    public function manualVerify(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return $this->renderStatus($token, 'expired');
        }

        // Issue #344 (PAY-16): enforce the same HMAC handshake that pay(),
        // expressPay(), and cancel() require, so that anyone with only the
        // intent token (from a shared screenshot, referrer leak, or log file)
        // cannot POST arbitrary sender_number / transaction_id values to
        // overwrite the metadata.verification block on a transaction they do
        // not own. Mirrors the check added to CheckoutController::manualVerify().
        $intentAmountVal = $intent['amount'] ?? '0';
        $intentAmount = (is_string($intentAmountVal) || is_int($intentAmountVal) || is_float($intentAmountVal)) ? (string) $intentAmountVal : '0';
        $intentCurrencyVal = $intent['currency'] ?? 'BDT';
        $intentCurrency = is_string($intentCurrencyVal) ? $intentCurrencyVal : 'BDT';
        $submittedHashVal = $req->input('checkout_hash', '');
        $submittedHash = is_string($submittedHashVal) ? $submittedHashVal : '';
        $hmacKeyVal = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        $hmacKey = is_string($hmacKeyVal) ? $hmacKeyVal : '';
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $intentAmount . '|' . $intentCurrency . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            if ($req->isAjax()) {
                return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
            }
            return $this->renderStatus($token, 'expired');
        }

        $midVal = $intent['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $intentIdVal = $intent['id'] ?? 0;
        $intentId = (is_int($intentIdVal) || is_string($intentIdVal)) ? (int) $intentIdVal : 0;
        
        $txn = $this->db->fetchOne(
            "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid AND status = 'awaiting_verification' ORDER BY id DESC LIMIT 1",
            ['pi' => $intentId, 'mid' => $mid]
        );

        if (!$txn) {
            return $this->renderStatus($token, 'expired');
        }

        $txnIdVal = $txn['id'] ?? 0;
        $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;

        $senderNumberRaw = $req->input('sender_number', '');
        $senderNumber = is_string($senderNumberRaw) ? trim($senderNumberRaw) : '';

        $verifyData = [
            'sender_number'  => $senderNumber,
            'transaction_id' => $req->input('transaction_id', ''),
            'submitted_at'   => DateHelper::now(),
        ];

        $txnMetaRaw = $txn['metadata'] ?? '{}';
        $txnMetaStr = is_string($txnMetaRaw) ? $txnMetaRaw : '{}';
        $existingMeta = json_decode($txnMetaStr, true);
        $existingMeta = is_array($existingMeta) ? $existingMeta : [];
        $existingMeta['verification'] = $verifyData;

        $this->txnRepo->setStatusWithMeta($txnId, 'pending_review', $existingMeta, $mid);

        // Persist the customer-supplied sender number into the dedicated
        // op_transactions.sender_account column (issue #56). Mirrors the
        // classic CheckoutController::manualVerify() behaviour so both flows
        // expose the value to admin UI, notification bell, and downstream
        // consumers that read sender_account directly.
        if ($senderNumber !== '') {
            $this->txnRepo->forTenant($mid)->updateScoped($txnId, [
                'sender_account' => $senderNumber,
            ]);
        }

        // Elevate checkout intent status to reflect review processing.
        $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'processing']);

        $this->events->doAction('checkout.manual_verify.submitted', $txn, $verifyData);

        return Response::redirect("/checkout/intent/{$token}/status");
    }

    /**
     * Render the payment confirmation status page.
     *
     * @param string $ref Unique transaction reference token.
     * @param string $status Target transaction status tag.
     * @param array<string, mixed>|null $intent The active intent details, if found.
     * @return Response HTML template response.
     */
    private function renderStatus(string $ref, string $status, ?array $intent = null): Response
    {
        $mid = 0;
        if ($intent) {
            $midVal = $intent['merchant_id'] ?? 0;
            $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        } else {
            $row = $this->intents->findByToken($ref);
            if ($row) {
                $midVal = $row['merchant_id'] ?? 0;
                $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
                $intent = $row;
            }
        }
        $brand = $mid > 0 ? $this->loadBrand($mid) : ['name' => 'OwnPay', 'logo' => '', 'color' => '#0D9488', 'support_email' => ''];

        $txn = null;
        if ($intent) {
            $intentIdVal = $intent['id'] ?? 0;
            $intentId = (is_int($intentIdVal) || is_string($intentIdVal)) ? (int) $intentIdVal : 0;
            // Query transaction scoped strictly to merchant ID to guarantee tenant isolation.
            $txn = $this->db->fetchOne(
                "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid ORDER BY id DESC LIMIT 1",
                ['pi' => $intentId, 'mid' => $mid]
            );

            // Reconcile and synchronize parent intent status with final transaction outcomes.
            if ($txn) {
                if (($txn['status'] ?? '') === 'completed' && ($intent['status'] ?? '') !== 'completed') {
                    $this->paymentService->markPaid($intentId, $mid);
                    $status = 'completed';
                } elseif (($txn['status'] ?? '') === 'failed' && ($intent['status'] ?? '') !== 'failed') {
                    $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'failed']);
                    $status = 'failed';
                }
            }
        }

        if ($txn) {
            $txnCurrencyVal = $txn['currency'] ?? 'BDT';
            $txnCurrency = is_string($txnCurrencyVal) ? $txnCurrencyVal : 'BDT';
            $txn['currency_symbol'] = $this->currencyService->getSymbol($txnCurrency);
        } elseif ($intent) {
            $intentTokenVal = $intent['token'] ?? '';
            $intentToken = is_string($intentTokenVal) ? $intentTokenVal : '';
            $intentAmountVal = $intent['amount'] ?? '0';
            $intentAmount = (is_string($intentAmountVal) || is_int($intentAmountVal) || is_float($intentAmountVal)) ? (string) $intentAmountVal : '0';
            $intentCurrencyVal = $intent['currency'] ?? 'BDT';
            $intentCurrency = is_string($intentCurrencyVal) ? $intentCurrencyVal : 'BDT';
            
            $txn = [
                'trx_id' => $intentToken,
                'amount' => $intentAmount,
                'currency' => $intentCurrency,
                'currency_symbol' => $this->currencyService->getSymbol($intentCurrency),
            ];
        } else {
            $txn = ['trx_id' => $ref];
        }

        $targetUrl = '';
        if ($intent) {
            $redirectUrlVal = $intent['redirect_url'] ?? '';
            $targetUrl = is_string($redirectUrlVal) ? $redirectUrlVal : '';
            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                $cancelUrlVal = $intent['cancel_url'] ?? $intent['redirect_url'] ?? '';
                $targetUrl = is_string($cancelUrlVal) ? $cancelUrlVal : '';
            }
        }

        $tplFilter = $this->events->applyFilter('checkout.status.template', 'checkout/checkout-status.twig');
        $tplName = is_string($tplFilter) ? $tplFilter : 'checkout/checkout-status.twig';
        $brandId = $mid > 0 ? $mid : null;
        return $this->renderThemed($tplName, $brandId, [
            'txn'                   => $txn,
            'status'                => $status,
            'status_label'          => $this->statusLabel($status),
            'brand'                 => $brand,
            'lang'                  => [
                'success_msg' => (!empty($brand['checkout_success_msg']) && is_string($brand['checkout_success_msg'])) ? $brand['checkout_success_msg'] : (is_string($this->settings->get('checkout', 'checkout_success_msg', '')) ? $this->settings->get('checkout', 'checkout_success_msg', '') : (is_string($this->settings->get('general', 'checkout_success_msg', '')) ? $this->settings->get('general', 'checkout_success_msg', '') : '')),
                'pending_msg' => (!empty($brand['checkout_pending_msg']) && is_string($brand['checkout_pending_msg'])) ? $brand['checkout_pending_msg'] : (is_string($this->settings->get('checkout', 'checkout_pending_msg', '')) ? $this->settings->get('checkout', 'checkout_pending_msg', '') : (is_string($this->settings->get('general', 'checkout_pending_msg', '')) ? $this->settings->get('general', 'checkout_pending_msg', '') : '')),
                'failed_msg'  => (!empty($brand['checkout_failed_msg']) && is_string($brand['checkout_failed_msg'])) ? $brand['checkout_failed_msg'] : (is_string($this->settings->get('checkout', 'checkout_failed_msg', '')) ? $this->settings->get('checkout', 'checkout_failed_msg', '') : (is_string($this->settings->get('general', 'checkout_failed_msg', '')) ? $this->settings->get('general', 'checkout_failed_msg', '') : '')),
            ],
            'merchant_redirect_url' => $targetUrl,
            'intent_payment_id'     => $intent['uuid'] ?? '',
            'intent_token'          => $ref,
            'intent_status'         => $status,
            'is_intent'             => true,
        ]);
    }

    /**
     * Handles express checkout (Apple Pay / Google Pay) for payment intents via AJAX post.
     *
     * @param \OwnPay\Http\Request $req The incoming HTTP request.
     * @return \OwnPay\Http\Response The JSON redirect outcome response.
     */
    public function expressPay(Request $req): Response
    {
        $token = (string) $req->param('token');
        $intent = $this->paymentService->findByToken($token);

        if (!$intent) {
            return Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
        }

        $intentStatusVal = $intent['status'] ?? '';
        $intentStatus = is_string($intentStatusVal) ? $intentStatusVal : '';
        if ($intentStatus !== 'pending') {
            return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
        }

        $intentAmountVal = $intent['amount'] ?? '0';
        $intentAmount = (is_string($intentAmountVal) || is_int($intentAmountVal) || is_float($intentAmountVal)) ? (string) $intentAmountVal : '0';
        $intentCurrencyVal = $intent['currency'] ?? 'BDT';
        $intentCurrency = is_string($intentCurrencyVal) ? $intentCurrencyVal : 'BDT';
        $intentIdVal = $intent['id'] ?? 0;
        $intentId = (is_int($intentIdVal) || is_string($intentIdVal)) ? (int) $intentIdVal : 0;

        // Verify active brand status
        $midVal = $intent['merchant_id'] ?? 0;
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $merchant = $this->merchants->find($mid);
        if ($merchant === null || ($merchant['status'] ?? 'active') !== 'active') {
            return Response::json(['success' => false, 'error' => 'Merchant account is suspended.'], 403);
        }

        // Verify checkout integrity via HMAC signature validation.
        $submittedHashVal = $req->input('checkout_hash', '');
        $submittedHash = is_string($submittedHashVal) ? $submittedHashVal : '';
        $hmacKeyVal = $_ENV['HMAC_KEY'] ?? $_SERVER['HMAC_KEY'] ?? getenv('HMAC_KEY') ?: ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        $hmacKey = is_string($hmacKeyVal) ? $hmacKeyVal : '';
        if ($hmacKey === '') {
            throw new \RuntimeException('HMAC_KEY or APP_KEY must be configured for checkout security.');
        }
        $expectedHash = hash_hmac('sha256', $intentAmount . '|' . $intentCurrency . '|' . $token, $hmacKey);
        if (!hash_equals($expectedHash, $submittedHash)) {
            return Response::json(['success' => false, 'error' => 'Session expired. Please refresh the page.'], 403);
        }

        $providerVal = $req->input('provider', '');
        $provider = is_string($providerVal) ? $providerVal : '';
        $gatewaySlug = '';
        if (in_array($provider, ['apple-pay', 'Apple Pay'], true)) {
            $gatewaySlug = 'apple-pay';
        } elseif (in_array($provider, ['google-pay', 'Google Pay'], true)) {
            $gatewaySlug = 'google-pay';
        } else {
            return Response::json(['success' => false, 'error' => 'Invalid express provider.'], 400);
        }

        // Assert configured and active
        $activeGateways = $this->apiGw->forTenant($mid)->listActiveForCheckout();
        $isActive = false;
        foreach ($activeGateways as $gw) {
            if (($gw['slug'] ?? '') === $gatewaySlug) {
                $isActive = true;
                break;
            }
        }
        if (!$isActive) {
            return Response::json(['success' => false, 'error' => 'Selected gateway is not active.'], 422);
        }

        // Initialize and write a database transaction record mapped to this intent.
        $txnData = [
            'merchant_id'       => $mid,
            'payment_intent_id' => $intentId,
            'customer_id'       => $intent['customer_id'],
            'gateway_slug'      => $gatewaySlug,
            'amount'            => $intentAmount,
            'currency'          => $intentCurrency,
            'method'            => 'api',
            'status'            => 'pending',
            'reference'         => $intent['description'] ?? null,
            'metadata'          => !empty($intent['metadata']) ? $intent['metadata'] : '{}',
        ];

        $db = \OwnPay\Core\Database::getInstance();
        $txn = null;
        $errorResponse = null;

        try {
            $db->transaction(function () use ($db, $token, $mid, $intentId, $gatewaySlug, $intentAmount, $intentCurrency, $txnData, &$txn, &$errorResponse) {
                // 1. SELECT FOR UPDATE on the payment intent by token to block concurrent checkout pay requests.
                $lockedIntent = $db->fetchOne(
                    "SELECT * FROM op_payment_intents WHERE token = :t FOR UPDATE",
                    ['t' => $token]
                );

                if (!$lockedIntent) {
                    $errorResponse = Response::json(['success' => false, 'error' => 'Transaction expired or not found.'], 404);
                    return;
                }

                $intentStatusVal = $lockedIntent['status'] ?? '';
                $intentStatus = is_string($intentStatusVal) ? $intentStatusVal : '';
                if ($intentStatus !== 'pending') {
                    $errorResponse = Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
                    return;
                }

                // Check expiry
                $expiresAtVal = $lockedIntent['expires_at'] ?? '';
                $expiresAt = is_scalar($expiresAtVal) ? (string) $expiresAtVal : '';
                if (DateHelper::isPast($expiresAt)) {
                    $db->execute("UPDATE op_payment_intents SET status = 'expired' WHERE id = :id", ['id' => $lockedIntent['id']]);
                    $errorResponse = Response::json(['success' => false, 'error' => 'Transaction expired.'], 410);
                    return;
                }

                // Check for existing pending/processing transaction to reuse (with FOR UPDATE lock)
                $existingTxn = $db->fetchOne(
                    "SELECT * FROM op_transactions WHERE payment_intent_id = :pi AND merchant_id = :mid ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    ['pi' => $intentId, 'mid' => $mid]
                );

                if ($existingTxn !== null) {
                    $existingTxnIdVal = $existingTxn['id'] ?? 0;
                    $existingTxnId = (is_int($existingTxnIdVal) || is_string($existingTxnIdVal)) ? (int) $existingTxnIdVal : 0;
                    
                    $existingTxnStatusVal = $existingTxn['status'] ?? '';
                    $existingTxnStatus = is_string($existingTxnStatusVal) ? $existingTxnStatusVal : '';
                    $statusObj = \OwnPay\Enum\TransactionStatus::tryFrom($existingTxnStatus);
                    if ($statusObj !== null && !$statusObj->isTerminal()) {
                        $db->execute(
                            "UPDATE op_transactions SET gateway_slug = :gw, amount = :amt, currency = :cur, method = 'api', reference = :ref, metadata = :meta WHERE id = :id",
                            [
                                'gw' => $gatewaySlug,
                                'amt' => $intentAmount,
                                'cur' => $intentCurrency,
                                'ref' => $lockedIntent['description'] ?? null,
                                'meta' => !empty($lockedIntent['metadata']) ? $lockedIntent['metadata'] : '{}',
                                'id' => $existingTxnId
                            ]
                        );
                        $txn = $this->txnRepo->forTenant($mid)->findScoped($existingTxnId);
                    }
                }

                if ($txn === null) {
                    $txn = $this->transactionService->create($mid, $txnData);
                }
            });
        } catch (\Throwable $e) {
            $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
            if ($logger instanceof \OwnPay\Service\System\Logger) {
                $logger->error('Atomic express pay transaction setup failed: ' . $e->getMessage());
            }
            return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
        }

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        if ($txn === null) {
            return Response::json(['success' => false, 'error' => 'Payment processing failed. Please try again.'], 500);
        }

        $txnIdVal = $txn['id'] ?? 0;
        $txnId = (is_int($txnIdVal) || is_string($txnIdVal)) ? (int) $txnIdVal : 0;

        // Atomically claim the transaction (status must still be 'pending') before calling out
        // to the gateway - see the identical guard in pay() for why this is needed even though
        // the transaction row itself was just created/reused under a FOR UPDATE lock above.
        if (!$this->txnRepo->claimPendingForPay($txnId, $gatewaySlug, 'processing', $mid)) {
            return Response::json(['success' => false, 'error' => 'Payment already submitted.'], 409);
        }

        if ($this->c->has(\OwnPay\Service\Payment\GatewayApiService::class)) {
            try {
                $svc = $this->c->get(\OwnPay\Service\Payment\GatewayApiService::class);
                if ($svc instanceof \OwnPay\Service\Payment\GatewayApiService) {
                    $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
                    if ($urlService instanceof \OwnPay\Service\Domain\DomainUrlService) {
                        $callbackUrl = $urlService->buildCallbackUrl($mid, $token, $req);

                        $txnAmountVal = $txn['amount'] ?? '0';
                        $txnAmount = (is_string($txnAmountVal) || is_int($txnAmountVal) || is_float($txnAmountVal)) ? (string) $txnAmountVal : '0';
                        $txnCurrencyVal = $txn['currency'] ?? 'BDT';
                        $txnCurrency = is_string($txnCurrencyVal) ? $txnCurrencyVal : 'BDT';
                        $txnTrxIdVal = $txn['trx_id'] ?? '';
                        $txnTrxId = is_string($txnTrxIdVal) ? $txnTrxIdVal : '';

                        $result = $svc->initiatePayment($mid, $gatewaySlug, [
                            'amount'       => $txnAmount,
                            'currency'     => $txnCurrency,
                            'trx_id'       => $txnTrxId,
                            'redirect_url' => $callbackUrl,
                            'cancel_url'   => $callbackUrl,
                            'existing_txn' => true,
                        ]);

                        if ($result['success'] && !empty($result['redirect_url'])) {
                            $this->intents->forTenant($mid)->updateScoped($intentId, ['status' => 'processing']);

                            return Response::json([
                                'success'      => true,
                                'redirect_url' => $result['redirect_url'],
                            ]);
                        }

                        $this->txnRepo->setGatewayAndStatus($txnId, $gatewaySlug, 'pending', $mid);

                        $errorMsg = $result['error'] ?? 'Gateway returned no redirect URL';
                        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                        if ($logger instanceof \OwnPay\Service\System\Logger) {
                            $logger->warning(
                                "Express Gateway {$gatewaySlug} failed for intent {$intentId}: {$errorMsg}"
                            );
                        }

                        return Response::json(['success' => false, 'error' => $errorMsg], 422);
                    }
                    $this->txnRepo->setGatewayAndStatus($txnId, $gatewaySlug, 'pending', $mid);
                } else {
                    $this->txnRepo->setGatewayAndStatus($txnId, $gatewaySlug, 'pending', $mid);
                }
            } catch (\Throwable $e) {
                $this->txnRepo->setGatewayAndStatus($txnId, $gatewaySlug, 'pending', $mid);

                $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->error(
                        "Express Gateway {$gatewaySlug} initiation failed: " . $e->getMessage()
                    );
                }
                return Response::json(['success' => false, 'error' => 'Gateway connection error. Please try again.'], 422);
            }
        } else {
            $this->txnRepo->setGatewayAndStatus($txnId, $gatewaySlug, 'pending', $mid);
        }

        return Response::json(['success' => false, 'error' => 'Payment service is not configured.'], 500);
    }

    /**
     * Validates a transaction amount against a manual gateway's configured limits.
     *
     * Mirrors {@see \OwnPay\Controller\Checkout\CheckoutController::validateManualGatewayAmount()}
     * so both checkout flows enforce the same min_amount/max_amount rules (issue #57).
     * Comparisons use bccomp with 2-decimal precision; float math is forbidden
     * for money per the project conventions.
     *
     * @param array<string, mixed> $gateway The manual gateway row, including min_amount/max_amount.
     * @param string $amount The transaction amount as a numeric string.
     * @return string|null Error message when out of range, null when acceptable.
     */
    private function validateManualGatewayAmount(array $gateway, string $amount): ?string
    {
        if (!is_numeric($amount)) {
            return 'Invalid amount.';
        }

        $minRaw = $gateway['min_amount'] ?? '0';
        $minAmount = (is_string($minRaw) || is_int($minRaw) || is_float($minRaw)) ? (string) $minRaw : '0';
        if (!is_numeric($minAmount)) {
            $minAmount = '0';
        }

        $maxRaw = $gateway['max_amount'] ?? '0';
        $maxAmount = (is_string($maxRaw) || is_int($maxRaw) || is_float($maxRaw)) ? (string) $maxRaw : '0';
        if (!is_numeric($maxAmount)) {
            $maxAmount = '0';
        }

        if (bccomp($minAmount, '0', 2) > 0 && bccomp($amount, $minAmount, 2) < 0) {
            return "Amount must be at least {$minAmount}.";
        }
        if (bccomp($maxAmount, '0', 2) > 0 && bccomp($amount, $maxAmount, 2) > 0) {
            return "Amount must not exceed {$maxAmount}.";
        }

        return null;
    }
}
