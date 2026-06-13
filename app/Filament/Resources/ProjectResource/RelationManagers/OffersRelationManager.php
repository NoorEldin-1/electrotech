<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ConductorType;
use App\Filament\Support\MoneyInput;
use App\Models\ProjectOffer;
use App\Services\OfferTotalsService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * البوكيه / العرض المُفصّل — Slides 2, 7 & 8.
 *
 * A project offer is no longer a single number: each offer carries one or
 * more priced tables (groups) of BOQ line items, an optional VAT, and a
 * free-text terms block. Totals are recomputed by OfferTotalsService after
 * every create/edit so the stored figures (and the Tender "last offer"
 * columns) stay consistent with the items.
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('resources.project_offers.title');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('resources.project_offers.sections.header'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('quotation_number')
                        ->label(__('resources.project_offers.fields.quotation_number'))
                        ->placeholder('Q-165A/2026')
                        ->maxLength(255),

                    Forms\Components\DateTimePicker::make('submitted_at')
                        ->label(__('resources.project_offers.fields.submitted_at'))
                        ->default(now())
                        ->required(),

                    MoneyInput::make('technical_amount')
                        ->label(__('resources.project_offers.fields.technical_amount'))
                        ->helperText(__('resources.project_offers.fields.technical_amount_helper')),

                    Forms\Components\TextInput::make('vat_percentage')
                        ->label(__('resources.project_offers.fields.vat_percentage'))
                        ->numeric()
                        ->default(14)
                        ->suffix('%')
                        ->live(onBlur: true),

                    Forms\Components\Toggle::make('show_vat')
                        ->label(__('resources.project_offers.fields.show_vat'))
                        ->helperText(__('resources.project_offers.fields.show_vat_helper'))
                        ->default(true)
                        ->live(),

                    // Slides 1 & 8: installation behaves like VAT — a % of the
                    // subtotal, added only when the client books installation.
                    Forms\Components\TextInput::make('installation_percentage')
                        ->label(__('resources.project_offers.fields.installation_percentage'))
                        ->numeric()
                        ->default(10)
                        ->suffix('%')
                        ->live(onBlur: true),

                    Forms\Components\Toggle::make('show_installation')
                        ->label(__('resources.project_offers.fields.show_installation'))
                        ->helperText(__('resources.project_offers.fields.show_installation_helper'))
                        ->default(false)
                        ->live(),

                    // Slides 5 & 11: an intro line + the mandatory DKC licence
                    // statement, printed before the tables. Pre-filled from a
                    // default so it is never forgotten, but editable per offer.
                    Forms\Components\Textarea::make('header_note')
                        ->label(__('resources.project_offers.fields.header_note'))
                        ->helperText(__('resources.project_offers.fields.header_note_helper'))
                        ->default(__('resources.project_offers.defaults.header_note'))
                        ->autosize()
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Repeater::make('groups')
                ->relationship()
                ->label(__('resources.project_offers.sections.groups'))
                ->addActionLabel(__('resources.project_offers.actions.add_group'))
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->collapsible()
                ->cloneable()
                ->defaultItems(1)
                // Slide 5: a follow-up offer may legitimately carry a different
                // number of tables than a previous one — even just a single
                // table. The only structural rule is "at least one table".
                ->minItems(1)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('label')
                            ->label(__('resources.project_offers.fields.group_label'))
                            ->placeholder('Bi-Metal Offer')
                            ->required(),

                        Forms\Components\Select::make('conductor_type')
                            ->label(__('resources.project_offers.fields.conductor_type'))
                            ->options(ConductorType::class)
                            ->native(false),
                    ]),

                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->label(__('resources.project_offers.sections.items'))
                        ->addActionLabel(__('resources.project_offers.actions.add_item'))
                        ->itemLabel(fn (array $state): ?string => $state['description'] ?? null)
                        ->defaultItems(1)
                        ->columns(12)
                        ->columnSpanFull()
                        ->schema([
                            // Slide 4: the description is long (e.g. "Busway 4000A
                            // type DKC … 4P") and a single-line input hid the tail,
                            // so Sales couldn't proof-read it (a 3P/4P typo swings
                            // the price). An autosizing Textarea shows the whole
                            // line for review before printing.
                            Forms\Components\Textarea::make('description')
                                ->label(__('resources.project_offers.fields.description'))
                                ->helperText(__('resources.project_offers.fields.description_helper'))
                                ->required()
                                ->autosize()
                                ->rows(2)
                                ->columnSpan(5),

                            Forms\Components\TextInput::make('unit')
                                ->label(__('resources.project_offers.fields.unit'))
                                ->placeholder('MT / NO')
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('quantity')
                                ->label(__('resources.project_offers.fields.quantity'))
                                ->numeric()
                                ->default(1)
                                ->live(onBlur: true)
                                ->columnSpan(2),

                            MoneyInput::make('unit_price')
                                ->label(__('resources.project_offers.fields.unit_price'))
                                ->live(onBlur: true)
                                ->columnSpan(2),

                            Forms\Components\Placeholder::make('line_total')
                                ->label(__('resources.project_offers.fields.line_total'))
                                // Parse through MoneyInput::toFloat so the live preview
                                // doesn't read the masked "5,000" as 5.0 (Slide 4).
                                ->content(fn (Forms\Get $get): string => number_format(
                                    MoneyInput::toFloat($get('quantity')) * MoneyInput::toFloat($get('unit_price')),
                                    2
                                ))
                                ->columnSpan(1),
                        ]),
                ]),

            Forms\Components\Placeholder::make('totals_preview')
                ->label(__('resources.project_offers.fields.totals'))
                ->columnSpanFull()
                ->content(function (Forms\Get $get): HtmlString {
                    $subtotal = 0.0;
                    foreach ((array) $get('groups') as $group) {
                        foreach ((array) ($group['items'] ?? []) as $item) {
                            // Strip the live mask's grouping commas before casting,
                            // otherwise "5,000" collapses to 5.0 in the preview (Slide 4).
                            $subtotal += MoneyInput::toFloat($item['quantity'] ?? 0) * MoneyInput::toFloat($item['unit_price'] ?? 0);
                        }
                    }
                    $tax = $get('show_vat') ? $subtotal * (MoneyInput::toFloat($get('vat_percentage')) / 100) : 0.0;
                    $installation = $get('show_installation') ? $subtotal * (MoneyInput::toFloat($get('installation_percentage')) / 100) : 0.0;
                    $grand = $subtotal + $tax + $installation;

                    $rows = __('resources.project_offers.fields.subtotal').': '.number_format($subtotal, 2).' EGP<br>'.
                        __('resources.project_offers.fields.tax_amount').': '.number_format($tax, 2).' EGP<br>';

                    if ($installation > 0) {
                        $rows .= __('resources.project_offers.fields.installation_amount').': '.number_format($installation, 2).' EGP<br>';
                    }

                    return new HtmlString(
                        $rows.
                        '<span style="font-weight:700">'.__('resources.project_offers.fields.grand_total').': '.number_format($grand, 2).' EGP</span>'
                    );
                }),

            // Slides 3 & 8: per-offer SPECIAL terms, printed directly behind the
            // tables (e.g. "if installation is requested, prices rise by 15%").
            Forms\Components\Textarea::make('terms')
                ->label(__('resources.project_offers.fields.special_terms'))
                ->helperText(__('resources.project_offers.fields.special_terms_helper'))
                ->rows(4)
                ->columnSpanFull(),

            // Slide 9: the standard terms — mostly fixed across all offers. Pre-
            // filled from a template (one point per line) so they are never
            // forgotten, and editable (add / remove a point) per offer.
            Forms\Components\Textarea::make('general_terms')
                ->label(__('resources.project_offers.fields.general_terms'))
                ->helperText(__('resources.project_offers.fields.general_terms_helper'))
                ->default(__('resources.project_offers.defaults.general_terms'))
                ->rows(8)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('quotation_number')
            ->modelLabel(__('resources.project_offers.label'))
            ->pluralModelLabel(__('resources.project_offers.plural_label'))
            ->columns([
                Tables\Columns\TextColumn::make('version')
                    ->label(__('resources.project_offers.columns.version'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('quotation_number')
                    ->label(__('resources.project_offers.columns.quotation_number'))
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('grand_total')
                    ->label(__('resources.project_offers.columns.grand_total'))
                    ->money('EGP')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('technical_amount')
                    ->label(__('resources.project_offers.columns.technical_amount'))
                    ->money('EGP')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('is_winning')
                    ->label(__('resources.project_offers.columns.is_winning'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label(__('resources.project_offers.columns.submitted_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('version', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(fn (ProjectOffer $record) => app(OfferTotalsService::class)->recalculate($record)),
            ])
            ->actions([
                // Slide 9: print in English (2026 default) or Arabic.
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('print_en')
                        ->label(__('resources.project_offers.actions.print_en'))
                        ->icon('heroicon-o-printer')
                        ->url(fn (ProjectOffer $record): string => route('offers.pdf', ['offer' => $record, 'lang' => 'en']))
                        ->openUrlInNewTab(),

                    Tables\Actions\Action::make('print_ar')
                        ->label(__('resources.project_offers.actions.print_ar'))
                        ->icon('heroicon-o-printer')
                        ->url(fn (ProjectOffer $record): string => route('offers.pdf', ['offer' => $record, 'lang' => 'ar']))
                        ->openUrlInNewTab(),
                ])
                    ->label(__('resources.project_offers.actions.print'))
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->button()
                    ->visible(fn (ProjectOffer $record): bool => auth()->user()?->can('print', $record) ?? false),

                Tables\Actions\EditAction::make()
                    ->after(fn (ProjectOffer $record) => app(OfferTotalsService::class)->recalculate($record)),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
