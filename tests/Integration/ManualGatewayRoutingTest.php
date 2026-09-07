<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\ManualGatewayRepository;

/**
 * Money-critical: verifies that a customer's manual-gateway payment routes to the correct account.
 * Uses unique 'zztest-' slugs so it never touches real seeded gateways and is fully self-cleaning.
 */
final class ManualGatewayRoutingTest extends IntegrationTestCase
{
    private Database $db;
    private ManualGatewayRepository $repo;
    private int $brandId = 2;
    private int $platformId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->repo = new ManualGatewayRepository($this->db);

        // Ensure a non-platform brand merchant exists so brand-scoped
        // operations target a different row than the platform template.
        $this->ensureMerchant($this->brandId);

        $row = $this->db->fetchOne("SELECT id FROM op_merchants WHERE is_platform = 1 ORDER BY id ASC LIMIT 1");
        $platformId = ($row !== null && isset($row['id']) && is_scalar($row['id'])) ? (int) $row['id'] : 0;
        if ($platformId === 0 || $platformId === $this->brandId) {
            $this->markTestSkipped('Platform-owner row not available or collides with brand id.');
        }
        $this->platformId = $platformId;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_manual_gateways WHERE slug LIKE 'zztest-%'");
    }

    private function insertGateway(int $merchantId, string $slug, string $account, string $status = 'active'): void
    {
        $this->db->execute(
            "INSERT INTO op_manual_gateways (merchant_id, slug, name, instructions, sms_verification, currency, status, sort_order)
             VALUES (:mid, :slug, :name, :instr, 0, 'BDT', :status, 0)",
            [
                'mid'    => $merchantId,
                'slug'   => $slug,
                'name'   => $slug,
                'instr'  => json_encode($account),
                'status' => $status,
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function findSlug(array $rows, string $slug): ?array
    {
        foreach ($rows as $row) {
            if (($row['slug'] ?? null) === $slug) {
                return $row;
            }
        }
        return null;
    }

    public function testBrandAccountWinsOverPlatformTemplate(): void
    {
        $this->insertGateway($this->platformId, 'zztest-both', 'PLATFORM-ACCT-both');
        $this->insertGateway($this->brandId, 'zztest-both', 'BRAND-ACCT-both');

        $result = $this->repo->listActiveForCheckout($this->brandId, $this->platformId);

        $gw = $this->findSlug($result, 'zztest-both');
        $this->assertNotNull($gw, 'Configured and active brand gateway must be offered at checkout');
        $this->assertSame($this->brandId, (int) $gw['merchant_id'], 'Brand account must win - funds route to the brand');
        $this->assertStringContainsString('BRAND-ACCT-both', (string) $gw['instructions']);

        $count = 0;
        foreach ($result as $row) {
            if (($row['slug'] ?? null) === 'zztest-both') {
                $count++;
            }
        }
        $this->assertSame(1, $count, 'A slug must collapse to a single effective gateway');
    }

    public function testPlatformTemplateNotOfferedWhenBrandHasNotConfigured(): void
    {
        $this->insertGateway($this->platformId, 'zztest-tmpl', 'PLATFORM-ACCT-tmpl');

        $result = $this->repo->listActiveForCheckout($this->brandId, $this->platformId);

        $gw = $this->findSlug($result, 'zztest-tmpl');
        $this->assertNull($gw, 'Opt-in model: Unconfigured platform template must NOT be offered on brand checkout');
    }

    public function testBrandOnlyLegacySlugPreserved(): void
    {
        $this->insertGateway($this->brandId, 'zztest-brandonly', 'BRAND-ACCT-legacy');

        $result = $this->repo->listActiveForCheckout($this->brandId, $this->platformId);

        $gw = $this->findSlug($result, 'zztest-brandonly');
        $this->assertNotNull($gw, 'A brand-only gateway must still be offered');
        $this->assertSame($this->brandId, (int) $gw['merchant_id']);
        $this->assertStringContainsString('BRAND-ACCT-legacy', (string) $gw['instructions']);
    }

    public function testInactivePlatformTemplateHidden(): void
    {
        $this->insertGateway($this->platformId, 'zztest-inactive', 'PLATFORM-ACCT-inactive', 'inactive');

        $result = $this->repo->listActiveForCheckout($this->platformId, $this->platformId);

        $this->assertNull($this->findSlug($result, 'zztest-inactive'), 'Inactive templates must not be offered');
    }

    public function testBrandDisabledAccountNotOfferedAndNeverFallsBackToPlatform(): void
    {
        $this->insertGateway($this->platformId, 'zztest-binactive', 'PLATFORM-ACCT-binactive');
        $this->insertGateway($this->brandId, 'zztest-binactive', 'BRAND-ACCT-binactive', 'inactive');

        $result = $this->repo->listActiveForCheckout($this->brandId, $this->platformId);

        $gw = $this->findSlug($result, 'zztest-binactive');
        $this->assertNull($gw, 'A disabled brand account must NOT be offered at checkout and must never fall back to platform');
    }

    public function testPlatformOwnerStoreShowsPlatformTemplate(): void
    {
        $this->insertGateway($this->platformId, 'zztest-platform-store', 'PLATFORM-ACCT-store');

        $result = $this->repo->listActiveForCheckout($this->platformId, $this->platformId);

        $gw = $this->findSlug($result, 'zztest-platform-store');
        $this->assertNotNull($gw, 'Platform owner store checkout must offer platform templates');
        $this->assertSame($this->platformId, (int) $gw['merchant_id']);
    }
}
