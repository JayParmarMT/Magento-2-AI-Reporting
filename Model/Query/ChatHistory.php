<?php
/**
 * Meetanshi AIReporting — the last few turns of the admin's chat, for follow-up questions
 *
 * Kept in the admin session on the server (like QueryTokenStorage), so the browser never
 * supplies SQL that ends up in the prompt. A conversation left alone for 30 minutes, or cleared
 * in the chat, starts fresh.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Magento\Backend\Model\Session;

class ChatHistory
{
    private const SESSION_KEY = 'meetanshi_aireporting_chat_history';

    /**
     * Turns sent with a new question.
     */
    private const MAX_TURNS = 3;

    /**
     * Seconds of inactivity after which the conversation starts over.
     */
    private const EXPIRY = 1800;

    public function __construct(
        private readonly Session $session
    ) {
    }

    /**
     * Earlier turns, oldest first.
     *
     * @return array<int, array{question: string, sql: string}>
     */
    public function getTurns(): array
    {
        $data = $this->session->getData(self::SESSION_KEY);
        if (!is_array($data) || ($data['updated'] ?? 0) < time() - self::EXPIRY) {
            return [];
        }

        return array_values(array_filter(
            (array) ($data['turns'] ?? []),
            static fn ($turn) => is_array($turn) && isset($turn['question'], $turn['sql'])
        ));
    }

    public function add(string $question, string $sql): void
    {
        $turns   = $this->getTurns();
        $turns[] = ['question' => mb_substr($question, 0, 1000), 'sql' => mb_substr($sql, 0, 4000)];

        $this->session->setData(self::SESSION_KEY, [
            'updated' => time(),
            'turns'   => array_slice($turns, -self::MAX_TURNS),
        ]);
    }

    public function clear(): void
    {
        $this->session->unsetData(self::SESSION_KEY);
    }
}
