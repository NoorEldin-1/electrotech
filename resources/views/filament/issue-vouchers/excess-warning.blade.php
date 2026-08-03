{{--
    تحذير صرف كمية زائدة — the rows on this voucher that go past what the work
    order's material plan still needs. Shown before anything moves, so the store
    keeper can either go back and correct the quantity, or (with the excess
    approval permission) carry on with a written reason.
--}}
<div class="et-report space-y-3">
    <p class="text-sm text-gray-700 dark:text-[var(--dark-text-muted)]">
        {{ __('resources.issue_vouchers.excess.intro') }}
    </p>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-gray-500 dark:border-[var(--border-hairline)] dark:text-[var(--dark-text-muted)]">
                    <th class="px-3 py-2 text-start font-medium">{{ __('resources.issue_vouchers.excess.columns.item') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.issue_vouchers.excess.columns.required') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.issue_vouchers.excess.columns.previously_issued') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.issue_vouchers.excess.columns.remaining') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.issue_vouchers.excess.columns.this_voucher') }}</th>
                    <th class="px-3 py-2 text-end font-medium">{{ __('resources.issue_vouchers.excess.columns.excess') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-[var(--border-hairline)]">
                @foreach ($rows as $row)
                    <tr class="text-gray-950 dark:text-[var(--dark-text)]">
                        <td class="px-3 py-2 text-start">{{ $row['item_name'] }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['required'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['previously_issued'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['remaining'], 2) }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format($row['this_voucher'], 2) }}</td>
                        <td class="px-3 py-2 text-end font-bold tabular-nums text-red-600 dark:text-red-400">
                            +{{ number_format($row['excess'], 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-sm font-medium {{ $canApprove ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400' }}">
        {{ $canApprove
            ? __('resources.issue_vouchers.excess.may_approve')
            : __('resources.issue_vouchers.excess.may_not_approve') }}
    </p>
</div>
