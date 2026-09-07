<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Core\Database;
use OwnPay\Service\Brand\BrandRoleSeeder;
use PHPUnit\Framework\TestCase;

final class BrandRoleSeederTest extends TestCase
{
    public function testSeedCreatesFourConflictFreeRolesAndPrunesPlatformPermissions(): void
    {
        $db = $this->createMock(Database::class);

        // Simulated op_permissions
        $permissions = [
            ['id' => 1, 'slug' => 'dashboard.view'],
            ['id' => 2, 'slug' => 'dashboard.stats'],
            ['id' => 4, 'slug' => 'transactions.view'],
            ['id' => 5, 'slug' => 'transactions.manage'],
            ['id' => 6, 'slug' => 'transactions.update'],
            ['id' => 7, 'slug' => 'transactions.export'],
            ['id' => 8, 'slug' => 'invoices.view'],
            ['id' => 13, 'slug' => 'payment_links.view'],
            ['id' => 18, 'slug' => 'customers.view'],
            ['id' => 22, 'slug' => 'gateways.view'],
            ['id' => 24, 'slug' => 'brands.view'],         // PLATFORM
            ['id' => 26, 'slug' => 'brands.access_all'],    // PLATFORM
            ['id' => 35, 'slug' => 'settings.view'],
            ['id' => 36, 'slug' => 'settings.manage'],
            ['id' => 46, 'slug' => 'plugins.view'],        // PLATFORM
            ['id' => 50, 'slug' => 'system.update'],       // PLATFORM
            ['id' => 51, 'slug' => 'system.audit'],
            ['id' => 52, 'slug' => 'system.reports'],
            ['id' => 3, 'slug' => 'admin.access'],
        ];

        $db->method('fetchAll')->willReturnCallback(function (string $sql) use ($permissions) {
            if (str_contains($sql, 'op_permissions')) {
                return $permissions;
            }
            return [];
        });

        $stmt = $this->createMock(\PDOStatement::class);
        $executedSql = [];
        $executedParams = [];
        $db->method('execute')->willReturnCallback(function (string $sql, array $params = []) use (&$executedSql, &$executedParams, $stmt) {
            $executedSql[] = $sql;
            $executedParams[] = $params;
            return $stmt;
        });

        // Simulate new brand: no existing roles
        $db->method('fetchOne')->willReturn(null);
        $db->method('lastInsertId')->willReturn('10', '11', '12', '13');

        BrandRoleSeeder::seed($db, 99);

        // Verify roles inserted
        $insertRolesQueries = array_filter($executedSql, fn($s) => str_contains($s, 'INSERT INTO op_roles'));
        $this->assertCount(4, $insertRolesQueries, 'Should insert 4 default roles (Owner, Manager, Staff, Finance).');

        // Verify platform permissions are pruned
        $pruneQueries = array_filter($executedSql, fn($s) => str_contains($s, 'DELETE rp FROM op_role_permissions'));
        $this->assertCount(4, $pruneQueries, 'Should prune platform permissions from each role.');

        // Verify platform permissions not assigned to Owner
        foreach ($executedParams as $p) {
            if (isset($p['pid'])) {
                $pid = $p['pid'];
                $this->assertNotEquals(26, $pid, 'brands.access_all (id 26) must NEVER be assigned to brand role.');
                $this->assertNotEquals(50, $pid, 'system.update (id 50) must NEVER be assigned to brand role.');
                $this->assertNotEquals(46, $pid, 'plugins.view (id 46) must NEVER be assigned to brand role.');
            }
        }
    }
}
