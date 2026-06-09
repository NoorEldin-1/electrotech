<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HISTORY — Slide 9: a per-operation activity timeline so Sales can follow
 * what happened to an operation (status moves, approvals, budget edits, lost
 * reason …). Backed by spatie/activitylog's `activities` relation, which the
 * Project model already records via LogsActivity. Read-only.
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
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.projects.relations.activities.columns.date'))
                    ->dateTime()
                    ->sortable(),

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
                            ->map(function (string $key): string {
                                $fieldKey = 'resources.projects.fields.'.$key;

                                return __($fieldKey) === $fieldKey ? $key : __($fieldKey);
                            })
                            ->implode('، ');
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label(__('resources.projects.relations.activities.columns.causer'))
                    ->placeholder('—'),
            ]);
    }
}
