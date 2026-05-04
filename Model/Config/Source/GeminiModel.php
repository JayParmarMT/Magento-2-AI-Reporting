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

class GeminiModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'gemini-1.5-flash', 'label' => 'Gemini 1.5 Flash (Recommended)'],
            ['value' => 'gemini-1.5-pro',   'label' => 'Gemini 1.5 Pro'],
            ['value' => 'gemini-2.0-flash',  'label' => 'Gemini 2.0 Flash'],
        ];
    }
}
