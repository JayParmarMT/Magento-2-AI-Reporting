<?php
/**
 * Meetanshi AIReporting — ChatHistory Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Query;

use Magento\Backend\Model\Session;
use Meetanshi\AIReporting\Model\Query\ChatHistory;
use PHPUnit\Framework\TestCase;

class ChatHistoryTest extends TestCase
{
    private array $store = [];
    private ChatHistory $history;

    protected function setUp(): void
    {
        // setData()/unsetData() are magic methods of the session (handled by __call)
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call'])
            ->getMock();
        $session->method('getData')->willReturnCallback(fn (string $key = '') => $this->store[$key] ?? null);
        $session->method('__call')->willReturnCallback(function (string $method, array $args) use ($session) {
            if ($method === 'setData') {
                $this->store[$args[0]] = $args[1];
            } elseif ($method === 'unsetData') {
                unset($this->store[$args[0]]);
            }
            return $session;
        });

        $this->history = new ChatHistory($session);
    }

    public function testKeepsTheLastThreeTurnsOldestFirst(): void
    {
        foreach (['q1', 'q2', 'q3', 'q4'] as $question) {
            $this->history->add($question, 'SELECT ' . $question);
        }

        $this->assertSame(['q2', 'q3', 'q4'], array_column($this->history->getTurns(), 'question'));
        $this->assertSame('SELECT q4', $this->history->getTurns()[2]['sql']);
    }

    public function testClearStartsANewConversation(): void
    {
        $this->history->add('q1', '');
        $this->history->clear();

        $this->assertSame([], $this->history->getTurns());
    }

    public function testConversationExpiresAfterInactivity(): void
    {
        $this->history->add('q1', 'SELECT 1');
        $key = array_key_first($this->store);
        $this->store[$key]['updated'] = time() - 3600;

        $this->assertSame([], $this->history->getTurns());
    }
}
