<?php
/**
 * Meetanshi AIReporting — builds a ReportContext wired to a mocked DB connection
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\Report\ReportScope;

trait ReportContextTrait
{
    private function createReportContext(
        AdapterInterface $connection,
        string $timezone = 'UTC',
        bool $msiEnabled = false,
        string $linkField = 'entity_id',
        ?ReportScope $scope = null,
        ?ScopeConfigInterface $scopeConfig = null
    ): ReportContext {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $connection->method('quote')->willReturnCallback(
            fn ($value) => "'" . implode("', '", (array) $value) . "'"
        );
        $connection->method('isTableExists')->willReturn($msiEnabled);

        $timezoneMock = $this->createMock(TimezoneInterface::class);
        $timezoneMock->method('getConfigTimezone')->willReturn($timezone);

        if ($scopeConfig === null) {
            $scopeConfig = $this->createMock(ScopeConfigInterface::class);
            $scopeConfig->method('getValue')->willReturn('USD');
        }

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('getCurrencySymbol')->willReturn('$');
        $priceCurrency->method('format')->willReturnCallback(
            fn ($amount) => '$' . number_format((float) $amount, 2)
        );

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn($msiEnabled);

        $stockConfiguration = $this->createMock(StockConfigurationInterface::class);
        $stockConfiguration->method('getIsQtyTypeIds')->willReturn(['simple' => true, 'virtual' => true]);
        $stockConfiguration->method('getManageStock')->willReturn(1);

        return new ReportContext(
            $resourceConnection,
            $timezoneMock,
            $scopeConfig,
            $priceCurrency,
            $metadataPool,
            $moduleManager,
            $stockConfiguration,
            $scope ?? $this->createAllStoresScope()
        );
    }

    /**
     * A ReportScope on All Store Views (single-store install).
     */
    private function createAllStoresScope(): ReportScope
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('hasSingleStore')->willReturn(true);

        return new ReportScope(
            $this->createMock(RequestInterface::class),
            $storeManager,
            $this->createMock(BackendSession::class)
        );
    }
}
