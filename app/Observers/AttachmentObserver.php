<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Services\InstallationService;

/**
 * Presentation.pptx سلايد 8 — uploading محاضر تسليم الأعمال finishes the
 * operation's running installation. An observer rather than a hook in the
 * project form's AttachmentPersistence, so the API upload path triggers it too.
 */
class AttachmentObserver
{
    public function created(Attachment $attachment): void
    {
        if ($attachment->category === AttachmentCategory::HandoverMinutes) {
            app(InstallationService::class)->completeFromHandoverMinutes($attachment);
        }
    }
}
