<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Api\AttachmentOwner;
use App\Models\Attachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permission-checked download of an attachment for the web panel.
 *
 * The restricted drawings (preliminary / executive drawing, site measurements)
 * live on the private `restricted` disk, which has no public URL, so this route
 * is the only way a browser can read them. Ordinary attachments are served
 * through it too when a screen links here.
 *
 * Two gates, both mandatory:
 *  1. the owner's own `view` policy (a project file is readable by whoever can
 *     view the project, a supplier's by whoever can view the supplier — the
 *     same rule the REST API download applies);
 *  2. the restricted-category rule: a restricted drawing needs
 *     `attachments.view_restricted_drawings`, on any owner type.
 */
class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        $key = AttachmentOwner::keyFor($attachment);

        // An orphan row (owner hard-deleted, or an owner type outside the
        // whitelist) has nothing left to authorize against: refuse it.
        abort_if($key === null, 404);

        $owner = $key === 'project' ? $attachment->project : $attachment->attachable;

        abort_if($owner === null, 404);

        Gate::authorize('view', $owner);
        abort_unless($attachment->isVisibleTo(Auth::user()), 403);

        $disk = Storage::disk($attachment->storageDisk());

        abort_unless($disk->exists($attachment->file_path), 404);

        // Always an attachment (never rendered inline in the app's origin) and
        // never sniffed into something executable.
        return $disk->download($attachment->file_path, $attachment->file_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
