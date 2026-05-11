<?php
declare(strict_types=1);

/**
 * CLI install / seed script. Creates the admin user (or resets the password
 * with --reset), runs migrations, and seeds a sample page if empty.
 *
 * Usage:
 *   php scripts/seed.php --username=admin --password=secret
 *   php scripts/seed.php --username=admin --password=new --reset
 */

require __DIR__ . '/../vendor/autoload.php';

use Tylio\Config;
use Tylio\Services\Auth;
use Tylio\Services\DB;
use Tylio\Services\Migrations;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
if (file_exists($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}
$config = new Config($rootPath);

$db = new DB($config);
(new Migrations($db, $config))->run();
$auth = new Auth($db, $config);

$opts = getopt('', ['username::', 'password::', 'reset']);
$username = $opts['username'] ?? readline('Admin username (default: admin): ') ?: 'admin';
$password = $opts['password'] ?? '';
if (!$password) {
    if (function_exists('readline_callback_handler_install')) {
        echo "Password (visible while typing): ";
        $password = trim((string)fgets(STDIN));
    } else {
        $password = trim((string)fgets(STDIN));
    }
}
if (strlen($password) < 8) {
    fwrite(STDERR, "ERROR: password must be at least 8 characters.\n");
    exit(1);
}

$user = $db->one('SELECT id FROM users WHERE username = ?', [$username]);
$hash = $auth->hashPassword($password);

if ($user) {
    if (isset($opts['reset'])) {
        $db->update('users', ['password_hash' => $hash], 'id = :id', ['id' => $user['id']]);
        echo "✓ Password updated for user '$username' (id={$user['id']}).\n";
    } else {
        echo "User '$username' already exists. Use --reset to change the password.\n";
        exit(0);
    }
} else {
    $id = $db->insert('users', ['username' => $username, 'password_hash' => $hash]);
    echo "✓ User '$username' created (id=$id).\n";
}

// Seed sample blocks if the page is empty
$blockCount = (int)$db->value('SELECT COUNT(*) FROM blocks');
if ($blockCount === 0) {
    echo "Empty page — seeding a starter set of blocks…\n";
    $samples = [
        ['type' => 'hero', 'data' => [
            'title' => 'Hi, this is your home.',
            'subtitle' => "One tile at a time.\nCompose your page from the admin.",
            'cta_label' => 'Explore',
            'cta_url' => '#',
        ]],
        ['type' => 'links', 'data' => [
            'title' => 'Quick links',
            'items' => [
                ['label' => 'Website', 'url' => 'https://example.com', 'icon' => 'lucide:globe'],
                ['label' => 'Newsletter', 'url' => 'https://example.com/newsletter', 'icon' => 'lucide:mail', 'badge' => 'new'],
            ],
        ]],
        ['type' => 'social', 'data' => [
            'title' => 'Follow me',
            'items' => [
                ['platform' => 'github', 'url' => 'https://github.com/'],
                ['platform' => 'instagram', 'url' => 'https://instagram.com/'],
            ],
        ]],
        ['type' => 'apps', 'data' => [
            'title' => 'Projects',
            'subtitle' => 'Things I make.',
            'columns' => '2',
            'items' => [
                ['name' => 'Project One', 'tagline' => 'Short tagline.', 'description' => 'Describe what it does.', 'url' => 'https://example.com/one', 'tag' => 'web', 'accent' => '#9bb6ff'],
                ['name' => 'Project Two', 'tagline' => 'Short tagline.', 'description' => 'Describe what it does.', 'url' => 'https://example.com/two', 'tag' => 'open source', 'accent' => '#a5e6c5'],
                ['name' => 'Project Three', 'tagline' => 'Short tagline.', 'description' => 'Describe what it does.', 'url' => 'https://example.com/three', 'tag' => 'tools', 'accent' => '#ffb8a3'],
            ],
        ]],
        ['type' => 'footer', 'data' => [
            'text' => '© ' . date('Y'),
            'show_powered_by' => true,
            'links' => [],
        ]],
    ];
    $pos = 10;
    foreach ($samples as $s) {
        $db->insert('blocks', [
            'type' => $s['type'],
            'position' => $pos,
            'enabled' => 1,
            'data' => json_encode($s['data'], JSON_UNESCAPED_UNICODE),
            'style' => '{}',
        ]);
        $pos += 10;
    }
    echo "✓ Sample page created.\n";
}

$appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
$adminUrl = $appUrl !== '' ? $appUrl . '/admin' : '/admin (replace with your domain)';
echo "\nDone. Open $adminUrl to sign in.\n";
