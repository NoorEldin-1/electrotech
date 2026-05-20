<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Transparent response compression for environments where the reverse-proxy
 * (nginx / Apache) cannot be relied upon to negotiate it — most notably the
 * factory deployment which often sits behind a thin proxy that strips the
 * Accept-Encoding header.
 *
 * On a 200 kbps link a Filament resource list page renders ~80 KB of HTML;
 * brotli/gzip shrinks that to ~10 KB. That difference is the gap between
 * "the page loads" and "the user reloads and gets nothing."
 */
class CompressResponse
{
    /**
     * Don't bother compressing tiny payloads — the CPU cost outweighs the
     * bytes saved once you're under ~1 KB.
     */
    private const MIN_BYTES = 1024;

    /**
     * Mime-type prefixes worth compressing. Binary / already-compressed
     * formats (images, fonts, zip) are skipped.
     */
    private const COMPRESSIBLE_PREFIXES = [
        'text/',
        'application/json',
        'application/javascript',
        'application/xml',
        'application/xhtml+xml',
        'application/ld+json',
        'application/manifest+json',
        'image/svg+xml',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $content = (string) $response->getContent();

        if (strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        [$encoding, $compressed] = $this->compress($content, $request->header('Accept-Encoding', ''));

        if ($compressed === null) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', $encoding);
        $response->headers->set('Content-Length', (string) strlen($compressed));
        // Tell caches the encoding depends on the request header so we don't
        // hand a brotli body to a gzip-only client out of a shared cache.
        $response->headers->set('Vary', trim(($response->headers->get('Vary') ?? '') . ', Accept-Encoding', ', '));

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if ($contentType === '') {
            return false;
        }

        foreach (self::COMPRESSIBLE_PREFIXES as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:string,1:string|null}  [encoding, compressed-body]
     */
    private function compress(string $content, string $acceptEncoding): array
    {
        $accepts = array_map('trim', explode(',', strtolower($acceptEncoding)));

        // Prefer brotli on the wire when both client and PHP support it.
        if (function_exists('brotli_compress') && in_array('br', $accepts, true)) {
            $compressed = brotli_compress($content, 4);
            if ($compressed !== false) {
                return ['br', $compressed];
            }
        }

        if (in_array('gzip', $accepts, true) && function_exists('gzencode')) {
            $compressed = gzencode($content, 5);
            if ($compressed !== false) {
                return ['gzip', $compressed];
            }
        }

        return ['', null];
    }
}
