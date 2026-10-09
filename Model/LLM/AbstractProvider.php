<?php
/**
 * Meetanshi AIReporting — shared HTTP handling for hosted LLM providers
 *
 * One place for the request timeout, retries of rate limits and temporary server errors
 * (honouring Retry-After), and error messages that say what to fix — including the
 * provider's own explanation (e.g. "model `x` does not exist") instead of only a status code.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\LLM;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Model\Config;
use Psr\Log\LoggerInterface;

abstract class AbstractProvider implements ProviderInterface
{
    /**
     * Retries after the first attempt for the statuses below.
     */
    private const MAX_RETRIES = 2;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504, 529];

    /**
     * Longest wait between attempts (seconds), so an admin request is never blocked for long.
     */
    private const MAX_RETRY_WAIT = 10;

    public function __construct(
        protected readonly Config $config,
        protected readonly Curl $curl,
        protected readonly Json $json,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Provider name for messages, e.g. "Groq".
     */
    abstract protected function getLabel(): string;

    /**
     * Configured model id.
     */
    abstract protected function getModel(): string;

    /**
     * POST a JSON payload and return the decoded response, retrying rate limits and temporary errors.
     *
     * @param array<string, string> $headers
     * @throws LlmException
     */
    protected function postJson(string $url, array $headers, array $payload): array
    {
        $body    = $this->json->serialize($payload);
        $timeout = $this->config->getLlmTimeout();

        for ($attempt = 0;; $attempt++) {
            $this->curl->setHeaders(['Content-Type' => 'application/json'] + $headers);
            $this->curl->setTimeout($timeout);
            try {
                $this->curl->post($url, $body);
            } catch (\Exception $e) {
                $this->logger->error('Meetanshi AIReporting ' . $this->getLabel() . ' connection error', [
                    'error' => $e->getMessage(),
                ]);
                throw new LlmException($this->describeConnectionError($e->getMessage(), $timeout));
            }

            $status       = $this->curl->getStatus();
            $responseBody = (string) $this->curl->getBody();
            if ($status === 200) {
                break;
            }

            if (in_array($status, self::RETRYABLE_STATUSES, true) && $attempt < self::MAX_RETRIES) {
                $wait = $this->getRetryDelay($attempt);
                $this->logger->warning('Meetanshi AIReporting ' . $this->getLabel() . ' temporary error, retrying', [
                    'status'  => $status,
                    'attempt' => $attempt + 1,
                    'wait'    => $wait,
                ]);
                $this->pause($wait);
                continue;
            }

            $this->logger->error('Meetanshi AIReporting ' . $this->getLabel() . ' error', [
                'status'   => $status,
                'response' => mb_substr($responseBody, 0, 2000),
            ]);
            throw new LlmException($this->describeHttpError($status, $responseBody));
        }

        try {
            $decoded = $this->json->unserialize($responseBody);
        } catch (\InvalidArgumentException $e) {
            throw new LlmException(__('Failed to parse the %1 response: %2', $this->getLabel(), $e->getMessage()));
        }
        if (!is_array($decoded)) {
            throw new LlmException(__('%1 returned an unexpected response.', $this->getLabel()));
        }

        return $decoded;
    }

    /**
     * Message for a non-200 response, with the provider's own explanation when it gives one.
     */
    protected function describeHttpError(int $status, string $body): \Magento\Framework\Phrase
    {
        $label  = $this->getLabel();
        $detail = $this->extractErrorMessage($body);
        $suffix = $detail !== '' ? ' ' . __('Details: %1', $detail) : '';

        switch (true) {
            case $status === 400:
                return __('%1 rejected the request (HTTP 400).%2', $label, $suffix);
            case $status === 401 || $status === 403:
                return __(
                    '%1 authentication failed (HTTP %2). Check the API key in Stores > Configuration > Meetanshi > AI Reporting.%3',
                    $label,
                    $status,
                    $suffix
                );
            case $status === 404:
                return __(
                    '%1 could not find model "%2" (HTTP 404). Use "Fetch Latest Models" to select a current model.%3',
                    $label,
                    $this->getModel(),
                    $suffix
                );
            case $status === 429:
                return __(
                    '%1 rate limit or quota reached (HTTP 429). Wait a moment and try again, or check your plan.%2',
                    $label,
                    $suffix
                );
            case $status >= 500:
                return __('%1 is temporarily unavailable (HTTP %2). Please try again in a few moments.', $label, $status);
            default:
                return __('%1 returned HTTP %2.%3', $label, $status, $suffix);
        }
    }

    /**
     * The "error.message" (or similar) of an error response, shortened; '' when there is none.
     */
    private function extractErrorMessage(string $body): string
    {
        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            return '';
        }
        if (!is_array($decoded)) {
            return '';
        }

        $error   = $decoded['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? '') : ($error ?? $decoded['message'] ?? '');

        return is_string($message) ? mb_substr(trim(strip_tags($message)), 0, 300) : '';
    }

    private function describeConnectionError(string $message, int $timeout): \Magento\Framework\Phrase
    {
        if (stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false) {
            return __(
                '%1 did not answer within %2 seconds. Try again, or increase "AI Request Timeout" in the configuration.',
                $this->getLabel(),
                $timeout
            );
        }

        return __('Could not connect to %1: %2', $this->getLabel(), $message);
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Seconds to wait before the next attempt: Retry-After when sent, otherwise 1s, 2s, … (capped).
     */
    private function getRetryDelay(int $attempt): int
    {
        $retryAfter = 0;
        try {
            $headers = array_change_key_case((array) $this->curl->getHeaders(), CASE_LOWER);
            $value   = $headers['retry-after'] ?? 0;
            $retryAfter = (int) ceil((float) (is_array($value) ? reset($value) : $value));
        } catch (\Exception $e) {
            $retryAfter = 0;
        }

        return min(self::MAX_RETRY_WAIT, max($retryAfter, 2 ** $attempt));
    }
}
