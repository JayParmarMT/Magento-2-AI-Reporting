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
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\User\Model\User;
use Meetanshi\AIReporting\Controller\Adminhtml\Report\Save;
use Meetanshi\AIReporting\Model\Query\QueryTokenStorage;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Meetanshi\AIReporting\Model\SavedReport;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaveTest extends TestCase
{
    private Save $controller;
    private Http|MockObject $request;
    private SavedReport|MockObject $savedReport;
    private SavedReportResource|MockObject $savedReportResource;
    private QueryTokenStorage|MockObject $tokenStorage;
    private array $params = [];
    private ?array $response = null;

    protected function setUp(): void
    {
        $jsonFactory               = $this->createMock(JsonFactory::class);
        $resultJson                = $this->createMock(Json::class);
        $this->savedReport         = $this->createMock(SavedReport::class);
        $this->savedReportResource = $this->createMock(SavedReportResource::class);
        $this->tokenStorage        = $this->createMock(QueryTokenStorage::class);
        $this->request             = $this->createMock(Http::class);

        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );
        $jsonFactory->method('create')->willReturn($resultJson);
        $resultJson->method('setData')->willReturnCallback(function (array $data) use ($resultJson) {
            $this->response = $data;
            return $resultJson;
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

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn('');

        $this->controller = new Save(
            $context,
            $jsonFactory,
            $savedReportFactory,
            $this->savedReportResource,
            $this->createMock(LoggerInterface::class),
            $this->tokenStorage,
            new SqlGuard($deploymentConfig)
        );
    }

    private function givenAjaxPost(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(true);
    }

    // ── Request validation ───────────────────────────────────────────────

    public function testRejectsNonAjaxRequest(): void
    {
        $this->request->method('isAjax')->willReturn(false);
        $this->request->method('isPost')->willReturn(true);

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsNonPostRequest(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->request->method('isPost')->willReturn(false);

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsMissingTitle(): void
    {
        $this->givenAjaxPost();
        $this->params = ['query_token' => str_repeat('a', 32)];

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString('required', (string) $this->response['message']);
    }

    public function testRejectsMissingQueryToken(): void
    {
        $this->givenAjaxPost();
        $this->params = ['title' => 'My Report'];

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    // ── Security ─────────────────────────────────────────────────────────

    public function testRejectsUnknownTokenAndNeverSaves(): void
    {
        $this->givenAjaxPost();
        $this->params = ['title' => 'My Report', 'query_token' => str_repeat('b', 32)];
        $this->tokenStorage->method('get')->willReturn(null);

        $this->savedReportResource->expects($this->never())->method('save');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
        $this->assertStringContainsString('expired', (string) $this->response['message']);
    }

    public function testSavesServerSideSqlAndIgnoresRequestSql(): void
    {
        $this->givenAjaxPost();
        $this->params = [
            'title'       => 'Orders',
            'query_token' => str_repeat('c', 32),
            'sql_query'   => 'DELETE FROM sales_order',
            'nlp_query'   => 'tampered',
            'chart_type'  => 'evil"]',
        ];
        $this->tokenStorage->method('get')
            ->willReturn(['nlp' => 'Orders by status', 'sql' => 'SELECT status FROM sales_order']);

        $this->savedReport->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['sql_query'] === 'SELECT status FROM sales_order'
                    && $data['nlp_query'] === 'Orders by status'
                    && $data['chart_type'] === 'table'
                    && $data['admin_user_id'] === 3;
            }))
            ->willReturnSelf();
        $this->savedReportResource->expects($this->once())->method('save');

        $this->controller->execute();

        $this->assertTrue($this->response['success']);
    }
}
