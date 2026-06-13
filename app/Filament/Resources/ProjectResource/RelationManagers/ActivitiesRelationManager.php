<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Models\DeliveryMinute;
use App\Models\DeliveryVoucher;
use App\Models\Installation;
use App\Models\OperationPayment;
use App\Models\Project;
use App\Models\ProjectOffer;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HISTORY — Slides 6 & 9: a per-operation activity timeline so Sales can follow
 * what happened to an operation. Beyond the project's own changes (status
 * moves, approvals, budget edits, lost reason …) it now folds in the lifecycle
 * events that live on related records — when each financial offer was raised,
 * when the advance payment was recorded, and when installation / delivery
 * happened — by OR-ing those subjects into the activity query. Read-only.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $icon = 'heroicon-o-clock';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('resources.projects.relations.activities.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('projects.view_history') ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(function (Builder $query): Builder {
                // Fold related-record lifecycle events into the project timeline.
                // Each group is parenthesised, so the result is
                // (project's own activities) OR (its offers') OR (its payments') …
                $owner = $this->getOwnerRecord();

                $children = [
                    ProjectOffer::class => ProjectOffer::where('project_id', $owner->getKey())->pluck('id'),
                    OperationPayment::class => OperationPayment::where('project_id', $owner->getKey())->pluck('id'),
                    Installation::class => Installation::where('project_id', $owner->getKey())->pluck('id'),
                    DeliveryVoucher::class => DeliveryVoucher::where('project_id', $owner->getKey())->pluck('id'),
                    DeliveryMinute::class => DeliveryMinute::where('project_id', $owner->getKey())->pluck('id'),
                ];

                foreach ($children as $class => $ids) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', $class)
                        ->whereIn('subject_id', $ids));
                }

                return $query;
            })
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.projects.relations.activities.columns.date'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subject_type')
                    ->label(__('resources.projects.relations.activities.columns.source'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $this->subjectLabel($state)),

                Tables\Columns\TextColumn::make('event')
                    ->label(__('resources.projects.relations.activities.columns.event'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(function (?string $state): string {
                        if ($state === null) {
                            return '—';
                        }
                        $key = 'resources.projects.relations.activities.events.'.$state;

                        return __($key) === $key ? $state : __($key);
                    }),

                Tables\Columns\TextColumn::make('changes')
                    ->label(__('resources.projects.relations.activities.columns.changes'))
                    ->getStateUsing(function (Model $record): string {
                        $attributes = $record->properties['attributes'] ?? [];

                        return collect($attributes)
                            ->keys()
                            ->map(fn (string $key): string => $this->fieldLabel($key))
                            ->implode('، ');
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label(__('resources.projects.relations.activities.columns.causer'))
                    ->placeholder('—'),
            ]);
    }

    /**
     * Friendly label for an activity's subject class (العملية / عرض / دفعة …).
     */
    private function subjectLabel(?string $class): string
    {
        $key = match ($class) {
            Project::class => 'project',
            ProjectOffer::class => 'offer',
            OperationPayment::class => 'payment',
            Installation::class => 'installation',
            DeliveryVoucher::class => 'delivery_voucher',
            DeliveryMinute::class => 'delivery_minute',
            default => null,
        };

        if ($key === null) {
            return $class !== null ? class_basename($class) : '—';
        }

        return __('resources.projects.relations.activities.sources.'.$key);
    }

    /**
     * Translate a changed-attribute key, trying the per-resource buckets first
     * then the shared activity-log bucket, falling back to the humanised key.
     */
    private function fieldLabel(string $key): string
    {
        foreach ([
            'resources.projects.fields.',
            'resources.project_offers.fields.',
            'resources.operation_payments.fields.',
            'resources.activities.field_labels.',
        ] as $prefix) {
            $translated = __($prefix.$key);
            if ($translated !== $prefix.$key) {
                return $translated;
            }
        }

        return (string) str($key)->headline();
    }
}
