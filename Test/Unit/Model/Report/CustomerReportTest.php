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

use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\CustomerReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerReportTest extends TestCase
{
    use ReportContextTrait;

    private CustomerReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->report     = new CustomerReport($this->createReportContext($this->connection, 'America/Chicago'));
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllMetrics(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                // today: new, total, with_orders
                '2', '25', '20',
                // week: new, total, with_orders
                '5', '25', '20',
                // month: new, total, with_orders
                '8', '25', '20',
                // year: new, total, with_orders
                '15', '25', '20'
            );

        $result = $this->report->getKpiCards();

        $this->assertSame(25, $result['today']['total_customers']);
        $this->assertSame(2, $result['today']['new_customers']);
        $this->assertSame(20, $result['today']['customers_with_orders']);
    }

    public function testNewCustomerCountsUseStoreTimezoneDayBoundaries(): void
    {
        $binds = [];
        $this->connection->method('fetchOne')->willReturnCallback(
            function (string $sql, array $bind = []) use (&$binds) {
                $binds[] = $bind;
                return '0';
            }
        );

        $this->report->getKpiCards();

        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Chicago'));
        $expectedFrom = $today->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // $binds[0] is "new today": local midnight converted to UTC (05:00 or 06:00 depending on DST)
        $this->assertSame($expectedFrom, $binds[0][0]);
        $this->assertStringNotContainsString(' 00:00:00', $binds[0][0]);
    }

    // ── getTopCustomersBySpend ───────────────────────────────────────────

    public function testGetTopCustomersBySpendConvertsDatesToStoreTimezone(): void
    {
        $this->connection->method('fetchAll')->willReturn([[
            'customer_email'   => 'john@example.com',
            'customer_name'    => 'John Smith',
            'total_orders'     => '12',
            'lifetime_value'   => '5400.00',
            'avg_order_value'  => '450.00',
            'first_order_date' => '2026-01-15 03:00:00',
            'last_order_date'  => '2026-07-01 12:00:00',
        ]]);

        $result = $this->report->getTopCustomersBySpend();

        $this->assertSame('John Smith', $result[0]['customer_name']);
        $this->assertSame('5400.00', $result[0]['lifetime_value']);
        $this->assertSame('2026-01-14 21:00:00', $result[0]['first_order_date']); // CST, UTC-6
        $this->assertSame('2026-07-01 07:00:00', $result[0]['last_order_date']);  // CDT, UTC-5
    }

    public function testGetTopCustomersBySpendReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->report->getTopCustomersBySpend());
    }

    public function testGetTopCustomersGroupsByEmailOnly(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [];
        });

        $this->report->getTopCustomersBySpend(5);

        $this->assertStringContainsString('GROUP BY o.customer_email', $captured);
        $this->assertStringContainsString('LIMIT 5', $captured);
    }

    // ── getRfmSegmentation ───────────────────────────────────────────────

    public function testGetRfmSegmentationReturnsScores(): void
    {
        $this->connection->method('fetchAll')->willReturn([[
            'customer_email' => 'john@example.com', 'customer_name' => 'John Smith',
            'recency_days' => '5', 'frequency' => '12', 'monetary' => '5400.00',
            'r_score' => '5', 'f_score' => '5', 'm_score' => '5',
        ]]);

        $result = $this->report->getRfmSegmentation();

        $this->assertSame('5', $result[0]['r_score']);
        $this->assertArrayHasKey('f_score', $result[0]);
        $this->assertArrayHasKey('m_score', $result[0]);
    }

    // ── getNewVsReturning ────────────────────────────────────────────────

    public function testGetNewVsReturningReturnsTwelveMonths(): void
    {
        $month = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('America/Chicago')))->format('Y-m');
        $this->connection->method('fetchAll')->willReturn([
            ['month' => $month, 'new_customers' => '5', 'returning_customers' => '3'],
        ]);

        $result = $this->report->getNewVsReturning();

        $this->assertCount(12, $result);
        $this->assertSame('5', $result[11]['new_customers']);
        $this->assertSame('3', $result[11]['returning_customers']);
        $this->assertSame(0, $result[0]['new_customers']);
    }

    // ── getCustomerAcquisition ───────────────────────────────────────────

    public function testGetCustomerAcquisitionReturnsTwelveMonths(): void
    {
        $month = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('America/Chicago')))->format('Y-m');
        $this->connection->method('fetchAll')->willReturn([
            ['month' => $month, 'new_customers' => '8'],
        ]);

        $result = $this->report->getCustomerAcquisition();

        $this->assertCount(12, $result);
        $this->assertSame('8', $result[11]['new_customers']);
    }

    // ── getClvDistribution ───────────────────────────────────────────────

    public function testGetClvDistributionReturnsAllRangesWithCurrencyLabels(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['bucket' => '1', 'customer_count' => '10'],
            ['bucket' => '5', 'customer_count' => '2'],
        ]);

        $result = $this->report->getClvDistribution();

        $this->assertSame(
            ['$0-$99', '$100-$249', '$250-$499', '$500-$999', '$1000+'],
            array_column($result, 'clv_range')
        );
        $this->assertSame([10, 0, 0, 0, 2], array_column($result, 'customer_count'));
    }

    // ── getRepeatPurchaseRate ────────────────────────────────────────────

    public function testGetRepeatPurchaseRateReturnsAllMetrics(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['total_customers' => '20', 'repeat_customers' => '8']);

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(20, $result['total_customers']);
        $this->assertSame(8, $result['repeat_customers']);
        $this->assertSame(12, $result['one_time_customers']);
        $this->assertSame(40.0, $result['repeat_rate']);
    }

    public function testGetRepeatPurchaseRateHandlesZeroCustomers(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['total_customers' => '0', 'repeat_customers' => '0']);

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(0, $result['total_customers']);
        $this->assertSame(0.0, $result['repeat_rate']);
    }

    public function testGetRepeatPurchaseRateCalculatesCorrectPercentage(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['total_customers' => '100', 'repeat_customers' => '33']);

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(33.0, $result['repeat_rate']);
        $this->assertSame(67, $result['one_time_customers']);
    }
}
