<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\ManualGatewayRepository;
use OwnPay\Service\System\FilesystemService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\System\AuditService;

/**
 * Class GatewayController
 *
 * Administrative portal controller handling discovery, configuration, toggling, and CRUD management
 * of payment gateways, encompassing both dynamic API integrations and custom manual (offline) options.
 *
 * @package OwnPay\Controller\Admin
 */
final class GatewayController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var ManualGatewayRepository The manual gateway repository.
     */
    private ManualGatewayRepository $manualGateways;

    /**
     * @var GatewayConfigRepository The API gateway configuration repository.
     */
    private GatewayConfigRepository $apiConfigs;

    /**
     * @var FilesystemService The filesystem operations service.
     */
    private FilesystemService $fs;

    /**
     * @var AuditService The application audit logging service.
     */
    private AuditService $audit;

    /**
     * GatewayController constructor.
     *
     * @param Container               $c              The dependency injection container.
     * @param AdminSession            $session        The administrative session service.
     * @param ManualGatewayRepository $manualGateways The manual gateway repository.
     * @param GatewayConfigRepository $apiConfigs     The API gateway configuration repository.
     * @param FilesystemService       $fs             The filesystem operations service.
     * @param AuditService            $audit          The application audit logging service.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        ManualGatewayRepository $manualGateways,
        GatewayConfigRepository $apiConfigs,
        FilesystemService $fs,
        AuditService $audit
    ) {
        $this->c              = $c;
        $this->session        = $session;
        $this->manualGateways = $manualGateways;
        $this->apiConfigs     = $apiConfigs;
        $this->fs             = $fs;
        $this->audit          = $audit;
    }

    /**
     * Displays the dashboard interface listing both API and manual gateways for the active brand.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The gateways dashboard page response.
     */
    public function index(Request $request): Response
    {
        $brand = $this->brandContext($request);
        $platformId = $brand->getPlatformId();
        $isGlobal = $this->isGlobalBrandView();
        $brandId = $brand->getActiveBrandId();
        $apiContextId = ($brandId !== null && $brandId > 0) ? $brandId : 0;

        // Manual gateways (model A: platform templates + per-brand account overrides).
        $manualGateways = [];
        if ($isGlobal) {
            foreach ($this->manualGateways->forTenant($platformId)->listAll() as $row) {
                $row = $this->decodeManualRow($row);
                $row['is_own'] = true;       // platform-owned; All Brands may edit/disable/delete
                $row['is_template'] = true;
                $row['configured'] = true;
                $manualGateways[] = $row;
            }
        } else {
            // Brand view: the brand's own account rows, plus platform templates it has not configured.
            $ownSlugs = [];
            foreach ($this->manualGateways->forTenant($apiContextId)->listAll() as $row) {
                $slugVal = $row['slug'] ?? '';
                if (is_string($slugVal) && $slugVal !== '') {
                    $ownSlugs[$slugVal] = true;
                }
                $row = $this->decodeManualRow($row);
                $row['is_own'] = true;       // brand owns its account row → edit/toggle/delete
                $row['is_template'] = false;
                $row['configured'] = true;
                $manualGateways[] = $row;
            }
            foreach ($this->manualGateways->forTenant($platformId)->listActive() as $row) {
                $slugVal = $row['slug'] ?? '';
                $slug = is_string($slugVal) ? $slugVal : '';
                if ($slug === '' || isset($ownSlugs[$slug])) {
                    continue; // brand already has its own account for this template
                }
                $row = $this->decodeManualRow($row);
                $row['is_own'] = false;      // platform default → offer "Configure account"
                $row['is_template'] = true;
                $row['configured'] = false;
                $manualGateways[] = $row;
            }
        }

        // Build API gateway list: filesystem discovery + op_plugins status
        /** @var \OwnPay\Repository\PluginRepository $pluginRepo */
        $pluginRepo = $this->c->get(\OwnPay\Repository\PluginRepository::class);

        /** @var \OwnPay\Plugin\PluginLoader $loader */
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        $discovered = $loader->discover();

        // Index installed plugins by slug for O(1) lookup
        $installedPlugins = [];
        $paginated = $pluginRepo->paginate(1, 200);
        foreach ($paginated['items'] as $p) {
            if (isset($p['slug']) && is_string($p['slug'])) {
                $installedPlugins[$p['slug']] = $p;
            }
        }

        // Also get gateway configs (credentials) per slug
        $configuredGateways = [];
        foreach ($this->apiConfigs->forTenant($apiContextId)->listActive() as $g) {
            if (isset($g['slug']) && is_string($g['slug'])) {
                $configuredGateways[$g['slug']] = $g;
            }
        }

        /** @var \OwnPay\Plugin\PluginManager $pm */
        $pm = $this->c->get(\OwnPay\Plugin\PluginManager::class);

        $apiGateways = [];
        foreach ($discovered as $manifest) {
            if ($manifest->type !== 'gateway') {
                continue;
            }

            $installed = $installedPlugins[$manifest->slug] ?? null;
            $configured = $configuredGateways[$manifest->slug] ?? null;

            $isBrandActive = $pluginRepo->isPluginActiveForBrand($manifest->slug, $apiContextId);

            // Determine display status
            if ($installed === null) {
                $status = 'uninstalled';
            } elseif ($isBrandActive) {
                $status = $configured ? 'active' : 'installed'; // active+configured vs just activated
            } else {
                $status = in_array($installed['status'], ['trashed', 'error'], true) ? $installed['status'] : 'inactive';
            }

            $logoPath = $pm->resolveIconPath($manifest->slug, $installed ?? ['type' => 'gateway'], $manifest);

            $apiGateways[] = [
                'slug'        => $manifest->slug,
                'name'        => $manifest->name,
                'description' => $manifest->description,
                'version'     => $manifest->version,
                'status'      => $status,
                'logo'        => $logoPath ?? ($configured['logo_path'] ?? ''),
                'mode'        => $configured['mode'] ?? '',
            ];
        }

        return $this->renderAdminPage('admin/gateways/index.twig', [
            'api_gateways'    => $apiGateways,
            'manual_gateways' => $manualGateways,
            'active_page'     => 'gateways',
            'is_global_view'  => $isGlobal,
        ]);
    }

    /**
     * Renders creation form or handles dynamic submission for creating a new manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The gateway creation page or redirect response.
     */
    public function createManual(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/gateways', 'add a manual gateway type')) {
            return $guard;
        }

        if ($request->method() === 'GET') {
            return $this->renderAdminPage('admin/gateways/create-manual.twig', ['old' => [], 'active_page' => 'gateways']);
        }

        $ownerId = $this->resolveOwnerId($request); // All Brands view → platform-owner id
        $postData = $request->post();
        $data = is_array($postData) ? $postData : [];
        $errors = $this->validateManualGateway($data);

        if (!empty($errors)) {
            return $this->renderAdminPage('admin/gateways/create-manual.twig', ['old' => $data, 'errors' => $errors, 'active_page' => 'gateways']);
        }

        $record = $this->buildGatewayRecord($data);
        $slugVal = $data['slug'] ?? '';
        $record['slug']   = InputSanitizer::slug(is_string($slugVal) ? $slugVal : '');
        $record['status'] = 'active';
        $this->applyUploads($record);

        $gid = $this->manualGateways->forTenant($ownerId)->createScoped($record);
        $this->audit->log('gateway.created', 'manual_gateway', (int) $gid, null, ['name' => $record['name']]);
        $this->session->flashSuccess('Gateway template created!');
        return Response::redirect('/admin/gateways');
    }

    /**
     * Brand-facing: configure THIS brand's own account for a platform-defined manual gateway template.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The configure-account page or redirect response.
     */
    public function configureAccount(Request $request): Response
    {
        $brand = $this->brandContext($request);
        $brandId = $brand->getActiveBrandId();
        if ($this->isGlobalBrandView() || $brandId === null || $brandId <= 0) {
            $this->session->flashError('Switch to a specific brand to configure its payment account.');
            return Response::redirect('/admin/gateways');
        }

        $platformId = $brand->getPlatformId();
        $slug = InputSanitizer::slug((string) $request->param('slug'));
        $template = $this->manualGateways->forTenant($platformId)->findBySlug($slug);
        if ($template === null) {
            $this->session->flashError('Gateway template not found.');
            return Response::redirect('/admin/gateways');
        }

        $existing = $this->manualGateways->forTenant($brandId)->findBySlug($slug);

        if ($request->method() === 'GET') {
            return $this->renderAdminPage('admin/gateways/configure-account.twig', [
                'template'                   => $this->decodeManualRow($template),
                'account'                    => $existing !== null ? $this->decodeManualRow($existing) : null,
                'instructions_text'          => $existing !== null ? $this->instructionsToText($existing['instructions'] ?? null) : '',
                'template_instructions_text' => $this->instructionsToText($template['instructions'] ?? null),
                'active_page'                => 'gateways',
            ]);
        }

        $postData = $request->post();
        $data = is_array($postData) ? $postData : [];
        $instructionsVal = $data['instructions'] ?? '';
        $instructions = $this->buildInstructionsJson(is_string($instructionsVal) ? $instructionsVal : '');
        $paymentNumberVal = $data['payment_number'] ?? '';
        $paymentNumber = InputSanitizer::string(is_string($paymentNumberVal) ? $paymentNumberVal : '');

        // Account-level fields only (brand-editable); the TYPE is owned by the platform template.
        $account = [
            'instructions'   => $instructions,
            'payment_number' => $paymentNumber !== '' ? $paymentNumber : null,
        ];
        $this->applyUploads($account);

        if ($existing !== null) {
            $idVal = $existing['id'] ?? 0;
            $id = (is_int($idVal) || is_string($idVal)) ? (int) $idVal : 0;
            $this->manualGateways->forTenant($brandId)->updateScoped($id, $account);
            $this->audit->log('gateway.account_configured', 'manual_gateway', $id, null, ['slug' => $slug]);
        } else {
            // First save: copy the template's TYPE definition, then apply the brand's account values.
            $record = $this->accountFromTemplate($template, $account);
            $gid = $this->manualGateways->forTenant($brandId)->createScoped($record);
            $this->audit->log('gateway.account_configured', 'manual_gateway', (int) $gid, null, ['slug' => $slug]);
        }

        $this->session->flashSuccess('Payment account saved for this brand!');
        return Response::redirect('/admin/gateways');
    }

    /**
     * Renders edit form or handles updating an existing manual gateway under the active brand context.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The edit page or redirect response.
     */
    public function editManual(Request $request): Response
    {
        $merchantId = $this->resolveOwnerId($request);
        $id = (int) $request->param('id');
        $gateway = $this->manualGateways->forTenant($merchantId)->findScoped($id);

        if ($gateway === null) {
            $this->session->flashError('Gateway not found');
            return Response::redirect('/admin/gateways');
        }

        $gateway = $this->decodeGatewayForEdit($gateway);

        if ($request->method() === 'GET') {
            return $this->renderAdminPage('admin/gateways/edit-manual.twig', ['gateway' => $gateway, 'active_page' => 'gateways']);
        }

        $postData = $request->post();
        $data = is_array($postData) ? $postData : [];
        $errors = $this->validateManualGateway($data, true);
        if (!empty($errors)) {
            return $this->renderAdminPage('admin/gateways/edit-manual.twig', ['gateway' => array_merge($gateway, $data), 'errors' => $errors, 'active_page' => 'gateways']);
        }

        $update = $this->buildGatewayRecord($data);
        $this->applyUploads($update);
        $this->manualGateways->forTenant($merchantId)->updateScoped($id, $update);
        $this->audit->log('gateway.updated', 'manual_gateway', $id, null, ['name' => $update['name'] ?? '']);

        $this->session->flashSuccess('Gateway updated!');
        return Response::redirect('/admin/gateways');
    }

    /**
     * Toggles the active/inactive state of a specific manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function toggleStatus(Request $request): Response
    {
        $merchantId = $this->resolveOwnerId($request);
        $id = (int) $request->param('id');
        $gateway = $this->manualGateways->forTenant($merchantId)->findScoped($id);
        if ($gateway !== null) {
            $newStatus = $gateway['status'] === 'active' ? 'inactive' : 'active';
            $this->manualGateways->forTenant($merchantId)->updateScoped($id, ['status' => $newStatus]);
            $this->audit->log('gateway.status_toggled', 'manual_gateway', $id, ['status' => $gateway['status']], ['status' => $newStatus]);
            $this->session->flashSuccess("Gateway {$newStatus}!");
        }
        return Response::redirect('/admin/gateways');
    }

    /**
     * Alias route handler for storing a new manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The API/page response.
     */
    public function storeManual(Request $request): Response { return $this->createManual($request); }

    /**
     * Alias route handler for updating a manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The API/page response.
     */
    public function updateManual(Request $request): Response { return $this->editManual($request); }

    /**
     * Alias route handler for toggling manual gateway status.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The API/page response.
     */
    public function toggle(Request $request): Response { return $this->toggleStatus($request); }

    /**
     * Permanently deletes a manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function delete(Request $request): Response
    {
        $merchantId = $this->resolveOwnerId($request);
        $gid = (int) $request->param('id');

        // MG-2: unlink orphaned logo/QR files before the row is removed. Files whose URL is
        // still referenced by another manual gateway of the same slug under a different
        // merchant (e.g. a platform template that a brand overrode) are left in place so
        // the surviving reference does not dangle.
        $this->purgeManualGatewayAssets($gid, $merchantId);

        $this->manualGateways->forTenant($merchantId)->deleteScoped($gid);
        $this->audit->log('gateway.deleted', 'manual_gateway', $gid);
        $this->session->flashSuccess('Gateway deleted.');
        return Response::redirect('/admin/gateways');
    }

    /**
     * Removes the uploaded logo/QR files attached to a manual gateway immediately before
     * the row itself is deleted.
     *
     * Files whose path is still referenced by another manual gateway of the same slug
     * (typically the platform template that a brand overrode) are kept on disk so the
     * surviving reference does not dangle. Asset URLs are strictly validated to live
     * within the public uploads subtree to prevent path traversal.
     *
     * @param int $gid        Manual gateway id about to be deleted.
     * @param int $merchantId Owner merchant id of the row being deleted.
     *
     * @return void
     */
    private function purgeManualGatewayAssets(int $gid, int $merchantId): void
    {
        $row = $this->manualGateways->forTenant($merchantId)->findScoped($gid);
        if ($row === null) {
            return;
        }

        $slugVal = $row['slug'] ?? '';
        $slug = is_string($slugVal) ? $slugVal : '';

        foreach (['logo_path', 'qr_code_path'] as $field) {
            $urlVal = $row[$field] ?? '';
            $url = is_string($urlVal) ? $urlVal : '';
            if ($url === '') {
                continue;
            }

            // Leave the file on disk if any other manual gateway (any merchant) of the
            // same slug still references the same asset URL.
            if ($slug !== '' && $this->manualGatewayAssetReferencedElsewhere($field, $url, $slug, $gid)) {
                continue;
            }

            $this->unlinkPublicUpload($url);
        }
    }

    /**
     * Determines whether a given asset URL is referenced by any manual gateway other than
     * the one currently being deleted. Used to decide whether the underlying file can be
     * safely unlinked (no other row depends on it).
     *
     * @param string $field      Column name ('logo_path' or 'qr_code_path').
     * @param string $url        Stored asset URL to look up.
     * @param string $slug       Manual gateway slug to scope the cross-reference check.
     * @param int    $excludeId  Manual gateway id being deleted (excluded from the count).
     *
     * @return bool True if at least one other manual gateway references the same asset URL.
     */
    private function manualGatewayAssetReferencedElsewhere(string $field, string $url, string $slug, int $excludeId): bool
    {
        $count = $this->manualGateways
            ->forAllTenants()
            ->countScoped(
                "slug = :slug AND {$field} = :url AND id <> :id",
                ['slug' => $slug, 'url' => $url, 'id' => $excludeId]
            );

        return $count > 0;
    }

    /**
     * Deletes a public-upload asset file by its web-root-relative URL.
     *
     * The URL must be of the form `/assets/uploads/<subdir>/<year>/<month>/<hash>.<ext>`
     * as produced by {@see FilesystemService::storePublicUpload()}. The path is resolved
     * strictly against the public uploads directory; any attempt to escape it (via `..`
     * segments, null bytes, absolute paths, or alternate prefixes) is silently ignored
     * rather than throwing - asset cleanup is best-effort and must never break the
     * surrounding delete flow.
     *
     * @param string $url Web-root-relative asset URL.
     *
     * @return void
     */
    private function unlinkPublicUpload(string $url): void
    {
        $prefix = '/assets/uploads/';
        if (!str_starts_with($url, $prefix)) {
            return;
        }

        $relative = substr($url, strlen($prefix));
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return;
        }

        $publicDir = dirname(__DIR__, 3) . '/public/assets/uploads';
        $baseReal = realpath($publicDir);
        if ($baseReal === false) {
            return;
        }

        $absolute = realpath($publicDir . '/' . $relative);
        if ($absolute === false || !is_file($absolute)) {
            return;
        }

        if (!str_starts_with($absolute, $baseReal . DIRECTORY_SEPARATOR)) {
            return;
        }

        @unlink($absolute);
    }

    // ---- Extracted Helpers
    /**
     * Resolves and returns the request-scoped BrandContext (active brand resolved from the request).
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return \OwnPay\Service\Brand\BrandContext The resolved brand context.
     */
    private function brandContext(Request $request): \OwnPay\Service\Brand\BrandContext
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service not found.');
        }
        $brand->resolveFromRequest($request);
        return $brand;
    }

    /**
     * Resolves the merchant id that OWNS manual-gateway writes for the current view: All Brands view
     * → the platform-owner id (manage TYPE templates); a specific brand → its own id (manage that
     * brand's account rows). Mirrors BrandContext::getWriteMerchantId().
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return int The owner merchant ID.
     */
    private function resolveOwnerId(Request $request): int
    {
        return $this->brandContext($request)->getWriteMerchantId();
    }

    /**
     * Decodes a raw manual-gateway DB row's JSON columns (input_fields, colors) for display.
     *
     * @param array<string, mixed> $row Raw database row.
     *
     * @return array<string, mixed> Row with input_fields/colors decoded to arrays.
     */
    private function decodeManualRow(array $row): array
    {
        $inputFieldsVal = $row['input_fields'] ?? '[]';
        $row['input_fields'] = json_decode(is_string($inputFieldsVal) ? $inputFieldsVal : '[]', true);
        $colorsVal = $row['colors'] ?? '{}';
        $row['colors'] = json_decode(is_string($colorsVal) ? $colorsVal : '{}', true);
        return $row;
    }

    /**
     * Converts a stored instructions JSON value ({"steps":[...]}, a list, or a plain string) into
     * newline-separated plain text suitable for prefilling a textarea.
     *
     * @param mixed $instructions Raw instructions column value.
     *
     * @return string Human-editable instructions text.
     */
    private function instructionsToText(mixed $instructions): string
    {
        if (!is_string($instructions) || $instructions === '') {
            return '';
        }
        $decoded = json_decode($instructions, true);
        if (is_array($decoded)) {
            $steps = (isset($decoded['steps']) && is_array($decoded['steps'])) ? $decoded['steps'] : $decoded;
            $lines = [];
            foreach ($steps as $step) {
                if (is_scalar($step)) {
                    $lines[] = (string) $step;
                }
            }
            return implode("\n", $lines);
        }
        return is_scalar($decoded) ? (string) $decoded : $instructions;
    }

    /**
     * Decodes a raw manual-gateway DB row's JSON-encoded columns into their render-ready /
     * human-editable forms for the platform-level edit form. Without this, the edit form would
     * pre-fill the "Payment Instructions" textarea with the raw stored JSON, and saving it would
     * re-wrap that JSON as a single instruction line, compounding on every save.
     *
     * @param array<string, mixed> $gateway Raw manual gateway DB row.
     *
     * @return array<string, mixed> Gateway row with `instructions`/`input_fields`/`colors` decoded.
     */
    private function decodeGatewayForEdit(array $gateway): array
    {
        $inputFieldsVal = $gateway['input_fields'] ?? '[]';
        $gateway['input_fields'] = json_decode(is_string($inputFieldsVal) ? $inputFieldsVal : '[]', true);
        $colorsVal = $gateway['colors'] ?? '{}';
        $gateway['colors'] = json_decode(is_string($colorsVal) ? $colorsVal : '{}', true);
        $gateway['instructions'] = $this->instructionsToText($gateway['instructions'] ?? null);
        return $gateway;
    }

    /**
     * Builds a brand-owned account record from a platform template, applying the brand's account
     * overrides (instructions + any uploaded logo/QR). The TYPE definition is copied verbatim from the
     * template so the brand row is self-contained; the slug is kept identical so checkout resolution
     * (ManualGatewayRepository::listActiveForCheckout) lets the brand row WIN over the template.
     *
     * @param array<string, mixed> $template The platform template row (raw DB values).
     * @param array<string, mixed> $account  Brand account overrides (instructions, logo_path, qr_code_path).
     *
     * @return array<string, mixed> The new brand-owned manual gateway record.
     */
    private function accountFromTemplate(array $template, array $account): array
    {
        $logoVal = $account['logo_path'] ?? ($template['logo_path'] ?? null);
        $qrVal = $account['qr_code_path'] ?? ($template['qr_code_path'] ?? null);
        $instructionsVal = $account['instructions'] ?? ($template['instructions'] ?? null);
        $paymentNumberVal = $account['payment_number'] ?? ($template['payment_number'] ?? null);

        return [
            'slug'               => is_string($template['slug'] ?? null) ? $template['slug'] : '',
            'name'               => is_string($template['name'] ?? null) ? $template['name'] : '',
            'logo_path'          => is_string($logoVal) ? $logoVal : null,
            'qr_code_path'       => is_string($qrVal) ? $qrVal : null,
            'payment_number'     => is_string($paymentNumberVal) ? $paymentNumberVal : null,
            'colors'             => is_string($template['colors'] ?? null) ? $template['colors'] : null,
            'input_fields'       => is_string($template['input_fields'] ?? null) ? $template['input_fields'] : null,
            'instructions'       => is_string($instructionsVal) ? $instructionsVal : null,
            'sms_verification'   => is_scalar($template['sms_verification'] ?? null) ? (int) $template['sms_verification'] : 0,
            'sms_sender_pattern' => is_string($template['sms_sender_pattern'] ?? null) ? $template['sms_sender_pattern'] : null,
            'sms_regex_template' => is_string($template['sms_regex_template'] ?? null) ? $template['sms_regex_template'] : null,
            'currency'           => is_string($template['currency'] ?? null) ? $template['currency'] : 'BDT',
            'min_amount'         => is_scalar($template['min_amount'] ?? null) ? (string) $template['min_amount'] : null,
            'max_amount'         => is_scalar($template['max_amount'] ?? null) ? (string) $template['max_amount'] : null,
            'sort_order'         => is_scalar($template['sort_order'] ?? null) ? (int) $template['sort_order'] : 0,
            'status'             => 'active',
        ];
    }

    /**
     * Formats incoming POST data into a normalized database record array for manual gateways.
     *
     * @param array<string, mixed> $data Raw input data from request payload.
     *
     * @return array<string, mixed> Normalized manual gateway database payload.
     */
    private function buildGatewayRecord(array $data): array
    {
        $nameVal = $data['name'] ?? '';
        $instructionsVal = $data['instructions'] ?? '';
        $fieldsVal = $data['fields'] ?? [];
        $minVal = $data['min_amount'] ?? '0';
        $maxVal = $data['max_amount'] ?? '0';
        $paymentNumberVal = $data['payment_number'] ?? '';

        /** @var array<int, array<string, mixed>> $fieldsArray */
        $fieldsArray = [];
        if (is_array($fieldsVal)) {
            foreach ($fieldsVal as $fv) {
                if (is_array($fv)) {
                    $fieldsArray[] = $fv;
                }
            }
        }

        $smsSenderPatternVal = $data['sms_sender_pattern'] ?? '';
        $smsRegexTemplateVal = $data['sms_regex_template'] ?? '';

        return [
            'name'             => InputSanitizer::string(is_string($nameVal) ? $nameVal : ''),
            'instructions'     => $this->buildInstructionsJson(is_string($instructionsVal) ? $instructionsVal : ''),
            'colors'           => $this->buildColorsJson($data),
            'input_fields'     => $this->buildFieldsJson($fieldsArray),
            'payment_number'   => InputSanitizer::string(is_string($paymentNumberVal) ? $paymentNumberVal : ''),
            'min_amount'       => InputSanitizer::decimal(is_string($minVal) ? $minVal : '0'),
            'max_amount'       => InputSanitizer::decimal(is_string($maxVal) ? $maxVal : '0'),
            'sms_verification' => (int) !empty($data['sms_verification']),
            'sms_sender_pattern' => InputSanitizer::string(is_string($smsSenderPatternVal) ? $smsSenderPatternVal : ''),
            'sms_regex_template' => InputSanitizer::string(is_string($smsRegexTemplateVal) ? $smsRegexTemplateVal : ''),
        ];
    }

    /**
     * Builds gateway brand color settings as a JSON string.
     *
     * @param array<string, mixed> $data Raw input data from request payload.
     * @return string Serialized colors JSON.
     */
    private function buildColorsJson(array $data): string
    {
        $json = json_encode([
            'primary'   => $data['color_primary'] ?? '#E2136E',
            'secondary' => $data['color_secondary'] ?? '#FFFFFF',
            'text'      => $data['color_text'] ?? '#FFFFFF',
        ]);
        return is_string($json) ? $json : '{}';
    }

    /**
     * Cleans and formats step-by-step raw multi-line instruction strings into JSON.
     *
     * @param string $raw Unfiltered raw instruction string separated by line breaks.
     *
     * @return string Serialized instruction steps mapping.
     */
    private function buildInstructionsJson(string $raw): string
    {
        if (empty($raw)) {
            return '{"steps":[]}';
        }
        $json = json_encode(['steps' => array_filter(array_map('trim', explode("\n", $raw)))]);
        return is_string($json) ? $json : '{"steps":[]}';
    }

    /**
     * Processes and stores uploaded merchant gateways branding files (logos, QRs).
     *
     * @param array<string, mixed> $record Reference to the gateway payload array to inject storage paths.
     *
     * @return void
     */
    private function applyUploads(array &$record): void
    {
        if (isset($_FILES['logo']) && is_array($_FILES['logo']) && !empty($_FILES['logo']['tmp_name']) && is_string($_FILES['logo']['tmp_name'])) {
            /** @var array{error: int, name: string, tmp_name: string} $file */
            $file = $_FILES['logo'];
            $record['logo_path'] = $this->fs->storePublicUpload($file, 'gateways');
        }
        if (isset($_FILES['qr_code']) && is_array($_FILES['qr_code']) && !empty($_FILES['qr_code']['tmp_name']) && is_string($_FILES['qr_code']['tmp_name'])) {
            /** @var array{error: int, name: string, tmp_name: string} $file */
            $file = $_FILES['qr_code'];
            $record['qr_code_path'] = $this->fs->storePublicUpload($file, 'gateways');
        }
    }

    /**
     * Validates manual gateway inputs.
     *
     * @param array<string, mixed> $data   The input data.
     * @param bool                 $isEdit Flag indicating if it is an update operation.
     *
     * @return string[] Array of validation error strings.
     */
    private function validateManualGateway(array $data, bool $isEdit = false): array
    {
        $errors = [];
        if (empty($data['name'])) {
            $errors[] = 'Gateway name is required';
        }
        if (!$isEdit && empty($data['slug'])) {
            $errors[] = 'Slug is required';
        }
        $slugVal = $data['slug'] ?? '';
        if (!$isEdit && !empty($slugVal) && is_string($slugVal) && !preg_match('/^[a-z0-9\-]+$/', $slugVal)) {
            $errors[] = 'Slug must be lowercase alphanumeric with hyphens only';
        }
        $smsVerificationVal = $data['sms_verification'] ?? null;
        if ($smsVerificationVal !== null
            && $smsVerificationVal !== ''
            && $smsVerificationVal !== 0
            && $smsVerificationVal !== 1
            && $smsVerificationVal !== '0'
            && $smsVerificationVal !== '1'
        ) {
            $errors[] = 'SMS verification must be 0 or 1';
        }
        return $errors;
    }

    /**
     * Validates and compiles customizable manual gateway form fields to serialized JSON structure.
     *
     * @param array<int, array<string, mixed>> $fields Configurable fields array.
     *
     * @return string Serialized custom field configuration mappings.
     */
    private function buildFieldsJson(array $fields): string
    {
        $clean = [];
        foreach ($fields as $field) {
            if (!empty($field['name']) && is_string($field['name']) && !empty($field['label']) && is_string($field['label'])) {
                $typeVal = $field['type'] ?? 'text';
                $clean[] = [
                    'name'     => InputSanitizer::slug($field['name']),
                    'label'    => InputSanitizer::string($field['label']),
                    'type'     => is_string($typeVal) ? $typeVal : 'text',
                    'required' => !empty($field['required']),
                ];
            }
        }
        $json = json_encode($clean);
        return is_string($json) ? $json : '[]';
    }
}
