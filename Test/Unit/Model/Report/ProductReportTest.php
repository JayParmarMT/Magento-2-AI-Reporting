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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\ProductReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductReportTest extends TestCase
{
    private ProductReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection   = $this->createMock(AdapterInterface::class);

        $resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        $this->report = new ProductReport($resourceConnection);
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllMetrics(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('100', '80', '15', '50', '250');

        $result = $this->report->getKpiCards();

        $this->assertArrayHasKey('total_products', $result);
        $this->assertArrayHasKey('simple_products', $result);
        $this->assertArrayHasKey('configurable', $result);
        $this->assertArrayHasKey('total_skus_sold', $result);
        $this->assertArrayHasKey('total_qty_sold', $result);

        $this->assertSame('100', $result['total_products']);
        $this->assertSame('80', $result['simple_products']);
    }

    // ── getBestSellersByRevenue ───────────────────────────────────────────

    public function testGetBestSellersByRevenueReturnsExpectedStructure(): void
    {
        $rows = [
            [
                'product_id'     => '1',
                'sku'            => 'TEST-LAPTOP-001',
                'product_name'   => 'ProBook Laptop 15"',
                'total_qty_sold' => '25',
                'total_revenue'  => '22499.75',
                'avg_price'      => '899.99',
                'order_count'    => '20',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getBestSellersByRevenue(20);

        $this->assertCount(1, $result);
        $this->assertSame('TEST-LAPTOP-001', $result[0]['sku']);
        $this->assertArrayHasKey('total_revenue', $result[0]);
        $this->assertArrayHasKey('avg_price', $result[0]);
    }

    // ── getBestSellersByQty ──────────────────────────────────────────────

    public function testGetBestSellersByQtyReturnsExpectedStructure(): void
    {
        $rows = [
            [
                'product_id'     => '10',
                'sku'            => 'TEST-CHARGER-001',
                'product_name'   => 'USB-C Fast Charger 65W',
                'total_qty_sold' => '50',
                'total_revenue'  => '1999.50',
                'order_count'    => '40',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getBestSellersByQty(20);

        $this->assertCount(1, $result);
        $this->assertSame('50', $result[0]['total_qty_sold']);
    }

    // ── getWorstSellers ──────────────────────────────────────────────────

    public function testGetWorstSellersReturnsExpectedStructure(): void
    {
        $rows = [
            ['sku' => 'TEST-CAMERA-001', 'product_name' => 'Mirrorless Camera Kit', 'total_qty_sold' => '1', 'total_revenue' => '1299.99'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getWorstSellers(10);

        $this->assertCount(1, $result);
        $this->assertSame('1', $result[0]['total_qty_sold']);
    }

    public function testGetWorstSellersReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([]);

        $result = $this->report->getWorstSellers();
        $this->assertSame([], $result);
    }

    // ── getRevenueByProductType ──────────────────────────────────────────

    public function testGetRevenueByProductTypeReturnsExpectedStructure(): void
    {
        $rows = [
            ['product_type' => 'simple',       'order_count' => '40', 'total_qty' => '100', 'total_revenue' => '15000.00'],
            ['product_type' => 'configurable', 'order_count' => '10', 'total_qty' => '20',  'total_revenue' => '5000.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRevenueByProductType();

        $this->assertCount(2, $result);
        $types = array_column($result, 'product_type');
        $this->assertContains('simple', $types);
    }

    // ── getTopProductsTrend ──────────────────────────────────────────────

    public function testGetTopProductsTrendReturnsEmptyWhenNoTopSkus(): void
    {
        $this->connection->method('fetchCol')
            ->willReturn([]);

        $result = $this->report->getTopProductsTrend();
        $this->assertSame([], $result);
    }

    public function testGetTopProductsTrendReturnsMonthlyData(): void
    {
        $this->connection->method('fetchCol')
            ->willReturn(['TEST-LAPTOP-001', 'TEST-PHONE-001']);

        $rows = [
            ['sku' => 'TEST-LAPTOP-001', 'product_name' => 'ProBook Laptop', 'month' => '2026-01', 'label' => 'Jan 2026', 'qty_sold' => '5', 'revenue' => '4499.95'],
            ['sku' => 'TEST-PHONE-001',  'product_name' => 'SmartPhone X',   'month' => '2026-01', 'label' => 'Jan 2026', 'qty_sold' => '8', 'revenue' => '5599.92'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getTopProductsTrend();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('month', $result[0]);
        $this->assertArrayHasKey('revenue', $result[0]);
    }

    // ── getProductsNeverSold ─────────────────────────────────────────────

    public function testGetProductsNeverSoldReturnsExpectedStructure(): void
    {
        $rows = [
            ['entity_id' => '99', 'sku' => 'UNSOLD-001', 'type_id' => 'simple'],
            ['entity_id' => '98', 'sku' => 'UNSOLD-002', 'type_id' => 'simple'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getProductsNeverSold(20);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('sku', $result[0]);
        $this->assertArrayHasKey('type_id', $result[0]);
    }

    // ── getRevenueByCategory ─────────────────────────────────────────────

    public function testGetRevenueByCategoryReturnsExpectedStructure(): void
    {
        $rows = [
            ['category_name' => 'Electronics', 'order_count' => '30', 'total_qty' => '50', 'total_revenue' => '25000.00'],
            ['category_name' => 'Accessories', 'order_count' => '20', 'total_qty' => '40', 'total_revenue' => '3000.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRevenueByCategory(15);

        $this->assertCount(2, $result);
        $this->assertSame('Electronics', $result[0]['category_name']);
    }
}
