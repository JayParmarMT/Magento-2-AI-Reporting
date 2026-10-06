<?php
/**
 * Meetanshi AIReporting — question → SQL → result, with one self-correction
 *
 * If the database rejects the generated SQL for a fixable reason (unknown column/table,
 * syntax, GROUP BY misuse …), the error is sent back to the LLM once to correct its query.
 * Security rejections, timeouts and read-only violations are never retried.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Meetanshi\AIReporting\Exception\QueryException;

class QueryRunner
{
    private const REPAIRABLE_ERRORS =
        "/(Unknown column|Unknown table|doesn't exist|syntax|ambiguous|Invalid use of group|isn't in GROUP BY"
        . "|Operand should contain|Illegal mix of collations|Incorrect parameter count|Unknown function"
        . "|FUNCTION [\\w.]+ does not exist|Every derived table must have its own alias)/i";

    /**
     * How many times to ask the LLM to correct a database-rejected query.
     */
    private const MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(
        private readonly NlpToSql $nlpToSql,
        private readonly QueryExecutor $queryExecutor
    ) {
    }

    /**
     * @return array{sql: string, result: array, repaired: bool}
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function run(string $question): array
    {
        $sql = $this->nlpToSql->convert($question);

        // First attempt
        try {
            return ['sql' => $sql, 'result' => $this->queryExecutor->execute($sql), 'repaired' => false];
        } catch (QueryException $e) {
            if (!$this->isRepairable($e->getMessage())) {
                throw $e;
            }
        }

        // Up to MAX_REPAIR_ATTEMPTS self-correction attempts, feeding each error back to the LLM
        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_REPAIR_ATTEMPTS; $attempt++) {
            $error = $lastException ? $lastException->getMessage() : $e->getMessage();
            $sql   = $this->nlpToSql->repair($question, $sql, $this->shortError($error));

            try {
                return [
                    'sql'      => $sql,
                    'result'   => $this->queryExecutor->execute($sql),
                    'repaired' => true
                ];
            } catch (QueryException $repairException) {
                $lastException = $repairException;
                if (!$this->isRepairable($repairException->getMessage())) {
                    throw $repairException;
                }
            }
        }

        // All repair attempts exhausted — surface the last database error
        throw $lastException;
    }

    public function isRepairable(string $message): bool
    {
        return str_contains($message, 'Query execution failed') && (bool) preg_match(self::REPAIRABLE_ERRORS, $message);
    }

    /**
     * Database error without the echoed SQL ("…, query was: SELECT …").
     */
    private function shortError(string $message): string
    {
        $message = (string) preg_replace('/^Query execution failed:\s*/', '', $message);
        $cut = stripos($message, ', query was:');

        return trim($cut === false ? $message : substr($message, 0, $cut));
    }
}
