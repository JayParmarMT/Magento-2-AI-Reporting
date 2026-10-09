<?php
/**
 * Meetanshi AIReporting — accuracy test against known correct answers
 *
 * run_ai_test.php checks that generated SQL is valid and runs; this script checks that it returns
 * the RIGHT data. Each case in gold_queries.json has a reference query (or "expect": "no_data" for
 * data the store does not record). A case passes when the reference result is contained in the
 * result of the AI's query — same rows, extra columns allowed, numbers compared to 2 decimals.
 *
 * Use it before and after changing prompts, retrieval or the model, to see whether accuracy moved.
 * Every case calls the configured AI provider (1–3 requests each, with repairs): costs apply for
 * hosted providers; Ollama runs locally for free.
 *
 * Usage (from the Magento root, after setup:di:compile when the store runs compiled DI):
 *   php app/code/Meetanshi/AIReporting/Test/ai-prompts/run_gold_test.php [options]
 *
 * Options:
 *   --check-gold   Only run the reference queries and print their results (no AI calls)
 *   --id=1,5,28    Run only these cases
 *   --verbose      Print the generated SQL of passing cases too
 *   --output=FILE  JSON report path (default: var/log/aireporting_gold_<date>.json)
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

use Meetanshi\AIReporting\Exception\DirectAnswerException;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\Query\QueryRunner;
use Meetanshi\AIReporting\Model\Report\ReportContext;

$magentoRoot = realpath(__DIR__ . '/../../../../../../');
if (!$magentoRoot || !file_exists($magentoRoot . '/app/bootstrap.php')) {
    fwrite(STDERR, "ERROR: run this script from a Magento installation (app/code/Meetanshi/AIReporting/Test/ai-prompts).\n");
    exit(1);
}

require $magentoRoot . '/app/bootstrap.php';

$params = $_SERVER;
$params[\Magento\Store\Model\StoreManager::PARAM_RUN_CODE] = 'admin';
$params[\Magento\Store\Model\StoreManager::PARAM_RUN_TYPE] = 'store';
$objectManager = \Magento\Framework\App\Bootstrap::create($magentoRoot, $params)->getObjectManager();
try {
    $objectManager->get(\Magento\Framework\App\State::class)->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Exception $e) {
    // Area already set
}

$options   = getopt('', ['check-gold', 'id::', 'verbose', 'output::']);
$checkGold = isset($options['check-gold']);
$verbose   = isset($options['verbose']);
$onlyIds   = isset($options['id']) ? array_map('intval', explode(',', (string) $options['id'])) : [];
$output    = $options['output'] ?? $magentoRoot . '/var/log/aireporting_gold_' . date('Ymd_His') . '.json';

$cases = json_decode((string) file_get_contents(__DIR__ . '/gold_queries.json'), true)['cases'] ?? [];
if ($onlyIds) {
    $cases = array_values(array_filter($cases, static fn (array $case) => in_array($case['id'], $onlyIds, true)));
}

/** @var QueryRunner $runner */
$runner = $objectManager->get(QueryRunner::class);
/** @var QueryExecutor $executor */
$executor = $objectManager->get(QueryExecutor::class);
$placeholders = buildPlaceholders($objectManager->get(ReportContext::class));
$provider = $objectManager->get(\Meetanshi\AIReporting\Model\Config::class)->getLlmProvider();

echo "Gold test: " . count($cases) . " cases, provider: {$provider}" . ($checkGold ? ' (reference queries only)' : '') . "\n\n";

$results = [];
foreach ($cases as $case) {
    $id       = (int) $case['id'];
    $noData   = ($case['expect'] ?? '') === 'no_data';
    $history  = array_map(
        static fn (array $turn) => ['question' => $turn['question'], 'sql' => fill($turn['sql'], $placeholders)],
        $case['history'] ?? []
    );
    $record = ['id' => $id, 'category' => $case['category'], 'question' => $case['question'], 'passed' => false];

    try {
        $gold = $noData ? null : $executor->execute(fill($case['sql'], $placeholders));
    } catch (\Exception $e) {
        printf("#%-3d REFERENCE QUERY FAILED: %s\n", $id, $e->getMessage());
        $results[] = $record + ['error' => 'reference query failed: ' . $e->getMessage()];
        continue;
    }

    if ($checkGold) {
        printf("#%-3d %s\n     %s\n", $id, $case['question'], $noData ? '(expects no data)' : json_encode($gold['rows']));
        continue;
    }

    $started = microtime(true);
    try {
        $run = $runner->run($case['question'], $history);
        $record['sql']        = $run['sql'];
        $record['assumption'] = $run['assumption'];
        if ($noData) {
            $record['reason'] = 'queried data the store does not record (' . $run['result']['row_count'] . ' rows)';
        } else {
            [$record['passed'], $record['reason']] = compareResults(
                $gold['rows'],
                $run['result']['rows'],
                !empty($case['ordered'])
            );
        }
    } catch (DirectAnswerException $e) {
        $record['answer'] = $e->getAnswer();
        $record['passed'] = $noData;
        $record['reason'] = $noData ? 'said the data is not recorded' : 'answered in text instead of querying';
    } catch (\Exception $e) {
        $record['error']  = $e->getMessage();
        $record['reason'] = 'error: ' . $e->getMessage();
    }
    $record['seconds'] = round(microtime(true) - $started, 1);
    $results[] = $record;

    printf("#%-3d %s  %-10s %5.1fs  %s\n", $id, $record['passed'] ? 'PASS' : 'FAIL', $case['category'], $record['seconds'], $case['question']);
    if (!$record['passed'] || $verbose) {
        foreach (['reason', 'assumption', 'sql', 'answer'] as $key) {
            if (!empty($record[$key])) {
                printf("       %-10s %s\n", $key . ':', preg_replace('/\s+/', ' ', mb_substr((string) $record[$key], 0, 400)));
            }
        }
    }
}

