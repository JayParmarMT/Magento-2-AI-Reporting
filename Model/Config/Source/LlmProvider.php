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

class LlmProvider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'groq',        'label' => __('Groq — FREE (Recommended for testing)')],
            ['value' => 'ollama',      'label' => __('Ollama — 100% Free, Runs Locally')],
            ['value' => 'openrouter',  'label' => __('OpenRouter — Free Models Available')],
            ['value' => 'gemini',      'label' => __('Google Gemini')],
            ['value' => 'openai',      'label' => __('OpenAI (ChatGPT)')],
            ['value' => 'claude',      'label' => __('Anthropic Claude')],
        ];
    }
}
