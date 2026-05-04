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
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\Collection;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\CollectionFactory;

class SavedReports extends Template
{
    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSavedReports(): Collection
    {
        return $this->collectionFactory->create()
            ->setOrder('created_at', 'DESC');
    }

    public function getQueryUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/query/index');
    }
}
