<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Restricted drawings (Final_Modifications Task 5 / R3, security fix S1):
 * the preliminary drawing (`upload`), the executive drawing and the site
 * measurements used to sit on the public disk, where anyone who guessed
 * `/storage/attachments/{project}/{category}/{file}` could fetch them with no
 * permission check. They now live on the private `restricted` disk (no public
 * URL) and are served only through permission-checked routes.
 *
 * This moves what is already stored. For every restricted attachment row —
 * soft-deleted ones too, their file is still on disk:
 *
 *   1. copy the file public -> restricted as a stream,
 *   2. verify the copy (exists, same size),
 *   3. only then delete the public copy.
 *
 * `file_path` never changes: it is the same relative path on the other disk, so
 * no row is rewritten. A row whose file is on neither disk is logged and
 * skipped. A failure on one file is logged and does not stop the others (the
 * public copy is kept in that case, and the next run retries it).
 *
 * Afterwards the restricted folders of every project and the pending-upload
 * folder (`attachments/new/{category}`: files picked on the Create screen and
 * never saved) are swept too, so no restricted file is left behind on the
 * public disk even without a row.
 *
 * Idempotent: a file already moved is recognised and left alone, so running
 * the migration twice — or on a database with no such rows — changes nothing.
 */
return new class extends Migration
{
    /** Stored values of the restricted categories (kept literal: a migration must not drift with the enum). */
    private const CATEGORIES = ['upload', 'executive_drawing', 'site_measurement'];

    private const FROM = 'public';

    private const TO = 'restricted';

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('attachments')) {
            return;
        }

        $public = Storage::disk(self::FROM);
        $private = Storage::disk(self::TO);

        $tally = ['moved' => 0, 'already' => 0, 'missing' => 0, 'failed' => 0];

        DB::table('attachments')
            ->whereIn('category', self::CATEGORIES)
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->orderBy('id')
            ->select(['id', 'file_path'])
            ->chunkById(200, function ($rows) use ($public, $private, &$tally): void {
                foreach ($rows as $row) {
                    $outcome = $this->move($public, $private, (string) $row->file_path);

                    if ($outcome === 'missing') {
                        Log::warning('Restricted attachment file is missing from both disks.', [
                            'attachment_id' => $row->id,
                            'file_path' => $row->file_path,
                        ]);
                    }

                    $tally[$outcome]++;
                }
            });

        // Files with no row: every project's restricted folders, and the
        // pending-upload folder of the Create screen.
        foreach ($this->strayFiles($public) as $path) {
            $tally[$this->move($public, $private, $path)]++;
        }

        Log::info('Restricted attachments moved to the private disk.', $tally);
    }

    public function down(): void
    {
        // Deliberately irreversible: copying the files back to the public disk
        // would re-expose them.
    }

    /**
     * Move one file. Returns what happened:
     *  - moved:   copied, verified, public copy removed
     *  - already: only the private copy exists
     *  - missing: on neither disk
     *  - failed:  could not be copied or verified; the public copy is kept
     *
     * @return 'moved'|'already'|'missing'|'failed'
     */
    private function move(Filesystem $public, Filesystem $private, string $path): string
    {
        try {
            $onPublic = $public->exists($path);
            $onPrivate = $private->exists($path);

            if (! $onPublic) {
                return $onPrivate ? 'already' : 'missing';
            }

            if (! $onPrivate) {
                $stream = $public->readStream($path);

                if (! is_resource($stream)) {
                    throw new RuntimeException("Cannot open {$path} for reading.");
                }

                try {
                    $private->writeStream($path, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }

            // Both copies exist now: the public one goes only when the
            // private one is provably complete.
            if (! $private->exists($path) || $private->size($path) !== $public->size($path)) {
                throw new RuntimeException("The private copy of {$path} could not be verified; the public copy was kept.");
            }

            $public->delete($path);

            return 'moved';
        } catch (Throwable $e) {
            Log::error('Could not move a restricted attachment to the private disk.', [
                'file_path' => $path,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Files still on the public disk under a restricted category folder of any
     * project (`attachments/{project}/{category}`) or of the Create screen
     * (`attachments/new/{category}`).
     *
     * @return list<string>
     */
    private function strayFiles(Filesystem $public): array
    {
        $found = [];

        try {
            foreach ($public->directories('attachments') as $projectDir) {
                foreach (self::CATEGORIES as $category) {
                    $dir = "{$projectDir}/{$category}";

                    foreach ($public->allFiles($dir) as $file) {
                        $found[] = $file;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('Could not sweep the public disk for restricted attachments.', [
                'error' => $e->getMessage(),
            ]);
        }

        return $found;
    }
};
