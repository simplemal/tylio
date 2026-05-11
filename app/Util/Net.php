<?php
declare(strict_types=1);

namespace Tylio\Util;

final class Net
{
    public static function ipInRanges(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipInRange($ip, $cidr)) return true;
        }
        return false;
    }

    public static function ipInRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $maskRaw] = explode('/', $cidr, 2);
        $mask = (int)$maskRaw;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) return true;

        $maskByte = chr((0xff << (8 - $bits)) & 0xff);
        return (ord($ipBin[$bytes]) & ord($maskByte)) === (ord($subnetBin[$bytes]) & ord($maskByte));
    }
}
