<?php
/**
 * Meetanshi AIReporting — shared HTTP handling (AbstractProvider) through the Groq and OpenAI providers
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\LLM;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\LLM\GroqProvider;
use Meetanshi\AIReporting\Model\LLM\OpenAIProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChatCompletionsProviderTest extends TestCase
{
    private Curl|MockObject $curl;
    private Config|MockObject $config;
    private array $bodies = [];
    private array $pauses = [];

    protected function setUp(): void
    {
        $this->curl   = $this->createMock(Curl::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getGroqApiKey')->willReturn('gsk-test');
        $this->config->method('getGroqModel')->willReturn('llama-x');
        $this->config->method('getOpenAiApiKey')->willReturn('sk-test');
        $this->config->method('getMaxTokens')->willReturn(1024);
        $this->config->method('getLlmTimeout')->willReturn(120);

        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) {
            $this->bodies[] = json_decode($body, true);
        });
    }

    private function groq(): GroqProvider
    {
        $pauses = &$this->pauses;

        return new class ($this->config, $this->curl, new Json(), $this->createMock(LoggerInterface::class), $pauses) extends GroqProvider {
            private array $pauses;

            public function __construct($config, $curl, $json, $logger, array &$pauses)
            {
                parent::__construct($config, $curl, $json, $logger);
                $this->pauses = &$pauses;
            }

            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
            }
        };
    }

    public function testRateLimitIsRetriedHonouringRetryAfter(): void
    {
        $this->curl->method('getStatus')->willReturnOnConsecutiveCalls(429, 200);
        $this->curl->method('getHeaders')->willReturn(['Retry-After' => '3']);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            '{"error":{"message":"slow down"}}',
            '{"choices":[{"message":{"content":"SELECT 1"}}]}'
        );

        $this->assertSame('SELECT 1', $this->groq()->complete('q', 'sys'));
        $this->assertSame([3], $this->pauses);
        $this->assertCount(2, $this->bodies);
        $this->assertSame(['role' => 'system', 'content' => 'sys'], $this->bodies[0]['messages'][0]);
    }

    public function testUnknownModelNamesTheModelAndTheProviderExplanation(): void
    {
        $this->curl->method('getStatus')->willReturn(404);
        $this->curl->method('getBody')->willReturn('{"error":{"message":"The model `llama-x` does not exist"}}');

        try {
            $this->groq()->complete('q');
            $this->fail('An exception was expected.');
        } catch (LlmException $e) {
            $this->assertStringContainsString('could not find model "llama-x"', $e->getMessage());
            $this->assertStringContainsString('does not exist', $e->getMessage());
            $this->assertSame([], $this->pauses, '404 must not be retried');
        }
    }

    public function testWithoutSystemPromptOnlyTheUserMessageIsSent(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"choices":[{"message":{"content":"OK"}}]}');

        $this->groq()->complete('q');

        $this->assertSame([['role' => 'user', 'content' => 'q']], $this->bodies[0]['messages']);
    }

    public function testTimeoutGetsActionableMessage(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('post')->willThrowException(new \Exception('Operation timed out after 120001 milliseconds'));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('did not answer within 120 seconds');
        $this->groq()->complete('q');
    }

    public function testOpenAiReasoningModelsGetNoTemperature(): void
    {
        $this->config->method('getOpenAiModel')->willReturn('gpt-5-mini');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"choices":[{"message":{"content":"SELECT 1"}}]}');

        $provider = new OpenAIProvider($this->config, $this->curl, new Json(), $this->createMock(LoggerInterface::class));
        $provider->complete('q');

        $this->assertArrayNotHasKey('temperature', $this->bodies[0]);
        $this->assertArrayNotHasKey('max_tokens', $this->bodies[0]);
        $this->assertSame(8000, $this->bodies[0]['max_completion_tokens']);
    }
}
