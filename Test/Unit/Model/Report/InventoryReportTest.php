<?php
/**
 * Meetanshi AIReporting — InventoryReport Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\InventoryReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InventoryReportTest extends TestCase
{
    use ReportContextTrait;

    private InventoryReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->report     = new InventoryReport($this->createReportContext($this->connection));
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllMetrics(): void
    {
        $this->connection->method('fetchRow')->willReturn(
            ['total_skus' => '100', 'in_stock' => '85', 'out_of_stock' => '15', 'low_stock' => '5']
        );
        $this->connection->method('fetchOne')->willReturn('125000.00');

        $result = $this->report->getKpiCards();

        $this->assertSame('100', $result['total_skus']);
        $this->assertSame('85', $result['in_stock']);
        $this->assertSame('15', $result['out_of_stock']);
        $this->assertSame('5', $result['low_stock']);
        $this->assertSame('125000.00', $result['total_stock_value']);
    }

    public function testKpisOnlyCountStockManagedQtyTypes(): void
    {
        $captured = '';
        $this->connection->method('fetchRow')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [];
        });

        $this->report->getKpiCards();

        $this->assertStringContainsString("e.type_id IN ('simple', 'virtual')", $captured);
        $this->assertStringContainsString('si.use_config_manage_stock = 1', $captured);
        $this->assertStringContainsString('cataloginventory_stock_item', $captured);
        $this->assertStringNotContainsString('inventory_source_item', $captured);
    }

    public function testKpisUseMsiSourceItemsWhenMsiEnabled(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $report     = new InventoryReport($this->createReportContext($connection, 'UTC', true));
        $captured   = '';
        $connection->method('fetchRow')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [];
        });

        $report->getKpiCards();

        $this->assertStringContainsString('inventory_source_item', $captured);
        $this->assertStringContainsString('src.enabled = 1', $captured);
        $this->assertStringContainsString('SUM(isi.quantity)', $captured);
    }

    // ── getLowStockProducts ──────────────────────────────────────────────

    public function testGetLowStockProductsReturnsExpectedStructure(): void
    {
        $this->connection->method('fetchAll')->willReturn([[
            'entity_id' => '1', 'sku' => 'LAPTOP-001', 'product_name' => 'Laptop',
            'qty' => '3', 'min_qty' => '0', 'is_in_stock' => '1', 'price' => '899.99',
        ]]);

        $result = $this->report->getLowStockProducts();

        $this->assertSame('3', $result[0]['qty']);
        $this->assertArrayHasKey('product_name', $result[0]);
        $this->assertArrayHasKey('price', $result[0]);
    }

    public function testGetLowStockProductsBindsThreshold(): void
    {
        $bind = null;
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql, array $b) use (&$bind) {
            $bind = $b;
            return [];
        });

        $this->assertSame([], $this->report->getLowStockProducts(7, 5));
        $this->assertSame([7], $bind);
    }

    // ── getOutOfStockProducts ────────────────────────────────────────────

    public function testGetOutOfStockProductsReturnsExpectedStructure(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['entity_id' => '5', 'sku' => 'CAMERA-001', 'product_name' => 'Camera', 'qty' => '0', 'price' => '1299.99'],
        ]);

        $result = $this->report->getOutOfStockProducts();

        $this->assertSame('0', $result[0]['qty']);
    }

    // ── getStockDistribution ─────────────────────────────────────────────

    public function testGetStockDistributionReturnsAllRangesZeroFilled(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['stock_range' => 'Out of Stock', 'product_count' => '5'],
            ['stock_range' => '100+ (High)', 'product_count' => '35'],
        ]);

        $result = $this->report->getStockDistribution();

        $this->assertCount(6, $result);
        $this->assertSame('Out of Stock', $result[0]['stock_range']);
        $this->assertSame(5, $result[0]['product_count']);
        $this->assertSame(0, $result[1]['product_count']);
        $this->assertSame(35, $result[5]['product_count']);
    }

    // ── getDemandVsSupply ────────────────────────────────────────────────

    public function testGetDemandVsSupplyMatchesStockByProductId(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [
                ['sku' => 'LAPTOP-001', 'qty_sold_30d' => '20', 'current_stock' => '3', 'stock_status' => 'CRITICAL'],
                ['sku' => 'CABLE-001', 'qty_sold_30d' => '10', 'current_stock' => '100', 'stock_status' => 'OK'],
            ];
        });

        $result = $this->report->getDemandVsSupply();

        $this->assertSame('CRITICAL', $result[0]['stock_status']);
        $this->assertSame('OK', $result[1]['stock_status']);
        $this->assertStringContainsString('s.product_id = oi.product_id', $captured);
    }

    // ── getInventoryTurnover ─────────────────────────────────────────────

    public function testGetInventoryTurnoverReturnsExpectedStructure(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['sku' => 'LAPTOP-001', 'product_name' => 'Laptop', 'qty_sold' => '30', 'current_stock' => '10', 'turnover_rate' => '3.00'],
        ]);

        $result = $this->report->getInventoryTurnover();

        $this->assertSame('3.00', $result[0]['turnover_rate']);
        $this->assertArrayHasKey('qty_sold', $result[0]);
        $this->assertArrayHasKey('current_stock', $result[0]);
    }

    public function testGetInventoryTurnoverReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->report->getInventoryTurnover());
    }

    // ── getSkuRows / getSkuRowsSql ───────────────────────────────────────

    public function testGetSkuRowsDerivesAvailabilityCoverStatusAndTier(): void
    {
        $longAgo = gmdate('Y-m-d H:i:s', time() - 200 * 86400);
        $this->connection->method('fetchAll')->willReturn([
            $this->skuRow('HEALTHY', 100, 21, 30),
            $this->skuRow('LOW', 8, 0, 0, $longAgo),
            $this->skuRow('GONE', 0, 0, 3, null, 0),
            $this->skuRow('FAST', 40, 0, 90),
            $this->skuRow('NEVER', 120, 0, 0, null),
        ]);

        $rows = array_column($this->report->getSkuRows(...$this->last30Days()), null, 'sku');

        // 30 sold in 30 days = 1/day; 100 on hand - 21 reserved = 79 available = 79 days of cover
        $this->assertSame(79.0, $rows['HEALTHY']['available']);
        $this->assertSame(79.0, $rows['HEALTHY']['cover_days']);
        $this->assertSame(0.3, $rows['HEALTHY']['turnover']);
        $this->assertSame(1000.0, $rows['HEALTHY']['value']);
        $this->assertSame(['ok', 'optimal', false, false], $this->flags($rows['HEALTHY']));

        $this->assertSame(['low', 'critical', true, true], $this->flags($rows['LOW']));
        $this->assertNull($rows['LOW']['cover_days']);
        $this->assertSame(200, $rows['LOW']['idle_days']);

        $this->assertSame(['out', 'out', true, false], $this->flags($rows['GONE']));
        $this->assertNull($rows['GONE']['turnover']);

        // 90 sold in 30 days = 3/day: 40 units last 13.3 days, under the 14-day mark
        $this->assertSame(13.3, $rows['FAST']['cover_days']);
        $this->assertSame(['depleting', 'buffer', true, false], $this->flags($rows['FAST']));

        $this->assertSame(['idle', 'high', false, true], $this->flags($rows['NEVER']));
        $this->assertNull($rows['NEVER']['last_sold_at']);
    }

    public function testSkuRowsCountAvailableBelowZeroAsOutOfStock(): void
    {
        $this->connection->method('fetchAll')->willReturn([$this->skuRow('OVERSOLD', 5, 7, 7)]);

        $row = $this->report->getSkuRows(...$this->last30Days())[0];

        $this->assertSame(-2.0, $row['available']);
        $this->assertSame(0.0, $row['cover_days']);
        $this->assertSame('out', $row['status']);
    }

    public function testSkuRowsSqlReadsReservationsOnlyWithMsi(): void
    {
        $legacy = $this->report->getSkuRowsSql('2026-01-01 00:00:00', '2026-02-01 00:00:00');

        $connection = $this->createMock(AdapterInterface::class);
        $msi = (new InventoryReport($this->createReportContext($connection, 'UTC', true)))
            ->getSkuRowsSql('2026-01-01 00:00:00', '2026-02-01 00:00:00');

        $this->assertStringNotContainsString('inventory_reservation', $legacy);
        $this->assertStringContainsString('0 AS reserved', $legacy);
        $this->assertStringContainsString('inventory_reservation', $msi);
        $this->assertStringContainsString("o.created_at >= '2026-01-01 00:00:00' AND o.created_at < '2026-02-01 00:00:00'", $msi);
        $this->assertStringContainsString("o.state NOT IN ('canceled','pending_payment')", $msi);
    }

    // ── summarize ────────────────────────────────────────────────────────

    public function testSummarizeTotalsTiersAndTabCounts(): void
    {
        $longAgo = gmdate('Y-m-d H:i:s', time() - 200 * 86400);
        $this->connection->method('fetchAll')->willReturn([
            $this->skuRow('HEALTHY', 100, 21, 30),
            $this->skuRow('LOW', 8, 0, 0, $longAgo),
            $this->skuRow('GONE', 0, 0, 3, null, 0),
            $this->skuRow('NEVER', 120, 0, 0, null),
        ]);
        $rows = $this->report->getSkuRows(...$this->last30Days());

        $result  = $this->report->summarize($rows, 30);
        $summary = $result['summary'];
        $tiers   = array_column($result['tiers'], null, 'key');

        $this->assertSame(4, $summary['skus']);
        $this->assertSame(3, $summary['in_stock']);
        $this->assertSame(1, $summary['out_of_stock']);
        $this->assertSame(1, $summary['low_stock']);
        $this->assertSame(2, $summary['risk']);
        $this->assertSame(2, $summary['idle']);
        $this->assertSame(2, $summary['selling']);
        $this->assertSame(228.0, $summary['units_on_hand']);
        $this->assertSame(21.0, $summary['units_reserved']);
        $this->assertSame(33.0, $summary['units_sold']);
        $this->assertSame(2280.0, $summary['stock_value']);
        $this->assertSame(1280.0, $summary['idle_value']);
        // 33 sold / 228 on hand over 30 days, scaled to a year
        $this->assertSame(0.14, $summary['turnover']);
        $this->assertSame(1.7, $summary['turnover_annualized']);

        $this->assertSame(['high', 'optimal', 'buffer', 'critical', 'out'], array_keys($tiers));
        $this->assertSame(1, $tiers['high']['skus']);
        $this->assertSame(1, $tiers['optimal']['skus']);
        $this->assertSame(0, $tiers['buffer']['skus']);
        $this->assertSame(1, $tiers['critical']['skus']);
        $this->assertSame(1, $tiers['out']['skus']);
    }

    /**
     * A getSkuRowsSql() result row: list price 10.
     */
    private function skuRow(
        string $sku,
        float $qty,
        float $reserved,
        float $sold,
        ?string $lastSoldAt = '',
        int $isInStock = 1
    ): array {
        return [
            'product_id'   => crc32($sku),
            'sku'          => $sku,
            'product_name' => $sku . ' product',
            'qty'          => (string) $qty,
            'is_in_stock'  => (string) $isInStock,
            'price'        => '10.0000',
            'status'       => '1',
            'reserved'     => (string) $reserved,
            'sold'         => (string) $sold,
            'last_sold_at' => $lastSoldAt === '' ? gmdate('Y-m-d H:i:s', time() - 86400) : $lastSoldAt,
        ];
    }

    /**
     * [status, tier, risk, idle]
     */
    private function flags(array $row): array
    {
        return [$row['status'], $row['tier'], $row['risk'], $row['idle']];
    }

    /**
     * The 30 days up to and including today (UTC, as in createReportContext()).
     *
     * @return \DateTimeImmutable[]
     */
    private function last30Days(): array
    {
        $tomorrow = new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC'));

        return [$tomorrow->modify('-30 days'), $tomorrow];
    }
}
