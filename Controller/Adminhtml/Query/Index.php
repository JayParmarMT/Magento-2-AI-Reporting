<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Query;

use Magento\Framework\Controller\ResultInterface;
use Meetanshi\AIReporting\Controller\Adminhtml\AbstractAction;

class Index extends AbstractAction
{
    public function execute(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Meetanshi_AIReporting::query');
        $resultPage->getConfig()->getTitle()->prepend(__('Ask AI — Natural Language Query'));

        return $resultPage;
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::query');
    }
}
