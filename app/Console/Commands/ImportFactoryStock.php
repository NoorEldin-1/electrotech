<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Imports\FactoryStock\FactoryStockImporter;
use App\Imports\FactoryStock\ReviewReport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Loads the factory-warehouse sheet («مخزن المصنع - بالصور») into the item
 * catalog: items, categories, photos and opening balances.
 *
 * Safe to re-run: items are matched by code and an opening balance is only
 * booked for an item that has no stock movements yet. Always start with
 * --dry-run and read the review report.
 *
 *   php artisan items:import-factory-stock "sheet.xlsx" --dry-run --report=review.xlsx
 *   php artisan items:import-factory-stock "sheet.xlsx" --purge-legacy --user=1
 */
class ImportFactoryStock extends Command
{
    protected $signature = 'items:import-factory-stock
        {file : Path to the .xlsx sheet}
        {--dry-run : Compute everything and write the report, but change nothing}
        {--purge-legacy : Remove the old/demo items that are not in the sheet first}
        {--user= : Id of the user the opening-balance movements are recorded under (default: first Admin)}
        {--report= : Where to write the review workbook (.xlsx)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Import the factory-warehouse sheet (items, photos, opening balances)';

    public function handle(FactoryStockImporter $importer, ReviewReport $report): int
    {
        $file = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            $this->error('No user to record the opening balances under. Pass --user=<id>.');

            return self::FAILURE;
        }
        Auth::setUser($user);

        if (! $dryRun && ! $this->option('force') && ! $this->confirm(
            $this->option('purge-legacy')
                ? 'This imports the sheet AND removes every item not in it. Continue?'
                : 'This imports the sheet into the item catalog. Continue?',
        )) {
            return self::FAILURE;
        }

        // Decoding multi-megapixel photos needs more than the CLI default.
        ini_set('memory_limit', '1024M');

        $result = $importer->run($file, $dryRun, (bool) $this->option('purge-legacy'));

        $this->info($dryRun ? 'DRY RUN — nothing was changed.' : 'Import complete.');
        $this->table(['', ''], collect($result->summary())->map(fn ($value, $key) => [$key, $value])->values()->all());

        if ($path = $this->option('report')) {
            $report->write($result, (string) $path);
            $this->info("Review report: {$path}");
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        if ($id = $this->option('user')) {
            return User::find($id);
        }

        return User::role('Admin')->orderBy('id')->first() ?? User::query()->orderBy('id')->first();
    }
}
