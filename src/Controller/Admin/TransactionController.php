<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Enum\TransactionStatus;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\RefundRepository;
use OwnPay\Service\System\PaginationService;
use OwnPay\Service\System\AuditService;
use OwnPay\Event\EventManager;
use OwnPay\Support\DateHelper;

/**
 * Controller for managing payment transactions within the admin portal.
 */
final class TransactionController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The transaction repository.
     */
    private TransactionRepository $txns;

    /**
     * The parsed SMS repository.
     */
    private SmsParsedRepository $smsRepo;

    /**
     * The audit log repository.
     */
    private AuditLogRepository $auditRepo;

    /**
     * The event manager instance.
     */
    private EventManager $events;

    /**
     * The audit service instance.
     */
    private AuditService $audit;

    /**
     * The database wrapper.
     */
    private \OwnPay\Core\Database $db;

    /**
     * The refund repository.
     */
    private RefundRepository $refunds;

    /**
     * TransactionController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param TransactionRepository $txns The transaction repository.
     * @param SmsParsedRepository $smsRepo The parsed SMS repository.
     * @param AuditLogRepository $auditRepo The audit log repository.
     * @param EventManager $events The event manager instance.
     * @param AuditService $audit The audit service instance.
     * @param RefundRepository $refunds The refund repository.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        TransactionRepository $txns,
        SmsParsedRepository $smsRepo,
        AuditLogRepository $auditRepo,
        EventManager $events,
        AuditService $audit,
        \OwnPay\Core\Database $db,
        RefundRepository $refunds
    ) {
        $this->c         = $c;
        $this->session   = $session;
        $this->txns      = $txns;
        $this->smsRepo   = $smsRepo;
        $this->auditRepo = $auditRepo;
        $this->events    = $events;
        $this->audit     = $audit;
        $this->db        = $db;
        $this->refunds   = $refunds;
    }

    /**
     * Display a paginated list of transactions filtered by the active brand and user query.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the transaction index page.
     * @throws \Exception If lookup or rendering fails.
     */
    public function index(Request $req): Response
    {
        $mid = $this->resolveMerchant($req);
        $isGlobal = $this->isGlobalView();

        $pageVal = $req->query('page', '1');
        $page = max(1, is_scalar($pageVal) && is_numeric($pageVal) ? (int) $pageVal : 1);
        
        $qVal = $req->query('q', '');
        $statusVal = $req->query('status', '');
        $gatewayVal = $req->query('gateway', '');
        $dateFromVal = $req->query('date_from', '');
        $dateToVal = $req->query('date_to', '');

        $filters = [
            'q'         => is_string($qVal) ? $qVal : '',
            'status'    => is_string($statusVal) ? $statusVal : '',
            'gateway'   => is_string($gatewayVal) ? $gatewayVal : '',
            'date_from' => is_string($dateFromVal) ? $dateFromVal : '',
            'date_to'   => is_string($dateToVal) ? $dateToVal : '',
        ];

        $repo = $isGlobal ? $this->txns->forAllTenants() : $this->txns->forTenant($mid);
        $total = $repo->countFiltered($filters);
        $pagination = PaginationService::calculate($page, 25, $total);
        $transactions = $repo->listFiltered($filters, $pagination['per_page'], $pagination['offset']);

        $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
        $transactions = array_map(function (array $txn) use ($enc) {
            if ($enc instanceof \OwnPay\Security\FieldEncryptor) {
                if (!empty($txn['customer_name']) && is_string($txn['customer_name'])) {
                    try {
                        $txn['customer_name'] = $enc->decrypt($txn['customer_name']);
                    } catch (\Throwable $e) {
                        $txn['customer_name'] = '[encrypted]';
                    }
                } else {
                    $txn['customer_name'] = '-';
                }
            }
            // For manual gateways, fallback to metadata if gateway_trx_id or sender_account is empty
            if (!empty($txn['metadata'])) {
                $meta = is_string($txn['metadata']) ? json_decode($txn['metadata'], true) : $txn['metadata'];
                if (is_array($meta)) {
                    $payDetails = (isset($meta['payment_details']) && is_array($meta['payment_details'])) ? $meta['payment_details'] : [];
                    if (empty($txn['gateway_trx_id'])) {
                        $manualTrx = $payDetails['transaction_id'] ?? ($meta['transaction_id'] ?? null);
                        if (is_string($manualTrx) && trim($manualTrx) !== '') {
                            $txn['gateway_trx_id'] = trim($manualTrx);
                        }
                    }
                    if (empty($txn['sender_account'])) {
                        $senderNum = $payDetails['sender_number'] ?? ($meta['sender_number'] ?? null);
                        if (is_string($senderNum) && trim($senderNum) !== '') {
                            $txn['sender_account'] = trim($senderNum);
                        }
                    }
                }
            }
            return $txn;
        }, $transactions);

        $gateways = $this->txns->getDistinctGateways($isGlobal ? null : $mid);

        return $this->renderAdminPage('admin/transactions/index.twig', [
            'transactions' => $transactions,
            'pagination'   => $pagination,
            'filters'      => $filters,
            'gateways'     => $gateways,
            'active_page'  => 'transactions',
        ]);
    }

    /**
     * Show details for a specific transaction including SMS verification logs and audits.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with transaction details or redirect.
     * @throws \Exception If lookup or rendering fails.
     */
    public function show(Request $req): Response
    {
        $id = (int) $req->param('id');
        $isGlobal = $this->isGlobalView();
        $mid = $this->resolveMerchant($req);

        $txn = ($isGlobal ? $this->txns->forAllTenants() : $this->txns->forTenant($mid))->findScoped($id);
        if ($txn === null) {
            $this->session->flashError('Transaction not found');
            return Response::redirect('/admin/transactions');
        }
        // In global view, scope brand-specific lookups to the record's own brand.
        $recordMid = is_numeric($txn['merchant_id'] ?? null) ? (int) $txn['merchant_id'] : $mid;

        // Fetch and decrypt customer details if customer_id is present
        if (!empty($txn['customer_id'])) {
            $customerRepo = $this->c->get(\OwnPay\Repository\CustomerRepository::class);
            if ($customerRepo instanceof \OwnPay\Repository\CustomerRepository) {
                $customerIdVal = $txn['customer_id'];
                $customerId = is_numeric($customerIdVal) ? (int) $customerIdVal : 0;
                $customer = $customerRepo->forTenant($recordMid)->findScoped($customerId);
                if ($customer) {
                    $enc = $this->c->get(\OwnPay\Security\FieldEncryptor::class);
                    if ($enc instanceof \OwnPay\Security\FieldEncryptor) {
                        try {
                            $txn['customer_name']  = (!empty($customer['name_enc']) && is_string($customer['name_enc'])) ? $enc->decrypt($customer['name_enc']) : (is_string($customer['name'] ?? null) ? $customer['name'] : '-');
                            $txn['customer_email'] = (!empty($customer['email_enc']) && is_string($customer['email_enc'])) ? $enc->decrypt($customer['email_enc']) : (is_string($customer['email'] ?? null) ? $customer['email'] : '-');
                            $txn['customer_phone'] = (!empty($customer['phone_enc']) && is_string($customer['phone_enc'])) ? $enc->decrypt($customer['phone_enc']) : (is_string($customer['phone'] ?? null) ? $customer['phone'] : '-');
                        } catch (\Throwable $e) {
                            $txn['customer_name']  = '[encrypted]';
                            $txn['customer_email'] = '[encrypted]';
                            $txn['customer_phone'] = '-';
                        }
                    }
                }
            }
        }
        
        // Resolve gateway name
        $gwSlug = is_scalar($txn['gateway_slug'] ?? null) ? (string) $txn['gateway_slug'] : 'api';
        $txn['gateway_name'] = ucfirst($gwSlug);
        $gatewayRepo = $this->c->get(\OwnPay\Repository\GatewayRepository::class);
        if ($gatewayRepo instanceof \OwnPay\Repository\GatewayRepository) {
            $gw = $gatewayRepo->findBySlug($gwSlug);
            if ($gw) {
                $txn['gateway_name'] = $gw['name'] ?? $txn['gateway_name'];
            }
        }

        // Resolve customer IP address
        $txn['ip_address'] = $txn['ip_address'] ?? null;
        if (empty($txn['ip_address']) && !empty($txn['metadata'])) {
            $meta = is_string($txn['metadata']) ? json_decode($txn['metadata'], true) : $txn['metadata'];
            if (is_array($meta)) {
                $txn['ip_address'] = $meta['ip_address'] ?? null;
            }
        }

        $smsData  = $this->smsRepo->listForTransaction($id);
        $auditLog = $this->auditRepo->listForEntity('transaction', $id);
        $refundsRepo = $isGlobal ? $this->refunds->forAllTenants() : $this->refunds->forTenant($recordMid);
        $refunds  = $refundsRepo->listFiltered(['transaction_id' => $id], 50, 0);

        // Same accounting RefundService::create() itself enforces (pending + completed count
        // against the limit) - shown here so the admin sees the real remaining refundable amount
        // rather than the transaction's full original amount once a prior refund exists.
        $totalRefundedRaw = $refundsRepo->getTotalRefundedAmount($id, $recordMid);
        $totalRefunded = is_numeric($totalRefundedRaw) ? $totalRefundedRaw : '0.00';
        $txnAmountVal = $txn['amount'] ?? '0.00';
        $txnAmountStr = is_scalar($txnAmountVal) && is_numeric((string) $txnAmountVal) ? (string) $txnAmountVal : '0.00';
        /** @var numeric-string $totalRefunded */
        /** @var numeric-string $txnAmountStr */
        $remainingRefundable = bcsub($txnAmountStr, $totalRefunded, 2);
        if (bccomp($remainingRefundable, '0.00', 2) < 0) {
            $remainingRefundable = '0.00';
        }

        // Extract manual payment submission details (e.g. sender_number, transaction_id, etc.)
        $paymentDetails = [];
        if (!empty($txn['metadata'])) {
            $meta = is_string($txn['metadata']) ? json_decode($txn['metadata'], true) : $txn['metadata'];
            if (is_array($meta)) {
                if (isset($meta['payment_details']) && is_array($meta['payment_details'])) {
                    $paymentDetails = $meta['payment_details'];
                } elseif (!empty($meta['sender_number']) || !empty($meta['transaction_id'])) {
                    $paymentDetails = array_filter([
                        'sender_number'  => $meta['sender_number'] ?? null,
                        'transaction_id' => $meta['transaction_id'] ?? null,
                    ]);
                }
            }
        }

        // If manual gateway transaction, ensure gateway_trx_id and sender_account are populated from payment_details if empty
        if (empty($txn['gateway_trx_id']) && !empty($paymentDetails['transaction_id'])) {
            $txn['gateway_trx_id'] = $paymentDetails['transaction_id'];
        }
        if (empty($txn['sender_account']) && !empty($paymentDetails['sender_number'])) {
            $txn['sender_account'] = $paymentDetails['sender_number'];
        }

        return $this->renderAdminPage('admin/transactions/edit.twig', [
            'txn'                  => $txn,
            'payment_details'      => $paymentDetails,
            'sms_data'             => $smsData,
            'audit_log'            => $auditLog,
            'refunds'              => $refunds,
            'total_refunded'       => $totalRefunded,
            'remaining_refundable' => $remainingRefundable,
            'active_page'          => 'transactions',
        ]);
    }

    /**
     * Update status of a transaction (e.g. mark completed, refund, cancel) and post double-entry ledger lines.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If transaction service operations, double-entry ledger bookkeeping, or audits fail.
     */
    public function updateStatus(Request $req): Response
    {
        $id = (int) $req->param('id');
        $isGlobal = $this->isGlobalView();
        $mid = $this->resolveMerchant($req);

        $newStatus = $req->post('status', '');
        if (!in_array($newStatus, ['completed', 'canceled', 'refunded'], true)) {
            $this->session->flashError('Invalid status');
            return Response::redirect("/admin/transactions/{$id}");
        }

        $txn = ($isGlobal ? $this->txns->forAllTenants() : $this->txns->forTenant($mid))->findScoped($id);
        if ($txn === null) {
            $this->session->flashError('Transaction not found');
            return Response::redirect('/admin/transactions');
        }
        // In global view, perform the write against the transaction's own brand.
        if ($isGlobal) {
            $mid = is_numeric($txn['merchant_id'] ?? null) ? (int) $txn['merchant_id'] : $mid;
        }

        if ($txn['status'] === $newStatus || ($newStatus === 'canceled' && $txn['status'] === 'cancelled')) {
            $this->session->flashSuccess("Transaction is already {$newStatus}");
            return Response::redirect("/admin/transactions/{$id}");
        }

        // State machine enforcement: terminal transactions must not be
        // re-completed or cancelled, and only completed transactions may be
        // marked refunded - otherwise ledger entries get posted for money
        // that never moved (e.g. "refunding" a failed payment).
        $currentStatus = isset($txn['status']) && is_scalar($txn['status']) ? (string) $txn['status'] : '';
        $terminalStatuses = array_map(static fn(TransactionStatus $s) => $s->value, TransactionStatus::terminal());

        if (in_array($newStatus, ['completed', 'canceled'], true) && in_array($currentStatus, $terminalStatuses, true)) {
            $this->session->flashError("Cannot mark a {$currentStatus} transaction as {$newStatus}");
            return Response::redirect("/admin/transactions/{$id}");
        }
        if ($newStatus === 'refunded' && $currentStatus !== 'completed') {
            $this->session->flashError('Only completed transactions can be marked refunded');
            return Response::redirect("/admin/transactions/{$id}");
        }

        $transactionService = $this->c->get(\OwnPay\Service\Payment\TransactionService::class);
        $ledgerService = $this->c->get(\OwnPay\Service\Payment\LedgerService::class);

        if (!$transactionService instanceof \OwnPay\Service\Payment\TransactionService) {
            throw new \RuntimeException('TransactionService not found.');
        }
        if (!$ledgerService instanceof \OwnPay\Service\Payment\LedgerService) {
            throw new \RuntimeException('LedgerService not found.');
        }

        $this->events->doAction('transaction.status.before', $txn, $newStatus);

        if ($newStatus === 'completed') {
            $transactionService->complete($id, $mid);
            $updatedTxn = $this->txns->forTenant($mid)->findScoped($id);
            if ($updatedTxn !== null) {
                $amountVal = $updatedTxn['amount'] ?? '0.00';
                $feeVal = $updatedTxn['fee'] ?? '0.00';
                $currencyVal = $updatedTxn['currency'] ?? 'BDT';
                $ledgerService->recordPaymentReceived(
                    $mid,
                    $id,
                    is_string($amountVal) ? $amountVal : '0.00',
                    is_string($feeVal) ? $feeVal : '0.00',
                    is_string($currencyVal) ? $currencyVal : 'BDT'
                );
            }
        } elseif ($newStatus === 'refunded') {
            $this->txns->forTenant($mid)->updateScoped($id, ['status' => 'refunded', 'updated_at' => DateHelper::now()]);
            $amountVal = $txn['amount'] ?? '0.00';
            $currencyVal = $txn['currency'] ?? 'BDT';
            $amount = is_string($amountVal) ? $amountVal : '0.00';

            // Find or create a refund record in op_refunds to get a unique refund ID for the ledger
            $refundRepo = $this->db->fetchOne(
                "SELECT id FROM op_refunds WHERE transaction_id = :txid AND merchant_id = :mid AND amount = :amt LIMIT 1",
                ['txid' => $id, 'mid' => $mid, 'amt' => $amount]
            );

            if ($refundRepo !== null && isset($refundRepo['id'])) {
                $idVal = $refundRepo['id'];
                $refundId = is_scalar($idVal) ? (int) $idVal : 0;
                $this->db->execute(
                    "UPDATE op_refunds SET status = 'completed', processed_at = NOW() WHERE id = :id",
                    ['id' => $refundId]
                );
            } else {
                $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
                $this->db->execute(
                    "INSERT INTO op_refunds (merchant_id, transaction_id, uuid, amount, reason, status, processed_at)
                     VALUES (:mid, :txid, :uuid, :amt, 'Refund processed by administrator', 'completed', NOW())",
                    ['mid' => $mid, 'txid' => $id, 'uuid' => $uuid, 'amt' => $amount]
                );
                $refundId = (int) $this->db->lastInsertId();
            }

            $ledgerService->recordRefund(
                $mid,
                $refundId,
                $id,
                $amount,
                is_string($currencyVal) ? $currencyVal : 'BDT'
            );
        } elseif ($newStatus === 'canceled') {
            $transactionService->cancel($id, $mid);
        }

        $this->events->doAction('transaction.status.changed', array_merge($txn, ['status' => $newStatus]));
        $this->audit->log('transaction.status_changed', 'transaction', $id, ['status' => $txn['status']], ['status' => $newStatus]);

        $this->session->flashSuccess("Transaction marked {$newStatus}");
        return Response::redirect("/admin/transactions/{$id}");
    }

    /**
     * Whether the admin is in the global "All Brands" (superadmin) view.
     *
     * @return bool True when operating across all brands.
     */
    private function isGlobalView(): bool
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        return $brand instanceof \OwnPay\Service\Brand\BrandContext && $brand->isGlobalView();
    }

    /**
     * Resolves the active merchant context ID from the request.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return int The resolved merchant ID.
     */
    private function resolveMerchant(Request $req): int
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service not found.');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('Brand ID not resolved.');
        }
        return $mid;
    }
}
