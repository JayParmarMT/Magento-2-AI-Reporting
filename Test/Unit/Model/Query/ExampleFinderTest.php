<?php
/**
 * Meetanshi AIReporting — ExampleFinder Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Query;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Meetanshi\AIReporting\Model\Query\ExampleFinder;
use PHPUnit\Framework\TestCase;

class ExampleFinderTest extends TestCase
{
    private function finder(array $savedReports, string $prefix = ''): ExampleFinder
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($savedReports);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(static fn (string $table) => $prefix . $table);

        return new ExampleFinder($resourceConnection);
    }

    public function testMostSimilarSavedReportsComeFirst(): void
    {
        $finder = $this->finder([
            ['nlp_query' => 'Top 5 customers by total spend', 'sql_query' => 'SELECT customers'],
            ['nlp_query' => 'Top 10 best selling products', 'sql_query' => 'SELECT best_sellers'],
            ['nlp_query' => 'Low stock products', 'sql_query' => 'SELECT stock'],
        ]);

        $found = $finder->find('Top 10 best selling products this month');

        $this->assertSame('SELECT best_sellers', $found[0]['sql']);
        $this->assertNotContains('SELECT customers', array_column($found, 'sql'));
    }

    public function testUnrelatedQuestionsGetNoExamples(): void
    {
        $finder = $this->finder([['nlp_query' => 'Top 10 best selling products', 'sql_query' => 'SELECT 1']]);

        $this->assertSame([], $finder->find('Which cron jobs failed yesterday?'));
    }

    public function testDuplicateSqlIsListedOnce(): void
    {
        $finder = $this->finder([
            ['nlp_query' => 'Best selling products', 'sql_query' => 'SELECT same'],
            ['nlp_query' => 'Best selling products list', 'sql_query' => 'SELECT same'],
        ]);

        $this->assertCount(1, $finder->find('best selling products'));
    }

    public function testTablePrefixIsRemovedFromSavedSql(): void
    {
        $finder = $this->finder(
            [['nlp_query' => 'Orders today', 'sql_query' => 'SELECT o.entity_id FROM m2_sales_order o JOIN `m2_store` s ON 1']],
            'm2_'
        );

        $this->assertSame(
            'SELECT o.entity_id FROM sales_order o JOIN `store` s ON 1',
            $finder->find('orders today')[0]['sql']
        );
    }
}