if ($checkGold) {
    exit(0);
}

$passed = count(array_filter($results, static fn (array $r) => $r['passed']));
$total  = count($results);
echo "\n" . sprintf('Accuracy: %d / %d (%.0f%%)', $passed, $total, $total ? 100 * $passed / $total : 0) . "\n";
$byCategory = [];
foreach ($results as $r) {
    $byCategory[$r['category']][] = $r['passed'];
}
foreach ($byCategory as $category => $outcomes) {
    printf("  %-12s %d / %d\n", $category, count(array_filter($outcomes)), count($outcomes));
}

file_put_contents($output, json_encode(
    ['date' => date('c'), 'provider' => $provider, 'passed' => $passed, 'total' => $total, 'results' => $results],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));
echo "Report: {$output}\n";

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Store-local date ranges in UTC and store facts, matching what the AI is told in STORE FACTS.
 *
 * @return array<string, string>
 */
function buildPlaceholders(ReportContext $ctx): array
{
    $today = $ctx->today();
    [$todayFrom, $todayTo] = $ctx->utcRange($today, $today->modify('+1 day'));
    [$last30From] = $ctx->utcRange($today->modify('-29 days'), $today);
    $monthStart = $today->modify('first day of this month');
    [$monthFrom, $monthTo] = $ctx->utcRange($monthStart, $monthStart->modify('+1 month'));
    [$lastMonthFrom] = $ctx->utcRange($monthStart->modify('-1 month'), $monthStart);
    $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);
    [$yearFrom, $yearTo] = $ctx->utcRange($yearStart, $yearStart->modify('+1 year'));
    [$lastYearFrom] = $ctx->utcRange($yearStart->modify('-1 year'), $yearStart);

    return [
        '{TODAY_FROM}'     => $todayFrom,
        '{TODAY_TO}'       => $todayTo,
        '{LAST30_FROM}'    => $last30From,
        '{MONTH_FROM}'     => $monthFrom,
        '{MONTH_TO}'       => $monthTo,
        '{LAST_MONTH_FROM}' => $lastMonthFrom,
        '{YEAR_FROM}'      => $yearFrom,
        '{YEAR_TO}'        => $yearTo,
        '{LAST_YEAR_FROM}' => $lastYearFrom,
        '{OFFSET}'         => $ctx->now()->format('P'),
        '{VALID}'          => "o.state NOT IN ('canceled','pending_payment')",
        '{STATUS_ATTR}'    => (string) $ctx->getAttributeId('catalog_product', 'status'),
        '{NAME_ATTR}'      => (string) $ctx->getAttributeId('catalog_product', 'name'),
        '{LINK}'           => $ctx->getProductLinkField(),
    ];
}

function fill(string $sql, array $placeholders): string
{
    return strtr($sql, $placeholders);
}

/**
 * Whether every reference row is contained in a row of the generated result (extra columns allowed).
 *
 * @return array{0: bool, 1: string}
 */
function compareResults(array $goldRows, array $generatedRows, bool $ordered): array
{
    $gold      = array_map('normalizeRow', $goldRows);
    $generated = array_map('normalizeRow', $generatedRows);

    // An aggregate over no rows: "0"/NULL from the reference vs. no row at all is the same answer
    if (count($gold) === 1 && !array_filter($gold[0], static fn ($v) => $v !== '0') && !$generated) {
        return [true, 'both empty'];
    }
    if (count($gold) !== count($generated)) {
        return [false, sprintf('expected %d rows, got %d (expected %s)', count($gold), count($generated), json_encode(array_slice($gold, 0, 5)))];
    }

    foreach ($gold as $i => $row) {
        if ($ordered) {
            if (!containsValues($generated[$i], $row)) {
                return [false, sprintf('row %d: expected %s, got %s', $i + 1, json_encode($row), json_encode($generated[$i]))];
            }
            continue;
        }
        $match = null;
        foreach ($generated as $j => $candidate) {
            if (containsValues($candidate, $row)) {
                $match = $j;
                break;
            }
        }
        if ($match === null) {
            return [false, 'missing row ' . json_encode($row) . ' (got ' . json_encode(array_slice($generated, 0, 5)) . ')'];
        }
        unset($generated[$match]);
    }

    return [true, 'match'];
}

/**
 * @return string[]
 */
function normalizeRow(array $row): array
{
    return array_map(static function ($value): string {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_numeric($value)) {
            $number = round((float) $value, 2);
            return $number == 0.0 ? '0' : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        }
        return mb_strtolower(trim((string) $value));
    }, array_values($row));
}

/**
 * Whether $values (a multiset) is contained in $row.
 */
function containsValues(array $row, array $values): bool
{
    foreach ($values as $value) {
        $index = array_search($value, $row, true);
        if ($index === false) {
            return false;
        }
        unset($row[$index]);
    }

    return true;
}
