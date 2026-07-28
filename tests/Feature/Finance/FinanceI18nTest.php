<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_labels_resolve_in_both_locales(): void
    {
        $keys = [
            'resources.accounts.plural_label',
            'resources.journal_entries.plural_label',
            'resources.general_ledger.title',
            'resources.trial_balance.title',

            // الإدخال السريع لسطور القيد
            'resources.journal_entries.sections.debit_lines',
            'resources.journal_entries.sections.credit_lines',
            'resources.journal_entries.fields.show_line_details',
            'resources.journal_entries.helpers.show_line_details',
            'resources.journal_entries.actions.create',
            'resources.journal_entries.actions.add_debit_line',
            'resources.journal_entries.actions.add_credit_line',
            'resources.journal_entries.actions.fill_remaining',
            'resources.journal_entries.placeholders.balanced',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_new_enum_labels_resolve_in_both_locales(): void
    {
        $keys = [
            'resources.enums.account_type.asset',
            'resources.enums.account_type.liability',
            'resources.enums.account_type.equity',
            'resources.enums.account_type.revenue',
            'resources.enums.account_type.expense',
            'resources.enums.document_type.payment_order',
            'resources.enums.document_type.supply_receipt',
            'resources.enums.document_type.settlement',
            'resources.enums.journal_status.draft',
            'resources.enums.journal_status.posted',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_document_types_are_arabic(): void
    {
        app()->setLocale('ar');
        $this->assertSame('أمر صرف', __('resources.enums.document_type.payment_order'));
        $this->assertSame('إيصال توريد', __('resources.enums.document_type.supply_receipt'));
        $this->assertSame('قيد تسوية', __('resources.enums.document_type.settlement'));
    }

    public function test_role_management_labels_resolve_for_new_groups(): void
    {
        $keys = [
            'resources.roles.groups.accounts',
            'resources.roles.groups.journal_entries',
            'resources.roles.permissions.accounts.view',
            'resources.roles.permissions.journal_entries.post',
            'resources.roles.permissions.trial_balance.view',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }
}
