<?php
declare(strict_types=1);
namespace Meetanshi\AIReporting\Block\Adminhtml\Reports;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Meetanshi\AIReporting\Model\Report\ProductReport;

class Product extends Template
{
    public function __construct(
        Context $context,
        private readonly ProductReport $productReport,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getKpiCards(): array              { return $this->productReport->getKpiCards(); }
    public function getBestSellersByRevenue(): array  { return $this->productReport->getBestSellersByRevenue(20); }
    public function getBestSellersByQty(): array      { return $this->productReport->getBestSellersByQty(20); }
    public function getWorstSellers(): array          { return $this->productReport->getWorstSellers(10); }
    public function getRevenueByProductType(): array  { return $this->productReport->getRevenueByProductType(); }
    public function getTopProductsTrend(): array      { return $this->productReport->getTopProductsTrend(); }
    public function getProductsNeverSold(): array     { return $this->productReport->getProductsNeverSold(20); }
    public function getRevenueByCategory(): array     { return $this->productReport->getRevenueByCategory(15); }

    public function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}
