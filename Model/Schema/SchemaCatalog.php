<?php
/**
 * Meetanshi AIReporting — live database schema for the AI prompt
 *
 * Reads every table and column of the store database once (cached until the config cache is
 * cleaned or setup:upgrade runs), so a question about any table — Magento core, Adobe Commerce
 * or a third-party module — is answered with the store's real table and column names.
 * Only the tables that match a question are sent to the AI, which keeps the prompt small.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Schema;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Meetanshi\AIReporting\Model\Query\SqlGuard;

class SchemaCatalog
{
    private const CACHE_ID       = 'meetanshi_aireporting_schema_catalog';
    private const CACHE_LIFETIME = 86400;

    /**
     * Columns listed per table in the prompt (the most relevant ones first).
     */
    private const MAX_COLUMNS = 40;

    /**
     * Index, changelog, sequence, replica and temporary tables never help to answer a question.
     */
    private const NOISE_PATTERN = '/(_cl$|^sequence_|_(tmp|temp|idx|replica)$|_(tmp|idx|replica)_|_index_store\d|'
        . '^catalogsearch_fulltext|^cache(_tag)?$|^flag$|^patch_list$|^ui_bookmark$|^release_notification)/';

    /**
     * Short words that still identify a table (most 3-letter words are noise).
     */
    private const SHORT_TERMS = ['rma', 'faq', 'sku', 'tax', 'cms', 'url', 'seo', 'ups', 'dhl', 'b2b', 'sms', 'pos', 'erp', 'crm'];

    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'these', 'those', 'what', 'which', 'who', 'whom',
        'how', 'many', 'much', 'are', 'was', 'were', 'has', 'have', 'had', 'does', 'did', 'can', 'could',
        'will', 'would', 'should', 'there', 'their', 'them', 'they', 'our', 'your', 'you', 'all', 'any',
        'each', 'per', 'into', 'over', 'under', 'about', 'than', 'then', 'show', 'list', 'give', 'tell',
        'find', 'get', 'display', 'please', 'me', 'my', 'is', 'in', 'on', 'of', 'to', 'by', 'or', 'an', 'a',
        'top', 'most', 'least', 'best', 'total', 'count', 'number', 'sum', 'average', 'avg', 'today',
        'yesterday', 'week', 'month', 'year', 'last', 'first', 'current', 'recent', 'latest', 'days', 'day',
        'store', 'magento', 'data', 'report', 'table', 'tables', 'record', 'records', 'row', 'rows', 'value',
        'values', 'also', 'not', 'yet', 'still', 'ever', 'never', 'only', 'just', 'some', 'more', 'less',
    ];

    /**
     * Everyday wording → words that appear in Magento / extension table names.
     */
    private const SYNONYMS = [
        'return'       => ['rma'],
        'refund'       => ['creditmemo'],
        'loyalty'      => ['reward', 'point'],
        'point'        => ['reward'],
        'cart'         => ['quote'],
        'basket'       => ['quote'],
        'abandoned'    => ['quote'],
        'tracking'     => ['track'],
        'comment'      => ['history'],
        'coupon'       => ['salesrule'],
        'promotion'    => ['salesrule', 'catalogrule'],
        'discount'     => ['salesrule'],
        'cartrule'     => ['salesrule'],
        'pricerule'    => ['catalogrule', 'salesrule'],
        'catalogpricerule' => ['catalogrule'],
        'storecredit'  => ['customerbalance'],
        'credit'       => ['customerbalance'],
        'view'         => ['viewed'],
        'notify'       => ['alert'],
        'subscriber'   => ['newsletter'],
        'subscription' => ['newsletter'],
        'inventory'    => ['stock', 'inventory'],
        'quantity'     => ['stock'],
        'warehouse'    => ['source'],
        'image'        => ['gallery'],
        'photo'        => ['gallery'],
        'video'        => ['gallery'],
        'bestseller'   => ['bestsellers'],
        'redirect'     => ['rewrite'],
        'variant'      => ['super', 'relation'],
        'job'          => ['cron'],
        'term'         => ['search'],
        'blog'         => ['post'],
        'article'      => ['post'],
        'wrapping'     => ['giftwrapping'],
        'registry'     => ['giftregistry'],
    ];

    /**
     * @var array<string, array{comment: string, columns: string[]}>|null
     */
    private ?array $tables = null;

    /**
     * Number of tables each name word occurs in (for weighting rare words higher).
     *
     * @var array<string, int>|null
     */
    private ?array $wordFrequency = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly SqlGuard $sqlGuard
    ) {
    }

    /**
     * Every queryable table (without the table prefix) with its comment and columns.
     *
     * @return array<string, array{comment: string, columns: string[]}>
     */
    public function getTables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $cached = $this->cache->load(self::CACHE_ID);
        if ($cached) {
            try {
                $this->tables = (array) $this->serializer->unserialize($cached);
                return $this->tables;
            } catch (\InvalidArgumentException $e) {
                $this->tables = null;
            }
        }

        try {
            $this->tables = $this->loadTables();
        } catch (\Exception $e) {
            // Schema unavailable (e.g. no INFORMATION_SCHEMA access): the prompt falls back to the core schema
            return $this->tables = [];
        }

        $this->cache->save(
            (string) $this->serializer->serialize($this->tables),
            self::CACHE_ID,
            [ConfigCache::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $this->tables;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->getTables()[$this->stripPrefix(strtolower($table))]);
    }

    /**
     * @return string[]
     */
    public function getColumns(string $table): array
    {
        return $this->getTables()[$this->stripPrefix(strtolower($table))]['columns'] ?? [];
    }

    /**
     * Tables that best match the question, best first.
     *
     * @param string[] $priorityTables tables that must be included first (e.g. of a module the question names)
     * @return string[]
     */
    public function findRelevantTables(string $question, array $priorityTables = [], int $limit = 8): array
    {
        $tables = $this->getTables();
        if (!$tables) {
            return [];
        }

        $terms  = $this->getQuestionTerms($question);
        $scores = [];
        foreach ($tables as $table => $info) {
            $score = $terms ? $this->scoreTable($table, $info, $terms) : 0.0;
            if ($score >= 3.0) {
                // Prefer shorter (more general) table names on equal scores
                $scores[$table] = $score - 0.01 * substr_count($table, '_');
            }
        }
        arsort($scores);

        // Priority tables first (the best-matching of them first), then the other matches
        $priority = [];
        foreach ($priorityTables as $table) {
            $table = $this->stripPrefix(strtolower($table));
            if (isset($tables[$table])) {
                $priority[$table] = $scores[$table] ?? 0.0;
            }
        }
        arsort($priority);
        $priority = array_keys($priority);

        return array_slice(array_values(array_unique([...$priority, ...array_keys($scores)])), 0, max(1, $limit));
    }

    /**
     * Columns of a table whose names match the question (used to point out columns that third-party
     * modules add to core tables, e.g. sales_order.some_flag).
     *
     * @param string[] $exclude columns to leave out (already described elsewhere)
     * @return string[]
     */
    public function findMatchingColumns(string $table, string $question, array $exclude = []): array
    {
        $terms = array_filter(
            $this->getQuestionTerms($question),
            static fn (string $term) => strlen($term) >= 5
        );
        if (!$terms) {
            return [];
        }

        $exclude = array_flip(array_map('strtolower', $exclude));
        $matches = [];
        foreach ($this->getColumns($table) as $column) {
            if (isset($exclude[$column])) {
                continue;
            }
            foreach ($terms as $term) {
                if (str_contains($column, $term)) {
                    $matches[] = $column;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * One prompt line: "- table (col, col, …) -- comment [label]". Columns matching the question come first.
     */
    public function describeTable(
        string $table,
        string $question = '',
        string $label = '',
        int $maxColumns = self::MAX_COLUMNS
    ): string {
        $table = $this->stripPrefix(strtolower($table));
        $info  = $this->getTables()[$table] ?? null;
        if ($info === null) {
            return '';
        }

        $columns = $info['columns'];
        $matched = $question !== '' ? $this->findMatchingColumns($table, $question) : [];
        if ($matched) {
            $columns = array_values(array_unique([...$matched, ...$columns]));
        }

        $list = implode(', ', array_slice($columns, 0, $maxColumns));
        if (count($columns) > $maxColumns) {
            $list .= ', …';
        }

        $line = "- {$table} ({$list})";
        if ($info['comment'] !== '') {
            $line .= ' -- ' . $info['comment'];
        }
        if ($label !== '') {
            $line .= ' [' . $label . ']';
        }

        return $line;
    }

    /**
     * Existing tables with a name close to an unknown one (for correcting "table doesn't exist").
     *
     * @return string[]
     */
    public function suggestTables(string $unknownTable, int $limit = 5): array
    {
        $unknown = $this->stripPrefix(strtolower($unknownTable));
        $scores  = [];
        foreach (array_keys($this->getTables()) as $table) {
            similar_text($unknown, $table, $percent);
            $sharedWords = count(array_intersect(explode('_', $unknown), explode('_', $table)));
            $score = $percent + 15 * $sharedWords;
            if ($score >= 60) {
                $scores[$table] = $score;
            }
        }
        arsort($scores);

        return array_slice(array_keys($scores), 0, $limit);
    }

    /**
     * Significant words of a question: singular forms, joined word pairs ("gift card" → giftcard)
     * and synonyms that appear in table names.
     *
     * @return string[]
     */
    public function getQuestionTerms(string $question): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop  = array_flip(self::STOP_WORDS);
        $terms = [];

        foreach ($words as $i => $word) {
            if (!isset($stop[$word]) && !ctype_digit($word)
                && (strlen($word) >= 4 || in_array($word, self::SHORT_TERMS, true))
            ) {
                $terms[] = $this->singular($word);
            }
            // Joined pairs and triples catch compound names: "size chart" → sizechart, "shop by brand" → shopbybrand
            foreach ([2, 3] as $size) {
                $parts = array_slice($words, $i, $size);
                if (count($parts) === $size && !array_filter($parts, 'ctype_digit')
                    && !isset($stop[$parts[0]]) && !isset($stop[$parts[$size - 1]])
                ) {
                    $terms[] = $this->singular(implode('', $parts));
                }
            }
        }

        foreach ($terms as $term) {
            foreach (self::SYNONYMS[$term] ?? [] as $synonym) {
                $terms[] = $synonym;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Weighted match of the question terms against a table's name, comment and columns.
     *
     * Name words match whole ("rule"), joined ("gift_card" ← giftcard) or as the start/end of a word
     * ("rule" → catalogrule), never in the middle ("sent" must not match addre-ss_ent-ity).
     * Tables with many name words the question does not mention score lower.
     *
     * @param array{comment: string, columns: string[]} $info
     * @param string[] $terms
     */
    private function scoreTable(string $table, array $info, array $terms): float
    {
        $words   = array_map([$this, 'singular'], explode('_', $table));
        $count   = count($words);
        $comment = strtolower($info['comment']);
        $matched = [];
        $score   = 0.0;
        $columnScore = 0.0;

        foreach ($terms as $term) {
            $weight = $this->getTermWeight($term);
            $hit    = $this->matchNameWords($term, $words);
            if ($hit !== null) {
                $score  += ($hit[1] ? 3 : 2) * $weight;
                $matched += array_flip($hit[0]);
            } elseif ($comment !== '' && preg_match('/\b' . preg_quote($term, '/') . '/', $comment)) {
                $score += 1.5;
            } elseif (strlen($term) >= 5 && $columnScore < 1.0) {
                foreach ($info['columns'] as $column) {
                    if (str_contains($column, $term)) {
                        $columnScore += 0.5;
                        break;
                    }
                }
            }
        }

        if ($score <= 0) {
            // Column matches only help a table that already matches by name or comment
            return 0.0;
        }

        return ($score + $columnScore) / (1 + 0.25 * ($count - count($matched)));
    }

    /**
     * Indexes of the table-name words a term matches, and whether the match is exact; null for no match.
     *
     * @param string[] $words singular table-name words
     * @return array{0: int[], 1: bool}|null
     */
    private function matchNameWords(string $term, array $words): ?array
    {
        $count = count($words);
        foreach ($words as $i => $word) {
            if ($word === $term) {
                return [[$i], true];
            }
            // Two or three consecutive words written as one: gift_card → giftcard
            for ($size = 2; $size <= 3 && $i + $size <= $count; $size++) {
                if (implode('', array_slice($words, $i, $size)) === $term) {
                    return [range($i, $i + $size - 1), true];
                }
            }
        }

        if (strlen($term) >= 4) {
            foreach ($words as $i => $word) {
                if (strlen($word) > strlen($term) && (str_starts_with($word, $term) || str_ends_with($word, $term))) {
                    return [[$i], false];
                }
            }
        }

        return null;
    }

    /**
     * Rare table-name words weigh more than common ones ("sizechart" vs. "sales").
     */
    private function getTermWeight(string $term): float
    {
        if ($this->wordFrequency === null) {
            $this->wordFrequency = [];
            foreach (array_keys($this->getTables()) as $table) {
                foreach (array_unique(array_map([$this, 'singular'], explode('_', $table))) as $word) {
                    $this->wordFrequency[$word] = ($this->wordFrequency[$word] ?? 0) + 1;
                }
            }
        }

        $total     = max(1, count($this->getTables()));
        $frequency = $this->wordFrequency[$term] ?? 1;

        return 1 + log($total / max(1, $frequency)) / 2;
    }

    private function singular(string $word): string
    {
        $length = strlen($word);
        if ($length > 4 && str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }
        if ($length > 4 && preg_match('/(sses|uses|xes|ches|shes)$/', $word)) {
            return substr($word, 0, -2);
        }
        if ($length > 3 && str_ends_with($word, 's') && !preg_match('/(ss|us|is)$/', $word)) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * @return array<string, array{comment: string, columns: string[]}>
     */
    private function loadTables(): array
    {
        // Two plain queries: joining COLUMNS with TABLES is very slow on MariaDB
        $connection = $this->resourceConnection->getConnection();
        $comments   = $connection->fetchPairs(
            'SELECT TABLE_NAME, TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        $rows = $connection->fetchAll(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );

        $tables = [];
        foreach ($rows as $row) {
            $physical = (string) $row['table_name'];
            $table    = $this->stripPrefix(strtolower($physical));
            $column   = strtolower((string) $row['column_name']);
            if (preg_match(self::NOISE_PATTERN, $table) || $this->sqlGuard->isDeniedTable($table)) {
                continue;
            }
            if (!isset($tables[$table])) {
                $tables[$table] = [
                    'comment' => mb_substr(trim((string) ($comments[$physical] ?? '')), 0, 80),
                    'columns' => [],
                ];
            }
            if (!$this->sqlGuard->isDeniedColumn($column)) {
                $tables[$table]['columns'][] = $column;
            }
        }

        return $tables;
    }

    private function stripPrefix(string $table): string
    {
        $prefix = strtolower((string) $this->resourceConnection->getTableName(''));

        return ($prefix !== '' && str_starts_with($table, $prefix)) ? substr($table, strlen($prefix)) : $table;
    }
}
