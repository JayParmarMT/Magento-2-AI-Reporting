<?php
/**
 * Meetanshi AIReporting — SavedReport Model Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model;

use Meetanshi\AIReporting\Model\SavedReport;
use PHPUnit\Framework\TestCase;

class SavedReportTest extends TestCase
{
    private SavedReport $model;

    protected function setUp(): void
    {
        // Test the getters on a model built with mocked framework dependencies
        $this->model = new class (
            $this->createMock(\Magento\Framework\Model\Context::class),
            $this->createMock(\Magento\Framework\Registry::class)
        ) extends SavedReport {
            // Override _construct to avoid ResourceModel dependency in unit tests
            protected function _construct(): void
            {
                // no-op for unit testing
            }
        };
    }

    // ── getTitle ─────────────────────────────────────────────────────────

    public function testGetTitleReturnsString(): void
    {
        $this->model->setData('title', 'Monthly Revenue Report');
        $this->assertSame('Monthly Revenue Report', $this->model->getTitle());
    }

    public function testGetTitleReturnsEmptyStringWhenNull(): void
    {
        $this->model->setData('title', null);
        $this->assertSame('', $this->model->getTitle());
    }

    // ── getNlpQuery ──────────────────────────────────────────────────────

    public function testGetNlpQueryReturnsString(): void
    {
        $this->model->setData('nlp_query', 'Show me top customers');
        $this->assertSame('Show me top customers', $this->model->getNlpQuery());
    }

    public function testGetNlpQueryReturnsEmptyStringWhenNull(): void
    {
        $this->model->setData('nlp_query', null);
        $this->assertSame('', $this->model->getNlpQuery());
    }

    // ── getSqlQuery ──────────────────────────────────────────────────────

    public function testGetSqlQueryReturnsString(): void
    {
        $sql = "SELECT customer_email, SUM(grand_total) FROM sales_order GROUP BY customer_email";
        $this->model->setData('sql_query', $sql);
        $this->assertSame($sql, $this->model->getSqlQuery());
    }

    // ── getChartType ─────────────────────────────────────────────────────

    public function testGetChartTypeReturnsConfiguredType(): void
    {
        $this->model->setData('chart_type', 'bar');
        $this->assertSame('bar', $this->model->getChartType());
    }

    public function testGetChartTypeDefaultsToTable(): void
    {
        $this->model->setData('chart_type', null);
        $this->assertSame('table', $this->model->getChartType());
    }

    public function testGetChartTypeDefaultsToTableWhenEmpty(): void
    {
        $this->model->setData('chart_type', '');
        $this->assertSame('table', $this->model->getChartType());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validChartTypeProvider')]
    public function testGetChartTypeAcceptsAllValidTypes(string $type): void
    {
        $this->model->setData('chart_type', $type);
        $this->assertSame($type, $this->model->getChartType());
    }

    public static function validChartTypeProvider(): array
    {
        return [
            'table' => ['table'],
            'line'  => ['line'],
            'bar'   => ['bar'],
            'pie'   => ['pie'],
            'area'  => ['area'],
        ];
    }

    // ── getChartConfig ───────────────────────────────────────────────────

    public function testGetChartConfigParsesJsonString(): void
    {
        $config = ['xAxis' => 'month', 'yAxis' => 'revenue'];
        $this->model->setData('chart_config', json_encode($config));

        $this->assertSame($config, $this->model->getChartConfig());
    }

    public function testGetChartConfigReturnsArrayWhenAlreadyArray(): void
    {
        $config = ['label' => 'status', 'value' => 'count'];
        $this->model->setData('chart_config', $config);

        $this->assertSame($config, $this->model->getChartConfig());
    }

    public function testGetChartConfigReturnsEmptyArrayForNull(): void
    {
        $this->model->setData('chart_config', null);
        $this->assertSame([], $this->model->getChartConfig());
    }

    public function testGetChartConfigReturnsEmptyArrayForEmptyString(): void
    {
        $this->model->setData('chart_config', '');
        $this->assertSame([], $this->model->getChartConfig());
    }

    public function testGetChartConfigReturnsEmptyArrayForInvalidJson(): void
    {
        $this->model->setData('chart_config', '{invalid json}');
        $this->assertSame([], $this->model->getChartConfig());
    }

    // ── getAdminUserId ───────────────────────────────────────────────────

    public function testGetAdminUserIdReturnsInt(): void
    {
        $this->model->setData('admin_user_id', '5');
        $this->assertSame(5, $this->model->getAdminUserId());
    }

    public function testGetAdminUserIdReturnsZeroWhenNull(): void
    {
        $this->model->setData('admin_user_id', null);
        $this->assertSame(0, $this->model->getAdminUserId());
    }
}
