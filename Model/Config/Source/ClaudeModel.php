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
            ['value' => 'claude-opus-5-5',   'label' => 'Claude Opus 5.5 (Recommended)'],
            ['value' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5 (Faster, lower cost)'],
            ['value' => 'claude-haiku-4-5',  'label' => 'Claude Haiku 4.5 (Fastest, lowest cost)'],
        ];
    }
}
