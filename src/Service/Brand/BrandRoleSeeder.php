<?php
declare(strict_types=1);

namespace OwnPay\Service\Brand;

use OwnPay\Core\Database;

/**
 * Class BrandRoleSeeder
 *
 * Dedicated seeder responsible for provisioning standard, conflict-free
 * administrative roles (Owner, Manager, Staff, Finance) scoped strictly
 * to a specific merchant brand. Automatically strips any platform-level
 * permissions to prevent privilege escalation or multi-tenant leaks.
 *
 * @package OwnPay\Service\Brand
 */
final class BrandRoleSeeder
{
    /**
     * Platform-only permissions that must NEVER be assigned to brand-scoped roles.
     */
    public const PLATFORM_PERMISSIONS = [
        'brands.access_all',
        'brands.view',
        'brands.manage',
        'merchants.view',
        'merchants.create',
        'merchants.update',
        'system.update',
        'system.balance',
        'plugins.view',
        'plugins.manage',
    ];

    /**
     * Seeds the 4 standard conflict-free roles for a given brand store.
     *
     * Notes: permissions are always (re)applied for the Owner role; other roles are only
     * provisioned on first creation.
     *
     * @param Database $db The database adapter.
     * @param int $merchantId The merchant brand identifier.
     * @return void
     */
    public static function seed(Database $db, int $merchantId): void
    {
        if ($merchantId <= 0) {
            return;
        }

        $allPermRows = $db->fetchAll("SELECT id, slug FROM op_permissions");
        $permMap = [];
        foreach ($allPermRows as $p) {
            $slug = $p['slug'] ?? null;
            $pId = $p['id'] ?? null;
            if (is_string($slug) && is_numeric($pId)) {
                $permMap[$slug] = (int) $pId;
            }
        }

        // 1. Owner: all brand-safe permissions (zero platform permissions)
        $brandSafePermSlugs = array_values(array_filter(array_keys($permMap), function ($slug) {
            return !in_array($slug, self::PLATFORM_PERMISSIONS, true);
        }));

        // 2. Manager: operations & team supervision
        $managerSlugs = [
            'dashboard.view', 'dashboard.stats',
            'transactions.view', 'transactions.manage', 'transactions.update', 'transactions.export',
            'invoices.view', 'invoices.manage', 'invoices.create', 'invoices.update',
            'payment_links.view', 'payment_links.manage', 'payment_links.create', 'payment_links.update',
            'customers.view', 'customers.manage', 'customers.create', 'customers.update',
            'gateways.view',
            'staff.view',
            'domains.view',
            'api_keys.view',
            'webhooks.view',
            'sms.view', 'sms.manage',
            'devices.view',
            'settings.view',
            'system.reports',
            'system.audit',
            'admin.access',
        ];

        // 3. Staff: order processing & support
        $staffSlugs = [
            'dashboard.view',
            'transactions.view', 'transactions.update',
            'invoices.view',
            'payment_links.view',
            'customers.view', 'customers.update',
            'sms.view',
            'admin.access',
        ];

        // 4. Finance: reconciliation & accounting
        $financeSlugs = [
            'dashboard.view', 'dashboard.stats',
            'transactions.view', 'transactions.export',
            'invoices.view',
            'customers.view',
            'system.reports',
            'admin.access',
        ];

        $templates = [
            [
                'name'        => 'Owner',
                'slug'        => 'owner',
                'description' => 'Full operational control of this brand store',
                'is_system'   => 1,
                'slugs'       => $brandSafePermSlugs,
            ],
            [
                'name'        => 'Manager',
                'slug'        => 'manager',
                'description' => 'Day-to-day operations and team oversight',
                'is_system'   => 0,
                'slugs'       => $managerSlugs,
            ],
            [
                'name'        => 'Staff',
                'slug'        => 'staff',
                'description' => 'Order processing and customer assistance',
                'is_system'   => 0,
                'slugs'       => $staffSlugs,
            ],
            [
                'name'        => 'Finance',
                'slug'        => 'finance',
                'description' => 'Financial reporting and reconciliation',
                'is_system'   => 0,
                'slugs'       => $financeSlugs,
            ],
        ];

        foreach ($templates as $tpl) {
            $existing = $db->fetchOne(
                "SELECT id FROM op_roles WHERE merchant_id = :mid AND slug = :slug LIMIT 1",
                ['mid' => $merchantId, 'slug' => $tpl['slug']]
            );

            if ($existing === null) {
                $db->execute(
                    "INSERT INTO op_roles (merchant_id, name, slug, description, is_system, created_at)
                     VALUES (:mid, :name, :slug, :desc, :sys, NOW(6))",
                    [
                        'mid'  => $merchantId,
                        'name' => $tpl['name'],
                        'slug' => $tpl['slug'],
                        'desc' => $tpl['description'],
                        'sys'  => $tpl['is_system'],
                    ]
                );
                $roleId = (int) $db->lastInsertId();
            } else {
                $existingId = $existing['id'] ?? null;
                $roleId = is_numeric($existingId) ? (int)$existingId : 0;
            }

            if ($roleId > 0 && ($existing === null || $tpl['slug'] === 'owner')) {
                foreach ($tpl['slugs'] as $s) {
                    if (isset($permMap[$s])) {
                        $db->execute(
                            "INSERT IGNORE INTO op_role_permissions (role_id, permission_id) VALUES (:rid, :pid)",
                            ['rid' => $roleId, 'pid' => $permMap[$s]]
                        );
                    }
                }
            }

            // Always strip any platform permissions from brand roles
            if ($roleId > 0) {
                $platformPlaceholders = implode(',', array_fill(0, count(self::PLATFORM_PERMISSIONS), '?'));
                $db->execute(
                    "DELETE rp FROM op_role_permissions rp
                     JOIN op_permissions p ON p.id = rp.permission_id
                     WHERE rp.role_id = ? AND p.slug IN ({$platformPlaceholders})",
                    array_merge([$roleId], self::PLATFORM_PERMISSIONS)
                );
            }
        }
    }
}
