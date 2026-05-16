<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Favicon upload + removal. Genera 32/180/192/512 PNG da un input
 * (PNG/JPG/WebP — l'SVG non è supportato perché GD non lo legge),
 * salva sotto `favicons/` e bumpa il setting `seo.favicon` per
 * invalidare CDN/browser cache sul layout pubblico.
 *
 * Due sorgenti di input gestite dallo stesso endpoint:
 *
 *   1. **File multipart** — `$_FILES['file']`. È quello che usa il
 *      drag-and-drop / file picker della Settings UI.
 *   2. **Media id** — body JSON `{ "media_id": N }`. Lo usa il
 *      "scegli da galleria" della stessa UI per riusare un asset già
 *      in `media`.
 *
 * Ogni step ha il proprio codice errore + messaggio italiano in
 * `detail` cosicché il SPA mostri qualcosa di leggibile senza
 * pescare nei log. Codici (errori → status HTTP):
 *
 *   - `no_input` (400) — né `file` né `media_id` nel body
 *   - `media_not_found` (404) — `media_id` referenzia una riga inesistente
 *   - `media_file_missing` (410) — riga in DB ma file su disco sparito
 *   - `invalid_mime` (415) — tipo non supportato
 *   - `image_decode_failed` (422) — GD non riesce a leggere
 *   - `gd_unavailable` (503) — l'estensione GD manca / disabilitata
 *   - `dest_not_writable` (500) — `favicons/` non scrivibile da PHP
 *   - `resize_failed` (500) — `imagecopyresampled` ha tirato
 *   - `write_failed` (500) — `imagepng` non è riuscito a scrivere
 *   - `db_failed` (500) — UPDATE del setting fallito
 *   - `exception` (500) — qualunque altro Throwable (catch-all)
 *
 * **Extendable by design.** Non-`final`; le subclasses possono
 * overridare la dir di output (e.g. tenant-scoped) senza ri-implementare
 * la pipeline di resize.
 */
class FaviconController
{
    /** Sizes generate dall'upload (PNG output a prescindere dal MIME in input). */
    private const SIZES = [32, 180, 192, 512];

    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(protected DB $db, protected Config $config) {}

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tmp = null;
        try {
            if (!extension_loaded('gd')) {
                return $this->err($response, 'gd_unavailable',
                    "L'estensione PHP GD non è installata: tylio non può ridimensionare le immagini. "
                    . "Su Debian/Ubuntu: `sudo apt install php-gd && sudo systemctl reload apache2`.",
                    503);
            }

            $tmp = $this->resolveInput($request);
            if ($tmp instanceof ResponseInterface) {
                return $tmp; // already-built error response
            }

            $mime = $this->detectMime($tmp);
            if (!in_array($mime, self::ALLOWED_MIMES, true)) {
                return $this->err($response, 'invalid_mime',
                    "Tipo di file non supportato: $mime. Accettati: PNG, JPEG, WebP. "
                    . "(L'SVG non è supportato dal ridimensionatore GD — convertilo prima in PNG.)",
                    415, ['mime' => $mime]);
            }

            $destDir = $this->config->path('favicons');
            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                return $this->err($response, 'dest_not_writable',
                    "Impossibile creare la directory $destDir. "
                    . "Verifica che il processo PHP possa scrivere sulla parent dir.",
                    500);
            }
            if (!is_writable($destDir)) {
                return $this->err($response, 'dest_not_writable',
                    "La directory $destDir non è scrivibile dal processo PHP. "
                    . "Esegui: `sudo chown www-data:www-data $destDir && sudo chmod 775 $destDir`.",
                    500);
            }

            $src = $this->decode($tmp, $mime);
            if (!$src) {
                return $this->err($response, 'image_decode_failed',
                    "Il file sembra avere MIME $mime ma GD non riesce a decodificarlo. "
                    . "Probabilmente è corrotto o usa una variante non supportata. "
                    . "Prova con un file diverso o riesporta dall'editor.",
                    422);
            }

            $sw = imagesx($src);
            $sh = imagesy($src);

            foreach (self::SIZES as $size) {
                $dst = @imagecreatetruecolor($size, $size);
                if (!$dst) {
                    imagedestroy($src);
                    return $this->err($response, 'resize_failed',
                        "GD ha fallito ad allocare un buffer {$size}x{$size}px. "
                        . "Probabile memory_limit di PHP troppo basso (è "
                        . ini_get('memory_limit') . "). Aumentalo a 256M.",
                        500);
                }
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                if (!@imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $sw, $sh)) {
                    imagedestroy($dst);
                    imagedestroy($src);
                    return $this->err($response, 'resize_failed',
                        "GD ha fallito il resampling a {$size}x{$size}px.",
                        500);
                }
                $outPath = $destDir . "/icon-$size.png";
                if (!@imagepng($dst, $outPath, 6)) {
                    imagedestroy($dst);
                    imagedestroy($src);
                    return $this->err($response, 'write_failed',
                        "Impossibile scrivere `$outPath`. "
                        . "Probabili permessi: `sudo chown www-data:www-data $destDir`.",
                        500);
                }
                imagedestroy($dst);
            }
            imagedestroy($src);

            $version = (string)time();
            try {
                $this->db->pdo()->prepare(
                    "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('seo.favicon', ?, datetime('now'))"
                )->execute([json_encode($version)]);
            } catch (\Throwable $e) {
                return $this->err($response, 'db_failed',
                    "Le immagini sono state generate ma l'UPDATE su `settings.seo.favicon` è fallito: "
                    . $e->getMessage(),
                    500);
            }

            return AuthController::json($response, [
                'ok' => true,
                'version' => $version,
                'sizes' => array_combine(
                    array_map(static fn(int $s): string => (string)$s, self::SIZES),
                    array_map(static fn(int $s): string => "/favicons/icon-$s.png?v=$version", self::SIZES),
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->err($response, 'exception', $e->getMessage(), 500, [
                'class' => get_class($e),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
        } finally {
            if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
        }
    }

    /**
     * Risolve l'input in un path su disco (un tmp file pronto da
     * leggere). Due sorgenti: upload multipart o `media_id` body.
     * Restituisce il path string OPPURE una ResponseInterface già
     * pronta con un errore specifico (early return path nel chiamante).
     */
    protected function resolveInput(ServerRequestInterface $request): string|ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if ($file instanceof UploadedFileInterface) {
            $err = $file->getError();
            if ($err !== UPLOAD_ERR_OK) {
                return $this->err(
                    new \Slim\Psr7\Response(),
                    'upload_failed',
                    "L'upload del file è fallito (codice $err — " . $this->uploadErrCode($err) . "). "
                    . "Limiti PHP attuali: upload_max_filesize="
                    . ini_get('upload_max_filesize')
                    . ", post_max_size=" . ini_get('post_max_size') . ".",
                    400,
                );
            }
            $tmp = tempnam(sys_get_temp_dir(), 'fav_');
            if ($tmp === false) {
                return $this->err(new \Slim\Psr7\Response(), 'tmp_alloc_failed',
                    "Impossibile creare un file temporaneo (sys_get_temp_dir non scrivibile).", 500);
            }
            $file->moveTo($tmp);
            return $tmp;
        }

        $body = (array)$request->getParsedBody();
        $mediaId = isset($body['media_id']) ? (int)$body['media_id'] : 0;
        if ($mediaId <= 0) {
            return $this->err(new \Slim\Psr7\Response(), 'no_input',
                "Nessun input nella richiesta: serve `file` (upload multipart) o `media_id` "
                . "(JSON body) per usare un'immagine già in galleria.", 400);
        }

        $row = $this->db->one('SELECT filename FROM media WHERE id = ? LIMIT 1', [$mediaId]);
        if (!$row) {
            return $this->err(new \Slim\Psr7\Response(), 'media_not_found',
                "Nessun media in galleria con id=$mediaId.", 404);
        }
        $path = $this->config->path('uploads/' . ltrim((string)$row['filename'], '/'));
        if (!is_file($path)) {
            return $this->err(new \Slim\Psr7\Response(), 'media_file_missing',
                "Il media id=$mediaId esiste nel DB ma il file `$path` è sparito da disco. "
                . "Reuploadalo dalla sezione Media.", 410);
        }
        // Copia in un tmp così la pipeline a valle può @unlink senza
        // rischiare di toccare il media originale.
        $tmp = tempnam(sys_get_temp_dir(), 'fav_');
        if ($tmp === false || !@copy($path, $tmp)) {
            if ($tmp !== false) @unlink($tmp);
            return $this->err(new \Slim\Psr7\Response(), 'tmp_alloc_failed',
                "Impossibile copiare il media in un file temporaneo per il resize.", 500);
        }
        return $tmp;
    }

    protected function detectMime(string $path): string
    {
        try {
            return (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return \GdImage|false */
    protected function decode(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/png'  => @imagecreatefrompng($path),
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
    }

    /**
     * Risposta JSON di errore standardizzata.
     * @param array<string,mixed> $extra
     */
    protected function err(
        ResponseInterface $response,
        string $code,
        string $detail,
        int $status,
        array $extra = [],
    ): ResponseInterface {
        return AuthController::json($response, array_merge([
            'ok' => false,
            'error' => $code,
            'detail' => $detail,
        ], $extra), $status);
    }

    /**
     * Mappa i codici UPLOAD_ERR_* a stringhe leggibili (anteposto dal
     * client per capire perché PHP ha rifiutato il file).
     */
    protected function uploadErrCode(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'oversize:ini',
            UPLOAD_ERR_FORM_SIZE  => 'oversize:form',
            UPLOAD_ERR_PARTIAL    => 'partial',
            UPLOAD_ERR_NO_FILE    => 'no_file',
            UPLOAD_ERR_NO_TMP_DIR => 'no_tmp_dir',
            UPLOAD_ERR_CANT_WRITE => 'cant_write',
            UPLOAD_ERR_EXTENSION  => 'extension_blocked',
            default               => 'unknown',
        };
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $destDir = $this->config->path('favicons');
            foreach (self::SIZES as $size) {
                @unlink($destDir . "/icon-$size.png");
            }
            $this->db->pdo()->prepare(
                "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('seo.favicon', ?, datetime('now'))"
            )->execute([json_encode('')]);
            return AuthController::json($response, ['ok' => true]);
        } catch (\Throwable $e) {
            return $this->err($response, 'exception', $e->getMessage(), 500);
        }
    }
}
