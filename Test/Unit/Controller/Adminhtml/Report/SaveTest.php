<?php
/**
 * Meetanshi AIReporting — Report Save Controller Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\User\Model\User;
use Meetanshi\AIReporting\Controller\Adminhtml\Report\Save;
use Meetanshi\AIReporting\Model\SavedReport;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaveTest extends TestCase
{
    private Save $controller;
    private JsonFactory|MockObject $jsonFactory;
    private Json|MockObject $resultJson;
    private SavedReportFactory|MockObject $savedReportFactory;
    private SavedReportResource|MockObject $savedReportResource;
    private RequestInterface|MockObject $request;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->jsonFactory         = $this->createMock(JsonFactory::class);
        $this->resultJson          = $this->createMock(Json::class);
        $this->savedReportFactory  = $this->createMock(SavedReportFactory::class);
        $this->savedReportResource = $this->createMock(SavedReportResource::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->request = $this->getMockBuilder(RequestInterface::class)
            ->addMethods(['isAjax', 'isPost'])
            ->getMockForAbstractClass();

        $this->jsonFactory->method('create')->willReturn($this->resultJson);
        $this->resultJson->method('setData')->willReturnSelf();

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $this->controller = new Save(
            $context,
            $this->jsonFactory,
            $this->savedReportFactory,
            $this->savedReportResource,
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
            ->with($this->callback(fn(array $d) => $d['success'] === false));

        $this->controller->execute();
    }

    public function testRejectsNonPostRequest(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(false);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(fn(array $d) => $d['success'] === false));

        $this->controller->execute();
    }

    public function testRejectsMissingTitle(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')
            ->willReturnMap([
                ['title', '', ''],
                ['nlp_query', '', 'some query'],
                ['sql_query', '', 'SELECT 1'],
                ['chart_type', 'table', 'table'],
                ['chart_config', [], []],
            ]);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $d) {
                return $d['success'] === false && str_contains((string) $d['message'], 'required');
            }));

        $this->controller->execute();
    }

    public function testRejectsMissingSqlQuery(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')
            ->willReturnMap([
                ['title', '', 'My Report'],
                ['nlp_query', '', 'some query'],
                ['sql_query', '', ''],
                ['chart_type', 'table', 'table'],
                ['chart_config', [], []],
            ]);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(fn(array $d) => $d['success'] === false));

        $this->controller->execute();
    }

    public function testRejectsMissingNlpQuery(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')
            ->willReturnMap([
                ['title', '', 'My Report'],
                ['nlp_query', '', ''],
                ['sql_query', '', 'SELECT 1'],
                ['chart_type', 'table', 'table'],
                ['chart_config', [], []],
            ]);

        $this->resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(fn(array $d) => $d['success'] === false));

        $this->controller->execute();
    }
}
