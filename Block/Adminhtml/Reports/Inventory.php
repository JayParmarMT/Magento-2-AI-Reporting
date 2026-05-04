<?php
declare(strict_types=1);
namespace Meetanshi\AIReporting\Block\Adminhtml\Reports;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Meetanshi\AIReporting\Model\Report\InventoryReport;

class Inventory extends Template
{
    public function __construct(
        Context $context,
        private readonly InventoryReport $inventoryReport,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getKpiCards(): array           { return $this->inventoryReport->getKpiCards(); }
    public function getLowStockProducts(): array   { return $this->inventoryReport->getLowStockProducts(10, 30); }
    public function getOutOfStockProducts(): array { return $this->inventoryReport->getOutOfStockProducts(30); }
    public function getStockDistribution(): array  { return $this->inventoryReport->getStockDistribution(); }
    public function getDemandVsSupply(): array      { return $this->inventoryReport->getDemandVsSupply(15); }
    public function getInventoryTurnover(): array   { return $this->inventoryReport->getInventoryTurnover(20); }

    public function getStockStatusClass(float $qty): string
    {
        if ($qty == 0) return 'ai-badge-red';
        if ($qty <= 5) return 'ai-badge-orange';
        if ($qty <= 10) return 'ai-badge-yellow';
        return 'ai-badge-green';
    }

    public function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}
