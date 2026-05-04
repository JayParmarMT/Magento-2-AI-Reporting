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

use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Exception\LlmException;

class ProviderPool
{
    /**
     * @param ProviderInterface[] $providers
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $providers = []
    ) {
    }

    /**
     * Return the currently configured LLM provider.
     *
     * @throws LlmException
     */
    public function getActiveProvider(): ProviderInterface
    {
        $code = $this->config->getLlmProvider();

        if (!isset($this->providers[$code])) {
            throw new LlmException(
                __('LLM provider "%1" is not registered. Available: %2', $code, implode(', ', array_keys($this->providers)))
            );
        }

        return $this->providers[$code];
    }

    /**
     * Return a specific provider by code.
     *
     * @throws LlmException
     */
    public function getProvider(string $code): ProviderInterface
    {
        if (!isset($this->providers[$code])) {
            throw new LlmException(__('LLM provider "%1" is not registered.', $code));
        }

        return $this->providers[$code];
    }
}
