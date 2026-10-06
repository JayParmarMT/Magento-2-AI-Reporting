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

class ClaudeModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-3-5-haiku-latest',  'label' => 'Claude 3.5 Haiku (Fast, Recommended)'],
            ['value' => 'claude-3-5-sonnet-latest',  'label' => 'Claude 3.5 Sonnet'],
            ['value' => 'claude-3-7-sonnet-latest',  'label' => 'Claude 3.7 Sonnet'],
            ['value' => 'claude-sonnet-4-0',         'label' => 'Claude Sonnet 4'],
            ['value' => 'claude-opus-4-0',           'label' => 'Claude Opus 4'],
        ];
    }
}
