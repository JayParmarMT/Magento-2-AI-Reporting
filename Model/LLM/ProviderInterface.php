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
     * @param string $prompt
     * @return string
     * @throws \Meetanshi\AIReporting\Exception\LlmException
     */
    public function complete(string $prompt): string;

    /**
     * Return the provider identifier (e.g. "openai", "gemini").
     *
     * @return string
     */
    public function getProviderCode(): string;
}
