<?php
declare(strict_types=1);
namespace Meetanshi\AIReporting\Block\Adminhtml\Reports;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Meetanshi\AIReporting\Model\Report\CustomerReport;

class Customer extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerReport $customerReport,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getKpiCards(): array              { return $this->customerReport->getKpiCards(); }
    public function getTopCustomers(): array          { return $this->customerReport->getTopCustomersBySpend(20); }
    public function getRfmSegmentation(): array       { return $this->customerReport->getRfmSegmentation(50); }
    public function getNewVsReturning(): array        { return $this->customerReport->getNewVsReturning(); }
    public function getCustomerAcquisition(): array   { return $this->customerReport->getCustomerAcquisition(); }
    public function getClvDistribution(): array       { return $this->customerReport->getClvDistribution(); }
    public function getRepeatPurchaseRate(): array    { return $this->customerReport->getRepeatPurchaseRate(); }

    public function getRfmLabel(int $r, int $f, int $m): string
    {
        $total = $r + $f + $m;
        if ($total >= 13) return 'Champions';
        if ($r >= 4 && $f >= 3) return 'Loyal';
        if ($r >= 4 && $f <= 2) return 'New Customer';
        if ($r <= 2 && $f >= 3) return 'At Risk';
        if ($r <= 2 && $f <= 2) return 'Lost';
        return 'Potential';
    }

    public function getRfmBadgeClass(string $label): string
    {
        return match($label) {
            'Champions'    => 'ai-badge-green',
            'Loyal'        => 'ai-badge-blue',
            'New Customer' => 'ai-badge-purple',
            'At Risk'      => 'ai-badge-orange',
            'Lost'         => 'ai-badge-red',
            default        => 'ai-badge-gray',
        };
    }

    public function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}
