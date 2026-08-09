<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Safe to run on every deploy.
 *
 * Behavior:
 *  - Adds new permissions defined in code to the DB.
 *  - Removes orphaned permissions (in DB but not in code).
 *  - Creates initial roles with default permissions ONLY if they don't exist.
 *  - Does NOT touch role-permission assignments that the Admin manages via UI,
 *    except the Admin role which is always force-synced to all permissions.
 */
class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->syncPermissionCatalog();
        $this->ensureInitialRolesExist();
        $this->grantAdminAllPermissions();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Sync the master permission list with the DB.
     * Adds new permissions and removes orphaned ones.
     * Role-permission assignments for removed permissions are cascade-deleted by Spatie.
     */
    private function syncPermissionCatalog(): void
    {
        $defined = $this->getPermissions();

        foreach ($defined as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Permission::where('guard_name', 'web')
            ->whereNotIn('name', $defined)
            ->delete();
    }

    /**
     * Create the initial set of roles if missing.
     * If a role already exists, its permissions are left untouched (Admin manages via UI).
     */
    private function ensureInitialRolesExist(): void
    {
        foreach ($this->getDefaultRoleDefinitions() as $roleName => $defaultPermissions) {
            $existing = Role::where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if ($existing) {
                continue;
            }

            $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($defaultPermissions);
        }
    }

    /**
     * Admin is the super-admin role: always has every permission in the system.
     */
    private function grantAdminAllPermissions(): void
    {
        $admin = Role::where('name', 'Admin')
            ->where('guard_name', 'web')
            ->first();

        if ($admin) {
            $admin->syncPermissions(Permission::where('guard_name', 'web')->get());
        }
    }

    /**
     * The single source of truth for permissions.
     * Adding a new entry here will surface it in the UI on next deploy.
     * Removing an entry here will delete it from the DB and revoke it from all roles/users.
     */
    private function getPermissions(): array
    {
        return [
            // Project Management
            'projects.view',
            'projects.create',
            'projects.edit',
            'projects.delete',
            'projects.change_status',
            'projects.move_to_tender',
            'projects.move_to_inhand',
            'projects.move_to_active',
            'projects.cancel_to_lost',
            'projects.set_alarm',
            'projects.manager_approve',
            'projects.view_history',
            'attachments.upload',
            'attachments.download',
            'attachments.delete',

            // Project Offers (Sales pipeline)
            'project_offers.view',
            'project_offers.create',
            'project_offers.edit',
            'project_offers.delete',
            'project_offers.print',

            // Technical Office / BOM
            'items.view',
            'items.create',
            'items.edit',
            'items.delete',
            'boms.view',
            'boms.create',
            'boms.edit',
            'boms.approve',
            'boms.delete',

            // Warehouse / Inventory
            'inventory.view',
            'inventory.manage',
            'inventory.hold',
            'inventory.release',
            'inventory.transfer',
            'inventory.view_pricing',
            'transactions.view',

            // Warehouse Vouchers
            'addition_vouchers.view',
            'addition_vouchers.create',
            'addition_vouchers.post',
            // Purchase invoicing (سلايد 11): record the supplier invoice on a
            // receipt, or close the receipt without one.
            'addition_vouchers.invoice',
            'issue_vouchers.view',
            'issue_vouchers.create',
            'issue_vouchers.post',
            // اعتماد صرف كمية زائدة: posting more than the work order's material
            // plan still needs is stopped at the gate; only this permission may
            // carry it through, and only with a written reason.
            'issue_vouchers.approve_excess',
            'return_vouchers.view',
            'return_vouchers.create',
            'return_vouchers.post',
            'depreciation_vouchers.view',
            'depreciation_vouchers.create',
            'depreciation_vouchers.post',
            'delivery_vouchers.view',
            'delivery_vouchers.create',
            'delivery_vouchers.approve_technical',
            'delivery_vouchers.approve_financial',
            'delivery_vouchers.cancel',

            // Procurement
            'purchase_orders.view',
            'purchase_orders.create',
            'purchase_orders.edit',
            'purchase_orders.approve',
            'purchase_orders.receive',
            'purchase_orders.print',
            'purchase_orders.delete',

            // Suppliers & Customers
            'suppliers.view',
            'suppliers.create',
            'suppliers.edit',
            'suppliers.delete',
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            'supplier_statements.view',
            'customer_statements.view',

            // Sales invoices matched against delivery vouchers (سلايد 10)
            'sales_invoices.view',
            'sales_invoices.create',
            'sales_invoices.edit',
            'sales_invoices.delete',

            // Finance — General Ledger (الإدارة المالية)
            'accounts.view',
            'accounts.create',
            'accounts.edit',
            'accounts.delete',
            'journal_entries.view',
            'journal_entries.create',
            'journal_entries.edit',
            'journal_entries.post',
            'journal_entries.delete',
            'journal_daybook.view',
            'general_ledger.view',
            'trial_balance.view',

            // Finance — القوائم المالية (ماليات.pptx): what comes after the
            // trial balance. Separate permissions per statement, because the
            // balance sheet and the income statement expose company-level
            // profitability that not every ledger reader should see.
            'operating_statement.view',
            'income_statement.view',
            'balance_sheet.view',
            'cash_flow_statement.view',

            // General Management (الإدارة العامة) — operations oversight, cost
            // center, delivery minutes and financial claims.
            'operations.overview',
            'operations.view_cost',
            // سلايد 12: closing the operation's cost centre into cost of goods
            // sold posts a journal entry — heavier than merely reading the file.
            'operations.close_cost_center',
            'operations.activate',
            'operations.complete',
            'operations.hold',
            'operations.reserve',
            'delivery_minutes.view',
            'delivery_minutes.create',
            'delivery_minutes.distribute',
            'financial_claims.view',
            'financial_claims.create',
            'financial_claims.submit',
            'financial_claims.collect',
            // General Management (Phase 7) — payments, facilities, installation,
            // site surveys.
            'operation_payments.view',
            'operation_payments.record',
            'supply_orders_file.view',
            'credit_facilities.view',
            'credit_facilities.manage',
            'installations.view',
            'installations.manage',
            'site_surveys.view',
            'site_surveys.manage',

            // Manufacturing / Work Orders
            'work_orders.view',
            'work_orders.create',
            'work_orders.edit',
            'work_orders.approve_order',
            'work_orders.start',
            'work_orders.submit_qa',
            'work_orders.approve_qa',
            'work_orders.complete',
            'work_orders.finish_manufacturing',
            'work_orders.delete',
            'quality_sheets.view',
            'quality_sheets.create',
            'quality_sheets.fill',
            'quality_sheets.approve',
            'quality_sheets.print',
            'production_entries.view',
            'scrap.view',

            // System Administration
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'roles.manage',
            'activity_log.view',
            'dashboard.view',
        ];
    }

    /**
     * Default permissions applied only when a role is first created.
     * Subsequent edits should be done via the admin UI.
     */
    private function getDefaultRoleDefinitions(): array
    {
        return [
            'Admin' => [], // handled by grantAdminAllPermissions()

            'Sales' => [
                'projects.view',
                'projects.create',
                'projects.edit',
                'projects.change_status',
                'projects.move_to_tender',
                'projects.move_to_inhand',
                'projects.move_to_active',
                'projects.cancel_to_lost',
                'projects.set_alarm',
                'projects.view_history',
                'project_offers.view',
                'project_offers.create',
                'project_offers.edit',
                'project_offers.print',
                'customers.view',
                'customers.create',
                'customers.edit',
                'customer_statements.view',
                'sales_invoices.view',
                'attachments.upload',
                'attachments.download',
                'dashboard.view',
            ],

            // Sales_Manager: same as Sales plus the manager-approve gate that
            // unlocks the In-Hand → Active transition.
            'Sales_Manager' => [
                'projects.view',
                'projects.create',
                'projects.edit',
                'projects.change_status',
                'projects.move_to_tender',
                'projects.move_to_inhand',
                'projects.move_to_active',
                'projects.cancel_to_lost',
                'projects.set_alarm',
                'projects.manager_approve',
                'projects.view_history',
                'project_offers.view',
                'project_offers.create',
                'project_offers.edit',
                'project_offers.delete',
                'project_offers.print',
                'customers.view',
                'customers.create',
                'customers.edit',
                'customers.delete',
                'customer_statements.view',
                'sales_invoices.view',
                'sales_invoices.create',
                'sales_invoices.edit',
                'attachments.upload',
                'attachments.download',
                'attachments.delete',
                // General management: can see the operations overview, push an
                // operation to active, and view delivery minutes.
                'operations.overview',
                'operations.activate',
                'delivery_minutes.view',
                'dashboard.view',
            ],

            'Technical_Office' => [
                'projects.view',
                'project_offers.view',
                // Procurement oversight: the technical-office manager approves
                // purchase orders (slide 5) and prints them (slide 8).
                'purchase_orders.view',
                'purchase_orders.approve',
                'purchase_orders.print',
                'items.view',
                'items.create',
                'items.edit',
                'boms.view',
                'boms.create',
                'boms.edit',
                'boms.approve',
                'work_orders.view',
                'work_orders.create',
                // PMO manager approves the manufacturing order out of Draft
                // (اعتماد مدير مكتب المشروعات — سلايد 5) before the floor starts.
                'work_orders.approve_order',
                // Quality sheet: the technical office reviews and prints it.
                'quality_sheets.view',
                'quality_sheets.print',
                'inventory.view',
                'customers.view',
                'delivery_vouchers.view',
                'delivery_vouchers.approve_technical',
                'production_entries.view',
                'scrap.view',
                'attachments.download',
                // Phase 7 — engineering: site measurements/drawings + sees
                // installation progress.
                'site_surveys.view',
                'site_surveys.manage',
                'installations.view',
                'dashboard.view',
            ],

            'Procurement' => [
                'projects.view',
                'project_offers.view',
                'attachments.download',
                'items.view',
                'boms.view',
                'inventory.view',
                'inventory.view_pricing',
                'purchase_orders.view',
                'purchase_orders.create',
                'purchase_orders.edit',
                'purchase_orders.approve',
                'purchase_orders.receive',
                'purchase_orders.print',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'addition_vouchers.view',
                'addition_vouchers.post',
                // Procurement chases the supplier invoice for every receipt
                // it raised (سلايد 11).
                'addition_vouchers.invoice',
                'supplier_statements.view',
                'transactions.view',
                'dashboard.view',
            ],

            'Factory_Manager' => [
                'projects.view',
                'project_offers.view',
                'attachments.download',
                'items.view',
                'boms.view',
                'inventory.view',
                'work_orders.view',
                'work_orders.edit',
                'work_orders.start',
                'work_orders.submit_qa',
                'work_orders.approve_qa',
                'work_orders.complete',
                'work_orders.finish_manufacturing',
                // Quality sheet: the factory manager fills, approves and prints
                // (QA dept fills + factory-manager final approval, التصنيع سلايد 3).
                'quality_sheets.view',
                'quality_sheets.create',
                'quality_sheets.fill',
                'quality_sheets.approve',
                'quality_sheets.print',
                'issue_vouchers.view',
                'issue_vouchers.create',
                'return_vouchers.view',
                'return_vouchers.create',
                'depreciation_vouchers.view',
                'depreciation_vouchers.create',
                'delivery_vouchers.view',
                'delivery_vouchers.approve_technical',
                'production_entries.view',
                'scrap.view',
                'transactions.view',
                // General management: oversees the operations floor — overview,
                // marking an operation complete, and reserving stock for it.
                'operations.overview',
                'operations.complete',
                'operations.reserve',
                // Phase 7 — executes installation on the floor.
                'installations.view',
                'installations.manage',
                'dashboard.view',
            ],

            // NOTE: inventory.view_pricing is intentionally excluded
            // per PDF spec: "pricing hidden from warehouse keepers"
            'Warehouse_Manager' => [
                'projects.view',
                'attachments.download',
                'items.view',
                'inventory.view',
                'inventory.manage',
                'inventory.hold',
                'inventory.release',
                'inventory.transfer',
                'transactions.view',
                'purchase_orders.view',
                'purchase_orders.receive',
                'addition_vouchers.view',
                'addition_vouchers.create',
                'issue_vouchers.view',
                'issue_vouchers.create',
                'issue_vouchers.post',
                // The store keeper may carry an over-issue through, but never
                // silently: the excess table is shown first and the reason is
                // stamped on the voucher with their name.
                'issue_vouchers.approve_excess',
                'return_vouchers.view',
                'return_vouchers.create',
                'return_vouchers.post',
                'depreciation_vouchers.view',
                'depreciation_vouchers.create',
                'depreciation_vouchers.post',
                'delivery_vouchers.view',
                'delivery_vouchers.create',
                'dashboard.view',
            ],

            // Finance / financial management (الإدارة المالية): signs off
            // delivery vouchers, posts supplier invoices, sees pricing and
            // both account statements.
            'Finance' => [
                'projects.view',
                'project_offers.view',
                'attachments.download',
                'suppliers.view',
                'customers.view',
                'inventory.view',
                'inventory.view_pricing',
                'transactions.view',
                'purchase_orders.view',
                'addition_vouchers.view',
                'addition_vouchers.post',
                // Purchase invoicing (سلايد 11): finance owns the matching of
                // supplier invoices against goods receipts.
                'addition_vouchers.invoice',
                'issue_vouchers.view',
                'depreciation_vouchers.view',
                'delivery_vouchers.view',
                'delivery_vouchers.approve_financial',
                'supplier_statements.view',
                'customer_statements.view',
                // Sales invoicing (سلايد 10): finance owns the matching of
                // invoices against delivery vouchers.
                'sales_invoices.view',
                'sales_invoices.create',
                'sales_invoices.edit',
                'sales_invoices.delete',
                // General ledger (الإدارة المالية): chart of accounts, journal
                // entries, ledger and trial balance.
                'accounts.view',
                'accounts.create',
                'accounts.edit',
                'journal_entries.view',
                'journal_entries.create',
                'journal_entries.edit',
                'journal_entries.post',
                'journal_daybook.view',
                'general_ledger.view',
                'trial_balance.view',
                // القوائم المالية (ماليات.pptx) — finance owns the annual
                // closing set that follows the trial balance.
                'operating_statement.view',
                'income_statement.view',
                'balance_sheet.view',
                'cash_flow_statement.view',
                // General management: the operation cost center and the
                // financial-claim workflow live with finance.
                'operations.view_cost',
                // سلايد 12: finance closes the operation's cost centre into
                // cost of goods sold once the customer has been delivered.
                'operations.close_cost_center',
                'financial_claims.view',
                'financial_claims.create',
                'financial_claims.submit',
                'financial_claims.collect',
                // Phase 7 — collection/financing side: payments, supply file,
                // credit facilities.
                'operation_payments.view',
                'operation_payments.record',
                'supply_orders_file.view',
                'credit_facilities.view',
                'credit_facilities.manage',
                'dashboard.view',
            ],

            // General_Manager (الإدارة العامة): the oversight role that owns the
            // operations overview, the cost center, lifecycle transitions,
            // delivery minutes and financial claims, with broad cross-department
            // read access. New role — created automatically on fresh installs.
            'General_Manager' => [
                'projects.view',
                'project_offers.view',
                'items.view',
                'boms.view',
                'inventory.view',
                'inventory.view_pricing',
                'work_orders.view',
                'quality_sheets.view',
                'quality_sheets.print',
                'purchase_orders.view',
                'addition_vouchers.view',
                'issue_vouchers.view',
                'delivery_vouchers.view',
                'production_entries.view',
                'suppliers.view',
                'customers.view',
                'supplier_statements.view',
                'customer_statements.view',
                'sales_invoices.view',
                'accounts.view',
                'journal_entries.view',
                'journal_daybook.view',
                'general_ledger.view',
                'trial_balance.view',
                // القوائم المالية — the general manager reads the company's
                // profitability and position; they do not post entries.
                'operating_statement.view',
                'income_statement.view',
                'balance_sheet.view',
                'cash_flow_statement.view',
                'transactions.view',
                // General management permissions (the owning role).
                'operations.overview',
                'operations.view_cost',
                'operations.close_cost_center',
                'operations.activate',
                'operations.complete',
                'operations.hold',
                'operations.reserve',
                'delivery_minutes.view',
                'delivery_minutes.create',
                'delivery_minutes.distribute',
                'financial_claims.view',
                'financial_claims.create',
                'financial_claims.submit',
                'financial_claims.collect',
                // Phase 7 — owns payments, supply file, facilities, installation,
                // and site surveys.
                'operation_payments.view',
                'operation_payments.record',
                'supply_orders_file.view',
                'credit_facilities.view',
                'credit_facilities.manage',
                'installations.view',
                'installations.manage',
                'site_surveys.view',
                'site_surveys.manage',
                'dashboard.view',
            ],
        ];
    }
}
