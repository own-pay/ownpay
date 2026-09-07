<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

/**
 * Repository layer for brands/stores (`op_merchants` table).
 *
 * Manages system brands, configurations, timezones, default checkout currencies, 
 * webhooks, and domains. Unscoped globally as merchants represent top-level brands.
 *
 * @package OwnPay\Repository
 */
final class MerchantRepository extends BaseRepository
{
    /**
     * @var string Database table name.
     */
    protected string $table = 'op_merchants';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'uuid', 'name', 'slug', 'email', 'phone', 'logo_path',
        'color', 'initials', 'description',
        'timezone', 'default_currency', 'webhook_secret', 'settings', 'status',
    ];

    /**
     * Finds a merchant record by its URL slug.
     *
     * @param string $slug Unique URL identifier of the merchant.
     * @return array<string, mixed>|null The merchant record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * Finds a merchant record by its primary contact email address.
     *
     * @param string $email The contact email.
     * @return array<string, mixed>|null The merchant record, or null if not found.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    /**
     * Finds the earliest-created merchant record (lowest id), used by the
     * onboarding wizard to detect whether any brand already exists.
     *
     * @return array<string, mixed>|null The merchant record, or null if none exist.
     */
    public function findFirst(): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} ORDER BY id ASC LIMIT 1"
        );
    }

    /**
     * Creates a new merchant brand.
     *
     * Automatically generates a UUIDv4 and a secure random webhook secret.
     *
     * @param array<string, mixed> $data The raw merchant configuration fields.
     * @return string The primary key ID of the newly created merchant.
     */
    public function createMerchant(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['webhook_secret'] = bin2hex(random_bytes(32));
        $id = $this->create($data);

        // Auto-seed default conflict-free roles (Owner, Manager, Staff, Finance)
        try {
            \OwnPay\Service\Brand\BrandRoleSeeder::seed($this->db, (int) $id);
        } catch (\Throwable) {
            // Non-blocking
        }

        return $id;
    }

    /**
     * Lists all merchant brands alongside their primary domains for the administrative views.
     *
     * @return array<int, array<string, mixed>> List of merchants with associated primary domains.
     */
    public function listWithDomains(): array
    {
        return $this->db->fetchAll(
            "SELECT m.id, m.name, m.slug, m.logo_path, m.status, m.created_at,
                    d.domain as primary_domain
             FROM {$this->table} m
             LEFT JOIN op_domains d ON d.merchant_id = m.id AND d.is_primary = 1
             ORDER BY m.created_at DESC"
        );
    }

    /**
     * Finds a merchant record with its primary domain and DNS verification status.
     *
     * @param int $id The merchant ID.
     * @return array<string, mixed>|null The merchant record augmented with primary domain fields, or null if not found.
     */
    public function findWithDomain(int $id): ?array
    {
        $brand = $this->find($id);
        if (!$brand) return null;

        $domain = $this->db->fetchOne(
            "SELECT domain, dns_verified FROM op_domains WHERE merchant_id = :mid AND is_primary = 1 LIMIT 1",
            ['mid' => $id]
        );
        if ($domain) {
            $brand['primary_domain'] = $domain['domain'];
            $brand['dns_verified'] = $domain['dns_verified'];
        }
        return $brand;
    }

    /**
     * Updates key branding and localization settings for a merchant.
     *
     * @param int $id The merchant ID.
     * @param array<string, mixed> $data The updated parameters.
     * @return void
     */
    public function updateBrand(int $id, array $data): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET name = :name, email = :email, phone = :phone,
             timezone = :tz, default_currency = :cur, status = :status,
             logo_path = :logo_path, settings = :settings
             WHERE id = :id",
            [
                'name'      => $data['name'],
                'email'     => $data['email'],
                'phone'     => $data['phone'] ?? '',
                'tz'        => $data['timezone'] ?? 'Asia/Dhaka',
                'cur'       => $data['default_currency'] ?? 'BDT',
                'status'    => $data['status'] ?? 'active',
                'logo_path' => $data['logo_path'] ?? null,
                'settings'  => $data['settings'] ?? null,
                'id'        => $id,
            ]
        );
    }
}

