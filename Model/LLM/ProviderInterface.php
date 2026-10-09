<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\LLM;

interface ProviderInterface
{
    /**
     * Send a prompt to the LLM and return the text response.
     *
     * The system prompt is set by the caller: SQL generation and answer writing need different
     * instructions. Keep it identical between calls where possible — providers cache a stable
     * system prompt (prefix), which makes repeated questions cheaper and faster.
     *
     * @param string $prompt the question / per-request part
     * @param string $system instructions and other content that rarely changes ('' for none)
     * @return string
     * @throws \Meetanshi\AIReporting\Exception\LlmException
     */
    public function complete(string $prompt, string $system = ''): string;

    /**
     * Return the provider identifier (e.g. "openai", "gemini").
     *
     * @return string
     */
    public function getProviderCode(): string;
}
