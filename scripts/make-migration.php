<?php
declare(strict_types=1);

/**
 * Generator for a new migration. Computes the next progressive number by
 * scanning `app/Database/migrations/` and creates the file with a standard
 * header.
 *
 * Usage:
 *   php scripts/make-migration.php add_user_avatar           → creates NNNN_add_user_avatar.sql
 *   php scripts/make-migration.php add_user_avatar --php     → creates NNNN_add_user_avatar.php
 *
 * Conventions:
 *   - The slug after NNNN_ must match [a-z0-9_]: it is normalized
 *     automatically (spaces → underscore, lowercase).
 *   - Zero-padded 4-digit numbering, starting from 0001.
 *   - DO NOT modify migrations ALREADY APPLIED elsewhere (CI / production /
 *     other developers). Always add a NEW migration.
 */

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$positional = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$isPhp = in_array('--php', $flags, true);

if (empty($positional) || in_array('--help', $flags, true) || in_array('-h', $flags, true)) {
    echo "Usage: php scripts/make-migration.php <name_slug> [--php]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php scripts/make-migration.php add_user_avatar\n";
    echo "  php scripts/make-migration.php recalc_block_positions --php\n";
    exit(1);
}

$slug = (string)$positional[0];
// Normalize: lower + replace non [a-z0-9_] with _
$slug = strtolower($slug);
$slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? $slug;
$slug = trim($slug, '_');
if ($slug === '') {
    fwrite(STDERR, "Empty slug after normalization.\n");
    exit(2);
}

$rootPath = dirname(__DIR__);
$dir = $rootPath . '/app/Database/migrations';

if (!is_dir($dir)) {
    fwrite(STDERR, "Migrations folder not found: $dir\n");
    exit(3);
}

// Next number: max existing + 1, zero-padded to 4 digits.
$next = 1;
foreach (glob($dir . '/*.{sql,php}', GLOB_BRACE) ?: [] as $f) {
    if (preg_match('/^(\d{4})_/', basename($f), $m)) {
        $next = max($next, ((int)$m[1]) + 1);
    }
}
$numFmt = str_pad((string)$next, 4, '0', STR_PAD_LEFT);
$ext = $isPhp ? 'php' : 'sql';
$file = $dir . '/' . $numFmt . '_' . $slug . '.' . $ext;
if (file_exists($file)) {
    fwrite(STDERR, "File already exists: $file\n");
    exit(4);
}

$header = "-- Migration: $numFmt $slug\n"
    . "-- Generated: " . date('Y-m-d H:i:s') . "\n"
    . "--\n"
    . "-- ⚠️  Once this migration is committed and applied in CI/production,\n"
    . "--    DO NOT modify it. Add a NEW migration to change the schema.\n"
    . "--    Editing an applied migration causes drift between installations.\n"
    . "--\n"
    . "-- Idempotency: prefer `CREATE TABLE IF NOT EXISTS`, `INSERT OR IGNORE`,\n"
    . "--   checks on PRAGMA table_info() for safe ADD COLUMN, etc.\n\n";

if ($isPhp) {
    $content = "<?php\ndeclare(strict_types=1);\n\n"
        . "/**\n"
        . " * Migration $numFmt $slug — PHP migration (data transform).\n"
        . " *\n"
        . " * Generated: " . date('Y-m-d H:i:s') . "\n"
        . " *\n"
        . " * ⚠️  DO NOT MODIFY once applied elsewhere.\n"
        . " */\n\n"
        . "use PDO;\nuse Tylio\\Services\\DB;\n\n"
        . "return function (PDO \$pdo, DB \$db): void {\n"
        . "    // TODO: implement the transformation here.\n"
        . "    // Example:\n"
        . "    // foreach (\$db->all('SELECT id, data FROM blocks') as \$row) {\n"
        . "    //     \$data = json_decode((string)\$row['data'], true) ?: [];\n"
        . "    //     // …transform…\n"
        . "    //     \$db->update('blocks', ['data' => json_encode(\$data)],\n"
        . "    //                 'id = :id', ['id' => \$row['id']]);\n"
        . "    // }\n"
        . "};\n";
} else {
    $content = $header
        . "-- TODO: SQL of the migration.\n"
        . "-- Example:\n"
        . "-- ALTER TABLE users ADD COLUMN avatar_url TEXT;\n"
        . "-- CREATE INDEX IF NOT EXISTS idx_users_avatar ON users(avatar_url);\n";
}

file_put_contents($file, $content);
echo "✓ Created: $file\n";
echo "Edit the file and then run: php scripts/migrate.php\n";
