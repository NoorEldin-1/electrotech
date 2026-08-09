<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            ChartOfAccountsSeeder::class,
            DemoUserSeeder::class,
        ]);

        // Demo figures for the four financial statements (ماليات.pptx). Kept
        // out of the list above and behind a flag: it writes posted journal
        // entries and opening balances, which nobody wants landing in a real
        // company's ledger on deploy.
        //
        //   php artisan db:seed --class=FinancialStatementsDemoSeeder
        //   SEED_FINANCIAL_DEMO=true php artisan db:seed
        if (filter_var(env('SEED_FINANCIAL_DEMO', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(FinancialStatementsDemoSeeder::class);
        }
    }
}
