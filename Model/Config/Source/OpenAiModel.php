<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OpenAiModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'gpt-4o',       'label' => 'GPT-4o (Recommended)'],
            ['value' => 'gpt-4o-mini',  'label' => 'GPT-4o Mini (Faster / Cheaper)'],
            ['value' => 'gpt-4-turbo',  'label' => 'GPT-4 Turbo'],
            ['value' => 'gpt-3.5-turbo','label' => 'GPT-3.5 Turbo (Budget)'],
        ];
    }
}
