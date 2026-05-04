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

class OpenRouterModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            // Free models (no credits needed)
            ['value' => 'meta-llama/llama-3.1-8b-instruct:free',   'label' => '✅ Llama 3.1 8B (FREE)'],
            ['value' => 'meta-llama/llama-3.2-3b-instruct:free',   'label' => '✅ Llama 3.2 3B (FREE)'],
            ['value' => 'mistralai/mistral-7b-instruct:free',       'label' => '✅ Mistral 7B (FREE)'],
            ['value' => 'google/gemma-2-9b-it:free',                'label' => '✅ Gemma 2 9B (FREE)'],
            ['value' => 'qwen/qwen-2-7b-instruct:free',             'label' => '✅ Qwen 2 7B (FREE)'],
            // Paid models
            ['value' => 'openai/gpt-4o',                            'label' => 'GPT-4o (Paid)'],
            ['value' => 'anthropic/claude-3.5-sonnet',              'label' => 'Claude 3.5 Sonnet (Paid)'],
        ];
    }
}
