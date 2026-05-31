<?php

namespace Tests\Feature\Sales;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_tender_projects_label_is_arabic_in_ar_locale(): void
    {
        app()->setLocale('ar');
        $this->assertSame('عمليات المناقصات', __('resources.tender_projects.plural_label'));
    }

    public function test_tender_projects_label_is_english_in_en_locale(): void
    {
        app()->setLocale('en');
        $this->assertSame('Tender Projects', __('resources.tender_projects.plural_label'));
    }

    public function test_new_project_status_enum_labels_resolve_in_both_locales(): void
    {
        $keys = ['tender', 'in_hand', 'lost'];

        app()->setLocale('en');
        foreach ($keys as $k) {
            $val = __('resources.enums.project_status.' . $k);
            $this->assertNotSame('resources.enums.project_status.' . $k, $val, "Missing EN key: $k");
        }

        app()->setLocale('ar');
        foreach ($keys as $k) {
            $val = __('resources.enums.project_status.' . $k);
            $this->assertNotSame('resources.enums.project_status.' . $k, $val, "Missing AR key: $k");
        }
    }

    public function test_lost_reasons_resolve_in_arabic(): void
    {
        app()->setLocale('ar');
        $this->assertSame('الأسعار غالية', __('resources.enums.lost_reason.high_price'));
        $this->assertSame('غير معتمدين عند الاستشاري', __('resources.enums.lost_reason.not_approved_by_consultant'));
    }

    public function test_attachment_categories_share_canonical_uppercase_label(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('UPLOAD', __('resources.enums.attachment_category.upload'));
            $this->assertSame('BOQ', __('resources.enums.attachment_category.boq'));
        }
    }

    public function test_new_permission_labels_resolve_in_both_locales(): void
    {
        $perms = ['move_to_tender', 'move_to_inhand', 'move_to_active', 'cancel_to_lost', 'set_alarm', 'manager_approve'];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach ($perms as $p) {
                $val = __('resources.roles.permissions.projects.' . $p);
                $this->assertNotSame(
                    'resources.roles.permissions.projects.' . $p,
                    $val,
                    "Missing $locale key: roles.permissions.projects.$p"
                );
            }
        }
    }
}
