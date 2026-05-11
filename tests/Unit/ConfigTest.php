<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tylio\Config;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['DATABASE_PATH']);
    }

    public function test_default_db_path_is_relative_to_root(): void
    {
        $config = new Config('/tmp/anyroot');
        $this->assertSame('/tmp/anyroot/data/db.sqlite', $config->dbPath());
    }

    public function test_relative_db_path_is_prefixed_with_root(): void
    {
        $_ENV['DATABASE_PATH'] = 'storage/test.sqlite';
        $config = new Config('/tmp/anyroot');
        $this->assertSame('/tmp/anyroot/storage/test.sqlite', $config->dbPath());
    }

    public function test_absolute_db_path_is_preserved(): void
    {
        $_ENV['DATABASE_PATH'] = '/var/lib/tylio/db.sqlite';
        $config = new Config('/tmp/anyroot');
        $this->assertSame('/var/lib/tylio/db.sqlite', $config->dbPath());
    }

    public function test_memory_db_path_is_preserved(): void
    {
        // Critical: `:memory:` must reach PDO untouched. If Config
        // prefixed it with rootPath, SQLite would treat it as a real
        // file and every "in-memory" connection would silently share
        // the same on-disk database.
        $_ENV['DATABASE_PATH'] = ':memory:';
        $config = new Config('/tmp/anyroot');
        $this->assertSame(':memory:', $config->dbPath());
    }

    public function test_empty_db_path_falls_back_to_default(): void
    {
        $_ENV['DATABASE_PATH'] = '';
        $config = new Config('/tmp/anyroot');
        $this->assertSame('/tmp/anyroot/data/db.sqlite', $config->dbPath());
    }

    public function test_bool_env_parsing(): void
    {
        $config = new Config('/tmp');
        $_ENV['MY_FLAG'] = 'true';
        $this->assertTrue($config->bool('MY_FLAG'));
        $_ENV['MY_FLAG'] = '1';
        $this->assertTrue($config->bool('MY_FLAG'));
        $_ENV['MY_FLAG'] = 'yes';
        $this->assertTrue($config->bool('MY_FLAG'));
        $_ENV['MY_FLAG'] = 'false';
        $this->assertFalse($config->bool('MY_FLAG'));
        $_ENV['MY_FLAG'] = '0';
        $this->assertFalse($config->bool('MY_FLAG'));
        unset($_ENV['MY_FLAG']);
        $this->assertFalse($config->bool('MY_FLAG'));
        $this->assertTrue($config->bool('MY_FLAG', true));
    }

    public function test_admin_path_trim(): void
    {
        $config = new Config('/tmp');
        $_ENV['ADMIN_PATH'] = '/admin/';
        $this->assertSame('/admin', $config->adminPath());
        $_ENV['ADMIN_PATH'] = '/control-panel';
        $this->assertSame('/control-panel', $config->adminPath());
        unset($_ENV['ADMIN_PATH']);
        $this->assertSame('/admin', $config->adminPath());
    }

    public function test_app_url_trim(): void
    {
        $config = new Config('/tmp');
        $_ENV['APP_URL'] = 'https://example.com/';
        $this->assertSame('https://example.com', $config->appUrl());
        $_ENV['APP_URL'] = 'https://example.com';
        $this->assertSame('https://example.com', $config->appUrl());
    }
}
