<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use OwnPay\Service\System\AuditService;
use OwnPay\Service\System\PaginationService;

/**
 * Controller managing administrative login attempt log records and lockout mitigation.
 */
final class LoginAttemptController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private LoginAttemptRepository $attemptsRepo;
    private AuditService $audit;

    /**
     * Initialises the LoginAttemptController.
     *
     * @param Container              $c            The dependency injection container.
     * @param AdminSession           $session      The admin session service.
     * @param LoginAttemptRepository $attemptsRepo The login attempt repository.
     * @param AuditService           $audit        The audit log service.
     */
    public function __construct(Container $c, AdminSession $session, LoginAttemptRepository $attemptsRepo, AuditService $audit)
    {
        $this->c = $c;
        if (!$this->c->has(LoginAttemptRepository::class)) {
        }
        $this->session = $session;
        $this->attemptsRepo = $attemptsRepo;
        $this->audit = $audit;
    }

    /**
     * Renders a list of recent login attempt log records.
     */
    public function index(Request $req): Response
    {
        $isSuperadmin = $this->session->isSuperadmin();
        $mid = null;

        if ($this->c->has(BrandContext::class)) {
            $brand = $this->c->get(BrandContext::class);
            if ($brand instanceof BrandContext) {
                $brand->resolveFromRequest($req);
                if (!$isSuperadmin || !$brand->isGlobalView()) {
                    $mid = $brand->getActiveBrandId();
                }
            }
        }
        if (!$isSuperadmin && ($mid === null || $mid <= 0)) {
            $mid = $this->session->merchantId();
        }

        $where = '1=1';
        $params = [];

        if ($mid !== null && $mid > 0) {
            $db = $this->attemptsRepo->getDatabase();
            $staffRows = $db->fetchAll(
                "SELECT email FROM op_merchant_users WHERE merchant_id = :mid",
                ['mid' => $mid]
            );
            $staffEmailMap = [];
            foreach ($staffRows as $sRow) {
                $e = $sRow['email'] ?? null;
                if (is_string($e) && $e !== '') {
                    $staffEmailMap[$e] = $e;
                }
            }
            $userEmail = $this->session->userEmail();
            if ($userEmail !== '') {
                $staffEmailMap[$userEmail] = $userEmail;
            }
            $staffEmails = array_values($staffEmailMap);

            if (empty($staffEmails)) {
                $where = '1=0';
            } else {
                $placeholders = [];
                foreach ($staffEmails as $idx => $email) {
                    $key = 'semail_' . $idx;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $email;
                }
                $where = 'email IN (' . implode(', ', $placeholders) . ')';
            }
        }

        $pageVal = $req->query('page', '1');
        $page = is_numeric($pageVal) ? (int)$pageVal : 1;
        $page = max(1, $page);
        $perPage = 50;

        $paginated = $this->attemptsRepo->paginate($page, $perPage, $where, $params, 'id DESC');
        $pagination = PaginationService::calculate($page, $perPage, $paginated['total']);

        return $this->renderAdminPage('admin/settings/login-attempts.twig', [
            'attempts'   => $paginated['items'],
            'pagination' => $pagination,
            'active_page' => 'activities',
            'active_subpage' => 'login_attempts',
        ]);
    }

    /**
     * Clears failed login attempts for a specific IP or Email to unlock the target.
     */
    public function unlock(Request $req): Response
    {
        $isSuperadmin = $this->session->isSuperadmin();
        $mid = null;

        if ($this->c->has(BrandContext::class)) {
            $brand = $this->c->get(BrandContext::class);
            if ($brand instanceof BrandContext) {
                $brand->resolveFromRequest($req);
                if (!$isSuperadmin || !$brand->isGlobalView()) {
                    $mid = $brand->getActiveBrandId();
                }
            }
        }
        if (!$isSuperadmin && ($mid === null || $mid <= 0)) {
            $mid = $this->session->merchantId();
        }

        $ipVal = $req->post('ip', '');
        $emailVal = $req->post('email', '');

        $ip = trim(is_string($ipVal) ? $ipVal : '');
        $email = trim(is_string($emailVal) ? $emailVal : '');

        $db = $this->attemptsRepo->getDatabase();

        // Audit fix ROL-2: the previous implementation ran
        // `DELETE FROM op_login_attempts WHERE ip_address = :ip` (or email),
        // destroying ALL login records - both successes and failures. That
        // wiped the audit trail of legitimate successful logins for the
        // target, making it impossible to investigate "did the user actually
        // log in successfully before being unlocked?". Scope the DELETE to
        // AND success = 0 so only the failed attempts that constitute the
        // lockout are cleared; successful logins are preserved for forensic
        // review.
        //
        // Additionally, write an audit-log entry recording who unlocked whom,
        // so the unlock action itself is traceable.
        $target = '';
        if ($ip !== '') {
            if ($mid !== null && $mid > 0) {
                // Non-superadmin / brand scoped: fetch brand staff emails
                $staffRows = $db->fetchAll(
                    "SELECT email FROM op_merchant_users WHERE merchant_id = :mid",
                    ['mid' => $mid]
                );
                $staffEmailMap = [];
                foreach ($staffRows as $sRow) {
                    $e = $sRow['email'] ?? null;
                    if (is_string($e) && $e !== '') {
                        $staffEmailMap[$e] = $e;
                    }
                }
                $userEmail = $this->session->userEmail();
                if ($userEmail !== '') {
                    $staffEmailMap[$userEmail] = $userEmail;
                }
                $staffEmails = array_values($staffEmailMap);

                if (empty($staffEmails)) {
                    $this->session->flashError("No staff accounts found for this brand.");
                    return Response::redirect('/admin/login-attempts');
                }

                $placeholders = [];
                $params = ['ip' => $ip];
                foreach ($staffEmails as $idx => $sEmail) {
                    $key = 'e_' . $idx;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $sEmail;
                }
                $db->delete(
                    "DELETE FROM op_login_attempts WHERE ip_address = :ip AND email IN (" . implode(', ', $placeholders) . ") AND success = 0",
                    $params
                );
                $this->session->flashSuccess("Failed login attempts from IP {$ip} for your brand staff have been cleared/unlocked.");
            } else {
                $db->delete("DELETE FROM op_login_attempts WHERE ip_address = :ip AND success = 0", ['ip' => $ip]);
                $this->session->flashSuccess("Failed login attempts from IP {$ip} have been cleared/unlocked.");
            }
            $target = $ip;
        } elseif ($email !== '') {
            if ($mid !== null && $mid > 0) {
                // Verify this email belongs to the merchant's staff
                $existsVal = $db->fetchColumn(
                    "SELECT COUNT(*) FROM op_merchant_users WHERE email = :email AND merchant_id = :mid",
                    ['email' => $email, 'mid' => $mid]
                );
                $exists = is_numeric($existsVal) ? (int)$existsVal : 0;
                if ($exists === 0 && $email !== $this->session->userEmail()) {
                    $this->session->flashError("You can only unlock staff accounts belonging to your brand.");
                    return Response::redirect('/admin/login-attempts');
                }
            }
            $db->delete("DELETE FROM op_login_attempts WHERE email = :email AND success = 0", ['email' => $email]);
            $target = $email;
            $this->session->flashSuccess("Failed login attempts for user {$email} have been cleared/unlocked.");
        } else {
            $this->session->flashError("Invalid IP or Email provided.");
            return Response::redirect('/admin/login-attempts');
        }

        $this->audit->log(
            'login_attempts.unlocked',
            'auth',
            null,
            null,
            [
                'admin_id' => $this->session->userId(),
                'target'   => $target,
            ]
        );

        return Response::redirect('/admin/login-attempts');
    }
}
