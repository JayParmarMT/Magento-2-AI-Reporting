<?php
/**
 * Meetanshi AIReporting — AI Prompt Batch Test Runner
 *
 * Runs all 1150 test prompts through the NLP-to-SQL pipeline and logs results.
 * Uses Magento's bootstrap to access the real DI container, LLM providers, and DB.
 *
 * Usage:
 *   php app/code/Meetanshi/AIReporting/Test/ai-prompts/run_ai_test.php [options]
 *
 * Options:
 *   --from=N          Start from prompt number N (default: 1)
 *   --to=N            End at prompt number N (default: last)
 *   --category=NAME   Run only a specific category (e.g. sales_basic, customer, product)
 *   --dry-run         Only test NLP-to-SQL conversion, skip DB execution
 *   --delay=MS        Delay between prompts in milliseconds (default: 500, for API rate limits)
 *   --output=FILE     Output report file path (default: auto-generated in var/log/)
 *   --stop-on-error   Stop on first error
 *   --verbose         Show full SQL and row data
 *   --security-only   Run only security/edge-case prompts (1101-1150)
 *   --limit=N         Run only first N prompts
 *   --skip-exec       Convert NLP to SQL but skip executing the SQL query
 *
 * Examples:
 *   php run_ai_test.php                          # Run all prompts
 *   php run_ai_test.php --from=1 --to=50         # Run prompts 1-50
 *   php run_ai_test.php --category=customer       # Run customer prompts only
 *   php run_ai_test.php --dry-run                 # NLP-to-SQL only, no DB
 *   php run_ai_test.php --security-only           # Test security prompts
 *   php run_ai_test.php --limit=100 --verbose     # First 100 with details
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

// ── Bootstrap Magento ────────────────────────────────────────────────────────

$magentoRoot = realpath(__DIR__ . '/../../../../../../');
if (!$magentoRoot || !file_exists($magentoRoot . '/app/bootstrap.php')) {
    // Try alternative path (if script is run from Magento root)
    $magentoRoot = realpath(__DIR__ . '/../../../../../..');
    if (!$magentoRoot || !file_exists($magentoRoot . '/app/bootstrap.php')) {
        fwrite(STDERR, "ERROR: Cannot find Magento root. Run this script from the Magento root directory:\n");
        fwrite(STDERR, "  php app/code/Meetanshi/AIReporting/Test/ai-prompts/run_ai_test.php\n\n");
        exit(1);
    }
}

require $magentoRoot . '/app/bootstrap.php';

$params = $_SERVER;
$params[\Magento\Store\Model\StoreManager::PARAM_RUN_CODE] = 'admin';
$params[\Magento\Store\Model\StoreManager::PARAM_RUN_TYPE] = 'store';

$bootstrap = \Magento\Framework\App\Bootstrap::create($magentoRoot, $params);
$objectManager = $bootstrap->getObjectManager();

// Ensure area is set
$state = $objectManager->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Exception $e) {
    // Area already set
}

// ── Parse CLI Arguments ──────────────────────────────────────────────────────

$options = getopt('', [
    'from::', 'to::', 'category::', 'dry-run', 'delay::',
    'output::', 'stop-on-error', 'verbose', 'security-only',
    'limit::', 'skip-exec', 'help'
]);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__);
    exit(0);
}

$fromId       = (int) ($options['from'] ?? 1);
$toId         = (int) ($options['to'] ?? 99999);
$category     = $options['category'] ?? null;
$dryRun       = isset($options['dry-run']);
$delayMs      = (int) ($options['delay'] ?? 500);
$outputFile   = $options['output'] ?? null;
$stopOnError  = isset($options['stop-on-error']);
$verbose      = isset($options['verbose']);
$securityOnly = isset($options['security-only']);
$limit        = isset($options['limit']) ? (int) $options['limit'] : null;
$skipExec     = isset($options['skip-exec']);

if ($securityOnly) {
    $fromId = 1101;
    $toId   = 1150;
}

// ── Load Services ────────────────────────────────────────────────────────────

/** @var \Meetanshi\AIReporting\Model\Config $config */
$config = $objectManager->get(\Meetanshi\AIReporting\Model\Config::class);

/** @var \Meetanshi\AIReporting\Model\Query\NlpToSql $nlpToSql */
$nlpToSql = $objectManager->get(\Meetanshi\AIReporting\Model\Query\NlpToSql::class);

