<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\DB;
use Tylio\Services\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Image upload + delete for the media library. Validates MIME against
 * the `UPLOAD_ALLOWED_MIME` whitelist (SVG NOT allowed by default —
 * XSS risk via inline `<script>`) and writes to `uploads/`.
 *
 * **Extendable by design.** Non-`final`; sub-classes can scope queries
 * by `tenant_id` and write to a per-tenant subdirectory (the platform
 * uses `uploads/<slug>/` for this).
 */
class MediaController
{
    public function __construct(protected DB $db, protected Config $config, protected I18n $i18n) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = $this->db->all('SELECT * FROM media ORDER BY id DESC LIMIT 200');
        foreach ($rows as &$r) {
            $r['url'] = '/uploads/' . $r['filename'];
        }
        return AuthController::json($response, ['media' => $rows]);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return AuthController::json($response, ['error' => 'no_file'], 400);
        }
        $this->i18n->setLocale($this->i18n->negotiate($request->getHeaderLine('Accept-Language')));
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $keyForCode = [
                UPLOAD_ERR_INI_SIZE   => 'media.upload.error.ini_size',
                UPLOAD_ERR_FORM_SIZE  => 'media.upload.error.form_size',
                UPLOAD_ERR_PARTIAL    => 'media.upload.error.partial',
                UPLOAD_ERR_NO_FILE    => 'media.upload.error.no_file',
                UPLOAD_ERR_NO_TMP_DIR => 'media.upload.error.no_tmp_dir',
                UPLOAD_ERR_CANT_WRITE => 'media.upload.error.cant_write',
                UPLOAD_ERR_EXTENSION  => 'media.upload.error.extension',
            ];
            $code = $file->getError();
            $key = $keyForCode[$code] ?? 'media.upload.error.unknown';
            return AuthController::json($response, [
                'error' => 'upload_error',
                'code' => $code,
                'message' => $this->i18n->t($key),
            ], 400);
        }
        $maxBytes = $this->config->int('UPLOAD_MAX_BYTES', 10 * 1024 * 1024);
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            return AuthController::json($response, [
                'error' => 'too_large',
                'message' => $this->i18n->t('media.upload.error.too_large', ['mb' => (int)round($maxBytes / 1048576)]),
            ], 413);
        }

        $allowed = array_filter(array_map('trim', explode(',', (string)$this->config->get('UPLOAD_ALLOWED_MIME', ''))));
        $tmp = tempnam(sys_get_temp_dir(), 'tylio_');
        $file->moveTo($tmp);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        if (!empty($allowed) && !in_array($mime, $allowed, true)) {
            @unlink($tmp);
            return AuthController::json($response, ['error' => 'mime_not_allowed', 'mime' => $mime], 415);
        }

        $ext = self::extensionForMime($mime, $file->getClientFilename() ?? '');
        $filename = bin2hex(random_bytes(8)) . '_' . date('YmdHis') . '.' . $ext;
        $destDir = $this->config->path('uploads');
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        $htaccess = $destDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n",
            );
        }
        $destPath = $destDir . '/' . $filename;

        $saved = false;
        if (rename($tmp, $destPath)) {
            $saved = true;
        } elseif (copy($tmp, $destPath)) {
            @unlink($tmp);
            $saved = true;
        }
        if (!$saved || !is_file($destPath)) {
            @unlink($tmp);
            return AuthController::json($response, [
                'error' => 'save_failed',
                'message' => 'Impossibile salvare il file. Verifica i permessi della cartella uploads/.',
            ], 500);
        }
        @chmod($destPath, 0664);

        $w = $h = null;
        $bytes = @filesize($destPath) ?: 0;
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $size = @getimagesize($destPath);
            if ($size) { $w = $size[0]; $h = $size[1]; }
        }

        $user = $request->getAttribute('user');
        $id = $this->db->insert('media', [
            'filename' => $filename,
            'original_name' => $file->getClientFilename() ?? $filename,
            'mime' => $mime,
            'size' => $bytes,
            'width' => $w,
            'height' => $h,
            'uploaded_by' => $user['id'] ?? null,
        ]);

        return AuthController::json($response, [
            'media' => [
                'id' => $id,
                'filename' => $filename,
                'url' => '/uploads/' . $filename,
                'mime' => $mime,
                'size' => $bytes,
                'width' => $w,
                'height' => $h,
            ],
        ], 201);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $row = $this->db->one('SELECT filename FROM media WHERE id = ?', [$id]);
        if ($row) {
            @unlink($this->config->path('uploads/' . $row['filename']));
            $this->db->query('DELETE FROM media WHERE id = ?', [$id]);
        }
        return AuthController::json($response, ['ok' => true]);
    }

    protected static function extensionForMime(string $mime, string $original): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (isset($map[$mime])) return $map[$mime];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'bin';
    }
}
