<?php
/**
 * Meetanshi AIReporting — CustomerReport Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\CustomerReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerReportTest extends TestCase
{
    private CustomerReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection   = $this->createMock(AdapterInterface::class);

        $resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        $this->report = new CustomerReport($resourceConnection);
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllMetrics(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('25', '2', '5', '8', '20');

        $result = $this->report->getKpiCards();

        $this->assertArrayHasKey('total_customers', $result);
        $this->assertArrayHasKey('new_today', $result);
        $this->assertArrayHasKey('new_this_week', $result);
        $this->assertArrayHasKey('new_this_month', $result);
        $this->assertArrayHasKey('customers_with_orders', $result);

        $this->assertSame('25', $result['total_customers']);
        $this->assertSame('2', $result['new_today']);
    }

    public function testGetKpiCardsHandlesZeroCustomers(): void
    {
        $this->connection->method('fetchOne')
            ->willReturn('0');

        $result = $this->report->getKpiCards();

        $this->assertSame('0', $result['total_customers']);
        $this->assertSame('0', $result['new_today']);
    }

    // ── getTopCustomersBySpend ────────────────────────────────────────────

    public function testGetTopCustomersBySpendReturnsExpectedStructure(): void
    {
        $rows = [
            [
                'customer_email'   => 'john.smith@aireporting-test.com',
                'customer_name'    => 'John Smith',
                'total_orders'     => '12',
                'lifetime_value'   => '5400.00',
                'avg_order_value'  => '450.00',
                'first_order_date' => '2025-06-15 10:30:00',
                'last_order_date'  => '2026-04-20 14:22:00',
            ],
            [
                'customer_email'   => 'sarah.johnson@aireporting-test.com',
                'customer_name'    => 'Sarah Johnson',
                'total_orders'     => '8',
                'lifetime_value'   => '3200.00',
                'avg_order_value'  => '400.00',
                'first_order_date' => '2025-08-01 09:15:00',
                'last_order_date'  => '2026-05-01 11:45:00',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getTopCustomersBySpend(20);

        $this->assertCount(2, $result);
        $this->assertSame('John Smith', $result[0]['customer_name']);
        $this->assertSame('5400.00', $result[0]['lifetime_value']);
        $this->assertArrayHasKey('first_order_date', $result[0]);
        $this->assertArrayHasKey('last_order_date', $result[0]);
    }

    public function testGetTopCustomersBySpendReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([]);

        $result = $this->report->getTopCustomersBySpend();
        $this->assertSame([], $result);
    }

    // ── getRfmSegmentation ───────────────────────────────────────────────

    public function testGetRfmSegmentationReturnsScores(): void
    {
        $rows = [
            [
                'customer_email' => 'john@test.com',
                'customer_name'  => 'John Smith',
                'recency_days'   => '15',
                'frequency'      => '10',
                'monetary'       => '5000.00',
                'r_score'        => '5',
                'f_score'        => '5',
                'm_score'        => '5',
            ],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRfmSegmentation();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('r_score', $result[0]);
        $this->assertArrayHasKey('f_score', $result[0]);
        $this->assertArrayHasKey('m_score', $result[0]);
        $this->assertSame('5', $result[0]['r_score']);
    }

    // ── getNewVsReturning ────────────────────────────────────────────────

    public function testGetNewVsReturningReturnsMonthlyBreakdown(): void
    {
        $rows = [
            ['month' => '2026-01', 'label' => 'Jan 2026', 'new_customers' => '5', 'returning_customers' => '10'],
            ['month' => '2026-02', 'label' => 'Feb 2026', 'new_customers' => '3', 'returning_customers' => '12'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getNewVsReturning();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('new_customers', $result[0]);
        $this->assertArrayHasKey('returning_customers', $result[0]);
    }

    // ── getCustomerAcquisition ───────────────────────────────────────────

    public function testGetCustomerAcquisitionReturnsMonthlyData(): void
    {
        $rows = [
            ['month' => '2026-01', 'label' => 'Jan 2026', 'new_customers' => '8'],
            ['month' => '2026-02', 'label' => 'Feb 2026', 'new_customers' => '12'],
            ['month' => '2026-03', 'label' => 'Mar 2026', 'new_customers' => '6'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getCustomerAcquisition();

        $this->assertCount(3, $result);
        $this->assertSame('8', $result[0]['new_customers']);
    }

    // ── getClvDistribution ───────────────────────────────────────────────

    public function testGetClvDistributionReturnsRanges(): void
    {
        $rows = [
            ['clv_range' => '$0-$99',     'customer_count' => '10'],
            ['clv_range' => '$100-$249',   'customer_count' => '8'],
            ['clv_range' => '$250-$499',   'customer_count' => '5'],
            ['clv_range' => '$500-$999',   'customer_count' => '3'],
            ['clv_range' => '$1000+',      'customer_count' => '2'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getClvDistribution();

        $this->assertCount(5, $result);
        $ranges = array_column($result, 'clv_range');
        $this->assertContains('$0-$99', $ranges);
        $this->assertContains('$1000+', $ranges);
    }

    // ── getRepeatPurchaseRate ────────────────────────────────────────────

    public function testGetRepeatPurchaseRateReturnsAllMetrics(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('20', '8');

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertArrayHasKey('total_customers', $result);
        $this->assertArrayHasKey('repeat_customers', $result);
        $this->assertArrayHasKey('one_time_customers', $result);
        $this->assertArrayHasKey('repeat_rate', $result);

        $this->assertSame(20, $result['total_customers']);
        $this->assertSame(8, $result['repeat_customers']);
        $this->assertSame(12, $result['one_time_customers']);
        $this->assertSame(40.0, $result['repeat_rate']);
    }

    public function testGetRepeatPurchaseRateHandlesZeroCustomers(): void
    {
        $this->connection->method('fetchOne')
            ->willReturn('0');

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(0, $result['total_customers']);
        $this->assertSame(0.0, $result['repeat_rate']);
    }

    public function testGetRepeatPurchaseRateCalculatesCorrectPercentage(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('100', '33');

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(33.0, $result['repeat_rate']);
        $this->assertSame(67, $result['one_time_customers']);
    }
}
