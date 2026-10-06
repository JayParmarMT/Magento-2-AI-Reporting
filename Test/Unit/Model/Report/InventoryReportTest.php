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
}
