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
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;

class SavedReport extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(SavedReportResource::class);
    }

    public function getTitle(): string
    {
        return (string) $this->getData('title');
    }

    public function getNlpQuery(): string
    {
        return (string) $this->getData('nlp_query');
    }

    public function getSqlQuery(): string
    {
        return (string) $this->getData('sql_query');
    }

    public function getChartType(): string
    {
        return (string) ($this->getData('chart_type') ?: 'table');
    }

    public function getChartConfig(): array
    {
        $config = $this->getData('chart_config');
        if (is_string($config) && !empty($config)) {
            return json_decode($config, true) ?? [];
        }
        return is_array($config) ? $config : [];
    }

    public function getAdminUserId(): int
    {
        return (int) $this->getData('admin_user_id');
    }
}
