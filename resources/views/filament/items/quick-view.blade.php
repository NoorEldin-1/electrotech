{{--
    Reusable item "quick view" card. Rendered inside a Filament modal so the user
    can review an item (catalog data + live stock) without leaving the page they
    are on — the items list, a purchase order, an addition voucher, etc.

    Expects:
      - $item: ?App\Models\Item  (with `inventories` eager-loaded)
--}}
@php
    /** @var \App\Models\Item|null $item */
    $canViewPricing = auth()->user()?->can('inventory.view_pricing') ?? false;

    // Quantities are decimals (e.g. metres); trim trailing zeros for readability.
    $qty = static fn ($value): string => rtrim(rtrim(number_format((float) ($value ?? 0), 4, '.', ','), '0'), '.') ?: '0';
@endphp

@if (! $item)
    <div class="p-4 text-sm text-gray-500 dark:text-gray-400">
        {{ __('resources.items.modal.not_found') }}
    </div>
@else
    @php
        $onHand = $item->inventories->sum(fn ($row) => (float) $row->on_hand_quantity);
        $onHold = $item->inventories->sum(fn ($row) => (float) $row->on_hold_quantity);
        $available = (float) $item->available_quantity;
        $belowMinimum = $item->isBelowMinimumStock();
        $value = $onHand * (float) $item->unit_cost;
    @endphp

    <div class="space-y-6 text-sm">
        {{-- Identity / catalog data --}}
        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
            <div>
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('resources.items.fields.sku') }}</div>
                <div class="mt-1 font-mono text-gray-950 dark:text-white">{{ $item->sku }}</div>
            </div>

            <div>
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('resources.items.fields.name') }}</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $item->name }}</div>
            </div>

            <div>
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('resources.items.fields.type') }}</div>
                <div class="mt-1 text-gray-950 dark:text-white">{{ $item->type?->getLabel() ?? '—' }}</div>
            </div>

            <div>
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('resources.items.fields.unit') }}</div>
                <div class="mt-1 text-gray-950 dark:text-white">{{ $item->unit?->getLabel() ?? '—' }}</div>
            </div>

            @if ($canViewPricing)
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('resources.items.fields.unit_cost') }}</div>
                    <div class="mt-1 font-semibold text-gray-950 dark:text-white">{{ number_format((float) $item->unit_cost, 2) }} EGP</div>
                </div>
            @endif

            @if (filled($item->description))
                <div class="sm:col-span-2">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('resources.items.fields.description') }}</div>
                    <div class="mt-1 whitespace-pre-line text-gray-950 dark:text-white">{{ $item->description }}</div>
                </div>
            @endif
        </div>

        {{-- Live stock --}}
        <div>
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('resources.items.modal.stock') }}
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('resources.items.modal.on_hand') }}</div>
                    <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $qty($onHand) }}</div>
                </div>

                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('resources.items.modal.on_hold') }}</div>
                    <div class="mt-1 text-base font-semibold text-warning-600 dark:text-warning-400">{{ $qty($onHold) }}</div>
                </div>

                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('resources.items.modal.available') }}</div>
                    <div @class([
                        'mt-1 text-base font-semibold',
                        'text-danger-600 dark:text-danger-400' => $belowMinimum,
                        'text-success-600 dark:text-success-400' => ! $belowMinimum,
                    ])>{{ $qty($available) }}</div>
                </div>

                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('resources.items.fields.minimum_stock') }}</div>
                    <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $qty($item->minimum_stock) }}</div>
                </div>
            </div>

            @if ($belowMinimum)
                <div class="mt-2 text-xs font-medium text-danger-600 dark:text-danger-400">
                    {{ __('resources.items.modal.below_minimum') }}
                </div>
            @endif

            @if ($canViewPricing)
                <div class="mt-3 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('resources.items.modal.value') }}:</span>
                    <span class="font-semibold text-gray-950 dark:text-white">{{ number_format($value, 2) }} EGP</span>
                </div>
            @endif
        </div>
    </div>
@endif
