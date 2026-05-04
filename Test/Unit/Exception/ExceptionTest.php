<?php
/**
 * Meetanshi AIReporting — Exception Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Exception\QueryException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    // ── LlmException ─────────────────────────────────────────────────────

    public function testLlmExceptionExtendsLocalizedException(): void
    {
        $exception = new LlmException(new Phrase('LLM error'));
        $this->assertInstanceOf(LocalizedException::class, $exception);
    }

    public function testLlmExceptionContainsMessage(): void
    {
        $exception = new LlmException(new Phrase('Provider %1 failed', ['groq']));
        $this->assertStringContainsString('groq', $exception->getMessage());
    }

    // ── QueryException ───────────────────────────────────────────────────

    public function testQueryExceptionExtendsLocalizedException(): void
    {
        $exception = new QueryException(new Phrase('Query error'));
        $this->assertInstanceOf(LocalizedException::class, $exception);
    }

    public function testQueryExceptionContainsMessage(): void
    {
        $exception = new QueryException(new Phrase('Query execution failed: %1', ['syntax error']));
        $this->assertStringContainsString('syntax error', $exception->getMessage());
    }
}
