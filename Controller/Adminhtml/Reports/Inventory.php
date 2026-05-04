<?php
declare(strict_types=1);
namespace Meetanshi\AIReporting\Controller\Adminhtml\Reports;

use Magento\Framework\Controller\ResultInterface;
use Meetanshi\AIReporting\Controller\Adminhtml\AbstractAction;

class Inventory extends AbstractAction
{
    public function execute(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Meetanshi_AIReporting::reports_inventory');
        $resultPage->getConfig()->getTitle()->prepend(__('Inventory Report'));
        return $resultPage;
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::reports_inventory');
    }
}
