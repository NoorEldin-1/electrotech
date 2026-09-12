<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * @group 41. Activity log
 *
 * سجل النشاط — who changed what, and when.
 *
 * **Read-only, and append-only underneath.** `ActivityPolicy` answers false to
 * create, update and delete, and there are no write routes. A log that could
 * be edited is not a log.
 *
 * Each row carries the subject it is about (`subject_type` + `subject_id`), the
 * user who caused it, and the `properties` bag holding the before/after values
 * the model recorded. The properties are passed through as written: they are
 * evidence, and reshaping evidence for presentation is how detail goes missing.
 *
 * The log grows without limit, so the index is filtered rather than browsed —
 * `filter[subject_type]` and `filter[subject_id]` together answer "what
 * happened to this record", which is the question that gets asked.
 */
class ActivityLogController extends ApiController
{
    /**
     * List activity
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the description. Example: approved
     * @queryParam filter[log_name] string Example: default
     * @queryParam filter[subject_type] string The subject's class name. Example: App\Models\WorkOrder
     * @queryParam filter[subject_id] integer The subject's id. Example: 31
     * @queryParam filter[causer_id] integer Who did it. Example: 9
     * @queryParam filter[event] string created, updated or deleted. Example: updated
     * @queryParam filter[created_at] string Inclusive date range from,until. Example: 2026-09-01,2026-09-30
     * @queryParam sort string Allowed: created_at, id. Example: -created_at
     *
     * @response 200 scenario="Success" {"data":[{"id":4120,"log_name":"default","description":"WO #WO-202609-0004 was updated","event":"updated","subject_type":"App\\Models\\WorkOrder","subject_id":31,"causer_id":9,"properties":{"attributes":{"status":"in_progress"},"old":{"status":"pending"}},"created_at":"2026-09-12T08:00:00+00:00"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('activity_log.view');

        $activities = ApiQuery::for(Activity::query(), $request)
            ->allowFilters([
                'log_name' => ApiQuery::exact('log_name'),
                'subject_type' => ApiQuery::exact('subject_type'),
                'subject_id' => ApiQuery::exact('subject_id'),
                'causer_id' => ApiQuery::exact('causer_id'),
                'event' => ApiQuery::exact('event'),
                'created_at' => ApiQuery::dateBetween('created_at'),
            ])
            ->allowSearch(['description'])
            ->allowSorts(['created_at', 'id'])
            ->defaultSort('-created_at')
            ->paginate();

        $activities->getCollection()->transform(fn (Activity $activity): array => [
            'id' => $activity->id,
            'type' => 'activity',
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'event' => $activity->event,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'causer_type' => $activity->causer_type,
            'causer_id' => $activity->causer_id,

            // Passed through as recorded — see the group note.
            'properties' => $activity->properties,

            'created_at' => $activity->created_at?->toIso8601String(),
        ]);

        return $this->respondPaginated($activities);
    }
}
