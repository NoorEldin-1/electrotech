<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the generated API documentation at a clean, shareable URL.
 *
 * The docs are STATIC files: `php artisan scribe:generate` writes them to
 * `public/api/docs/`, they are committed to the repository, and the web server
 * normally serves them directly without PHP ever running. Scribe is a
 * `require-dev` package and production installs with `--no-dev`, so they
 * cannot be generated on the server — see API_PROGRESS.md § Deployment.
 *
 * This controller is the safety net for the bare `/api/docs` URL (no trailing
 * slash) on a web server that does not do directory-index resolution. It only
 * runs when the request actually reaches PHP.
 *
 * Note the path: `/docs` is already taken by the Arabic end-user manual in
 * routes/web.php, which is a different document for a different audience.
 */
class ApiDocsController extends Controller
{
    public function __invoke(): Response
    {
        if (! config('api.docs.enabled')) {
            throw new NotFoundHttpException;
        }

        $path = public_path('api/docs/index.html');

        if (! is_file($path)) {
            // A deliberately loud failure. Silently 404ing here would mean the
            // link handed to the mobile developer just breaks, with nothing
            // saying why.
            throw new NotFoundHttpException(
                'API documentation has not been generated. Run `php artisan scribe:generate` and commit public/api/docs.',
            );
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Public and cacheable: the page is identical for everyone and
            // changes only on deploy.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
