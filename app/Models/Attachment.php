<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Attachment extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'attachable_type',
        'attachable_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'category',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'category' => AttachmentCategory::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['file_name', 'file_type', 'file_size', 'category', 'project_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Attachment '{$this->file_name}' was {$eventName}");
    }

    /**
     * Restricted drawings (Final_Modifications Task 5 / R3): leave out the
     * preliminary / executive drawings and site measurements unless the user
     * holds `attachments.view_restricted_drawings`. Every query that lists,
     * counts or serves attachments to a person goes through this scope; system
     * work with no person behind it (timeline stage dates, observers) queries
     * the model directly and is deliberately unscoped.
     *
     * @param  Builder<Attachment>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user = null): void
    {
        if (! AttachmentCategory::userCanViewRestricted($user)) {
            $query->whereNotIn($query->qualifyColumn('category'), AttachmentCategory::restrictedValues());
        }
    }

    /**
     * Whether the given user (default: the signed-in one) may see or download
     * this single attachment.
     */
    public function isVisibleTo(?User $user = null): bool
    {
        $category = $this->category;

        if (is_string($category)) {
            $category = AttachmentCategory::tryFrom($category);
        }

        return $category === null || $category->isViewableBy($user);
    }

    /**
     * The filesystem disk holding this attachment's file: the private
     * `restricted` disk for the restricted drawings, `public` for the rest.
     */
    public function storageDisk(): string
    {
        return AttachmentCategory::diskFor($this->category);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Polymorphic owner (Supplier / PurchaseOrder). Projects continue to use
     * the dedicated project_id column above, so this is null for them.
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
