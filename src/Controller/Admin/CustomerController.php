<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Security\PiiMasker;
use OwnPay\Service\Customer\CustomerPiiService;
use OwnPay\Service\System\AuditService;
use OwnPay\Service\System\PaginationService;

/**
 * Class CustomerController
 *
 * Coordinates administrative customer management operations, handling creation, display,
 * search/pagination, and deletion of customer profiles while ensuring proper context isolation
 * and PII decryption.
 *
 * @package OwnPay\Controller\Admin
 */
final class CustomerController
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
     * @var \OwnPay\Repository\CustomerRepository The customer records repository.
     */
    private \OwnPay\Repository\CustomerRepository $customerRepo;

    /**
     * @var CustomerPiiService PII service handling encryption, hashing, and lifecycle events.
     */
    private CustomerPiiService $piiService;

    /**
     * @var AuditService Audit logging service for security-sensitive admin actions.
     */
    private AuditService $audit;

    /**
     * CustomerController constructor.
     *
     * @param Container                             $c            The dependency injection container.
     * @param AdminSession                          $session      The administrative session service.
     * @param \OwnPay\Repository\CustomerRepository $customerRepo The customer records repository.
     * @param CustomerPiiService                    $piiService   The PII service for create/lookup operations.
     * @param AuditService                          $audit        The audit log service.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        \OwnPay\Repository\CustomerRepository $customerRepo,
        CustomerPiiService $piiService,
        AuditService $audit
    ) {
        $this->c = $c;
        $this->session = $session;
        $this->customerRepo = $customerRepo;
        $this->piiService = $piiService;
        $this->audit = $audit;
    }

    /**
     * Lists customers of the active brand with pagination and search filtering.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The customers list overview response.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class); 
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req); 
        $isGlobal = $brand->isGlobalView();
        $mid = $brand->getActiveBrandId();
        if ($mid === null && !$isGlobal) {
            throw new \RuntimeException('No active brand found.');
        }

        $pageVal = $req->query('page', '1');
        $page = max(1, is_int($pageVal) || is_string($pageVal) ? (int)$pageVal : 1);
        $qVal = $req->query('q', '');
        $q = is_string($qVal) ? $qVal : '';

        // Compute the email blind-index hash via the canonical service helper so
        // the algorithm matches the one used at customer-write time (HMAC-SHA256
        // with the server's field-encryption key). Prior to audit fix API-12 the
        // repository recomputed the hash with plain hash('sha256', ...) which
        // never matched the stored HMAC hashes, so admin search by email was
        // completely broken.
        $emailHash = '';
        if (trim($q) !== '') {
            $emailHash = $this->piiService->hashEmailForSearch($q);
        }

        $paginated = $this->customerRepo->paginateWithStats($isGlobal ? null : $mid, $emailHash, $page, 20);

        // Determine whether the current viewer is permitted to reveal unmasked
        // customer PII. The previous implementation unconditionally decrypted
        // full PII (name, email, phone) for every row in the paginated list and
        // passed the plaintext values straight to Twig - a staff member with
        // only the customers.view (read-only) permission saw every customer's
        // email and phone in cleartext. Now we mask by default and only attach
        // the unmasked plaintext when the viewer has customers.manage (or is a
        // superadmin, which bypasses all permission checks upstream).
        $permsVal = $req->getAttribute('user_permissions', []);
        $perms = is_array($permsVal) ? $permsVal : [];
        $canRevealPii = $this->session->isSuperadmin()
            || in_array('customers.manage', $perms, true);

        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        if (!$enc instanceof \OwnPay\Security\FieldEncryptor) {
            throw new \RuntimeException('FieldEncryptor service unavailable');
        }
        $customers = array_map(function (array $c) use ($enc, $canRevealPii) {
            // Decrypt only what's needed for display. The plaintext is never
            // exposed to the template unless the viewer can manage customers.
            $namePlain  = '-';
            $emailPlain = '-';
            $phonePlain = '-';
            try {
                $namePlain  = !empty($c['name_enc']) && is_string($c['name_enc']) ? $enc->decrypt($c['name_enc']) : (is_string($c['name'] ?? null) ? $c['name'] : '-');
                $emailPlain = !empty($c['email_enc']) && is_string($c['email_enc']) ? $enc->decrypt($c['email_enc']) : (is_string($c['email'] ?? null) ? $c['email'] : '-');
                $phonePlain = !empty($c['phone_enc']) && is_string($c['phone_enc']) ? $enc->decrypt($c['phone_enc']) : (is_string($c['phone'] ?? null) ? $c['phone'] : '-');
            } catch (\Throwable) {
                $namePlain  = is_string($c['name'] ?? null) ? $c['name'] : '[encrypted]';
                $emailPlain = is_string($c['email'] ?? null) ? $c['email'] : '[encrypted]';
                $phonePlain = is_string($c['phone'] ?? null) ? $c['phone'] : '-';
            }

            // Name is left decrypted because the avatar/identifier column
            // needs to remain useful for locating customers in the list.
            $c['name'] = $namePlain;

            // Masked values are the default rendered in the list table. The
            // Twig template reads `email_masked`/`phone_masked` instead of the
            // plaintext `email`/`phone` columns.
            $c['email_masked'] = PiiMasker::maskEmail($emailPlain);
            $c['phone_masked'] = PiiMasker::maskPhone($phonePlain);
            // Default the legacy email/phone columns to the masked values so
            // any third-party template partial that still reads `c.email` is
            // safe by default.
            $c['email'] = $c['email_masked'];
            $c['phone'] = $c['phone_masked'];

            // The plaintext is only attached when the viewer is permitted to
            // reveal it; the template renders a per-row "Reveal" affordance
            // gated on `can_reveal_pii` AND the presence of `email_revealed`.
            if ($canRevealPii) {
                $c['email_revealed'] = $emailPlain;
                $c['phone_revealed'] = $phonePlain;
            }

            // Strip the encrypted columns so the ciphertext never reaches Twig.
            unset($c['email_enc'], $c['phone_enc'], $c['name_enc'], $c['address_enc']);
            return $c;
        }, $paginated['items']);

        return $this->renderAdminPage('admin/customers.twig', [
            'customers'       => $customers,
            'can_reveal_pii'  => $canRevealPii,
            'filters'         => ['q' => $q],
            'pagination'      => [
                'page'         => $paginated['page'],
                'current_page' => $paginated['page'],
                'per_page'     => $paginated['per_page'],
                'total_items'  => $paginated['total'],
                'total_pages'  => $paginated['total_pages'],
                'has_prev'     => $paginated['page'] > 1,
                'has_next'     => $paginated['page'] < $paginated['total_pages'],
                'offset'       => ($paginated['page'] - 1) * $paginated['per_page'],
            ],
            'active_page' => 'customers',
        ]);
    }

    /**
     * Displays details for a single customer profile, decrypted, along with transaction history.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The customer details page.
     */
    public function show(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class); 
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req); 
        $isGlobal = $brand->isGlobalView();
        $mid = $brand->getActiveBrandId();
        if ($mid === null && !$isGlobal) {
            throw new \RuntimeException('No active brand found.');
        }
        
        if ($isGlobal || $mid === 0) {
            $customer = $this->customerRepo->find($id);
        } else {
            $scopedRepo = $this->customerRepo->forTenant($mid);
            $customer = $scopedRepo->findScoped($id);
        }
        
        if (!$customer) { 
            $this->session->flashError('Customer not found'); 
            return Response::redirect('/admin/customers'); 
        }

        // Decrypt PII
        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        if (!$enc instanceof \OwnPay\Security\FieldEncryptor) {
            throw new \RuntimeException('FieldEncryptor service unavailable');
        }
        try {
            $customer['name']  = !empty($customer['name_enc']) && is_string($customer['name_enc']) ? $enc->decrypt($customer['name_enc']) : (is_string($customer['name'] ?? null) ? $customer['name'] : '-');
            $customer['email'] = !empty($customer['email_enc']) && is_string($customer['email_enc']) ? $enc->decrypt($customer['email_enc']) : (is_string($customer['email'] ?? null) ? $customer['email'] : '-');
            $customer['phone'] = !empty($customer['phone_enc']) && is_string($customer['phone_enc']) ? $enc->decrypt($customer['phone_enc']) : (is_string($customer['phone'] ?? null) ? $customer['phone'] : '-');
        } catch (\Throwable $e) {
            $customer['name']  = '[encrypted]';
            $customer['email'] = '[encrypted]';
            $customer['phone'] = '-';
        }

        $custMid = $customer['merchant_id'] ?? null;
        $effectiveMid = is_scalar($custMid) ? (int)$custMid : ($mid ?? 0);
        $txns = $this->customerRepo->getRecentTransactions($id, $effectiveMid, 50);

        return $this->renderAdminPage('admin/customers/show.twig', [
            'customer'       => $customer,
            'transactions'   => $txns,
            'active_page'    => 'customers',
            'show_detail'    => true,
        ]);
    }

    /**
     * Renders the customer creation view.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The customer creation page response.
     */
    public function create(Request $req): Response
    {
        return $this->renderAdminPage('admin/customers/create.twig', [
            'active_page' => 'customers',
        ]);
    }

    /**
     * Stores a new customer profile encrypting sensitive PII fields.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response to the customer listing.
     */
    public function store(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($guard = $this->requireActiveBrand($mid, '/admin/customers')) {
            return $guard;
        }
        // requireActiveBrand guarantees $mid is a positive int from here on.
        \assert($mid !== null && $mid > 0);

        $nameVal = $req->post('name', '');
        $emailVal = $req->post('email', '');
        $phoneVal = $req->post('phone', '');

        $name  = is_string($nameVal) ? trim($nameVal) : '';
        $email = is_string($emailVal) ? trim($emailVal) : '';
        $phone = is_string($phoneVal) ? trim($phoneVal) : '';

        if ($name === '' || $email === '') {
            $this->session->flashError('Name and email are required');
            return Response::redirect('/admin/customers/create');
        }

        // Email format validation. The previous raw INSERT accepted any string,
        // producing garbage rows like "not-an-email" whose hash could never be
        // looked up again. Reject early so the row is never persisted.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->session->flashError('Please enter a valid email address.');
            return Response::redirect('/admin/customers/create');
        }

        // Basic phone-format guard: only digits, +, -, spaces, parentheses,
        // up to 30 chars. Rejects control chars, letters, and absurdly long
        // inputs that would blow up the encrypted column.
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{1,30}$/', $phone)) {
            $this->session->flashError('Phone number may only contain digits, +, -, spaces, and parentheses (max 30 chars).');
            return Response::redirect('/admin/customers/create');
        }

        // Duplicate-email check. The previous raw INSERT blindly wrote a row
        // even when an existing customer shared the same email_hash, leaving
        // two customer records resolving to the same person.
        $existing = $this->piiService->findByEmail($mid, $email);
        if ($existing !== null) {
            $this->session->flashError('A customer with this email already exists.');
            return Response::redirect('/admin/customers/create');
        }

        // Delegate creation to CustomerPiiService::create() so we benefit from
        // the canonical UUID generation, email_hash/phone_hash computation,
        // AES-256-GCM encryption, and customer.created event dispatch. The
        // previous raw INSERT bypassed all of these, leaving rows without a
        // UUID and without triggering downstream integrations.
        try {
            $customer = $this->piiService->create($mid, [
                'name'  => $name,
                'email' => $email,
                'phone' => $phone,
            ]);
        } catch (\Throwable $e) {
            $this->session->flashError('Failed to create customer: ' . $e->getMessage());
            return Response::redirect('/admin/customers/create');
        }

        $customerId = isset($customer['id']) && is_scalar($customer['id']) ? (int) $customer['id'] : null;
        $this->audit->log(
            'customer.created',
            'customers',
            $customerId,
            null,
            [
                'admin_id'     => $this->session->userId(),
                'merchant_id'  => $mid,
                'email_masked' => PiiMasker::maskEmail($email),
            ]
        );

        $this->session->flashSuccess("Customer '{$name}' created");
        return Response::redirect('/admin/customers');
    }

    /**
     * Soft-deletes a customer profile under the scoped merchant context.
     *
     * Delegates to CustomerPiiService::delete() so that the row is preserved
     * with `status='deleted'` and all PII fields (email_enc, phone_enc,
     * name_enc, email_hash, phone_hash) are cleared in place. This keeps the
     * transaction->customer FK intact (no SET NULL cascade) and dispatches
     * the `customer.deleted` event that plugins/CRM integrations rely on.
     * An audit-log entry is written so the action is attributable.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function delete(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $isGlobal = $brand->isGlobalView();
        $mid = $brand->getActiveBrandId();
        if ($mid === null && !$isGlobal) {
            throw new \RuntimeException('No active brand found.');
        }

        if ($isGlobal || $mid === 0) {
            $customer = $this->customerRepo->find($id);
        } else {
            $scopedRepo = $this->customerRepo->forTenant($mid);
            $customer = $scopedRepo->findScoped($id);
        }

        if (!$customer) {
            $this->session->flashError('Customer not found or access denied');
            return Response::redirect('/admin/customers');
        }

        $custMid = $customer['merchant_id'] ?? null;
        $effectiveMid = is_scalar($custMid) ? (int)$custMid : ($mid ?? 0);

        // SECURITY (CUS-1): perform a SOFT delete via CustomerPiiService so
        // that PII is wiped but the row is preserved with status='deleted'.
        // Previously this method ran `DELETE FROM op_customers WHERE id=...`
        // which physically removed the row, nulled every op_transactions.
        // customer_id (FK ON DELETE SET NULL), skipped PII-retention
        // controls, skipped the `customer.deleted` event, and produced no
        // audit-log entry.
        $pii = $this->c->get(\OwnPay\Service\Customer\CustomerPiiService::class);
        if (!$pii instanceof \OwnPay\Service\Customer\CustomerPiiService) {
            throw new \RuntimeException('CustomerPiiService unavailable');
        }
        $pii->delete($effectiveMid, $id);

        // Audit-log the soft-delete so the action is attributable.
        $audit = $this->c->get(\OwnPay\Service\System\AuditService::class);
        if ($audit instanceof \OwnPay\Service\System\AuditService) {
            $audit->log(
                'customer.deleted',
                'customer',
                $id,
                ['status' => $customer['status'] ?? null],
                ['status' => 'deleted']
            );
        }

        $this->session->flashSuccess('Customer deleted');
        return Response::redirect('/admin/customers');
    }
}
