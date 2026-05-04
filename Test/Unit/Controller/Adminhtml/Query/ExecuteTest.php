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
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Meetanshi\AIReporting\Controller\Adminhtml\Query\Execute;
use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Exception\QueryException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\NlpToSql;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\QueryLogFactory;
use Meetanshi\AIReporting\Model\ResourceModel\QueryLog as QueryLogResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExecuteTest extends TestCase
{
    private Execute $controller;
    private JsonFactory|MockObject $jsonFactory;
    private Json|MockObject $resultJson;
    private NlpToSql|MockObject $nlpToSql;
    private QueryExecutor|MockObject $queryExecutor;
    private Config|MockObject $config;
    private RequestInterface|MockObject $request;
    private QueryLogFactory|MockObject $queryLogFactory;
    private QueryLogResource|MockObject $queryLogResource;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->jsonFactory     = $this->createMock(JsonFactory::class);
        $this->resultJson      = $this->createMock(Json::class);
        $this->nlpToSql        = $this->createMock(NlpToSql::class);
        $this->queryExecutor   = $this->createMock(QueryExecutor::class);
        $this->config          = $this->createMock(Config::class);
        $this->queryLogFactory = $this->createMock(QueryLogFactory::class);
        $this->queryLogResource= $this->createMock(QueryLogResource::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->request = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['isAjax', 'isPost'])
            ->getMockForAbstractClass();

        $this->jsonFactory->method('create')->willReturn($this->resultJson);
        $this->resultJson->method('setData')->willReturnSelf();

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $this->controller = new Execute(
            $context,
            $this->jsonFactory,
            $this->nlpToSql,
            $this->queryExecutor,
            $this->config,
            $this->queryLogFactory,
            $this->queryLogResource,
            $this->logger
        );
    }

    // ── Request validation ───────────────────────────────────────────────

    public function testRejectsNonAjaxRequest(): void
    {
        $this->request->method('isAjax')->willReturn(false);
        $this->request->method('isPost')->willReturn(true);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['success'] === false && str_contains((string) $data['message'], 'Invalid');
            }));

        $this->controller->execute();
    }

    public function testRejectsNonPostRequest(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(false);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['success'] === false;
            }));

        $this->controller->execute();
    }

    public function testRejectsWhenModuleDisabled(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
        $this->config->method('isEnabled')->willReturn(false);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['success'] === false && str_contains((string) $data['message'], 'disabled');
            }));

        $this->controller->execute();
    }

    public function testRejectsEmptyQuery(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
        $this->config->method('isEnabled')->willReturn(true);
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['sql', '', ''],
            ]);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['success'] === false && str_contains((string) $data['message'], 'enter a query');
            }));

        $this->controller->execute();
    }
}
