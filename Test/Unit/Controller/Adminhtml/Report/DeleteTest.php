<?php
/**
 * Meetanshi AIReporting — Report Delete Controller Unit Tests
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
use Meetanshi\AIReporting\Controller\Adminhtml\Report\Delete;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\Collection;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\CollectionFactory;
use Meetanshi\AIReporting\Model\SavedReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeleteTest extends TestCase
{
    private Delete $controller;
    private Http|MockObject $request;
    private Collection|MockObject $collection;
    private SavedReportResource|MockObject $savedReportResource;
    private array $params = [];
    private array $filters = [];
    private ?array $response = null;

    protected function setUp(): void
    {
        $jsonFactory               = $this->createMock(JsonFactory::class);
        $resultJson                = $this->createMock(Json::class);
        $this->collection          = $this->createMock(Collection::class);
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
        $this->collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) {
            $this->filters[$field] = $condition;
            return $this->collection;
        });

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getAuth')->willReturn($auth);

        $this->controller = new Delete(
            $context,
            $jsonFactory,
            $collectionFactory,
            $this->savedReportResource,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param int[] $ids
     */
    private function givenOwnedReports(array $ids): void
    {
        $reports = array_map(function (int $id) {
            $report = $this->createMock(SavedReport::class);
            $report->method('getId')->willReturn($id);
            return $report;
        }, $ids);

        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($reports));
    }

    public function testRejectsNonAjaxRequest(): void
    {
        $this->request->method('isAjax')->willReturn(false);
        $this->savedReportResource->expects($this->never())->method('delete');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testRejectsEmptySelection(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->params = ['ids' => ['0', '-4', 'abc']];
        $this->savedReportResource->expects($this->never())->method('delete');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
    }

    public function testDeletesOnlyTheCurrentAdminsReports(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->params = ['ids' => ['5', '7', '7', '11']];
        // 11 belongs to another admin: the owner filter leaves it out of the collection
        $this->givenOwnedReports([5, 7]);
        $this->savedReportResource->expects($this->exactly(2))->method('delete');

        $this->controller->execute();

        $this->assertSame(3, $this->filters['admin_user_id']);
        $this->assertSame(['in' => [5, 7, 11]], $this->filters['report_id']);
        $this->assertTrue($this->response['success']);
        $this->assertSame([5, 7], $this->response['deleted']);
    }

    public function testAcceptsASingleId(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->params = ['ids' => '8'];
        $this->givenOwnedReports([8]);

        $this->controller->execute();

        $this->assertTrue($this->response['success']);
        $this->assertSame([8], $this->response['deleted']);
    }

    public function testReportsNotFoundWhenNothingIsOwned(): void
    {
        $this->request->method('isAjax')->willReturn(true);
        $this->params = ['ids' => ['11']];
        $this->givenOwnedReports([]);
        $this->savedReportResource->expects($this->never())->method('delete');

        $this->controller->execute();

        $this->assertFalse($this->response['success']);
        $this->assertSame([], $this->response['deleted']);
    }
}
