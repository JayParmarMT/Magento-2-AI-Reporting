<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SavedReport extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('meetanshi_aireporting_saved_report', 'report_id');
    }
}
