<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\ResourceModel\SavedReport;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Meetanshi\AIReporting\Model\SavedReport;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SavedReport::class, SavedReportResource::class);
    }
}
