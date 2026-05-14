<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
use Tylio\Services\Import;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Two flavors of "import a tar.gz archive into this tylio install":
 *
 *  - `fromInstall()` — POST /install/import. Used as an alternative to
 *    creating the admin user from scratch. Only available while the
 *    install lock is open (no row in `users`); 403 once a user exists.
 *  - `fromAdmin()`   — POST /admin/import. Auth + CSRF gated. The caller
 *    must pass `confirm=true` in the POST body to acknowledge that the
 *    existing site will be overwritten.
 *
 * Both accept `multipart/form-data` with an `archive` file field
 * (.tar.gz, ≤ Import::MAX_ARCHIVE_BYTES).
 *
 * **Extendable by design.** Non-`final`; the SaaS overlay subclasses
 * to scope inserts to the resolved tenant and refuses the
 * /install/import flow (the install wizard isn't reachable on a
 * provisioned SaaS instance anyway).
 */
class ImportController
{
    public function __construct(
        protected DB $db,
        protected Import $importer,
    ) {}

    /**
     * Install-flow import. Open while the admin user doesn't exist yet.
     */
    public function fromInstall(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->installLocked()) {
            return AuthController::json($response, [
                'error' => 'install_locked',
                'message' => 'tylio is already installed. Use POST /admin/import instead.',
            ], 403);
        }
        return $this->handleUpload($request, $response, fromInstall: true);
    }

    /**
     * Authenticated import. Overwrites the current site state.
     */
    public function fromAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $confirm = (string)($body['confirm'] ?? '');
        if ($confirm !== 'true' && $confirm !== '1') {
            return AuthController::json($response, [
                'error' => 'confirmation_required',
                'message' => 'Pass `confirm=true` to acknowledge the existing site will be overwritten.',
            ], 400);
        }
        return $this->handleUpload($request, $response, fromInstall: false);
    }

    /**
     * Shared upload-handling pipeline. Validates the uploaded file,
     * stages it to disk, calls Import::importFrom() and returns a JSON
     * result.
     */
    protected function handleUpload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        bool $fromInstall
    ): ResponseInterface {
        $files = $request->getUploadedFiles();
        $file = $files['archive'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return AuthController::json($response, ['error' => 'no_archive'], 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return AuthController::json($response, [
                'error' => 'upload_error',
                'code' => $file->getError(),
            ], 400);
        }
        if ($file->getSize() !== null && $file->getSize() > Import::MAX_ARCHIVE_BYTES) {
            return AuthController::json($response, [
                'error' => 'too_large',
                'limit' => Import::MAX_ARCHIVE_BYTES,
            ], 413);
        }
        $clientName = (string)($file->getClientFilename() ?? '');
        if ($clientName !== '' && !preg_match('/\.(tar\.gz|tgz)$/i', $clientName)) {
            return AuthController::json($response, [
                'error' => 'bad_extension',
                'message' => 'Archive must have a .tar.gz (or .tgz) extension.',
            ], 415);
        }

        $tmp = (string)tempnam(sys_get_temp_dir(), 'tylio_imp_');
        $file->moveTo($tmp);
        try {
            $summary = $this->importer->importFrom($tmp);
        } catch (\Throwable $e) {
            return AuthController::json($response, [
                'error' => 'import_failed',
                'message' => $e->getMessage(),
            ], 500);
        } finally {
            @unlink($tmp);
        }

        // Audit-log on the admin path (we know who triggered it). For the
        // install path there's no user yet.
        if (!$fromInstall) {
            $user = $request->getAttribute('user');
            $this->db->insert('audit_log', [
                'user_id' => $user['id'] ?? null,
                'action' => 'import.archive',
                'metadata' => json_encode($summary),
                'ip' => (string)($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            ]);
        }

        return AuthController::json($response, [
            'ok' => true,
            'summary' => $summary,
        ]);
    }

    /**
     * `true` once at least one user exists — the install wizard
     * disables itself in this state.
     */
    protected function installLocked(): bool
    {
        try {
            return (int)$this->db->value('SELECT COUNT(*) FROM users') > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
