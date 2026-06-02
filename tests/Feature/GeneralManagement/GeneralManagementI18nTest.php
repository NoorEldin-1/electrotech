<?php

declare(strict_types=1);

namespace Tests\Feature\GeneralManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralManagementI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_group_and_resource_labels_resolve_in_both_locales(): void
    {
        $keys = [
            'navigation.groups.general_management',
            'resources.operations_overview.title',
            'resources.operations_overview.navigation_label',
            'resources.operations_overview.columns.operation',
            'resources.operations_overview.columns.actual_cost',
            'resources.operations_overview.empty',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_role_management_labels_resolve_for_new_groups(): void
    {
        $keys = [
            'resources.roles.groups.operations',
            'resources.roles.groups.delivery_minutes',
            'resources.roles.groups.financial_claims',
            'resources.roles.permissions.operations.overview',
            'resources.roles.permissions.operations.view_cost',
            'resources.roles.permissions.delivery_minutes.distribute',
            'resources.roles.permissions.financial_claims.collect',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_all_general_management_resource_and_enum_labels_resolve(): void
    {
        $keys = [
            // Cost center / overview
            'resources.operations_cost.title',
            'resources.operations_cost.cards.profit',
            'resources.operations.actions.complete',
            'resources.operations.notifications.activated_title',
            // Stock reservations
            'resources.stock_reservations.plural_label',
            'resources.stock_reservations.actions.reserve',
            'resources.enums.reservation_status.active',
            'resources.enums.reservation_status.released',
            // Delivery minutes
            'resources.delivery_minutes.plural_label',
            'resources.delivery_minutes.actions.distribute',
            // Financial claims
            'resources.financial_claims.plural_label',
            'resources.financial_claims.actions.submit',
            'resources.enums.claim_status.draft',
            'resources.enums.claim_status.collected',
            // Work-order cost comparison columns
            'resources.work_orders.columns.estimated_cost',
            'resources.work_orders.columns.cost_variance',
            // GL line cost-center field
            'resources.journal_entries.fields.project',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_operation_error_messages_resolve(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                'errors.operations.illegal_transition',
                'errors.operations.claim_before_completion',
                'errors.operations.facility_exceeds_available',
            ] as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_phase7_resource_enum_and_role_labels_resolve(): void
    {
        $keys = [
            // Payments + supply file
            'resources.operation_payments.plural_label',
            'resources.operation_payments.columns.direction',
            'resources.supply_orders_file.title',
            'resources.supply_orders_file.summary.outstanding',
            'resources.enums.payment_direction.incoming',
            'resources.enums.payment_method.cheque',
            // Credit facilities
            'resources.credit_facilities.plural_label',
            'resources.facility_allocations.actions.allocate',
            'resources.enums.facility_status.active',
            // Installation
            'resources.installations.plural_label',
            'resources.installations.actions.complete',
            'resources.enums.installation_status.in_progress',
            // Site surveys
            'resources.site_surveys.plural_label',
            'resources.site_surveys.fields.measurements',
            'resources.enums.attachment_category.site_measurement',
            // Role-management labels
            'resources.roles.groups.operation_payments',
            'resources.roles.groups.credit_facilities',
            'resources.roles.groups.installations',
            'resources.roles.groups.site_surveys',
            'resources.roles.permissions.operation_payments.record',
            'resources.roles.permissions.credit_facilities.manage',
            'resources.roles.permissions.installations.manage',
            'resources.roles.permissions.site_surveys.manage',
            'resources.roles.permissions.supply_orders_file.view',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing $locale key: $key");
            }
        }
    }

    public function test_general_management_group_is_arabic(): void
    {
        app()->setLocale('ar');
        $this->assertSame('الإدارة العامة', __('navigation.groups.general_management'));
    }
}