/** @var \Meetanshi\AIReporting\Model\Query\QueryExecutor $queryExecutor */
$queryExecutor = $objectManager->get(\Meetanshi\AIReporting\Model\Query\QueryExecutor::class);

// ── Load Prompts ─────────────────────────────────────────────────────────────

$promptsFile = __DIR__ . '/test_prompts.md';
if (!file_exists($promptsFile)) {
    fwrite(STDERR, "ERROR: test_prompts.md not found at: {$promptsFile}\n");
    exit(1);
}

$lines = file($promptsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$prompts = [];
$currentCategory = 'unknown';
$currentSubcategory = 'general';

foreach ($lines as $line) {
    // Detect section headers for category
    if (preg_match('/^## SECTION \d+:\s*(.+)/i', $line, $m)) {
        $currentCategory = strtolower(trim(preg_replace('/\s*\(.*\)/', '', $m[1])));
        $currentCategory = preg_replace('/[^a-z0-9_]/', '_', $currentCategory);
        continue;
    }
    if (preg_match('/^### (.+)/i', $line, $m)) {
        $currentSubcategory = strtolower(trim(preg_replace('/\s*\(.*\)/', '', $m[1])));
        $currentSubcategory = preg_replace('/[^a-z0-9_]/', '_', $currentSubcategory);
        continue;
    }

    // Parse numbered prompts: "123. Some prompt text"
    if (preg_match('/^(\d+)\.\s+(.+)$/', $line, $m)) {
        $id = (int) $m[1];
        $text = trim($m[2]);
        $prompts[] = [
            'id'          => $id,
            'prompt'      => $text,
            'category'    => $currentCategory,
            'subcategory' => $currentSubcategory,
        ];
    }
}

if (empty($prompts)) {
    fwrite(STDERR, "ERROR: No prompts parsed from test_prompts.md\n");
    exit(1);
}

// ── Filter Prompts ───────────────────────────────────────────────────────────

$filtered = array_filter($prompts, function ($p) use ($fromId, $toId, $category) {
    if ($p['id'] < $fromId || $p['id'] > $toId) {
        return false;
    }
    if ($category !== null && stripos($p['category'], $category) === false
        && stripos($p['subcategory'], $category) === false) {
        return false;
    }
    return true;
});

if ($limit !== null) {
    $filtered = array_slice($filtered, 0, $limit);
}

$totalPrompts = count($filtered);

if ($totalPrompts === 0) {
    fwrite(STDERR, "No prompts match the given filters.\n");
    exit(1);
}

// ── Prepare Output ───────────────────────────────────────────────────────────

$timestamp = date('Ymd_His');
if (!$outputFile) {
    $logDir = $magentoRoot . '/var/log/aireporting_tests';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $outputFile = $logDir . '/test_run_' . $timestamp . '.log';
}

$csvFile = preg_replace('/\.log$/', '.csv', $outputFile);

// ── Print Header ─────────────────────────────────────────────────────────────

$provider = $config->getLlmProvider();
$model = match ($provider) {
    'groq'       => $config->getGroqModel(),
    'openai'     => $config->getOpenAiModel(),
    'gemini'     => $config->getGeminiModel(),
    'ollama'     => $config->getOllamaModel(),
    'openrouter' => $config->getOpenRouterModel(),
    default      => 'unknown',
};

$header = <<<HEADER
╔══════════════════════════════════════════════════════════════════════╗
║           MEETANSHI AI REPORTING — BATCH TEST RUNNER                ║
╠══════════════════════════════════════════════════════════════════════╣
║  Date       : {$timestamp}
║  Provider   : {$provider}
║  Model      : {$model}
║  Prompts    : {$totalPrompts}
║  Range      : #{$fromId} – #{$toId}
║  Mode       : %MODE%
║  Delay      : {$delayMs}ms between prompts
║  Log File   : {$outputFile}
║  CSV File   : {$csvFile}
╚══════════════════════════════════════════════════════════════════════╝

HEADER;

$mode = $dryRun ? 'DRY RUN (NLP→SQL only, no DB)' : ($skipExec ? 'SKIP EXEC (NLP→SQL, no DB exec)' : 'FULL (NLP→SQL→DB Execute)');
$header = str_replace('%MODE%', $mode, $header);

echo $header;
file_put_contents($outputFile, $header);

// CSV header
$csvHeader = "id,category,subcategory,prompt,status,sql_query,row_count,exec_time_ms,error\n";
file_put_contents($csvFile, $csvHeader);

// ── Run Tests ────────────────────────────────────────────────────────────────

$stats = [
    'total'            => $totalPrompts,
    'success'          => 0,
    'nlp_success'      => 0,
    'nlp_error'        => 0,
    'exec_success'     => 0,
    'exec_error'       => 0,
    'security_blocked' => 0,
    'skipped'          => 0,
    'total_time_ms'    => 0,
    'total_rows'       => 0,
    'errors'           => [],
];

$counter = 0;

foreach ($filtered as $p) {
    $counter++;
    $id       = $p['id'];
    $prompt   = $p['prompt'];
    $cat      = $p['category'];
    $subcat   = $p['subcategory'];

    $progress = sprintf("[%d/%d] #%04d", $counter, $totalPrompts, $id);

    $sqlQuery     = '';
    $status       = 'pending';
    $rowCount     = 0;
    $execTimeMs   = 0;
    $errorMsg     = '';
    $startTime    = microtime(true);

    // ── Step 1: NLP to SQL ───────────────────────────────────────────────
    try {
        $sqlQuery = $nlpToSql->convert($prompt);
        $stats['nlp_success']++;

        $sqlPreview = strlen($sqlQuery) > 120
            ? substr($sqlQuery, 0, 120) . '...'
            : $sqlQuery;

        echo "{$progress} ✅ NLP→SQL OK";

        if ($verbose) {
            echo "\n         SQL: {$sqlQuery}";
        } else {
            echo " | " . $sqlPreview;
        }

    } catch (\Meetanshi\AIReporting\Exception\LlmException $e) {
        $errorMsg = $e->getMessage();
        $stats['nlp_error']++;

        // Check if it's a security block (expected for prompts 1121-1140)
        if (stripos($errorMsg, 'Security violation') !== false
            || stripos($errorMsg, 'non-SELECT') !== false) {
            $status = 'security_blocked';
            $stats['security_blocked']++;
            echo "{$progress} 🛡️  BLOCKED (expected) | {$errorMsg}";
        } else {
            $status = 'nlp_error';
            echo "{$progress} ❌ NLP ERROR | {$errorMsg}";
            $stats['errors'][] = ['id' => $id, 'prompt' => $prompt, 'phase' => 'nlp', 'error' => $errorMsg];
        }

    } catch (\Exception $e) {
        $errorMsg = $e->getMessage();
        $status = 'nlp_error';
        $stats['nlp_error']++;
        echo "{$progress} ❌ NLP EXCEPTION | {$errorMsg}";
        $stats['errors'][] = ['id' => $id, 'prompt' => $prompt, 'phase' => 'nlp', 'error' => $errorMsg];
    }

    // ── Step 2: Execute SQL (if not dry-run and NLP succeeded) ───────────
    if (!empty($sqlQuery) && !$dryRun && !$skipExec && $status === 'pending') {
        try {
            $result = $queryExecutor->execute($sqlQuery);
            $rowCount   = $result['row_count'];
            $execTimeMs = $result['execution_time_ms'];
            $status     = 'success';
            $stats['exec_success']++;
            $stats['success']++;
            $stats['total_rows'] += $rowCount;

            echo " | {$rowCount} rows | {$execTimeMs}ms";

            if ($verbose && $rowCount > 0 && $rowCount <= 5) {
                echo "\n         Data: " . json_encode($result['rows'], JSON_UNESCAPED_UNICODE);
            }

        } catch (\Meetanshi\AIReporting\Exception\QueryException $e) {
            $errorMsg = $e->getMessage();
            $status = 'exec_error';
            $stats['exec_error']++;
            echo " | ❌ EXEC ERROR: {$errorMsg}";
            $stats['errors'][] = ['id' => $id, 'prompt' => $prompt, 'phase' => 'exec', 'sql' => $sqlQuery, 'error' => $errorMsg];

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $status = 'exec_error';
            $stats['exec_error']++;
            echo " | ❌ EXEC EXCEPTION: {$errorMsg}";
            $stats['errors'][] = ['id' => $id, 'prompt' => $prompt, 'phase' => 'exec', 'sql' => $sqlQuery, 'error' => $errorMsg];
        }
    } elseif (!empty($sqlQuery) && ($dryRun || $skipExec) && $status === 'pending') {
        $status = 'nlp_only';
        $stats['success']++;
        $stats['skipped']++;
        echo " | (exec skipped)";
    }

    $totalTimeMs = (int) round((microtime(true) - $startTime) * 1000);
    $stats['total_time_ms'] += $totalTimeMs;

    echo "\n";

    // ── Log to file ──────────────────────────────────────────────────────
    $logLine = sprintf(
        "[%s] #%04d | %s | %s | %s | SQL: %s | Rows: %d | Time: %dms | Error: %s\n",
        date('H:i:s'),
        $id,
        $status,
        $cat,
        $prompt,
        $sqlQuery ?: '(none)',
        $rowCount,
        $totalTimeMs,
        $errorMsg ?: '(none)'
    );
    file_put_contents($outputFile, $logLine, FILE_APPEND);

    // ── Log to CSV ───────────────────────────────────────────────────────
    $csvLine = sprintf(
        "%d,%s,%s,%s,%s,%s,%d,%d,%s\n",
        $id,
        $cat,
        $subcat,
        '"' . str_replace('"', '""', $prompt) . '"',
        $status,
        '"' . str_replace('"', '""', $sqlQuery) . '"',
        $rowCount,
        $totalTimeMs,
        '"' . str_replace('"', '""', $errorMsg) . '"'
    );
    file_put_contents($csvFile, $csvLine, FILE_APPEND);

    // ── Stop on error ────────────────────────────────────────────────────
    if ($stopOnError && in_array($status, ['nlp_error', 'exec_error'])) {
        echo "\n⛔ Stopped on error (--stop-on-error flag)\n";
        break;
    }

    // ── Rate limit delay ─────────────────────────────────────────────────
    if ($delayMs > 0 && $counter < $totalPrompts) {
        usleep($delayMs * 1000);
    }
}

// ── Print Summary ────────────────────────────────────────────────────────────

$avgTimeMs = $totalPrompts > 0 ? round($stats['total_time_ms'] / $totalPrompts) : 0;
$successRate = $totalPrompts > 0 ? round(($stats['success'] / $totalPrompts) * 100, 1) : 0;
$nlpSuccessRate = $totalPrompts > 0 ? round(($stats['nlp_success'] / $totalPrompts) * 100, 1) : 0;

$summary = <<<SUMMARY

╔══════════════════════════════════════════════════════════════════════╗
║                        TEST RESULTS SUMMARY                        ║
╠══════════════════════════════════════════════════════════════════════╣
║  Total Prompts Tested  : {$stats['total']}
║  Overall Success Rate  : {$successRate}%
║                                                                      
║  NLP→SQL Conversion:                                                 
║    ✅ Success           : {$stats['nlp_success']}
║    ❌ Errors            : {$stats['nlp_error']}
║    🛡️  Security Blocked  : {$stats['security_blocked']}
║    📊 NLP Success Rate  : {$nlpSuccessRate}%
║                                                                      
║  SQL Execution:                                                      
║    ✅ Success           : {$stats['exec_success']}
║    ❌ Errors            : {$stats['exec_error']}
║    ⏭️  Skipped           : {$stats['skipped']}
║                                                                      
║  Performance:                                                        
║    ⏱️  Total Time        : {$stats['total_time_ms']}ms
║    ⏱️  Avg per Prompt    : {$avgTimeMs}ms
║    📊 Total Rows        : {$stats['total_rows']}
║                                                                      
║  Provider: {$provider} ({$model})
║                                                                      
║  Log: {$outputFile}
║  CSV: {$csvFile}
╚══════════════════════════════════════════════════════════════════════╝

SUMMARY;

echo $summary;
file_put_contents($outputFile, $summary, FILE_APPEND);

// ── Error Details ────────────────────────────────────────────────────────────

if (!empty($stats['errors'])) {
    $errorSection = "\n═══ ERROR DETAILS (" . count($stats['errors']) . " errors) ═══\n\n";

    foreach ($stats['errors'] as $i => $err) {
        $errorSection .= sprintf(
            "  [%d] Prompt #%d: %s\n      Phase: %s\n      Error: %s\n",
            $i + 1,
            $err['id'],
            $err['prompt'],
            $err['phase'],
            $err['error']
        );
        if (!empty($err['sql'])) {
            $errorSection .= "      SQL: {$err['sql']}\n";
        }
        $errorSection .= "\n";
    }

    echo $errorSection;
    file_put_contents($outputFile, $errorSection, FILE_APPEND);
}

// ── Exit Code ────────────────────────────────────────────────────────────────

$exitCode = ($stats['nlp_error'] + $stats['exec_error'] > 0) ? 1 : 0;
exit($exitCode);
