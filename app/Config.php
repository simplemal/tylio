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
        return $this->path((string)$this->get('DATABASE_PATH', 'data/db.sqlite'));
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
