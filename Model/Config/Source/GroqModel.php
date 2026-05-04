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
            ['value' => 'llama-3.3-70b-versatile',        'label' => 'Llama 3.3 70B Versatile (Recommended)'],
            ['value' => 'llama-3.1-8b-instant',            'label' => 'Llama 3.1 8B Instant (Fastest)'],
            ['value' => 'llama3-70b-8192',                 'label' => 'Llama 3 70B'],
            ['value' => 'llama3-8b-8192',                  'label' => 'Llama 3 8B'],
            ['value' => 'mixtral-8x7b-32768',              'label' => 'Mixtral 8x7B'],
            ['value' => 'gemma2-9b-it',                    'label' => 'Gemma 2 9B'],
        ];
    }
}
