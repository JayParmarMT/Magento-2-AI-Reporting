<?php
declare(strict_types=1);
namespace Meetanshi\AIReporting\Block\Adminhtml\Reports;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Meetanshi\AIReporting\Model\Report\SalesReport;
use Meetanshi\AIReporting\Model\Report\ReportContext;

class Sales extends Template
{
    public function __construct(
        Context $context,
        private readonly SalesReport $salesReport,
        private readonly ReportContext $reportContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getKpiCards(): array         { return $this->salesReport->getKpiCards(); }
    public function getRevenueByMonth(): array   { return $this->salesReport->getRevenueByMonth(); }
    public function getRevenueByDay(): array     { return $this->salesReport->getRevenueByDay(); }
    public function getOrdersByStatus(): array   { return $this->salesReport->getOrdersByStatus(); }
    public function getOrdersByStatusPerPeriod(): array { return $this->salesReport->getOrdersByStatusPerPeriod(); }
    public function getRevenueTimeSeriesPerPeriod(): array { return $this->salesReport->getRevenueTimeSeriesPerPeriod(); }
    public function getRevenueByDayOfWeekPerPeriod(): array { return $this->salesReport->getRevenueByDayOfWeekPerPeriod(); }
    public function getRevenueByShipping(): array{ return $this->salesReport->getRevenueByShipping(); }
    public function getCouponUsage(): array      { return $this->salesReport->getCouponUsage(); }
    public function getRevenueByRegion(): array  { return $this->salesReport->getRevenueByRegion(); }
    public function getRefundSummary(): array    { return $this->salesReport->getRefundSummary(); }
    public function getRevenueByDayOfWeek(): array { return $this->salesReport->getRevenueByDayOfWeek(); }

    public function formatCurrency(float $amount): string
    {
        return $this->reportContext->formatPrice($amount);
    }

    public function formatNumber(float $num): string
    {
        return number_format($num, 0);
    }
}
