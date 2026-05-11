<?php
declare(strict_types=1);

/**
 * CLI migration runner.
 *
 * Usage:
 *   php scripts/migrate.php          → apply pending migrations
 *   php scripts/migrate.php status   → list applied + pending (read-only)
 *   php scripts/migrate.php version  → name of the latest applied
 *   php scripts/migrate.php --help   → this help
 */

require __DIR__ . '/../vendor/autoload.php';

use Tylio\Config;
use Tylio\Services\DB;
use Tylio\Services\Migrations;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}
$config = new Config($rootPath);
@mkdir($config->path('data'), 0770, true);
@mkdir($config->path('data/sessions'), 0770, true);
@mkdir($config->path('data/logs'), 0770, true);

$db = new DB($config);
$migrations = new Migrations($db, $config);

$cmd = $argv[1] ?? 'up';
if ($cmd === '--help' || $cmd === '-h' || $cmd === 'help') {
    echo trim((string)file_get_contents(__FILE__, false, null, 0, 600)), "\n";
    exit(0);
}

switch ($cmd) {
    case 'up':
    case 'run':
    case 'apply':
        $applied = $migrations->run();
        if (empty($applied)) {
            echo "No new migrations to apply.\n";
        } else {
            echo "Migrations applied:\n";
            foreach ($applied as $m) echo "  ✓ $m\n";
        }
        break;

    case 'status':
        $st = $migrations->status();
        echo "Applied migrations (" . count($st['applied']) . "):\n";
        foreach ($st['applied'] as $row) {
            echo sprintf("  ✓ %-40s  applied: %s\n", $row['name'], $row['applied_at']);
        }
        echo "\nPending migrations (" . count($st['pending']) . "):\n";
        if (empty($st['pending'])) {
            echo "  (none)\n";
        } else {
            foreach ($st['pending'] as $name) echo "  ○ $name\n";
        }
        break;

    case 'version':
        $st = $migrations->status();
        $last = end($st['applied']);
        if ($last === false) {
            echo "(no migrations applied yet)\n";
            exit(1);
        }
        echo $last['name'], "\n";
        break;

    default:
        fwrite(STDERR, "Unknown command: $cmd\n");
        fwrite(STDERR, "Usage: php scripts/migrate.php [up|status|version|--help]\n");
        exit(2);
}
