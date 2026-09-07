<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;

/**
 * Controller managing brand webhooks CRUD actions.
 */
final class WebhookController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private WebhookRepository $webhookRepo;

    /**
     * WebhookController constructor.
     */
    public function __construct(Container $c, AdminSession $session, WebhookRepository $webhookRepo)
    {
        $this->c = $c;
        $this->session = $session;
        $this->webhookRepo = $webhookRepo;
    }

    /**
     * Stores a new webhook endpoint or updates an existing one.
     */
    public function store(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        // In global view, allow superadmin to assign target brand
        if (($mid === null || $mid === 0) && $this->session->isSuperadmin()) {
            $postMid = $req->post('merchant_id');
            if (is_numeric($postMid) && (int)$postMid > 0) {
                $mid = (int)$postMid;
            }
        }

        if ($guard = $this->requireActiveBrand($mid, '/admin/developer#webhooks')) {
            return $guard;
        }
        assert(is_int($mid));

        $idVal = $req->post('id', '0');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        $urlVal = $req->post('url', '');
        $url = is_string($urlVal) ? trim($urlVal) : '';

        $secretVal = $req->post('secret', '');
        $secret = is_string($secretVal) ? trim($secretVal) : '';

        $eventsPost = $req->post('events');
        $events = is_array($eventsPost) ? $eventsPost : [];

        if ($url === '' || !\OwnPay\Security\UrlValidator::isValidWebhookUrl($url)) {
            $this->session->flashError('A valid Webhook URL is required.');
            return Response::redirect('/admin/developer#webhooks');
        }

        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
        }

        $data = [
            'merchant_id' => $mid,
            'url'         => $url,
            'secret'      => $secret,
            'events'      => json_encode($events),
            'status'      => 'active'
        ];

        $scopedRepo = $this->webhookRepo->forTenant($mid);

        if ($id > 0) {
            $existing = $scopedRepo->findScoped($id);
            if (!$existing) {
                $this->session->flashError('Webhook endpoint not found.');
                return Response::redirect('/admin/developer#webhooks');
            }
            $scopedRepo->updateScoped($id, $data);
            $this->session->flashSuccess('Webhook endpoint updated successfully.');
        } else {
            $scopedRepo->createScoped($data);
            $this->session->flashSuccess('Webhook endpoint created successfully.');
        }

        return Response::redirect('/admin/developer#webhooks');
    }

    /**
     * Deletes a webhook endpoint.
     */
    public function delete(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $idVal = $req->param('id');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        // In global view for superadmin, resolve merchant from the webhook record
        if (($mid === null || $mid <= 0) && $this->session->isSuperadmin()) {
            $rawWh = $this->webhookRepo->forAllTenants()->findScoped($id);
            if ($rawWh && isset($rawWh['merchant_id']) && is_numeric($rawWh['merchant_id'])) {
                $mid = (int)$rawWh['merchant_id'];
            }
        }

        if ($guard = $this->requireActiveBrand($mid, '/admin/developer#webhooks')) {
            return $guard;
        }
        assert(is_int($mid));

        $scopedRepo = $this->webhookRepo->forTenant($mid);
        $count = $scopedRepo->deleteScoped($id);
        if ($count === 0) {
            $this->session->flashError('Webhook endpoint not found.');
        } else {
            $this->session->flashSuccess('Webhook endpoint deleted successfully.');
        }

        return Response::redirect('/admin/developer#webhooks');
    }

    /**
     * Toggles status (active/inactive) of a webhook endpoint.
     */
    public function toggle(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $idVal = $req->param('id');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        // In global view for superadmin, resolve merchant from the webhook record
        if (($mid === null || $mid <= 0) && $this->session->isSuperadmin()) {
            $rawWh = $this->webhookRepo->forAllTenants()->findScoped($id);
            if ($rawWh && isset($rawWh['merchant_id']) && is_numeric($rawWh['merchant_id'])) {
                $mid = (int)$rawWh['merchant_id'];
            }
        }

        if ($guard = $this->requireActiveBrand($mid, '/admin/developer#webhooks')) {
            return $guard;
        }
        assert(is_int($mid));

        $scopedRepo = $this->webhookRepo->forTenant($mid);
        $webhook = $scopedRepo->findScoped($id);

        if ($webhook) {
            $currentStatus = $webhook['status'] ?? 'active';
            $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';
            $scopedRepo->updateScoped($id, ['status' => $newStatus]);
            $this->session->flashSuccess("Webhook endpoint set to {$newStatus}.");
        } else {
            $this->session->flashError('Webhook endpoint not found.');
        }

        return Response::redirect('/admin/developer#webhooks');
    }
}
