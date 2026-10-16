<?php
/**
 * Meetanshi AIReporting — ReportScope unit tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Meetanshi\AIReporting\Model\Report\ReportScope;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReportScopeTest extends TestCase
{
    private RequestInterface|MockObject $request;
    private StoreManagerInterface|MockObject $storeManager;
    private ReportScope $scope;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('hasSingleStore')->willReturn(false);

        // Website 4 "Europe Website" with store views 10 and 11 (default 10); store view 11 "Europe German"
        $german = $this->store(11, 4, 'Europe German');
        $english = $this->store(10, 4, 'Europe English');
        $group = $this->createMock(Group::class);
        $group->method('getStores')->willReturn([10 => $english, 11 => $german]);
        $group->method('getName')->willReturn('Europe Store');
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(4);
        $website->method('getCode')->willReturn('perf_eu');
        $website->method('getName')->willReturn('Europe Website');
        $website->method('getStoreIds')->willReturn([10 => 10, 11 => 11]);
        $website->method('getDefaultStore')->willReturn($english);
        $website->method('getGroups')->willReturn([$group]);

        $this->storeManager->method('getWebsite')->willReturnCallback(function ($id) use ($website) {
            if ((int) $id !== 4) {
                throw new \Magento\Framework\Exception\LocalizedException(__('no website'));
            }
            return $website;
        });
        $this->storeManager->method('getStore')->willReturnCallback(function ($id) use ($english, $german) {
            return match ((int) $id) {
                10 => $english,
                11 => $german,
                default => throw new NoSuchEntityException(__('no store')),
            };
        });
        $this->storeManager->method('getWebsites')->willReturn([4 => $website]);

        $this->scope = new ReportScope($this->request, $this->storeManager, $this->createMock(BackendSession::class));
    }

    public function testDefaultsToAllStoreViews(): void
    {
        $this->assertTrue($this->scope->isAll());
        $this->assertSame([], $this->scope->getStoreIds());
        $this->assertNull($this->scope->getWebsiteId());
        $this->assertSame(0, $this->scope->getValueStoreId());
    }

    public function testWebsiteScope(): void
    {
        $this->scope->set('website:4');

        $this->assertSame('website:4', $this->scope->getKey());
        $this->assertSame([10, 11], $this->scope->getStoreIds());
        $this->assertSame(4, $this->scope->getWebsiteId());
        $this->assertSame('perf_eu', $this->scope->getWebsiteCode());
        $this->assertNull($this->scope->getStoreViewId());
        $this->assertSame(10, $this->scope->getValueStoreId());
        $this->assertSame('Europe Website', $this->scope->getLabel());
    }

    public function testStoreViewScope(): void
    {
        $this->scope->set('store:11');

        $this->assertSame([11], $this->scope->getStoreIds());
        $this->assertSame(4, $this->scope->getWebsiteId());
        $this->assertSame(11, $this->scope->getStoreViewId());
        $this->assertSame(11, $this->scope->getValueStoreId());
        $this->assertSame('Europe German (Europe Website)', $this->scope->getLabel());
    }

    public function testUnknownOrMalformedScopesFallBackToAll(): void
    {
        foreach (['website:99', 'store:99', 'store:0', 'website:abc', "store:11' OR 1=1", 'group:2', ''] as $key) {
            $this->scope->set($key);
            $this->assertTrue($this->scope->isAll(), $key);
        }
    }

    public function testActivateUsesTheRequestParameter(): void
    {
        $this->request->method('getParam')->with('scope')->willReturn('store:10');

        $this->scope->activate();

        $this->assertSame('store:10', $this->scope->getKey());
    }

    public function testActivateDoesNotReplaceAScopeSetDirectly(): void
    {
        $this->request->method('getParam')->willReturn('store:10');

        $this->scope->set('website:4');
        $this->scope->activate();

        $this->assertSame('website:4', $this->scope->getKey());
    }

    public function testOptionsListWebsitesThenTheirStoreViews(): void
    {
        $options = $this->scope->getOptions();

        $this->assertSame(['all', 'website:4', 'store:10', 'store:11'], array_column($options, 'value'));
        $this->assertSame([0, 1, 2, 2], array_column($options, 'level'));
        $this->assertSame('Europe German', $options[3]['label']);
    }

    public function testSingleStoreInstallHasNoOptionsAndStaysOnAll(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('hasSingleStore')->willReturn(true);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn('website:4');
        $scope = new ReportScope($request, $storeManager, $this->createMock(BackendSession::class));

        $scope->activate();

        $this->assertSame([], $scope->getOptions());
        $this->assertTrue($scope->isAll());
    }

    private function store(int $id, int $websiteId, string $name): Store|MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getName')->willReturn($name);

        return $store;
    }
}
