<?php
/**
 * Meetanshi AIReporting - Dashboard Refresh Data Controller
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\LayoutFactory;

class RefreshData extends Action
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly LayoutFactory $layoutFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $dateRange = $this->getRequest()->getParam('date_range', 'last_6_months');
            $customStart = $this->getRequest()->getParam('custom_start');
            $customEnd = $this->getRequest()->getParam('custom_end');
            
            // Create dashboard block
            $layout = $this->layoutFactory->create();
            $block = $layout->createBlock(\Meetanshi\AIReporting\Block\Adminhtml\Dashboard::class);
            
            // Fetch data
            $data = $block->getDashboardData($dateRange, $customStart, $customEnd);
            
            return $this->resultJsonFactory->create()->setData([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return $this->resultJsonFactory->create()->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::dashboard');
    }
}
