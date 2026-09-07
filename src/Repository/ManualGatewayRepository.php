<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for merchant-defined manual payment gateways (`op_manual_gateways` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages properties such as custom instructions, input fields configuration, SMS verification patterns,
 * and currency/limits.
 *
 * @package OwnPay\Repository
 */
final class ManualGatewayRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_manual_gateways';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'slug', 'name', 'logo_path', 'qr_code_path', 'colors',
        'input_fields', 'instructions', 'admin_notes', 'sms_verification',
        'sms_sender_pattern', 'sms_regex_template', 'currency', 'payment_number',
        'min_amount', 'max_amount', 'sort_order', 'status',
    ];

    /**
     * Finds a manual gateway record by its unique slug under the active tenant context.
     *
     * @param string $slug Unique identifier/slug of the manual gateway.
     * @return array<string, mixed>|null The manual gateway database record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE slug = :s AND merchant_id = :mid LIMIT 1",
            ['s' => $slug, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists active manual gateway records under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of active manual gateways.
     */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' ORDER BY sort_order ASC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists all manual gateway records under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of all manual gateways.
     */
    public function listAll(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY sort_order ASC, id DESC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Resolves the manual gateways a brand's customer can pay through at checkout.
     *
     * Opt-in model:
     * When paying under a specific brand ($brandId > 0 and $brandId !== $platformId),
     * only manual gateways that the brand has explicitly configured and activated
     * (merchant_id = $brandId AND status = 'active') are offered. Platform templates
     * are never exposed to brand customers unless the brand has explicitly configured and
     * enabled its own account for that gateway.
     *
     * When paying directly under the platform owner ($brandId === $platformId),
     * the platform's own active manual gateways are returned.
     *
     * @param int $brandId    The paying brand/merchant id (the transaction's merchant_id).
     * @param int $platformId The reserved platform-owner merchant id (BrandContext::getPlatformId()).
     * @return array<int, array<string, mixed>> Effective active manual gateways, sorted by sort_order.
     */
    public function listActiveForCheckout(int $brandId, int $platformId): array
    {
        $targetId = ($brandId > 0 && $brandId !== $platformId) ? $brandId : $platformId;

        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active' AND merchant_id = :mid
             ORDER BY sort_order ASC, id ASC",
            ['mid' => $targetId]
        );
    }
}

