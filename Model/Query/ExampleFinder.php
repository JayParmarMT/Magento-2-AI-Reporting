<?php
/**
 * Meetanshi AIReporting — saved reports as examples for the AI
 *
 * A saved report is a question an admin asked, checked and kept, with the SQL that answered it
 * on this store. Including the most similar ones with a new question shows the AI the joins and
 * filters that work here. Similarity is word overlap; at the number of reports a store keeps,
 * that is fast and needs no embeddings or vector database.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Magento\Framework\App\ResourceConnection;

class ExampleFinder
{
    private const TABLE = 'meetanshi_aireporting_saved_report';

    /**
     * Most recent saved reports considered.
     */
    private const MAX_CANDIDATES = 300;

    /**
     * Minimum share of words two questions must have in common (Jaccard index).
     */
    private const MIN_SIMILARITY = 0.34;

    /**
     * Words that say nothing about which data a question needs. Period and ranking words
     * ("today", "month", "top") are kept: they shape the SQL.
     */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'these', 'what', 'which', 'who', 'how', 'many',
        'much', 'are', 'was', 'were', 'has', 'have', 'had', 'does', 'did', 'can', 'could', 'would', 'should',
        'there', 'their', 'our', 'your', 'you', 'all', 'any', 'each', 'per', 'show', 'list', 'give', 'tell',
        'find', 'get', 'display', 'please', 'me', 'my', 'is', 'in', 'on', 'of', 'to', 'by', 'or', 'an', 'a',
        'its', 'it', 'be', 'do', 'we', 'us', 'i', 'store',
    ];

    /**
     * @var array<int, array{question: string, sql: string, words: string[]}>|null
     */
    private ?array $examples = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Saved reports most similar to the question, best first.
     *
     * @return array<int, array{question: string, sql: string}>
     */
    public function find(string $question, int $limit = 3): array
    {
        $words = $this->getWords($question);
        if (!$words) {
            return [];
        }

        $scores = [];
        foreach ($this->getExamples() as $i => $example) {
            $common = count(array_intersect($words, $example['words']));
            if ($common === 0) {
                continue;
            }
            $similarity = $common / count(array_unique([...$words, ...$example['words']]));
            if ($similarity >= self::MIN_SIMILARITY) {
                $scores[$i] = $similarity;
            }
        }
        arsort($scores);

        $found = [];
        $seen  = [];
        foreach (array_keys($scores) as $i) {
            $example = $this->examples[$i];
            if (isset($seen[$example['sql']])) {
                continue;
            }
            $seen[$example['sql']] = true;
            $found[] = ['question' => $example['question'], 'sql' => $example['sql']];
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * @return array<int, array{question: string, sql: string, words: string[]}>
     */
    private function getExamples(): array
    {
        if ($this->examples !== null) {
            return $this->examples;
        }

        $this->examples = [];
        try {
            $connection = $this->resourceConnection->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($this->resourceConnection->getTableName(self::TABLE), ['nlp_query', 'sql_query'])
                    ->order('report_id DESC')
                    ->limit(self::MAX_CANDIDATES)
            );
        } catch (\Exception $e) {
            return $this->examples;
        }

        foreach ($rows as $row) {
            $question = trim((string) $row['nlp_query']);
            $sql      = trim((string) $row['sql_query']);
            if ($question !== '' && $sql !== '') {
                $this->examples[] = [
                    'question' => $question,
                    'sql'      => $this->stripTablePrefix($sql),
                    'words'    => $this->getWords($question),
                ];
            }
        }

        return $this->examples;
    }

    /**
     * Lower-case significant words in singular form.
     *
     * @return string[]
     */
    private function getWords(string $text): array
    {
        $stop  = array_flip(self::STOP_WORDS);
        $words = [];
        foreach (preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (isset($stop[$word]) || ctype_digit($word)) {
                continue;
            }
            $words[] = (strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss'))
                ? substr($word, 0, -1)
                : $word;
        }

        return array_values(array_unique($words));
    }

    /**
     * Saved SQL has the store's table prefix applied; the AI works with unprefixed names.
     */
    private function stripTablePrefix(string $sql): string
    {
        $prefix = (string) $this->resourceConnection->getTableName('');
        if ($prefix === '') {
            return $sql;
        }

        return (string) preg_replace('/\b(FROM|JOIN)(\s+`?)' . preg_quote($prefix, '/') . '/i', '$1$2', $sql);
    }
}
