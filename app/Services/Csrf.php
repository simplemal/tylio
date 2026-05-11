<?php
declare(strict_types=1);

namespace Tylio\Services;

final class Csrf
{
    public function isValid(?string $expected, ?string $received): bool
    {
        if ($expected === null || $received === null) return false;
        return hash_equals($expected, $received);
    }
}
