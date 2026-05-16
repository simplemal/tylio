<?php
declare(strict_types=1);

/**
 * Compatibility sentinel — DO NOT remove.
 *
 * tylio's real entry point is `index.php` at the project ROOT (with
 * `.htaccess` routing every URL through it). The `public/` directory
 * is used only for static assets (`public/admin/` for the admin SPA).
 *
 * However, the v0.3.1 `UpdateApplier::stagingLooksValid()` mistakenly
 * looks for `public/index.php` as a sanity check on the downloaded
 * source tarball. Without this stub in v0.3.3+, a self-host on
 * v0.3.1 cannot apply ANY in-app update (the staging directory fails
 * the check, returning HTTP 422). This stub satisfies the v0.3.1
 * check; v0.3.2+ moves the check to look at the root `index.php`
 * and no longer relies on this file.
 *
 * Defensive behavior: if some hosting environment auto-configures
 * Apache with DocumentRoot=public/, we redirect to the real root
 * so the site still works. On a tylio install with the canonical
 * root-level `.htaccess`, this file is never served.
 */
http_response_code(301);
header('Location: /');
