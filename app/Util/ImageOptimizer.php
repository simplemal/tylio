<?php
declare(strict_types=1);

namespace Tylio\Util;

final class ImageOptimizer
{
    public const OG_MAX_WIDTH = 1200;
    public const OG_MAX_HEIGHT = 630;
    public const OG_JPEG_QUALITY = 82;

    /**
     * Resize-fit an image to max 1200x630 and re-encode as JPEG q=82.
     * Overwrites the file at $path. Returns metadata on success, null on failure.
     *
     * @return array{width:int,height:int,bytes:int,mime:string,ext:string}|null
     */
    public static function optimizeForOg(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) return null;
        if (!function_exists('imagecreatefromstring')) return null;

        $info = @getimagesize($path);
        if (!$info) return null;
        [$srcW, $srcH] = $info;
        $srcMime = (string)($info['mime'] ?? '');

        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $src = @imagecreatefromstring($raw);
        if (!$src) return null;

        $ratio = min(self::OG_MAX_WIDTH / max(1, $srcW), self::OG_MAX_HEIGHT / max(1, $srcH), 1.0);
        $dstW = (int)max(1, round($srcW * $ratio));
        $dstH = (int)max(1, round($srcH * $ratio));

        $dst = imagecreatetruecolor($dstW, $dstH);
        if (!$dst) {
            imagedestroy($src);
            return null;
        }

        if (in_array($srcMime, ['image/png', 'image/webp', 'image/gif'], true)) {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        $tmp = $path . '.opt.tmp';
        $ok = imagejpeg($dst, $tmp, self::OG_JPEG_QUALITY);
        imagedestroy($dst);
        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return null;
        }

        if (!@rename($tmp, $path)) {
            if (!@copy($tmp, $path)) {
                @unlink($tmp);
                return null;
            }
            @unlink($tmp);
        }
        @chmod($path, 0664);

        $bytes = @filesize($path) ?: 0;
        return [
            'width' => $dstW,
            'height' => $dstH,
            'bytes' => $bytes,
            'mime' => 'image/jpeg',
            'ext' => 'jpg',
        ];
    }
}
