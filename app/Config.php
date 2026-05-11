<?php
declare(strict_types=1);

namespace Tylio;

final class Config
{
    public function __construct(public readonly string $rootPath) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        // $v can be a string or false (from getenv when missing). Never
        // null — `??` would skip it and fall through to the default.
        return ($v === false || $v === '') ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null) return $default;
        return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === null ? $default : (int)$v;
    }

    public function path(string $relative): string
    {
        return $this->rootPath . '/' . ltrim($relative, '/');
    }

    public function dbPath(): string
    {
        $path = (string)$this->get('DATABASE_PATH', 'data/db.sqlite');
        // Special-case the SQLite in-memory pseudo-path: it must reach
        // PDO untouched, otherwise our root-prefix logic turns it into
        // `<rootPath>/:memory:` which SQLite treats as a real file —
        // surprise: every "isolated" connection ends up writing to the
        // same on-disk file. Same goes for any other path that already
        // looks absolute or carries a scheme.
        if ($path === '' || $path === ':memory:' || $path[0] === '/' || str_contains($path, '://')) {
            return $path === '' ? $this->path('data/db.sqlite') : $path;
        }
        return $this->path($path);
    }

    public function adminPath(): string
    {
        return rtrim((string)$this->get('ADMIN_PATH', '/admin'), '/');
    }

    public function appUrl(): string
    {
        return rtrim((string)$this->get('APP_URL', ''), '/');
    }
}
