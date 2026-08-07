<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Base class for every v1 API controller.
 *
 * Carries two things and no business logic:
 *
 *  - AuthorizesRequests, so `$this->authorize('update', $model)` reaches the
 *    exact same policies the Filament panel uses. The API adds no parallel
 *    authorization system.
 *  - Thin response helpers that funnel everything through ApiResponse, so the
 *    envelope is produced in one place.
 *
 * Design rule from API_Development_Plan.md §1.1: a controller may validate,
 * authorize, delegate to a service, and serialize. Anything else belongs in
 * App\Services.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    protected function respond(JsonResource|array|null $resource, int $status = 200): JsonResponse
    {
        return ApiResponse::item($resource, $status);
    }

    protected function respondCreated(JsonResource|array $resource): JsonResponse
    {
        return ApiResponse::item($resource, 201);
    }

    protected function respondPaginated(ResourceCollection|LengthAwarePaginator $collection): JsonResponse
    {
        return ApiResponse::paginated($collection);
    }

    /**
     * For genuinely bounded lists — an enum catalog, the permission list, a
     * user's active devices. Anything that grows with business volume must be
     * paginated instead.
     */
    protected function respondCollection(array $items): JsonResponse
    {
        return ApiResponse::collection($items);
    }

    protected function respondNoContent(): JsonResponse
    {
        return ApiResponse::noContent();
    }

    /**
     * Raise a 422 from inside a controller when a check does not belong in a
     * FormRequest (e.g. it needs the resolved model). Goes through
     * ValidationException so the response shape is identical to a FormRequest
     * rejection and the client has one code path.
     */
    protected function failValidation(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
