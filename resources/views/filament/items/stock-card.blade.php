@php
    /** @var array{warehouse: \App\Enums\WarehouseType, rows: array, totals: array} $card */
    $card = $getState();
    $rows = $card['rows'] ?? [];
    $totals = $card['totals'] ?? ['quantity' => 0, 'unit_cost' => 0, 'value' => 0];

    $canViewPricing = auth()->user()?->can('inventory.view_pricing') ?? false;
    // Each group (وارد / صادر / الرصيد) is 3 columns with pricing, 1 (qty only) without.
    $groupCols = $canViewPricing ? 3 : 1;
    // الإجمالي label spans: #, date, statement (3) + وارد + صادر groups.
    $totalLabelSpan = 3 + (2 * $groupCols);

    $warehouseOptions = $card['warehouse_options'] ?? [];

    $qty = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', ',');
@endphp

<div class="fi-stock-card">
    <div class="mb-3 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <span>{{ __('resources.stock_card.warehouse') }}:</span>
        @if (count($warehouseOptions) > 1)
            <select
                wire:model.live="stockCardWarehouse"
                class="rounded-lg border-gray-300 bg-white py-1 text-sm font-medium text-gray-700 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:[color-scheme:dark]"
            >
                @foreach ($warehouseOptions as $value => $label)
                    <option value="{{ $value }}" class="bg-white text-gray-950 dark:bg-gray-900 dark:text-white">{{ $label }}</option>
                @endforeach
            </select>
        @else
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $card['warehouse']->getLabel() }}</span>
        @endif
    </div>

    @if (empty($rows))
        <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
            {{ __('resources.stock_card.empty') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 ring-1 ring-gray-950/5 dark:border-white/10 dark:ring-white/10">
            <table class="w-full text-center text-sm text-gray-700 dark:text-gray-200">
                <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    <tr>
                        <th rowspan="2" class="border-b border-gray-200 px-3 py-2 dark:border-white/10">#</th>
                        <th rowspan="2" class="border-b border-gray-200 px-3 py-2 dark:border-white/10">{{ __('resources.stock_card.columns.date') }}</th>
                        <th rowspan="2" class="border-b border-gray-200 px-3 py-2 text-start dark:border-white/10">{{ __('resources.stock_card.columns.statement') }}</th>
                        <th colspan="{{ $groupCols }}" class="border-b border-s border-gray-200 bg-success-50 px-3 py-2 text-success-700 dark:border-white/10 dark:bg-success-500/10 dark:text-success-400">{{ __('resources.stock_card.groups.incoming') }}</th>
                        <th colspan="{{ $groupCols }}" class="border-b border-s border-gray-200 bg-danger-50 px-3 py-2 text-danger-700 dark:border-white/10 dark:bg-danger-500/10 dark:text-danger-400">{{ __('resources.stock_card.groups.outgoing') }}</th>
                        <th colspan="{{ $groupCols }}" class="border-b border-s border-gray-200 bg-primary-50 px-3 py-2 text-primary-700 dark:border-white/10 dark:bg-primary-500/10 dark:text-primary-400">{{ __('resources.stock_card.groups.balance') }}</th>
                    </tr>
                    <tr>
                        @foreach (['incoming', 'outgoing', 'balance'] as $group)
                            <th class="border-b border-s border-gray-200 px-2 py-1.5 font-medium dark:border-white/10">{{ __('resources.stock_card.columns.quantity') }}</th>
                            @if ($canViewPricing)
                                <th class="border-b border-gray-200 px-2 py-1.5 font-medium dark:border-white/10">{{ __('resources.stock_card.columns.price') }}</th>
                                <th class="border-b border-gray-200 px-2 py-1.5 font-medium dark:border-white/10">{{ __('resources.stock_card.columns.value') }}</th>
                            @endif
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $i => $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="px-3 py-2 text-gray-400">{{ $i + 1 }}</td>
                            <td class="whitespace-nowrap px-3 py-2">{{ $row['date']?->translatedFormat('Y/m/d') }}</td>
                            <td class="px-3 py-2 text-start">{{ $row['reference'] }}</td>

                            {{-- وارد --}}
                            <td class="border-s border-gray-100 px-2 py-2 dark:border-white/5">{{ $qty($row['in_qty']) }}</td>
                            @if ($canViewPricing)
                                <td class="px-2 py-2">{{ $money($row['in_price']) }}</td>
                                <td class="px-2 py-2">{{ $money($row['in_value']) }}</td>
                            @endif

                            {{-- صادر --}}
                            <td class="border-s border-gray-100 px-2 py-2 dark:border-white/5">{{ $qty($row['out_qty']) }}</td>
                            @if ($canViewPricing)
                                <td class="px-2 py-2">{{ $money($row['out_price']) }}</td>
                                <td class="px-2 py-2">{{ $money($row['out_value']) }}</td>
                            @endif

                            {{-- الرصيد --}}
                            <td class="border-s border-gray-100 px-2 py-2 font-medium dark:border-white/5">{{ $qty($row['balance_qty']) }}</td>
                            @if ($canViewPricing)
                                <td class="px-2 py-2 font-medium">{{ $money($row['balance_price']) }}</td>
                                <td class="px-2 py-2 font-semibold">{{ $money($row['balance_value']) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>

                <tfoot class="bg-primary-50 font-bold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                    <tr>
                        <td colspan="{{ $totalLabelSpan }}" class="border-t border-gray-200 px-3 py-2.5 text-start dark:border-white/10">
                            {{ __('resources.stock_card.total') }}
                        </td>
                        <td class="border-s border-t border-gray-200 px-2 py-2.5 dark:border-white/10">{{ $qty($totals['quantity']) }}</td>
                        @if ($canViewPricing)
                            <td class="border-t border-gray-200 px-2 py-2.5 dark:border-white/10">{{ $money($totals['unit_cost']) }}</td>
                            <td class="border-t border-gray-200 px-2 py-2.5 dark:border-white/10">{{ $money($totals['value']) }}</td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
