<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ETag / `If-None-Match` support for safe requests.
 *
 * On a weak mobile link the win is real: a work order with its materials and
 * outputs is tens of kilobytes, and a client polling it every time the user
 * opens the screen re-downloads all of it. With an ETag, an unchanged resource
 * costs one round trip and ~200 bytes.
 *
 * The tag is a hash of the rendered body, so it is correct by construction —
 * no cache-invalidation logic to get wrong when a nested relation changes.
 * We still pay the cost of building the response; what is saved is bandwidth,
 * which is the scarce resource here, not CPU.
 *
 * Applied to GET/HEAD only, and only to 200s. The `Vary` header includes
 * Accept-Language because the same record renders different enum labels in
 * Arabic and English and must not collide in a client-side cache.
 */
class ConditionalGet
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || $content === '') {
            return $response;
        }

        $etag = '"'.hash('xxh128', $this->canonicalize($content)).'"';
        $response->headers->set('ETag', $etag);
        $response->headers->set('Vary', 'Accept-Language, Authorization');

        if ($this->matches($request->header('If-None-Match'), $etag)) {
            $response->setNotModified();
        }

        return $response;
    }

    /**
     * Strips the parts of the envelope that change on every request before
     * hashing.
     *
     * `meta.request_id` is unique per call by design. Hashing it would mean
     * two identical representations never share an ETag, so `If-None-Match`
     * could never match and the whole conditional-GET mechanism would be dead
     * weight that still costs a hash on every response.
     *
     * Everything else in the body — including `meta.pagination` — genuinely
     * describes the representation and must stay in the tag.
     */
    private function canonicalize(string $content): string
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! isset($decoded['meta']['request_id'])) {
            return $content;
        }

        unset($decoded['meta']['request_id']);

        return json_encode($decoded) ?: $content;
    }

    /**
     * `If-None-Match` may carry a comma-separated list, and a client may send
     * back a weak tag ("W/..."). Compare on the opaque part only.
     */
    private function matches(?string $header, string $etag): bool
    {
        if ($header === null || $header === '') {
            return false;
        }

        if (trim($header) === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            $candidate = preg_replace('/^W\//', '', $candidate) ?? $candidate;

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
