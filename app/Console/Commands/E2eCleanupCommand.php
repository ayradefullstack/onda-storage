<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Consultation\DeleteConsultationAssets;
use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Deposit\VaultBytesReleaser;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Cleans up after the Playwright e2e suite, which uploads real files into the
 * dev vault. It "purges" only deposits that are unmistakably the suite's own:
 * `original_name` starts with the e2e prefix AND the uploader is the e2e demo
 * author. Each is soft-deleted and stamped `purged_at` (the project's own
 * "this row gives its claim on the bytes up" marker, see VaultBytesReleaser),
 * its derivatives and variants are removed, its quota charge is returned, and
 * the vault bytes go through `VaultBytesReleaser` — so bytes shared with any
 * other row are kept. Dry-run unless --apply.
 */
final class E2eCleanupCommand extends Command
{
    protected $signature = 'e2e:cleanup
        {--prefix=e2e- : original_name prefix of the suite\'s deposits}
        {--email=author1@onda.dz : the e2e demo author}
        {--apply : Actually purge (default is a dry run)}';

    protected $description = 'Purge the e2e suite\'s own deposits (files named e2e-* by the demo author) and free their bytes. Dry-run unless --apply.';

    public function handle(VaultBytesReleaser $releaser, DeleteConsultationAssets $deleteAssets): int
    {
        $prefix = (string) $this->option('prefix');
        $user = User::where('email', (string) $this->option('email'))->first();

        if ($prefix === '' || $user === null) {
            $this->info('Nothing to do: no such e2e author or an empty prefix.');

            return self::SUCCESS;
        }

        $files = MediaFile::withTrashed()
            ->whereNull('purged_at')
            ->where('uploaded_by', $user->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (MediaFile $file): bool => str_starts_with($file->original_name, $prefix))
            ->values();

        if ($files->isEmpty()) {
            $this->info('No e2e deposit to purge.');

            return self::SUCCESS;
        }

        $bytes = (int) $files->sum('size_bytes');
        $this->line(sprintf('%d deposit(s) named %s*, %s claimed.', $files->count(), $prefix, round($bytes / 1048576, 1).' MiB'));

        if (! $this->option('apply')) {
            $this->warn('Dry run: nothing was changed. Re-run with --apply.');

            return self::SUCCESS;
        }

        $released = 0;
        $kept = 0;

        foreach ($files as $file) {
            $object = StoredObjectMapper::fromMediaFile($file);

            $deleteAssets->handle($file->consultationAssets()->get());

            foreach (MediaVariant::where('media_file_id', $file->id)->get() as $variant) {
                Storage::disk('variants')->delete($variant->path);
                $variant->delete();
            }

            MediaFile::withTrashed()->where('id', $file->id)->update(['purged_at' => now(), 'deleted_at' => $file->deleted_at ?? now()]);

            $quota = StorageQuota::where('user_id', $file->uploaded_by)->first();

            if ($quota !== null) {
                $quota->decrement('used_bytes', min($file->size_bytes, $quota->used_bytes));
            }

            // After the row has given up its claim: deleted only if no other row has it.
            $releaser->releaseIfUnreferenced($file, $object, 'e2e-cleanup') ? $released++ : $kept++;
        }

        $this->info("Purged {$files->count()} deposit(s): bytes released for {$released}, kept (still referenced) for {$kept}.");

        return self::SUCCESS;
    }
}
