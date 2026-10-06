<?php
/**
 * Meetanshi AIReporting — SQL guard for AI-generated / saved report queries
 *
 * Every query goes through sanitize() before it is executed or saved:
 *  - comments and markdown fences are removed (so the text that is validated is the text that runs),
 *  - exactly one SELECT / WITH statement is allowed,
 *  - write, locking, file and sleep constructs are rejected anywhere in the statement,
 *  - credential/session tables, secret columns and other schemas cannot be referenced.
 *
 * This is one layer; QueryExecutor also runs the query in a READ ONLY transaction with a
 * statement timeout, ideally on a dedicated read-only database user.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Magento\Framework\App\DeploymentConfig;
use Meetanshi\AIReporting\Exception\QueryException;

class SqlGuard
{
    /**
     * Tables holding credentials, sessions, tokens or admin accounts. Never queryable.
     */
    private const DENIED_TABLES = [
        'admin_user', 'admin_user_session', 'admin_passwords', 'admin_user_expiration',
        'authorization_role', 'authorization_rule',
        'integration', 'jwt_auth_revoked', 'password_reset_request_event',
        'vault_payment_token', 'vault_payment_token_order_payment_link',
        'persistent_session', 'customer_visitor', 'session',
        'meetanshi_aireporting_saved_report', 'meetanshi_aireporting_query_log',
    ];

    /**
     * Table name prefixes that are never queryable.
     */
    private const DENIED_TABLE_PREFIXES = ['oauth_', 'login_as_customer', 'adobe_ims', 'admin_adobe_ims'];

    /**
     * Columns that hold passwords, tokens or payment secrets. Never selectable; redacted if they appear.
     */
    private const DENIED_COLUMNS = [
        'password', 'password_hash', 'rp_token', 'rp_token_created_at', 'confirmation',
        'token', 'secret', 'api_key', 'access_token', 'refresh_token',
        'cc_number_enc', 'cc_cid_enc', 'cc_ss_issue', 'cc_secure_verify',
        'cc_debug_request_body', 'cc_debug_response_body', 'cc_debug_response_serialized',
        'additional_information', 'gateway_token', 'public_hash',
        'session_id', 'session_data', 'subscriber_confirm_code',
    ];

    /**
     * Tables that contain denied columns: "SELECT *" / "t.*" is refused when they are referenced,
     * so a secret column can never be pulled in (or renamed) through a wildcard.
     */
    private const WILDCARD_RESTRICTED_TABLES = [
        'customer_entity', 'sales_order_payment', 'quote_payment', 'sales_payment_transaction',
        'newsletter_subscriber', 'core_config_data',
    ];

    private const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    /**
     * Keywords that must not appear anywhere (outside string literals) in a read-only query.
     * INSERT/REPLACE/TRUNCATE are omitted on purpose: they are also SQL functions, and as statements
     * they are already excluded by the "must start with SELECT/WITH" rule.
     */
    private const FORBIDDEN_KEYWORDS = [
        'UPDATE', 'DELETE', 'INTO', 'DROP', 'ALTER', 'CREATE', 'RENAME', 'GRANT', 'REVOKE',
        'HANDLER', 'CALL', 'EXECUTE', 'PREPARE', 'DEALLOCATE', 'LOCK', 'UNLOCK', 'KILL', 'SHUTDOWN',
        'FLUSH', 'RESET', 'PURGE', 'INSTALL', 'UNINSTALL', 'LOAD', 'OUTFILE', 'DUMPFILE', 'PROCEDURE',
    ];

    private const FORBIDDEN_FUNCTIONS = [
        'SLEEP', 'BENCHMARK', 'LOAD_FILE', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'IS_FREE_LOCK',
        'IS_USED_LOCK', 'MASTER_POS_WAIT', 'SOURCE_POS_WAIT', 'MASTER_GTID_WAIT', 'WAIT_FOR_EXECUTED_GTID_SET',
        'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS', 'NEXTVAL', 'SETVAL', 'SYS_EXEC', 'SYS_EVAL',
    ];

    private const STATEMENT_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE',
        'RENAME', 'CALL', 'LOAD', 'LOCK', 'UNLOCK', 'SET', 'HANDLER', 'KILL', 'FLUSH', 'RESET', 'PURGE',
    ];

    private const SENSITIVE_CONFIG_PATTERN =
        '/(password|passwd|secret|private|api_?key|token|passphrase|credential|signature|encryption|license)/i';

    private const ENCRYPTED_VALUE_PATTERN = '/^\d+:\d+:[A-Za-z0-9+\/=]{16,}$/';

    public const REDACTED = '[redacted]';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Clean and validate a query. Returns the exact SQL that may be executed.
     *
     * @throws QueryException
     */
    public function sanitize(string $sql): string
    {
        $sql = preg_replace('/```(?:sql)?/i', '', $sql);
        [$clean, $masked, $literals] = $this->lex($sql);

        // Remove trailing semicolons/whitespace from both views, keeping them aligned
        $clean  = rtrim($clean);
        $masked = rtrim($masked);
        while (str_ends_with($masked, ';')) {
            $clean  = rtrim(substr($clean, 0, -1));
            $masked = rtrim(substr($masked, 0, -1));
        }
        $offset = strlen($clean) - strlen(ltrim($clean));
        $clean  = substr($clean, $offset);
        $masked = substr($masked, $offset);

        if ($clean === '') {
            throw new QueryException(__('AI did not return a valid SELECT query. Please rephrase your question.'));
        }

        if (!preg_match('/^(SELECT|WITH)\b/i', $masked)) {
            $first = strtoupper((string) strtok($masked, " \t\r\n("));
            if (in_array($first, self::STATEMENT_KEYWORDS, true)) {
                throw new QueryException(
                    __('Security violation: only SELECT queries are allowed (found %1).', $first)
                );
            }
            throw new QueryException(__('AI did not return a valid SELECT query. Please rephrase your question.'));
        }

        if (str_contains($masked, ';')) {
            throw new QueryException(__('Security violation: multiple SQL statements are not allowed.'));
        }

        if (preg_match('/\b(' . implode('|', self::FORBIDDEN_KEYWORDS) . ')\b/i', $masked, $m)
            || preg_match('/\bFOR\s+SHARE\b|\bNEXT\s+VALUE\s+FOR\b/i', $masked, $m)
        ) {
            throw new QueryException(
                __('Security violation: "%1" is not allowed in a read-only report query.', strtoupper($m[0]))
            );
        }

        if (preg_match('/\b(' . implode('|', self::FORBIDDEN_FUNCTIONS) . ')\s*\(/i', $masked, $m)) {
            throw new QueryException(__('Security violation: function %1() is not allowed.', strtoupper($m[1])));
        }

        if (str_contains($masked, '@')) {
            throw new QueryException(__('Security violation: user and system variables are not allowed.'));
        }

        if (preg_match('/\b(' . implode('|', self::SYSTEM_SCHEMAS) . ')`?\s*\./i', $masked, $m)) {
            throw new QueryException(__('Security violation: the %1 schema cannot be queried.', strtolower($m[1])));
        }

        $this->assertCurrentDatabaseOnly($masked);

        $identifiers = $this->getIdentifiers($masked);
        foreach ($identifiers as $identifier) {
            if ($this->isDeniedTable($identifier)) {
                throw new QueryException(
                    __('Security violation: table "%1" contains credentials and cannot be queried.', $identifier)
                );
            }
            if (in_array($identifier, self::DENIED_COLUMNS, true)) {
                throw new QueryException(
                    __('Security violation: column "%1" contains sensitive data and cannot be queried.', $identifier)
                );
            }
        }

        $restricted = array_intersect(array_map([$this, 'stripPrefix'], $identifiers), self::WILDCARD_RESTRICTED_TABLES);
        if ($restricted
            && preg_match('/(?:\bSELECT\s+(?:ALL\s+|DISTINCT\s+|DISTINCTROW\s+)?|,\s*)(?:`?\w+`?\s*\.\s*)?\*/i', $masked)
        ) {
            throw new QueryException(
                __('SELECT * is not allowed on %1; list the columns explicitly.', implode(', ', $restricted))
            );
        }

        if (in_array('core_config_data', $restricted, true)) {
            foreach ($literals as $literal) {
                if (preg_match(self::SENSITIVE_CONFIG_PATTERN, $literal)) {
                    throw new QueryException(
                        __('Security violation: configuration values for credentials cannot be queried.')
                    );
                }
            }
        }

        return $clean;
    }

    /**
     * Make sure the query returns at most $maxRows rows: append a LIMIT, or lower an existing top-level LIMIT.
     */
    public function applyRowLimit(string $sql, int $maxRows): string
    {
        $maxRows = max(1, $maxRows);
        [$clean, $masked] = $this->lex($sql);

        // Blank out everything inside parentheses so only top-level clauses remain
        $depth = 0;
        $topLevel = '';
        $length = strlen($masked);
        for ($i = 0; $i < $length; $i++) {
            $char = $masked[$i];
            if ($char === '(') {
                $depth++;
            }
            $topLevel .= ($depth > 0) ? ' ' : $char;
            if ($char === ')' && $depth > 0) {
                $depth--;
            }
        }

        $topLevel = rtrim($topLevel);

        // If the query already ends with a top-level LIMIT clause, cap its row count in place.
        if (preg_match(
            '/\bLIMIT\s+(\d+)(?:\s*,\s*(\d+)|\s+OFFSET\s+\d+)?\s*$/i',
            $topLevel,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            // The count to cap is the second number for "LIMIT offset, count", otherwise the first.
            [$count, $position] = (isset($m[2]) && $m[2][1] >= 0) ? $m[2] : $m[1];
            if ((int) $count > $maxRows) {
                $clean = substr_replace($clean, (string) $maxRows, $position, strlen($count));
            }

            return rtrim($clean);
        }

        // No usable top-level LIMIT. Guard against appending after a trailing LIMIT the regex
        // above could not fully parse (e.g. "LIMIT 10,") which would create an invalid double LIMIT.
        if (preg_match('/\bLIMIT\b[^()]*$/i', $topLevel)) {
            // There is a dangling/partial LIMIT at the end — do not append another one.
            return rtrim($clean);
        }

        return rtrim($clean) . ' LIMIT ' . $maxRows;
    }

    /**
     * Replace secret values in a result set: denied columns, encrypted config values and
     * values of credential-like configuration paths.
     */
    public function redactRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $sensitivePath = isset($row['path']) && is_string($row['path'])
                && preg_match(self::SENSITIVE_CONFIG_PATTERN, $row['path']);

            foreach ($row as $column => &$value) {
                $name = strtolower((string) $column);
                if (in_array($name, self::DENIED_COLUMNS, true)
                    || (is_string($value) && preg_match(self::ENCRYPTED_VALUE_PATTERN, $value))
                    || ($sensitivePath && !in_array($name, ['path', 'config_id', 'scope', 'scope_id', 'updated_at'], true))
                ) {
                    $value = self::REDACTED;
                }
            }
            unset($value);
        }
        unset($row);

        return $rows;
    }

    /**
     * Whether a table (with or without the configured table prefix) must never be exposed.
     */
    public function isDeniedTable(string $table): bool
    {
        $table = $this->stripPrefix(strtolower($table));
        if (in_array($table, self::DENIED_TABLES, true)) {
            return true;
        }
        foreach (self::DENIED_TABLE_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isDeniedColumn(string $column): bool
    {
        return in_array(strtolower($column), self::DENIED_COLUMNS, true);
    }

    /**
     * Whether a configuration value must not be shown: a credential-like path or an encrypted value.
     */
    public function isSecretConfigValue(string $path, mixed $value): bool
    {
        return (bool) preg_match(self::SENSITIVE_CONFIG_PATTERN, $path)
            || (is_string($value) && preg_match(self::ENCRYPTED_VALUE_PATTERN, $value));
    }

    /**
     * Split SQL into:
     *  - clean:    SQL with comments removed (this is what gets executed),
     *  - masked:   same length as clean, string literal contents replaced by "x"
     *              (backtick-quoted identifiers are kept as-is) for keyword/identifier checks,
     *  - literals: contents of string literals.
     *
     * @return array{0: string, 1: string, 2: string[]}
     * @throws QueryException
     */
    private function lex(string $sql): array
    {
        $clean = '';
        $masked = '';
        $literals = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"' || $char === '`') {
                $j = $i + 1;
                $content = '';
                while (true) {
                    if ($j >= $length) {
                        throw new QueryException(__('The SQL query contains an unterminated quote.'));
                    }
                    $current = $sql[$j];
                    if ($current === '\\' && $char !== '`' && $j + 1 < $length) {
                        $content .= $current . $sql[$j + 1];
                        $j += 2;
                        continue;
                    }
                    if ($current === $char) {
                        if ($j + 1 < $length && $sql[$j + 1] === $char) {
                            $content .= $char . $char;
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $content .= $current;
                    $j++;
                }

                $clean .= substr($sql, $i, $j - $i + 1);
                if ($char === '`') {
                    $masked .= '`' . $content . '`';
                } else {
                    $masked .= $char . str_repeat('x', strlen($content)) . $char;
                    $literals[] = $content;
                }
                $i = $j + 1;
                continue;
            }

            $isDashComment = $char === '-' && ($sql[$i + 1] ?? '') === '-'
                && ($i + 2 >= $length || ctype_space($sql[$i + 2]));
            if ($char === '#' || $isDashComment) {
                $end = strpos($sql, "\n", $i);
                $i = ($end === false) ? $length : $end;
                $clean .= ' ';
                $masked .= ' ';
                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new QueryException(__('The SQL query contains an unterminated comment.'));
                }
                $i = $end + 2;
                $clean .= ' ';
                $masked .= ' ';
                continue;
            }

            $clean .= $char;
            $masked .= $char;
            $i++;
        }

        return [$clean, $masked, $literals];
    }

    /**
     * Lower-cased identifier tokens (table, column and alias names) of a masked query.
     */
    private function getIdentifiers(string $masked): array
    {
        // Drop the masked string literals ('xxx') so their filler is not read as identifiers
        $withoutLiterals = preg_replace('/([\'"])x*\1/', ' ', $masked);
        preg_match_all('/[A-Za-z_][A-Za-z0-9_$]*/', (string) $withoutLiterals, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0])));
    }

    /**
     * Reject FROM/JOIN references qualified with another database name.
     * "FROM" inside EXTRACT(), TRIM() or SUBSTRING() is a column expression, not a table, and is skipped.
     */
    private function assertCurrentDatabaseOnly(string $masked): void
    {
        $current = strtolower((string) $this->deploymentConfig->get('db/connection/default/dbname'));
        if (!preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?\s*\.\s*`?\w+/i', $masked, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as [$schema, $offset]) {
            if (strtolower($schema) === $current || $this->isInsideFunction($masked, $offset)) {
                continue;
            }
            throw new QueryException(
                __('Security violation: tables from other databases (%1) cannot be queried.', $schema)
            );
        }
    }

    /**
     * Whether $offset sits directly inside EXTRACT(...), TRIM(...), SUBSTRING(...) or SUBSTR(...).
     */
    private function isInsideFunction(string $masked, int $offset): bool
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            if ($masked[$i] === ')') {
                $depth++;
            } elseif ($masked[$i] === '(') {
                if ($depth === 0) {
                    return (bool) preg_match('/\b(EXTRACT|TRIM|SUBSTRING|SUBSTR)\s*$/i', substr($masked, 0, $i));
                }
                $depth--;
            }
        }

        return false;
    }

    private function stripPrefix(string $table): string
    {
        $prefix = strtolower((string) $this->deploymentConfig->get('db/table_prefix'));

        return ($prefix !== '' && str_starts_with($table, $prefix)) ? substr($table, strlen($prefix)) : $table;
    }
}
