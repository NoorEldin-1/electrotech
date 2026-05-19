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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 2;

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
        // Fetch and group permissions
        $permissions = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        $sections = [];

        foreach ($permissions as $group => $groupPermissions) {
            $sections[] = Forms\Components\Section::make(__('resources.roles.groups.' . $group))
                ->schema([
                    Forms\Components\CheckboxList::make('permissions_' . $group)
                        ->label('')
                        ->options($groupPermissions->mapWithKeys(fn ($permission) => [
                            $permission->name => __('resources.roles.permissions.' . $permission->name),
                        ]))
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->bulkToggleable()
                        ->afterStateHydrated(function ($component, ?Role $record) use ($groupPermissions) {
                            if ($record) {
                                $component->state($record->permissions->whereIn('name', $groupPermissions->pluck('name'))->pluck('name')->toArray());
                            }
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
}
