<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Repository\WebhookEventRepository;
use OwnPay\Service\Payment\WebhookService;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\System\PaginationService;
use OwnPay\Core\Database;

/**
 * Controller managing administrative oversight of outbound webhook dispatch history and DLQ.
 */
final class WebhookEventController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession Active administrative session details.
     */
    private AdminSession $session;

    /**
     * @var WebhookEventRepository The webhook event records repository.
     */
    private WebhookEventRepository $webhookEventRepo;

    /**
     * @var WebhookService Outbound webhook dispatch and retrying manager.
     */
    private WebhookService $webhookService;

    /**
     * @var Database The database connection instance.
     */
    private Database $db;

    /**
     * WebhookEventController constructor.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        WebhookEventRepository $webhookEventRepo,
        WebhookService $webhookService,
        Database $db
    ) {
        $this->c = $c;
        $this->session = $session;
        $this->webhookEventRepo = $webhookEventRepo;
        $this->webhookService = $webhookService;
        $this->db = $db;
    }

    /**
     * Lists webhook event dispatches with pagination.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $isGlobal = $this->isGlobalBrandView();
        $mid = $isGlobal ? null : $brand->getActiveBrandId();

        $pageVal = $req->query('page', '1');
        $page = max(1, is_int($pageVal) || is_string($pageVal) ? (int)$pageVal : 1);
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $events = $this->webhookEventRepo->listPaginated($mid, $perPage, $offset);
        $total = $this->webhookEventRepo->countFiltered($mid);

        $pagination = PaginationService::calculate($page, $perPage, $total);

        // Fetch recent webhook deliveries
        $dispatcher = $this->c->get(\OwnPay\Service\Notification\WebhookDispatcher::class);
        if (!$dispatcher instanceof \OwnPay\Service\Notification\WebhookDispatcher) {
            throw new \RuntimeException('WebhookDispatcher service unavailable');
        }
        $deliveries = $dispatcher->listDeliveries($mid, 50);

        return $this->renderAdminPage('admin/webhooks/events.twig', [
            'events'             => $events,
            'pagination'         => $pagination,
            'active_page'        => 'webhook_events',
            'webhook_deliveries' => $deliveries,
        ]);
    }

    /**
     * Displays execution and HTTP response delivery logs for a single webhook event.
     */
    public function logs(Request $req): Response
    {
        $eventId = (int)$req->param('id');
        $event = $this->webhookEventRepo->find($eventId);

        if (!$event) {
            $this->session->flashError('Webhook event not found.');
            return Response::redirect('/admin/webhooks/events');
        }

        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $isGlobal = $this->isGlobalBrandView();
        $mid = $isGlobal ? null : $brand->getActiveBrandId();

        // Verify brand scope access
        $webhookId = isset($event['webhook_id']) && is_scalar($event['webhook_id']) ? (int)$event['webhook_id'] : 0;
        $webhook = $this->db->fetchOne(
            "SELECT * FROM op_webhooks WHERE id = :id LIMIT 1",
            ['id' => $webhookId]
        );

        $merchantIdVal = is_array($webhook) && isset($webhook['merchant_id']) ? $webhook['merchant_id'] : 0;
        $merchantId = is_scalar($merchantIdVal) ? (int)$merchantIdVal : 0;

        if (!is_array($webhook) || (!$isGlobal && $merchantId !== $mid)) {
            $this->session->flashError('Unauthorized access to webhook details.');
            return Response::redirect('/admin/webhooks/events');
        }

        $logs = $this->db->fetchAll(
            "SELECT * FROM op_webhook_delivery_logs WHERE webhook_event_id = :eid ORDER BY created_at DESC",
            ['eid' => $eventId]
        );

        return $this->renderAdminPage('admin/webhooks/logs.twig', [
            'event'       => $event,
            'webhook'     => $webhook,
            'logs'        => $logs,
            'active_page' => 'webhook_events',
        ]);
    }

    /**
     * Manually triggers immediate synchronous redelivery/replay for a failed webhook event.
     */
    public function replay(Request $req): Response
    {
        $eventId = (int)$req->param('id');
        $event = $this->webhookEventRepo->find($eventId);

        if (!$event) {
            $this->session->flashError('Webhook event not found.');
            return Response::redirect('/admin/webhooks/events');
        }

        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $isGlobal = $this->isGlobalBrandView();
        $mid = $isGlobal ? null : $brand->getActiveBrandId();

        // Verify brand scope access
        $webhookId = isset($event['webhook_id']) && is_scalar($event['webhook_id']) ? (int)$event['webhook_id'] : 0;
        $webhook = $this->db->fetchOne(
            "SELECT * FROM op_webhooks WHERE id = :id LIMIT 1",
            ['id' => $webhookId]
        );

        $merchantIdVal = is_array($webhook) && isset($webhook['merchant_id']) ? $webhook['merchant_id'] : 0;
        $merchantId = is_scalar($merchantIdVal) ? (int)$merchantIdVal : 0;

        if (!is_array($webhook) || (!$isGlobal && $merchantId !== $mid)) {
            $this->session->flashError('Unauthorized access to webhook details.');
            return Response::redirect('/admin/webhooks/events');
        }

        $payloadVal = $event['payload'] ?? '{}';
        $eventData = json_decode(is_string($payloadVal) ? $payloadVal : '{}', true);
        if (!is_array($eventData)) {
            $eventData = [];
        }

        $eventType = isset($event['event_type']) && is_scalar($event['event_type']) ? (string)$event['event_type'] : '';

        $success = $this->webhookService->deliver(
            $webhook,
            $eventType,
            $eventData,
            $eventId
        );

        if ($success) {
            $this->session->flashSuccess('Webhook replayed and delivered successfully.');
        } else {
            $this->session->flashError('Webhook replay attempt failed. View delivery logs for details.');
        }

        return Response::redirect("/admin/webhooks/events/{$eventId}/logs");
    }
}
