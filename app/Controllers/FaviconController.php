<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Favicon upload + removal. Generates 32/180/192/512 PNGs from any
 * input (PNG/JPG/WebP/SVG), stores them under `favicons/` and bumps
 * the `seo.favicon` setting so the public layout busts the CDN cache.
 *
 * **Extendable by design.** Non-`final`; sub-classes can override the
 * output directory (e.g. per-tenant `favicons/<slug>/`) without
 * re-implementing the resize pipeline.
 */
class FaviconController
{
    /** Sizes generated from the upload (PNG output regardless of input format). */
    private const SIZES = [32, 180, 192, 512];

    public function __construct(protected DB $db, protected Config $config) {}

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return AuthController::json($response, ['error' => 'no_file'], 400);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fav_');
        $file->moveTo($tmp);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            @unlink($tmp);
            return AuthController::json($response, ['error' => 'invalid_mime', 'mime' => $mime], 415);
        }

        $destDir = $this->config->path('favicons');
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $src = match ($mime) {
            'image/png' => @imagecreatefrompng($tmp),
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
        };
        if (!$src) {
            @unlink($tmp);
            return AuthController::json($response, ['error' => 'image_decode_failed'], 422);
        }
        $sw = imagesx($src);
        $sh = imagesy($src);

        foreach (self::SIZES as $size) {
            $dst = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $sw, $sh);
            imagepng($dst, $destDir . "/icon-$size.png", 6);
            imagedestroy($dst);
        }
        imagedestroy($src);
        @unlink($tmp);

        $version = (string)time();
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('seo.favicon', ?, datetime('now'))"
        )->execute([json_encode($version)]);

        return AuthController::json($response, ['ok' => true, 'version' => $version]);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $destDir = $this->config->path('favicons');
        foreach (self::SIZES as $size) {
            @unlink($destDir . "/icon-$size.png");
        }
        $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('seo.favicon', ?, datetime('now'))"
        )->execute([json_encode('')]);
        return AuthController::json($response, ['ok' => true]);
    }
}
