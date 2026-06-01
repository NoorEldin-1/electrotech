<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 71;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.system');
    }

    public static function getLabel(): string
    {
        return __('resources.roles.label');
    }

    public static function getPluralLabel(): string
    {
        return __('resources.roles.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.roles.navigation_label');
    }

    public static function form(Form $form): Form
    {
        // Fetch grouped permission names from Redis. The shape we cache is
        // [group => [permission_name, ...]] — purely string scalars, so
        // there's no model hydration cost on subsequent renders. The cache
        // is invalidated by PermissionObserver when permissions change.
        $groupedPermissionNames = Cache::remember(
            'role_resource_permission_groups',
            now()->addDay(),
            fn (): array => Permission::query()
                ->orderBy('name')
                ->pluck('name')
                ->groupBy(fn (string $name) => explode('.', $name)[0])
                ->map(fn ($group) => $group->all())
                ->all(),
        );

        $sections = [];

        foreach ($groupedPermissionNames as $group => $permissionNames) {
            $sections[] = Forms\Components\Section::make(__('resources.roles.groups.' . $group))
                ->schema([
                    Forms\Components\CheckboxList::make('permissions_' . $group)
                        ->label('')
                        ->options(collect($permissionNames)->mapWithKeys(fn (string $name) => [
                            $name => __('resources.roles.permissions.' . $name),
                        ])->all())
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->bulkToggleable()
                        ->afterStateHydrated(function ($component, ?Role $record) use ($permissionNames) {
                            if (! $record) {
                                return;
                            }

                            // intersect() compares scalar names against the
                            // already-loaded `permissions` collection — no
                            // extra query is issued here.
                            $component->state(
                                $record->permissions
                                    ->pluck('name')
                                    ->intersect($permissionNames)
                                    ->values()
                                    ->all()
                            );
                        })
                        ->disabled(fn (?Role $record): bool => $record && $record->name === 'Admin'),
                ])
                ->collapsible();
        }

        return $form
            ->schema([
                Forms\Components\Section::make(__('resources.roles.sections.role_details'))
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('resources.roles.fields.name'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->disabled(fn (?Role $record): bool => $record && $record->name === 'Admin'),
                    ]),

                Forms\Components\Section::make(__('resources.roles.sections.permissions'))
                    ->icon('heroicon-o-key')
                    ->description(__('resources.roles.fields.permissions_helper'))
                    ->schema([
                        Forms\Components\Grid::make()
                            ->schema($sections),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('resources.roles.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->badge(fn (Role $record): bool => $record->name === 'Admin')
                    ->color(fn (Role $record): string => $record->name === 'Admin' ? 'danger' : 'primary'),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label(__('resources.roles.columns.permissions_count'))
                    ->counts('permissions')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('resources.roles.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->action(function (Role $record) {
                        if ($record->name === 'Admin') {
                            Notification::make()
                                ->danger()
                                ->title(__('resources.roles.notifications.admin_protected'))
                                ->send();

                            return;
                        }
                        $record->delete();
                    })
                    ->hidden(fn (Role $record): bool => $record->name === 'Admin'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function ($records) {
                            $records->each(function (Role $record) {
                                if ($record->name !== 'Admin') {
                                    $record->delete();
                                }
                            });
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('permissions:id,name');
    }
}
