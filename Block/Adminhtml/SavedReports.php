<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\Collection;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\CollectionFactory;

class SavedReports extends Template
{
    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly AuthSession $authSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Saved reports belonging to the logged-in admin only.
     */
    public function getSavedReports(): Collection
    {
        $user = $this->authSession->getUser();

        return $this->collectionFactory->create()
            ->addFieldToFilter('admin_user_id', $user ? (int) $user->getId() : 0)
            ->setOrder('created_at', 'DESC');
    }

    public function getQueryUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/query/index');
    }
}
