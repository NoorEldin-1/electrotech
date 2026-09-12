<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\ValidationException;

/**
 * @group 40. Notifications
 *
 * What the platform has told **you**: a manufacturing order finished, a
 * quality sheet approved, a delivery minute circulated.
 *
 * Every endpoint here is **self-scoped** and carries no permission. That is
 * deliberate and it is the safest possible rule: a notification is addressed
 * to one user, so the only question is whether you are that user. There is no
 * "read someone else's notifications" endpoint to get the authorization wrong
 * on.
 *
 * These are the same rows the admin panel's bell shows, so marking one read on
 * a phone clears it on the desktop.
 *
 * The body of a notification is the panel's own payload — `title`, `body`,
 * `icon`, `color`, `actions`. It is passed through as `data` rather than
 * reshaped, because the panel's notification format is what every producer in
 * the platform already writes, and translating it here would mean a second
 * format to keep in step.
 */
class NotificationController extends ApiController
{
    /**
     * List your notifications
     *
     * Newest first. `filter[unread]=true` narrows to what you have not seen,
     * which is what a badge and a pull-to-refresh both want.
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam filter[unread] boolean Only unread notifications. Example: true
     *
     * @response 200 scenario="Success" {"data":[{"id":"9f1c2d3e","type":"App\\Notifications\\ManufacturingFinished","data":{"title":"Manufacturing finished","body":"WO-202609-0004 is ready for delivery"},"read":false,"read_at":null,"created_at":"2026-09-12T16:30:00+00:00"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->getQuery();

        if ($request->query('filter.unread') !== null || $request->input('filter.unread') !== null) {
            $unread = filter_var($request->input('filter.unread'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($unread === null) {
                throw ValidationException::withMessages([
                    'filter.unread' => ['Expected a boolean value (true/false).'],
                ]);
            }

            $unread
                ? $query->whereNull('read_at')
                : $query->whereNotNull('read_at');
        }

        $perPage = min(
            max((int) $request->query('per_page', (string) config('api.pagination.default_per_page')), 1),
            (int) config('api.pagination.max_per_page'),
        );

        $notifications = $query->latest()->paginate($perPage)->appends($request->query());

        $notifications->getCollection()->transform(fn (DatabaseNotification $notification) => $this->present($notification));

        return $this->respondPaginated($notifications);
    }

    /**
     * How many you have not read
     *
     * The cheapest call in the API — one indexed count. Intended for a badge,
     * so it is safe to call on every foreground.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"data":{"unread":3},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->respond([
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one as read
     *
     * Scoped to your own notifications: another user's id is a **404**, not a
     * 403. Answering "forbidden" would confirm that the notification exists,
     * which is more than a caller who cannot read it should learn.
     *
     * Marking an already-read notification is a silent success.
     *
     * @authenticated
     *
     * @urlParam notification string required The notification id. Example: 9f1c2d3e-4b5a-6789-abcd-ef0123456789
     *
     * @response 200 scenario="Marked" {"data":{"id":"9f1c2d3e","read":true,"read_at":"2026-09-12T18:00:00+00:00"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        $row->markAsRead();

        return $this->respond($this->present($row->fresh()));
    }

    /**
     * Mark everything as read
     *
     * Returns how many were actually changed, so a client can say "3 cleared"
     * rather than guessing.
     *
     * @authenticated
     *
     * @response 200 scenario="Cleared" {"data":{"marked":3,"unread":0},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $marked = $request->user()->unreadNotifications()->count();

        $request->user()->unreadNotifications->markAsRead();

        return $this->respond([
            'marked' => $marked,
            'unread' => 0,
        ]);
    }

    /**
     * Delete one of your notifications
     *
     * Self-scoped like the rest: another user's id is a 404.
     *
     * @authenticated
     *
     * @urlParam notification string required The notification id. Example: 9f1c2d3e-4b5a-6789-abcd-ef0123456789
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->delete();

        return $this->respondNoContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,

            // The panel's own payload, passed through rather than reshaped —
            // see the group note.
            'data' => $notification->data,

            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
