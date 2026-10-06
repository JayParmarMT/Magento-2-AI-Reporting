<?php
/**
 * Meetanshi AIReporting — API-Based Prompt Test Runner
 *
 * Tests prompts via the admin AJAX endpoint using cURL.
 * Does NOT require Magento bootstrap — works standalone.
 *
 * Usage:
 *   php run_api_test.php --url=https://your-store.com/admin --user=admin --pass=admin123 [options]
 *
 * Required:
 *   --url=URL          Magento admin base URL (e.g. https://store.com/admin)
 *   --user=USERNAME    Admin username
 *   --pass=PASSWORD    Admin password
 *
 * Options:
 *   --from=N           Start from prompt number N (default: 1)
 *   --to=N             End at prompt number N (default: last)
 *   --limit=N          Run only first N prompts
 *   --delay=MS         Delay between requests in ms (default: 1000)
 *   --security-only    Run only security test prompts (1101-1150)
 *   --verbose          Show full response data
 *   --output=FILE      Output file path
 *
 * Examples:
 *   php run_api_test.php --url=https://mystore.com/admin --user=admin --pass=Test@123
 *   php run_api_test.php --url=https://mystore.com/admin --user=admin --pass=Test@123 --from=1 --to=50
 *   php run_api_test.php --url=https://mystore.com/admin --user=admin --pass=Test@123 --security-only
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

// ── Parse Arguments ──────────────────────────────────────────────────────────

$options = getopt('', [
    'url:', 'user:', 'pass:',
    'from::', 'to::', 'limit::', 'delay::',
    'security-only', 'verbose', 'output::', 'help'
]);

if (isset($options['help']) || !isset($options['url']) || !isset($options['user']) || !isset($options['pass'])) {
    echo <<<HELP
Meetanshi AIReporting — API Test Runner

Usage:
  php run_api_test.php --url=ADMIN_URL --user=USERNAME --pass=PASSWORD [options]

Required:
  --url=URL       Magento admin base URL (e.g. https://store.com/admin)
  --user=USER     Admin username
  --pass=PASS     Admin password

Options:
  --from=N        Start from prompt N (default: 1)
  --to=N          End at prompt N (default: last)
  --limit=N       Run only first N prompts
  --delay=MS      Delay between requests in ms (default: 1000)
  --security-only Run only security prompts (1101-1150)
  --verbose       Show full response
  --output=FILE   Output file path

HELP;
    exit(isset($options['help']) ? 0 : 1);
}

$adminUrl     = rtrim($options['url'], '/');
$adminUser    = $options['user'];
$adminPass    = $options['pass'];
$fromId       = (int) ($options['from'] ?? 1);
$toId         = (int) ($options['to'] ?? 99999);
$limit        = isset($options['limit']) ? (int) $options['limit'] : null;
$delayMs      = (int) ($options['delay'] ?? 1000);
$securityOnly = isset($options['security-only']);
$verbose      = isset($options['verbose']);
$outputFile   = $options['output'] ?? null;

if ($securityOnly) {
    $fromId = 1101;
    $toId   = 1150;
}

// ── Load Prompts ─────────────────────────────────────────────────────────────

$promptsFile = __DIR__ . '/test_prompts.md';
if (!file_exists($promptsFile)) {
    fwrite(STDERR, "ERROR: test_prompts.md not found\n");
    exit(1);
}

$lines = file($promptsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$prompts = [];
$currentCategory = 'unknown';

foreach ($lines as $line) {
    if (preg_match('/^## SECTION \d+:\s*(.+)/i', $line, $m)) {
        $currentCategory = strtolower(trim(preg_replace('/\s*\(.*\)/', '', $m[1])));
        continue;
    }
    if (preg_match('/^(\d+)\.\s+(.+)$/', $line, $m)) {
        $id = (int) $m[1];
        $prompts[] = ['id' => $id, 'prompt' => trim($m[2]), 'category' => $currentCategory];
    }
}

// Filter
$filtered = array_filter($prompts, fn($p) => $p['id'] >= $fromId && $p['id'] <= $toId);
if ($limit) {
    $filtered = array_slice($filtered, 0, $limit);
}

$totalPrompts = count($filtered);
if ($totalPrompts === 0) {
    fwrite(STDERR, "No prompts match filters.\n");
    exit(1);
}

// ── Admin Login ──────────────────────────────────────────────────────────────

echo "🔐 Logging into Magento admin...\n";

$cookieFile = tempnam(sys_get_temp_dir(), 'mage_cookie_');

// Step 1: Get the login page to extract form_key
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $adminUrl . '/admin/auth/login/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'MeetanshiAIReportingTestRunner/1.0',
]);
$loginPage = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$loginPage) {
    fwrite(STDERR, "ERROR: Cannot reach admin URL: {$adminUrl}\n");
    @unlink($cookieFile);
    exit(1);
}

// Extract form_key
$formKey = '';
if (preg_match('/name="form_key"\s+.*?value="([^"]+)"/', $loginPage, $m)) {
    $formKey = $m[1];
} elseif (preg_match('/var\s+FORM_KEY\s*=\s*[\'"]([^\'"]+)[\'"]/', $loginPage, $m)) {
    $formKey = $m[1];
}

if (empty($formKey)) {
    // Try to get form_key from cookie or page source
    if (preg_match('/form_key["\s]*[:=]\s*["\']([^"\']+)/', $loginPage, $m)) {
        $formKey = $m[1];
    }
}

if (empty($formKey)) {
    fwrite(STDERR, "ERROR: Cannot extract form_key from login page. Check admin URL.\n");
    @unlink($cookieFile);
    exit(1);
}

// Step 2: POST login
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $adminUrl . '/admin/auth/login/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'form_key' => $formKey,
        'login'    => ['username' => $adminUser, 'password' => $adminPass],
    ]),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'MeetanshiAIReportingTestRunner/1.0',
]);
$loginResponse = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Step 3: Get a fresh form_key from the dashboard
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $adminUrl . '/admin/dashboard/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'MeetanshiAIReportingTestRunner/1.0',
]);
$dashPage = curl_exec($ch);
curl_close($ch);

if (preg_match('/var\s+FORM_KEY\s*=\s*[\'"]([^\'"]+)[\'"]/', $dashPage, $m)) {
    $formKey = $m[1];
} elseif (preg_match('/name="form_key"\s+.*?value="([^"]+)"/', $dashPage, $m)) {
    $formKey = $m[1];
}

// Check if login succeeded
if (stripos($dashPage, 'dashboard') === false && stripos($dashPage, 'form_key') === false) {
    fwrite(STDERR, "WARNING: Login may have failed. Continuing anyway...\n");
}

echo "✅ Logged in. Form key: " . substr($formKey, 0, 8) . "...\n\n";

// ── Prepare Output ───────────────────────────────────────────────────────────

$timestamp = date('Ymd_His');
if (!$outputFile) {
    $outputFile = __DIR__ . '/api_test_' . $timestamp . '.log';
}
$csvFile = preg_replace('/\.log$/', '.csv', $outputFile);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     MEETANSHI AI REPORTING — API BATCH TEST                 ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  Admin URL : {$adminUrl}\n";
echo "║  Prompts   : {$totalPrompts}\n";
echo "║  Delay     : {$delayMs}ms\n";
echo "║  Log       : {$outputFile}\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

file_put_contents($csvFile, "id,category,prompt,status,sql_query,row_count,time_ms,error\n");

// ── Execute Tests ────────────────────────────────────────────────────────────

$queryUrl = $adminUrl . '/meetanshi_aireporting/query/execute/';

$stats = [
    'total' => $totalPrompts, 'success' => 0, 'error' => 0,
    'security_blocked' => 0, 'total_time_ms' => 0, 'total_rows' => 0,
    'errors' => [],
];

$counter = 0;

foreach ($filtered as $p) {
    $counter++;
    $id     = $p['id'];
    $prompt = $p['prompt'];
    $cat    = $p['category'];

    $progress = sprintf("[%d/%d] #%04d", $counter, $totalPrompts, $id);

    $startTime = microtime(true);

    // Make AJAX POST request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $queryUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'form_key' => $formKey,
            'query'    => $prompt,
        ]),
        CURLOPT_HTTPHEADER     => [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'MeetanshiAIReportingTestRunner/1.0',
    ]);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError    = curl_error($ch);
    curl_close($ch);

    $timeMs = (int) round((microtime(true) - $startTime) * 1000);
    $stats['total_time_ms'] += $timeMs;

    $status   = 'error';
    $sqlQuery = '';
    $rowCount = 0;
    $errorMsg = '';

    if ($curlError) {
        $errorMsg = "cURL error: {$curlError}";
        $stats['error']++;
    } elseif ($httpCode !== 200) {
        $errorMsg = "HTTP {$httpCode}";
        $stats['error']++;
    } else {
        $response = json_decode($responseBody, true);

        if ($response === null) {
            $errorMsg = 'Invalid JSON response';
            $stats['error']++;
        } elseif (!empty($response['success'])) {
            $status   = 'success';
            $sqlQuery = $response['sql_query'] ?? '';
            $rowCount = $response['row_count'] ?? 0;
            $stats['success']++;
            $stats['total_rows'] += $rowCount;
        } else {
            $errorMsg = $response['message'] ?? 'Unknown error';

            if (stripos($errorMsg, 'Security violation') !== false
                || stripos($errorMsg, 'non-SELECT') !== false) {
                $status = 'security_blocked';
                $stats['security_blocked']++;
            } else {
                $stats['error']++;
                $stats['errors'][] = ['id' => $id, 'prompt' => $prompt, 'error' => $errorMsg];
            }
        }
    }

    // Print result
    $icon = match ($status) {
        'success'          => '✅',
        'security_blocked' => '🛡️ ',
        default            => '❌',
    };

    $sqlPreview = $sqlQuery ? (strlen($sqlQuery) > 80 ? substr($sqlQuery, 0, 80) . '...' : $sqlQuery) : '';

    echo "{$progress} {$icon} {$status}";
    if ($status === 'success') {
        echo " | {$rowCount} rows | {$timeMs}ms";
        if ($verbose) {
            echo "\n         SQL: {$sqlQuery}";
        }
    } elseif ($status === 'security_blocked') {
        echo " | {$errorMsg}";
    } else {
        echo " | {$errorMsg}";
    }
    echo "\n";

    // Log
    $logLine = sprintf("[%s] #%04d | %s | %s | %dms | Rows:%d | SQL:%s | Err:%s\n",
        date('H:i:s'), $id, $status, $prompt, $timeMs, $rowCount, $sqlQuery ?: '-', $errorMsg ?: '-');
    file_put_contents($outputFile, $logLine, FILE_APPEND);

    $csvLine = sprintf("%d,%s,%s,%s,%s,%d,%d,%s\n",
        $id, $cat,
        '"' . str_replace('"', '""', $prompt) . '"',
        $status,
        '"' . str_replace('"', '""', $sqlQuery) . '"',
        $rowCount, $timeMs,
        '"' . str_replace('"', '""', $errorMsg) . '"');
    file_put_contents($csvFile, $csvLine, FILE_APPEND);

    // Delay
    if ($delayMs > 0 && $counter < $totalPrompts) {
        usleep($delayMs * 1000);
    }
}

// ── Cleanup ──────────────────────────────────────────────────────────────────

@unlink($cookieFile);

// ── Summary ──────────────────────────────────────────────────────────────────

$successRate = $totalPrompts > 0 ? round(($stats['success'] / $totalPrompts) * 100, 1) : 0;
$avgTime = $totalPrompts > 0 ? round($stats['total_time_ms'] / $totalPrompts) : 0;

$summary = <<<SUMMARY

╔══════════════════════════════════════════════════════════════════════╗
║                     API TEST RESULTS SUMMARY                       ║
╠══════════════════════════════════════════════════════════════════════╣
║  Total Tested        : {$stats['total']}
║  ✅ Success           : {$stats['success']}  ({$successRate}%)
║  ❌ Errors            : {$stats['error']}
║  🛡️  Security Blocked  : {$stats['security_blocked']}
║  ⏱️  Total Time        : {$stats['total_time_ms']}ms
║  ⏱️  Avg per Prompt    : {$avgTime}ms
║  📊 Total Rows        : {$stats['total_rows']}
║  Log: {$outputFile}
║  CSV: {$csvFile}
╚══════════════════════════════════════════════════════════════════════╝

SUMMARY;

echo $summary;
file_put_contents($outputFile, $summary, FILE_APPEND);

if (!empty($stats['errors'])) {
    $errSection = "\n═══ ERRORS ═══\n";
    foreach ($stats['errors'] as $i => $e) {
        $errSection .= sprintf("  [%d] #%d: %s → %s\n", $i + 1, $e['id'], $e['prompt'], $e['error']);
    }
    echo $errSection;
    file_put_contents($outputFile, $errSection, FILE_APPEND);
}

exit($stats['error'] > 0 ? 1 : 0);
