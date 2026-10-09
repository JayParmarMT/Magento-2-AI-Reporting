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

    // ── Range-based figures ──────────────────────────────────────────────

    public function testGetCatalogSummary(): void
    {
        $this->connection->method('fetchRow')->willReturn(['total' => '46', 'enabled' => '44', 'created' => '3']);

        $result = $this->report->getCatalogSummary(...$this->range());

        $this->assertSame(['total' => 46, 'enabled' => 44, 'disabled' => 2, 'created' => 3], $result);
    }

    public function testGetSalesTotalsComputesSellThroughAndBasket(): void
    {
        $sqls = [];
        $this->connection->method('fetchRow')->willReturnCallback(function (string $sql) use (&$sqls) {
            $sqls[] = $sql;
            return count($sqls) === 1
                ? ['units' => '206', 'revenue' => '6417.2', 'orders' => '72']
                : ['sellable' => '45', 'sold' => '37'];
        });

        $result = $this->report->getSalesTotals(...$this->range());

        $this->assertSame(2.86, $result['avg_basket']);
        $this->assertSame(8, $result['idle_products']);
        $this->assertSame(82.2, $result['sell_through']);
        // Sold = any line of a valid order (children included); grouped containers are not sellable
        $this->assertStringContainsString("e.type_id <> 'grouped'", $sqls[1]);
        $this->assertStringNotContainsString('soi.parent_item_id', $sqls[1]);
    }

    public function testRateVelocity(): void
    {
        $rows = [
            ['qty_sold' => 50.0, 'stock_qty' => 500.0],
            ['qty_sold' => 30.0, 'stock_qty' => null],
            ['qty_sold' => 20.0, 'stock_qty' => 5.0],
            ['qty_sold' => 10.0, 'stock_qty' => 0.0],
            ['qty_sold' => 1.0, 'stock_qty' => 100.0],
        ];

        $result = $this->report->rateVelocity($rows, 10);

        $this->assertSame(['high', 'medium', 'restock', 'restock', 'low'], array_column($result, 'velocity'));
        $this->assertSame(100.0, $result[0]['days_of_cover']);
        $this->assertNull($result[1]['days_of_cover']);
        $this->assertSame(2.5, $result[2]['days_of_cover']);
    }

    public function testGetPriceBandsFillsEveryBand(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['band' => '2', 'units' => '123', 'revenue' => '4226.2', 'products' => '19'],
        ]);

        $result = $this->report->getPriceBands(...$this->range());

        $this->assertCount(5, $result);
        $this->assertSame(['min' => 25, 'max' => 50, 'units' => 123.0, 'revenue' => 4226.2, 'products' => 19], $result[1]);
        $this->assertNull($result[0]['min']);
        $this->assertNull($result[4]['max']);
        $this->assertSame(0.0, $result[0]['units']);
    }

    public function testGetProductPerformanceDecodesNamesAndCastsValues(): void
    {
        $this->connection->method('fetchAll')->willReturn([[
            'product_id' => '18', 'sku' => '24-UG02', 'product_name' => 'Pursuit Lumaflex&trade; Tone Band',
            'product_type' => 'simple', 'qty_sold' => '3.0000', 'revenue' => '48.00', 'avg_price' => '16.00',
            'order_count' => '2', 'cost' => null,
        ]]);

        $row = $this->report->getProductPerformance(...$this->range())[0];

        $this->assertSame('Pursuit Lumaflex™ Tone Band', $row['product_name']);
        $this->assertSame(3.0, $row['qty_sold']);
        $this->assertNull($row['cost']);
    }

    public function testProductPerformanceCostOnlyWhenEveryLineHasOne(): void
    {
        $sql = $this->report->getProductPerformanceSql();

        $this->assertStringContainsString('CASE WHEN SUM(IFNULL(oi.base_cost, 0) > 0) = COUNT(*)', $sql);
        $this->assertStringContainsString('oi.parent_item_id IS NULL', $sql);
        $this->assertStringNotContainsString('LIMIT', $sql);
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function range(): array
    {
        $tz = new \DateTimeZone('UTC');

        return [new \DateTimeImmutable('2026-01-01', $tz), new \DateTimeImmutable('2026-10-09', $tz)];
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
