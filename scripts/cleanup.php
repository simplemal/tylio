<?php
declare(strict_types=1);

/**
 * tylio housekeeping — purges expired or stale rows that the
 * application accumulates over time but does not need to keep forever.
 *
 * Tables and retention windows (all configurable via `.env` so an
 * operator can tighten or loosen the policy per deploy):
 *
 *   sessions          → expires_at < now()           (always, immediate)
 *   login_attempts    → > LOGIN_RETENTION_DAYS old   (default 7)
 *   audit_log         → > AUDIT_RETENTION_DAYS old   (default 90)
 *   visits            → > VISITS_RETENTION_DAYS old  (default 365)
 *
 * Usage (manual):
 *     php scripts/cleanup.php
 *     php scripts/cleanup.php --dry-run     # show counts, change nothing
 *
 * Cron example (daily at 03:30):
 *     30 3 * * * cd /var/www/tylio && php scripts/cleanup.php >> data/logs/cleanup.log 2>&1
 *
 * Exit code 0 = success, 1 = error. Safe to run concurrently with HTTP
 * traffic (SQLite WAL serializes writers).
 */

require __DIR__ . '/../vendor/autoload.php';

use Tylio\Config;
use Tylio\Services\DB;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}
$config = new Config($rootPath);
$db = new DB($config);

$dryRun = in_array('--dry-run', $argv, true);

$loginDays = $config->int('LOGIN_RETENTION_DAYS', 7);
$auditDays = $config->int('AUDIT_RETENTION_DAYS', 90);
$visitsDays = $config->int('VISITS_RETENTION_DAYS', 365);

$jobs = [
    [
        'label' => 'sessions (expired)',
        'count_sql' => 'SELECT COUNT(*) AS c FROM sessions WHERE expires_at < datetime("now")',
        'delete_sql' => 'DELETE FROM sessions WHERE expires_at < datetime("now")',
        'params' => [],
    ],
    [
        'label' => "login_attempts (> {$loginDays} days)",
        'count_sql' => 'SELECT COUNT(*) AS c FROM login_attempts WHERE attempted_at < datetime("now", ?)',
        'delete_sql' => 'DELETE FROM login_attempts WHERE attempted_at < datetime("now", ?)',
        'params' => ['-' . $loginDays . ' days'],
    ],
    [
        'label' => "audit_log (> {$auditDays} days)",
        'count_sql' => 'SELECT COUNT(*) AS c FROM audit_log WHERE created_at < datetime("now", ?)',
        'delete_sql' => 'DELETE FROM audit_log WHERE created_at < datetime("now", ?)',
        'params' => ['-' . $auditDays . ' days'],
    ],
    [
        'label' => "visits (> {$visitsDays} days)",
        'count_sql' => 'SELECT COUNT(*) AS c FROM visits WHERE created_at < datetime("now", ?)',
        'delete_sql' => 'DELETE FROM visits WHERE created_at < datetime("now", ?)',
        'params' => ['-' . $visitsDays . ' days'],
    ],
];

$ts = date('c');
echo "[$ts] tylio cleanup" . ($dryRun ? ' (DRY-RUN)' : '') . "\n";

$totalDeleted = 0;
foreach ($jobs as $job) {
    try {
        $row = $db->one($job['count_sql'], $job['params']);
        $count = (int)($row['c'] ?? 0);
        if ($count === 0) {
            echo "  · {$job['label']}: 0 rows\n";
            continue;
        }
        if ($dryRun) {
            echo "  · {$job['label']}: would delete {$count} rows\n";
            continue;
        }
        $db->query($job['delete_sql'], $job['params']);
        $totalDeleted += $count;
        echo "  ✓ {$job['label']}: deleted {$count} rows\n";
    } catch (\Throwable $e) {
        // "no such table" is a benign condition on partial installs
        // (e.g. someone ran cleanup before applying migrations, or a
        // table that exists in the platform overlay was queried on a
        // plain OSS DB). Skip rather than abort the whole sweep.
        if (str_contains($e->getMessage(), 'no such table')) {
            echo "  · {$job['label']}: skipped (table missing)\n";
            continue;
        }
        echo "  ✗ {$job['label']}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Optional VACUUM after a big batch — SQLite recovers disk space only on
// VACUUM, and we only spend the cost when the cleanup actually freed
// meaningful rows.
if (!$dryRun && $totalDeleted >= 1000) {
    echo "  · VACUUM (reclaiming disk after {$totalDeleted} deletions)\n";
    try {
        $db->pdo()->exec('VACUUM');
    } catch (\Throwable $e) {
        echo "  ! VACUUM skipped: " . $e->getMessage() . "\n";
    }
}

echo "[$ts] done. Total deleted: {$totalDeleted}\n";
exit(0);
