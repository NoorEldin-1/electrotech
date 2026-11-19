<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricted drawings (Final_Modifications Task 5 / R3, security fix S2).
 *
 * The activity log records an attachment's file name and category, so an entry
 * about a restricted drawing (preliminary / executive drawing, site
 * measurements) would give away the very file the user may not see. Every
 * screen or endpoint that lists activity applies this filter: the panel's
 * ActivityResource and the REST API's activity-log index share it, so the two
 * cannot drift apart.
 *
 * Users holding `attachments.view_restricted_drawings` (Admin implicitly) see
 * everything; for everyone else the entries whose subject is a restricted
 * attachment — soft-deleted ones included, their log entries survive them —
 * are left out. Entries about any other subject are untouched.
 */
final class RestrictedActivityFilter
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  an activity query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, ?User $user = null): Builder
    {
        if (AttachmentCategory::userCanViewRestricted($user)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('subject_type')
            ->orWhere('subject_type', '!=', (new Attachment)->getMorphClass())
            ->orWhereNotIn('subject_id', Attachment::withTrashed()
                ->whereIn('category', AttachmentCategory::restrictedValues())
                ->select('id')));
    }
}
