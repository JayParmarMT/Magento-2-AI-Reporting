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
            ['value' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash (Recommended)'],
            ['value' => 'gemini-2.5-pro',   'label' => 'Gemini 2.5 Pro'],
            ['value' => 'gemini-2.5-flash-lite', 'label' => 'Gemini 2.5 Flash Lite'],
            ['value' => 'gemini-flash-latest', 'label' => 'Gemini Flash (Latest)'],
            ['value' => 'gemini-pro-latest',   'label' => 'Gemini Pro (Latest)'],
        ];
    }
}
