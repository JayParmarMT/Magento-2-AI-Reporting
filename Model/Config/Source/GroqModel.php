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

class GroqModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openai/gpt-oss-120b', 'label' => 'GPT-OSS 120B (Recommended)'],
            ['value' => 'openai/gpt-oss-20b',  'label' => 'GPT-OSS 20B (Fastest)'],
            ['value' => 'qwen/qwen3.8-27b',    'label' => 'Qwen 3.8 27B'],
        ];
    }
}
