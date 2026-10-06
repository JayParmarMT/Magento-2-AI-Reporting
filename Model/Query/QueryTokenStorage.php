<?php
/**
 * Meetanshi AIReporting — remembers queries the server itself generated/executed
 *
 * After a successful run, the SQL is kept in the admin's session under a random token.
 * Saving a report sends only that token, so the browser can never submit its own SQL.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Magento\Backend\Model\Session;

class QueryTokenStorage
{
    private const SESSION_KEY = 'meetanshi_aireporting_queries';
    private const MAX_ENTRIES = 20;

    public function __construct(
        private readonly Session $session
    ) {
    }

    /**
     * Store an executed query and return the token that refers to it.
     */
    public function remember(string $nlpQuery, string $sql): string
    {
        $token   = bin2hex(random_bytes(16));
        $entries = $this->getEntries();
        $entries[$token] = ['nlp' => $nlpQuery, 'sql' => $sql];

        $this->session->setData(self::SESSION_KEY, array_slice($entries, -self::MAX_ENTRIES, null, true));

        return $token;
    }

    /**
     * @return array{nlp: string, sql: string}|null
     */
    public function get(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return $this->getEntries()[$token] ?? null;
    }

    private function getEntries(): array
    {
        $entries = $this->session->getData(self::SESSION_KEY);

        return is_array($entries) ? $entries : [];
    }
}
