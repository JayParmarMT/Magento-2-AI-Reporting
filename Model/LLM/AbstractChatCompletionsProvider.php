<?php
/**
 * Meetanshi AIReporting — providers with an OpenAI-style /chat/completions API (OpenAI, Groq, OpenRouter)
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\LLM;

use Meetanshi\AIReporting\Exception\LlmException;

abstract class AbstractChatCompletionsProvider extends AbstractProvider
{
    abstract protected function getApiUrl(): string;

    abstract protected function getApiKey(): string;

    /**
     * Message shown when no API key is saved.
     */
    abstract protected function getMissingKeyMessage(): \Magento\Framework\Phrase;

    /**
     * Headers besides Content-Type and Authorization.
     *
     * @return array<string, string>
     */
    protected function getExtraHeaders(): array
    {
        return [];
    }

    /**
     * Output limit and sampling parameters of the request.
     */
    protected function getGenerationOptions(): array
    {
        return [
            'max_tokens'  => $this->config->getMaxTokens(),
            'temperature' => 0.1,
        ];
    }

    /**
     * @inheritDoc
     */
    public function complete(string $prompt, string $system = ''): string
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            throw new LlmException($this->getMissingKeyMessage());
        }

        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $response = $this->postJson(
            $this->getApiUrl(),
            ['Authorization' => 'Bearer ' . $apiKey] + $this->getExtraHeaders(),
            ['model' => $this->getModel(), 'messages' => $messages] + $this->getGenerationOptions()
        );

        $choice  = $response['choices'][0] ?? [];
        $content = trim((string) ($choice['message']['content'] ?? ''));
        if ($content === '') {
            throw new LlmException(($choice['finish_reason'] ?? '') === 'length'
                ? __('%1 used up the "Max Tokens" limit before answering. Increase Max Tokens in the configuration.', $this->getLabel())
                : __('%1 returned an empty response.', $this->getLabel()));
        }

        return $content;
    }
}
