<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
use Tylio\Services\StaticExporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API endpoint to download the public page as a self-contained static HTML.
 * Requires admin authentication (= the site owner).
 */
class ExportController
{
    public function __construct(
        protected StaticExporter $exporter,
        protected DB $db,
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
}
