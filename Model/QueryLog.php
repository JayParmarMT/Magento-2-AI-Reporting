<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model;

use Magento\Framework\Model\AbstractModel;
use Meetanshi\AIReporting\Model\ResourceModel\QueryLog as QueryLogResource;

class QueryLog extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(QueryLogResource::class);
    }
}
