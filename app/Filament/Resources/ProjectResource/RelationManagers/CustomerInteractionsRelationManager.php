<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Filament\Resources\CustomerInteractionResource;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * متابعة العملاء on the operation file (Presentation.pptx سلايد 6–7) — the same
 * log as CustomerInteractionResource, scoped to this operation.
 */
class CustomerInteractionsRelationManager extends RelationManager
{
    protected static string $relationship = 'customerInteractions';

    protected static ?string $icon = 'heroicon-o-chat-bubble-left-right';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('resources.customer_interactions.plural_label');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('customer_interactions.view') ?? false;
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema(CustomerInteractionResource::formFields(withProject: false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns(CustomerInteractionResource::tableColumns(withProject: false))
            ->defaultSort('interacted_at', 'desc')
            ->filters(CustomerInteractionResource::tableFilters(withProject: false))
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
                    ->tooltip(__('resources.common.actions')),
            ]);
    }
}
