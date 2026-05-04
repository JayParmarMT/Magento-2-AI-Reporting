<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

abstract class AbstractAction extends Action
{
    public function __construct(
        Context $context,
        protected readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Check admin ACL.
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::aireporting');
    }
}
