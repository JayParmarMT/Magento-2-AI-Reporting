<?php
/**
 * Meetanshi AIReporting — Report Update Controller Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\User\Model\User;
use Meetanshi\AIReporting\Controller\Adminhtml\Report\Update;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Meetanshi\AIReporting\Model\SavedReport;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateTest extends TestCase
{
    private Update $controller;
    private Http|MockObject $request;
    private SavedReport|MockObject $savedReport;
    private SavedReportResource|MockObject $savedReportResource;
    private array $params = [];
    private array $saved = [];
    private ?array $response = null;

    protected function setUp(): void
    {
        $jsonFactory               = $this->createMock(JsonFactory::class);
        $resultJson                = $this->createMock(Json::class);
        $this->savedReport         = $this->createMock(SavedReport::class);
        $this->savedReportResource = $this->createMock(SavedReportResource::class);
        $this->request             = $this->createMock(Http::class);

        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );
        $jsonFactory->method('create')->willReturn($resultJson);
        $resultJson->method('setData')->willReturnCallback(function (array $data) use ($resultJson) {
            $this->response = $data;
            return $resultJson;
        });
        $this->savedReport->method('setData')->willReturnCallback(function (string $key, $value) {
            $this->saved[$key] = $value;
            return $this->savedReport;
        });

        $savedReportFactory = $this->createMock(SavedReportFactory::class);
        $savedReportFactory->method('create')->willReturn($this->savedReport);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getAuth')->willReturn($auth);

        $this->controller = new Update(
            $context,
            $jsonFactory,
            $savedReportFactory,
            $this->savedReportResource,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function givenAjax(): void
    {
        $this->request->method('isAjax')->willReturn(true);
    }

    private function givenReportOwnedBy(int $adminUserId): void
    {
        $this->savedReport->method('getId')->willReturn(12);
        $this->savedReport->method('getAdminUserId')->willReturn($adminUserId);
    }

    public function testRejectsNonAjaxRequest(): void
    {
        $this->request->method('isAjax')->willReturn(false);
        $this->savedReportResource->expects($this->never())->method('save');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsEmptyTitle(): void
    {
        $this->givenAjax();
        $this->params = ['report_id' => 12, 'title' => '   ', 'chart_type' => 'bar'];
        $this->savedReportResource->expects($this->never())->method('save');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsUnknownChartType(): void
    {
        $this->givenAjax();
        $this->params = ['report_id' => 12, 'title' => 'Revenue', 'chart_type' => 'radar'];
        $this->savedReportResource->expects($this->never())->method('save');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsAnotherAdminsReport(): void
    {
        $this->givenAjax();
        $this->givenReportOwnedBy(9);
        $this->params = ['report_id' => 12, 'title' => 'Revenue', 'chart_type' => 'bar'];
        $this->savedReportResource->expects($this->never())->method('save');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString('not found', (string) $this->response['message']);
    }

    public function testUpdatesTitleAndChartOnly(): void
    {
        $this->givenAjax();
        $this->givenReportOwnedBy(3);
        $this->params = [
            'report_id'  => 12,
            'title'      => '  Monthly revenue  ',
            'chart_type' => 'line',
            'sql_query'  => 'DELETE FROM sales_order',
            'nlp_query'  => 'changed question',
        ];
        $this->savedReportResource->expects($this->once())->method('save')->with($this->savedReport);

        $this->controller->execute();

        $this->assertTrue($this->response['success']);
        $this->assertSame(['title' => 'Monthly revenue', 'chart_type' => 'line'], $this->saved);
        $this->assertSame(['id' => 12, 'title' => 'Monthly revenue', 'chart' => 'line'], $this->response['report']);
    }

    public function testTruncatesLongTitles(): void
    {
        $this->givenAjax();
        $this->givenReportOwnedBy(3);
        $this->params = ['report_id' => 12, 'title' => str_repeat('x', 300), 'chart_type' => 'table'];

        $this->controller->execute();

        $this->assertTrue($this->response['success']);
        $this->assertSame(255, mb_strlen($this->saved['title']));
    }
}
