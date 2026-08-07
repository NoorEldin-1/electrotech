<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use App\Http\Api\ApiResponse;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps every throwable that escapes an API controller onto the error envelope
 * documented in API_Development_Plan.md §3.3.
 *
 * Registered in bootstrap/app.php and scoped to requests whose path starts with
 * `api/`, so the panel's HTML error pages are untouched.
 *
 * The contract that matters to the Flutter developer: `error.code` is a stable
 * machine string to branch on; `error.message` is localized prose to display.
 * Never swap those roles.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $this->handles($request)) {
            return null;
        }

        return match (true) {
            // Something upstream already built the exact response it wants —
            // Laravel's throttle middleware wraps our own 429 envelope this
            // way. Re-rendering it would turn a deliberate 429 into a 500.
            $e instanceof HttpResponseException => $this->passThrough($e),

            $e instanceof ValidationException => $this->validation($e),
            $e instanceof AuthenticationException => $this->unauthenticated(),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => $this->forbidden($e),
            $e instanceof ModelNotFoundException => $this->modelNotFound($e),
            $e instanceof NotFoundHttpException => $this->notFound(),
            $e instanceof MethodNotAllowedHttpException => $this->methodNotAllowed($e),
            $e instanceof TooManyRequestsHttpException => $this->rateLimited($e),
            $e instanceof ServiceUnavailableHttpException => $this->maintenance($e),
            $e instanceof DomainException => $this->businessRule($e),
            $e instanceof HttpExceptionInterface => $this->httpException($e),
            default => $this->serverError($e),
        };
    }

    /**
     * Only the API owns this renderer. Everything else keeps Laravel's default
     * behaviour, including the Filament panel's HTML error pages.
     */
    private function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    /**
     * Returning the wrapped response when it is already JSON; otherwise null,
     * which hands the exception back to Laravel rather than guessing.
     */
    private function passThrough(HttpResponseException $e): ?JsonResponse
    {
        $response = $e->getResponse();

        return $response instanceof JsonResponse ? $response : null;
    }

    private function validation(ValidationException $e): JsonResponse
    {
        return ApiResponse::error(
            'validation_failed',
            $e->getMessage(),
            422,
            $e->errors(),
        );
    }

    private function unauthenticated(): JsonResponse
    {
        return ApiResponse::error(
            'unauthenticated',
            __('errors.api.unauthenticated'),
            401,
            headers: ['WWW-Authenticate' => 'Bearer'],
        );
    }

    /**
     * A token whose abilities do not cover this route is a *different* failure
     * from a user who lacks the permission: the first is fixed by logging in
     * again with a wider scope, the second needs an administrator. Sanctum
     * signals the former with a distinctive message, so we split them here and
     * give the client two codes to branch on.
     */
    private function forbidden(Throwable $e): JsonResponse
    {
        $isTokenScope = str_contains(strtolower($e->getMessage()), 'ability');

        return ApiResponse::error(
            $isTokenScope ? 'insufficient_token_ability' : 'forbidden',
            $isTokenScope
                ? __('errors.api.insufficient_token_ability')
                : __('errors.api.forbidden'),
            403,
        );
    }

    /**
     * Route-model binding missed. We name the resource type but never echo the
     * requested id back — that turns the 404 into an existence oracle for a
     * caller probing for records it may not see.
     */
    private function modelNotFound(ModelNotFoundException $e): JsonResponse
    {
        $model = class_basename((string) $e->getModel());

        return ApiResponse::error(
            'not_found',
            __('errors.api.model_not_found', ['model' => $model]),
            404,
        );
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error(
            'not_found',
            __('errors.api.not_found'),
            404,
        );
    }

    private function methodNotAllowed(MethodNotAllowedHttpException $e): JsonResponse
    {
        $allowed = $e->getHeaders()['Allow'] ?? '';

        return ApiResponse::error(
            'method_not_allowed',
            __('errors.api.method_not_allowed'),
            405,
            $allowed !== '' ? ['allowed_methods' => explode(', ', $allowed)] : [],
            $e->getHeaders(),
        );
    }

    private function rateLimited(TooManyRequestsHttpException $e): JsonResponse
    {
        return ApiResponse::error(
            'rate_limited',
            __('errors.api.rate_limited'),
            429,
            headers: $e->getHeaders(),
        );
    }

    private function maintenance(ServiceUnavailableHttpException $e): JsonResponse
    {
        return ApiResponse::error(
            'maintenance',
            __('errors.api.maintenance'),
            503,
            headers: $e->getHeaders(),
        );
    }

    /**
     * The services in app/Services throw DomainException for "your payload was
     * fine, the business state was not" — e.g. finishing a work order whose
     * materials were never issued.
     *
     * These messages are written for humans and are safe to show directly, so
     * the client can render `message` without a lookup table. 422 rather than
     * 409 because the semantics match a validation failure the client cannot
     * fix by retrying: something about the request was not acceptable given
     * current state.
     */
    private function businessRule(DomainException $e): JsonResponse
    {
        return ApiResponse::error(
            'business_rule_violated',
            $e->getMessage(),
            422,
        );
    }

    /**
     * Any other HttpException (abort(409, ...), abort(413, ...)) keeps its
     * status and gets a code derived from it, so an endpoint can raise a
     * one-off status without a new branch here.
     */
    private function httpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $code = match ($status) {
            400 => 'bad_request',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            default => 'http_error',
        };

        $message = $e->getMessage() !== ''
            ? $e->getMessage()
            : __('errors.api.server_error');

        return ApiResponse::error($code, $message, $status, headers: $e->getHeaders());
    }

    /**
     * Unhandled. The client gets a generic message and the request id; the real
     * exception goes to the log where it belongs. Details are attached only
     * when APP_DEBUG is on, so a production stack trace can never leak through
     * the API even if someone forgets to check.
     */
    private function serverError(Throwable $e): JsonResponse
    {
        Log::error('Unhandled API exception', [
            'request_id' => \App\Http\Api\ApiRequestId::current(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $details = config('app.debug')
            ? [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]
            : [];

        return ApiResponse::error(
            'server_error',
            __('errors.api.server_error'),
            500,
            $details,
        );
    }
}
