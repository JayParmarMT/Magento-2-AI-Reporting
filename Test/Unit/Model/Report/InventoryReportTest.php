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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\InventoryReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InventoryReportTest extends TestCase
{
    private InventoryReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection   = $this->createMock(AdapterInterface::class);

        $resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        $this->report = new InventoryReport($resourceConnection);
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllMetrics(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('100', '85', '10', '5', '125000.00');

        $result = $this->report->getKpiCards();

        $this->assertArrayHasKey('total_skus', $result);
        $this->assertArrayHasKey('in_stock', $result);
        $this->assertArrayHasKey('out_of_stock', $result);
        $this->assertArrayHasKey('low_stock', $result);
        $this->assertArrayHasKey('total_stock_value', $result);

        $this->assertSame('100', $result['total_skus']);
        $this->assertSame('85', $result['in_stock']);
        $this->assertSame('10', $result['out_of_stock']);
    }

    // ── getLowStockProducts ──────────────────────────────────────────────

    public function testGetLowStockProductsReturnsExpectedStructure(): void
    {
        $rows = [
            [
                'entity_id'    => '5',
                'sku'          => 'TEST-MOUSE-001',
                'product_name' => 'Ergonomic Wireless Mouse',
                'qty'          => '3',
                'min_qty'      => '0',
                'is_in_stock'  => '1',
                'price'        => '79.99',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getLowStockProducts(10, 30);

        $this->assertCount(1, $result);
        $this->assertSame('3', $result[0]['qty']);
        $this->assertArrayHasKey('product_name', $result[0]);
        $this->assertArrayHasKey('price', $result[0]);
    }

    public function testGetLowStockProductsReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([]);

        $result = $this->report->getLowStockProducts();
        $this->assertSame([], $result);
    }

    // ── getOutOfStockProducts ────────────────────────────────────────────

    public function testGetOutOfStockProductsReturnsExpectedStructure(): void
    {
        $rows = [
            [
                'entity_id'    => '12',
                'sku'          => 'TEST-CAMERA-001',
                'product_name' => 'Mirrorless Camera Kit',
                'qty'          => '0',
                'price'        => '1299.99',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getOutOfStockProducts(30);

        $this->assertCount(1, $result);
        $this->assertSame('0', $result[0]['qty']);
    }

    // ── getStockDistribution ─────────────────────────────────────────────

    public function testGetStockDistributionReturnsAllRanges(): void
    {
        $rows = [
            ['stock_range' => 'Out of Stock',    'product_count' => '10'],
            ['stock_range' => '1-5 (Critical)',   'product_count' => '5'],
            ['stock_range' => '6-10 (Low)',       'product_count' => '8'],
            ['stock_range' => '11-50 (Medium)',   'product_count' => '30'],
            ['stock_range' => '51-100 (Good)',    'product_count' => '25'],
            ['stock_range' => '100+ (High)',      'product_count' => '22'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getStockDistribution();

        $this->assertCount(6, $result);
        $ranges = array_column($result, 'stock_range');
        $this->assertContains('Out of Stock', $ranges);
        $this->assertContains('100+ (High)', $ranges);
    }

    // ── getDemandVsSupply ────────────────────────────────────────────────

    public function testGetDemandVsSupplyReturnsExpectedStructure(): void
    {
        $rows = [
            [
                'sku'           => 'TEST-PHONE-001',
                'product_name'  => 'SmartPhone X Pro',
                'qty_sold_30d'  => '20',
                'current_stock' => '5',
                'is_in_stock'   => '1',
                'stock_status'  => 'CRITICAL',
            ],
            [
                'sku'           => 'TEST-CABLE-001',
                'product_name'  => 'Braided USB-C Cable 2m',
                'qty_sold_30d'  => '10',
                'current_stock' => '100',
                'is_in_stock'   => '1',
                'stock_status'  => 'OK',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getDemandVsSupply(15);

        $this->assertCount(2, $result);
        $this->assertSame('CRITICAL', $result[0]['stock_status']);
        $this->assertSame('OK', $result[1]['stock_status']);
    }

    // ── getInventoryTurnover ─────────────────────────────────────────────

    public function testGetInventoryTurnoverReturnsExpectedStructure(): void
    {
        $rows = [
            [
                'sku'           => 'TEST-CHARGER-001',
                'product_name'  => 'USB-C Fast Charger 65W',
                'qty_sold'      => '30',
                'current_stock' => '10',
                'turnover_rate' => '3.00',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getInventoryTurnover(20);

        $this->assertCount(1, $result);
        $this->assertSame('3.00', $result[0]['turnover_rate']);
        $this->assertArrayHasKey('qty_sold', $result[0]);
        $this->assertArrayHasKey('current_stock', $result[0]);
    }

    public function testGetInventoryTurnoverReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([]);

        $result = $this->report->getInventoryTurnover();
        $this->assertSame([], $result);
    }
}
