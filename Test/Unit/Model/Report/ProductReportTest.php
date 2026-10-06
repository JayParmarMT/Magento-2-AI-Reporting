<?php
/**
 * Meetanshi AIReporting — ProductReport Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\ProductReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductReportTest extends TestCase
{
    use ReportContextTrait;

    private ProductReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->report     = new ProductReport($this->createReportContext($this->connection));
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllMetrics(): void
    {
        $this->connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['total_products' => '100', 'simple_products' => '80', 'configurable' => '15'],
            ['total_skus_sold' => '50', 'total_qty_sold' => '250'],
            ['total_products' => '100', 'simple_products' => '80', 'configurable' => '15'],
            ['total_skus_sold' => '50', 'total_qty_sold' => '250'],
            ['total_products' => '100', 'simple_products' => '80', 'configurable' => '15'],
            ['total_skus_sold' => '50', 'total_qty_sold' => '250'],
            ['total_products' => '100', 'simple_products' => '80', 'configurable' => '15'],
            ['total_skus_sold' => '50', 'total_qty_sold' => '250']
        );

        $result = $this->report->getKpiCards();

        $this->assertSame(100, $result['today']['total_products']);
        $this->assertSame(80, $result['today']['simple_products']);
        $this->assertSame(15, $result['today']['configurable']);
        $this->assertSame(50, $result['today']['total_skus_sold']);
        $this->assertSame(250, $result['today']['total_qty_sold']);
    }

    // ── best / worst sellers ─────────────────────────────────────────────

    public function testBestSellersUseTopLevelItemsWithNetRevenueAndQty(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [[
                'product_id' => '1', 'sku' => 'LAPTOP-001', 'product_name' => 'Laptop',
                'total_qty_sold' => '10', 'total_revenue' => '8999.90', 'avg_price' => '899.99', 'order_count' => '8',
            ]];
        });

        $result = $this->report->getBestSellersByRevenue();

        $this->assertSame('LAPTOP-001', $result[0]['sku']);
        $this->assertStringContainsString('oi.parent_item_id IS NULL', $captured);
        $this->assertStringContainsString('base_discount_amount', $captured);
        $this->assertStringContainsString('qty_refunded', $captured);
        $this->assertStringContainsString('ORDER BY total_revenue DESC', $captured);
    }

    public function testGetBestSellersByQtyOrdersByQuantity(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [['sku' => 'CABLE-001', 'total_qty_sold' => '50']];
        });

        $result = $this->report->getBestSellersByQty();

        $this->assertSame('50', $result[0]['total_qty_sold']);
        $this->assertStringContainsString('ORDER BY total_qty_sold DESC', $captured);
    }

    public function testGetWorstSellersReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->report->getWorstSellers());
    }

    // ── getRevenueByProductType ──────────────────────────────────────────

    public function testGetRevenueByProductTypeReturnsExpectedStructure(): void
    {
        $rows = [
            ['product_type' => 'simple', 'order_count' => '40', 'total_qty' => '100', 'total_revenue' => '15000.00'],
            ['product_type' => 'configurable', 'order_count' => '10', 'total_qty' => '20', 'total_revenue' => '5000.00'],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $this->assertSame($rows, $this->report->getRevenueByProductType());
    }

    // ── getTopProductsTrend ──────────────────────────────────────────────

    public function testGetTopProductsTrendReturnsEmptyWhenNoTopSkus(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->assertSame([], $this->report->getTopProductsTrend());
    }

    public function testGetTopProductsTrendQuotesSkusSafely(): void
    {
        $this->connection->method('fetchCol')->willReturn(["SKU'1", 'SKU-2']);
        $this->connection->method('quoteInto')->willReturn("oi.sku IN ('SKU\\'1', 'SKU-2')");
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [['sku' => 'SKU-2', 'month' => '2026-05', 'revenue' => '100.00']];
        });

        $result = $this->report->getTopProductsTrend();

        $this->assertSame('2026-05', $result[0]['month']);
        $this->assertStringContainsString("oi.sku IN ('SKU\\'1', 'SKU-2')", $captured);
    }

    // ── getProductsNeverSold ─────────────────────────────────────────────

    public function testGetProductsNeverSoldIgnoresCancelledOrders(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [['entity_id' => '99', 'sku' => 'NEVER-SOLD', 'type_id' => 'simple']];
        });

        $result = $this->report->getProductsNeverSold();

        $this->assertSame('NEVER-SOLD', $result[0]['sku']);
        $this->assertStringContainsString('NOT EXISTS', $captured);
        $this->assertStringContainsString("o.state NOT IN ('canceled','pending_payment')", $captured);
    }

    // ── getRevenueByCategory ─────────────────────────────────────────────

    public function testGetRevenueByCategoryExcludesRootsAndUsesLookedUpAttribute(): void
    {
        $this->connection->method('fetchOne')->willReturn('45');
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [['category_name' => 'Electronics', 'order_count' => '30', 'total_qty' => '80', 'total_revenue' => '25000.00']];
        });

        $result = $this->report->getRevenueByCategory();

        $this->assertSame('Electronics', $result[0]['category_name']);
        $this->assertStringContainsString('ce.level >= 2', $captured);
        $this->assertStringContainsString('cv.attribute_id = 45', $captured);
        $this->assertStringContainsString('cv.store_id = 0', $captured);
    }
}
