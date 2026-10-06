<?php
/**
 * Meetanshi AIReporting — ReportContext Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use PHPUnit\Framework\TestCase;

class ReportContextTest extends TestCase
{
    use ReportContextTrait;

    private ReportContext $context;

    protected function setUp(): void
    {
        $this->context = $this->createReportContext($this->createMock(AdapterInterface::class), 'America/Chicago');
    }

    public function testLocalTimeExprHandlesDaylightSavingTransitions(): void
    {
        $tz   = new \DateTimeZone('America/Chicago');
        $expr = $this->context->localTimeExpr(
            'o.created_at',
            new \DateTimeImmutable('2026-01-01', $tz),
            new \DateTimeImmutable('2026-12-31', $tz)
        );

        $this->assertSame(
            "CASE WHEN o.created_at < '2026-03-08 08:00:00' THEN (o.created_at + INTERVAL -21600 SECOND)"
            . " WHEN o.created_at < '2026-11-01 07:00:00' THEN (o.created_at + INTERVAL -18000 SECOND)"
            . ' ELSE (o.created_at + INTERVAL -21600 SECOND) END',
            $expr
        );
    }

    public function testLocalTimeExprWithoutTransitionIsSimpleShift(): void
    {
        $tz = new \DateTimeZone('America/Chicago');

        $this->assertSame(
            '(o.created_at + INTERVAL -18000 SECOND)',
            $this->context->localTimeExpr(
                'o.created_at',
                new \DateTimeImmutable('2026-06-01', $tz),
                new \DateTimeImmutable('2026-07-01', $tz)
            )
        );
    }

    public function testUtcRangeConvertsLocalMidnight(): void
    {
        $tz = new \DateTimeZone('America/Chicago');

        $this->assertSame(
            ['2026-07-01 05:00:00', '2026-07-02 05:00:00'],
            $this->context->utcRange(new \DateTimeImmutable('2026-07-01', $tz), new \DateTimeImmutable('2026-07-02', $tz))
        );
    }

    public function testParseLocalDateRejectsInvalidInput(): void
    {
        $this->assertNull($this->context->parseLocalDate('garbage'));
        $this->assertNull($this->context->parseLocalDate('2026-13-45'));
        $this->assertNull($this->context->parseLocalDate(null));
        $this->assertSame('2026-02-28 00:00:00', $this->context->parseLocalDate('2026-02-28')->format('Y-m-d H:i:s'));
    }

    public function testValidOrderConditionExcludesCancelledAndUnpaid(): void
    {
        $this->assertSame("so.state NOT IN ('canceled','pending_payment')", $this->context->validOrderCondition('so'));
    }

    public function testRevenueExpressionsUseBaseCurrencyNetOfRefunds(): void
    {
        $order = $this->context->orderRevenueExpr();
        $this->assertStringContainsString('o.base_grand_total - IFNULL(o.base_total_refunded, 0)', $order);
        $this->assertStringContainsString('o.base_to_global_rate', $order);

        $item = $this->context->itemRevenueExpr();
        $this->assertStringContainsString('oi.base_row_total', $item);
        $this->assertStringContainsString('oi.base_discount_amount', $item);
        $this->assertStringContainsString('oi.base_amount_refunded', $item);
    }

    public function testFillMonthlyZeroFillsMissingMonths(): void
    {
        $tz     = new \DateTimeZone('UTC');
        $result = $this->context->fillMonthly(
            [['month' => '2026-02', 'orders' => '4']],
            new \DateTimeImmutable('2026-01-01', $tz),
            new \DateTimeImmutable('2026-03-15', $tz),
            ['orders' => 0]
        );

        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($result, 'month'));
        $this->assertSame([0, '4', 0], array_column($result, 'orders'));
        $this->assertSame('Feb 2026', $result[1]['label']);
    }

    public function testFillDailyUsesCustomKey(): void
    {
        $tz     = new \DateTimeZone('UTC');
        $result = $this->context->fillDaily(
            [['day' => '2026-05-02', 'orders' => '1']],
            new \DateTimeImmutable('2026-05-01', $tz),
            new \DateTimeImmutable('2026-05-04', $tz),
            ['orders' => 0],
            'day'
        );

        $this->assertSame(['2026-05-01', '2026-05-02', '2026-05-03'], array_column($result, 'day'));
        $this->assertSame('02 May', $result[1]['label']);
    }

    public function testResolveTablesReplacesTokens(): void
    {
        $this->assertSame(
            'SELECT * FROM sales_order o JOIN sales_order_item oi',
            $this->context->resolveTables('SELECT * FROM {{sales_order}} o JOIN {{sales_order_item}} oi')
        );
    }

    public function testFormatPriceUsesCurrency(): void
    {
        $this->assertSame('$1,234.50', $this->context->formatPrice(1234.5));
        $this->assertSame('USD', $this->context->getCurrencyCode());
    }
}
