<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees the API speaks JSON in both directions.
 *
 * Two jobs:
 *
 *  1. Force `Accept: application/json` on the way in. Laravel decides whether
 *     to render an HTML error page or a JSON one by inspecting this header. A
 *     client that forgets it would get an HTML 500 page it cannot parse — the
 *     single most common cause of "the app just shows a blank error".
 *
 *  2. Reject a request body sent as anything other than JSON. Accepting
 *     form-encoded bodies would mean two parsing paths and two sets of edge
 *     cases for the same endpoint. Multipart is allowed because file uploads
 *     have no other transport.
 */
class ForceJsonResponse
{
    /** @var list<string> */
    private const ALLOWED_CONTENT_TYPES = [
        'application/json',
        'multipart/form-data',
    ];

    /** @var list<string> */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        if (in_array($request->method(), self::BODY_METHODS, true)) {
            $contentType = (string) $request->header('Content-Type', '');

            // An empty body with no Content-Type is fine — some actions
            // (approve, post, revoke) legitimately carry no payload.
            $hasBody = $contentType !== '' || $request->getContent() !== '';

            if ($hasBody && ! $this->isAllowedContentType($contentType)) {
                return ApiResponse::error(
                    'unsupported_media_type',
                    'The API accepts application/json (or multipart/form-data for uploads).',
                    415,
                );
            }
        }

        return $next($request);
    }

    private function isAllowedContentType(string $contentType): bool
    {
        // Strip parameters: "application/json; charset=utf-8" → "application/json".
        $base = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($base, self::ALLOWED_CONTENT_TYPES, true);
    }
}
