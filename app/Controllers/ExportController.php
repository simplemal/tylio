<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
use Tylio\Services\Export;
use Tylio\Services\StaticExporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API endpoint to download the public page as a self-contained static
 * HTML — either a multi-file ZIP (`download()`) or a single inline-
 * everything `index.html` (`downloadInline()`). Requires admin
 * authentication (= the site owner).
 *
 * Also serves the full-site archive download (`archive()`): a portable
 * `.tar.gz` produced by `Tylio\Services\Export` containing the DB rows
 * (blocks/theme/settings/media) plus every uploaded file. This is the
 * complement of the import endpoint and the export format consumed by
 * the install wizard and by `POST /admin/import`.
 *
 * **Extendable by design.** Non-`final` and exposes `$exporter` /
 * `$archive` / `$db` as `protected` so a sub-class can plug in a
 * tenant-aware exporter without rewriting the response framing.
 */
class ExportController
{
    public function __construct(
        protected StaticExporter $exporter,
        protected DB $db,
        protected Export $archive,
    ) {}

    /**
     * "Single-file" variant: one index.html with every asset (images, logo,
     * favicon) inlined as a data URI. Opens offline and works via file:// or
     * on any static host without any other files.
     */
    public function downloadInline(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $html = $this->exporter->exportInline();
        $size = strlen($html);

        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => 'export.inline',
            'metadata' => json_encode(['size' => $size]),
            'ip' => (string)($request->getServerParams()['REMOTE_ADDR'] ?? ''),
        ]);

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="index.html"')
            ->withHeader('Content-Length', (string)$size)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $zipPath = $this->exporter->export();
        $size = (int)filesize($zipPath);
        $filename = basename($zipPath);

        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => 'export.static',
            'metadata' => json_encode(['size' => $size, 'filename' => $filename]),
            'ip' => (string)($request->getServerParams()['REMOTE_ADDR'] ?? ''),
        ]);

        $stream = fopen($zipPath, 'rb');
        if ($stream === false) {
            return AuthController::json($response, ['error' => 'export_failed'], 500);
        }
        register_shutdown_function(static function () use ($zipPath) {
            @unlink($zipPath);
        });

        $body = $response->getBody();
        rewind($stream);
        while (!feof($stream)) {
            $body->write((string)fread($stream, 8192));
        }
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)$size)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Full-site portable archive: tar.gz with `meta.json` + `data.json`
     * + `uploads/` + `favicons/`. Re-importable on any tylio instance
     * via `POST /admin/import` (or `POST /install/import` on a fresh
     * install before the admin user exists).
     */
    public function archive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');

        $tarPath = $this->archive->build();
        $size = (int)filesize($tarPath);
        $downloadName = $this->archiveDownloadName();

        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => 'export.archive',
            'metadata' => json_encode(['size' => $size, 'filename' => $downloadName]),
            'ip' => (string)($request->getServerParams()['REMOTE_ADDR'] ?? ''),
        ]);

        $stream = fopen($tarPath, 'rb');
        if ($stream === false) {
            return AuthController::json($response, ['error' => 'archive_failed'], 500);
        }
        register_shutdown_function(static function () use ($tarPath) {
            @unlink($tarPath);
        });

        $body = $response->getBody();
        rewind($stream);
        while (!feof($stream)) {
            $body->write((string)fread($stream, 8192));
        }
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'application/gzip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
            ->withHeader('Content-Length', (string)$size)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Filename suggested to the browser for the archive. Subclasses
     * (TenantExportController) override to embed the tenant slug.
     */
    protected function archiveDownloadName(): string
    {
        return 'tylio-export-' . date('Ymd-His') . '.tar.gz';
    }
}
