<?php
/**
 * Meetanshi AIReporting — Query Execute Controller Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Controller\Adminhtml\Query;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\User\Model\User;
use Meetanshi\AIReporting\Controller\Adminhtml\Query\Execute;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\Query\QueryRunner;
use Meetanshi\AIReporting\Model\Query\QueryTokenStorage;
use Meetanshi\AIReporting\Model\QueryLogFactory;
use Meetanshi\AIReporting\Model\ResourceModel\QueryLog as QueryLogResource;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Meetanshi\AIReporting\Model\SavedReport;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExecuteTest extends TestCase
{
    private const ADMIN_ID = 7;

    private Execute $controller;
    private Json|MockObject $resultJson;
    private QueryRunner|MockObject $queryRunner;
    private QueryExecutor|MockObject $queryExecutor;
    private Config|MockObject $config;
    private Http|MockObject $request;
    private SavedReport|MockObject $savedReport;
    private QueryTokenStorage|MockObject $tokenStorage;
    private array $params = [];
    private ?array $response = null;

    protected function setUp(): void
    {
        $jsonFactory         = $this->createMock(JsonFactory::class);
        $this->resultJson    = $this->createMock(Json::class);
        $this->queryRunner   = $this->createMock(QueryRunner::class);
        $this->queryExecutor = $this->createMock(QueryExecutor::class);
        $this->config        = $this->createMock(Config::class);
        $this->tokenStorage  = $this->createMock(QueryTokenStorage::class);
        $this->savedReport   = $this->createMock(SavedReport::class);
        $this->request       = $this->createMock(Http::class);

        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );
        $jsonFactory->method('create')->willReturn($this->resultJson);
        $this->resultJson->method('setData')->willReturnCallback(function (array $data) {
            $this->response = $data;
            return $this->resultJson;
        });

        $savedReportFactory = $this->createMock(SavedReportFactory::class);
        $savedReportFactory->method('create')->willReturn($this->savedReport);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(self::ADMIN_ID);
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getAuth')->willReturn($auth);

        $this->controller = new Execute(
            $context,
            $jsonFactory,
            $this->queryRunner,
            $this->queryExecutor,
            $this->config,
            $this->createMock(QueryLogFactory::class),
            $this->createMock(QueryLogResource::class),
            $this->createMock(LoggerInterface::class),
            $savedReportFactory,
            $this->createMock(SavedReportResource::class),
            $this->tokenStorage
        );
    }

    private function givenValidAjaxPost(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
        $this->config->method('isEnabled')->willReturn(true);
    }

    // ── Request validation ───────────────────────────────────────────────

    public function testRejectsNonAjaxRequest(): void
    {
        $this->request->method('isAjax')->willReturn(false);
        $this->request->method('isPost')->willReturn(true);

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString('Invalid', (string) $this->response['message']);
    }

    public function testRejectsNonPostRequest(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(false);

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsWhenModuleDisabled(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
        $this->config->method('isEnabled')->willReturn(false);

        $this->controller->execute();

        $this->assertStringContainsString('disabled', (string) $this->response['message']);
    }

    public function testRejectsEmptyQuery(): void
    {
        $this->givenValidAjaxPost();

        $this->controller->execute();

        $this->assertStringContainsString('enter a query', (string) $this->response['message']);
    }

    // ── Security ─────────────────────────────────────────────────────────

    public function testRawSqlParameterIsIgnored(): void
    {
        $this->givenValidAjaxPost();
        $this->params = ['sql' => 'DELETE FROM sales_order'];

        $this->queryExecutor->expects($this->never())->method('execute');
        $this->queryRunner->expects($this->never())->method('run');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString('enter a query', (string) $this->response['message']);
    }

    public function testSavedReportOfAnotherAdminIsNotRun(): void
    {
        $this->givenValidAjaxPost();
        $this->params = ['report_id' => '5'];
        $this->savedReport->method('getId')->willReturn(5);
        $this->savedReport->method('getAdminUserId')->willReturn(self::ADMIN_ID + 1);

        $this->queryExecutor->expects($this->never())->method('execute');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString('not found', (string) $this->response['message']);
    }

    public function testOwnSavedReportRunsItsStoredSql(): void
    {
        $this->givenValidAjaxPost();
        $this->params = ['report_id' => '5'];
        $this->savedReport->method('getId')->willReturn(5);
        $this->savedReport->method('getAdminUserId')->willReturn(self::ADMIN_ID);
        $this->savedReport->method('getNlpQuery')->willReturn('Orders by status');
        $this->savedReport->method('getSqlQuery')->willReturn('SELECT status, COUNT(*) FROM sales_order GROUP BY status');
        $this->tokenStorage->method('remember')->willReturn('tok');

        $this->queryRunner->expects($this->never())->method('run');
        $this->queryExecutor->expects($this->once())
            ->method('execute')
            ->with('SELECT status, COUNT(*) FROM sales_order GROUP BY status')
            ->willReturn(['columns' => ['status'], 'rows' => [], 'row_count' => 0, 'execution_time_ms' => 1]);

        $this->controller->execute();

        $this->assertTrue($this->response['success']);
        $this->assertSame('tok', $this->response['query_token']);
    }

    public function testNaturalLanguageQueryIsConvertedExecutedAndTokenIssued(): void
    {
        $this->givenValidAjaxPost();
        $this->params = ['query' => 'How many orders?'];
        $this->queryRunner->method('run')->with('How many orders?')->willReturn([
            'sql'      => 'SELECT COUNT(*) FROM sales_order',
            'result'   => ['columns' => ['c'], 'rows' => [['c' => '3']], 'row_count' => 1, 'execution_time_ms' => 2],
            'repaired' => false,
        ]);

        $this->tokenStorage->expects($this->once())
            ->method('remember')
            ->with('How many orders?', 'SELECT COUNT(*) FROM sales_order')
            ->willReturn('abc');

        $this->controller->execute();

        $this->assertTrue($this->response['success']);
        $this->assertSame('abc', $this->response['query_token']);
        $this->assertSame(1, $this->response['row_count']);
    }
}
