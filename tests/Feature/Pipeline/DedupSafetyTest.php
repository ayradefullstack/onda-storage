<?php

declare(strict_types=1);

use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Deposit\VaultBytesReleaser;
use App\Domain\Deposit\VaultConsistency;
use App\Jobs\DeduplicateFile;
use App\Jobs\RecordDeposit;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

const DEDUP_BYTES = 'identical deposit content — a legal deposit must never be destroyed';

beforeEach(function () {
    $this->author = pipelineAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
});

/**
 * @return list<MediaFile> n identical files, each with its OWN bytes on
 *                         disk and its hash already computed — the state
 *                         right before the dedup jobs of a parallel upload.
 */
function dedupFiles(object $test, int $n): array
{
    $files = [];

    for ($i = 0; $i < $n; $i++) {
        $file = pipelineFile($test->author, $test->oeuvre, DEDUP_BYTES, "copy-{$i}.pdf", 'application/pdf', 'scanning');
        $file->ref_count = 1;
        $file->save();
        $files[] = $file;
    }

    return $files;
}

/** Hard delete (the ledger rows are append-only, so tests drop them first). */
function hardDelete(MediaFile ...$files): void
{
    foreach ($files as $file) {
        FileAccessLog::where('media_file_id', $file->id)->delete();
        MediaFile::withTrashed()->where('id', $file->id)->forceDelete();
    }
}

function runDedup(MediaFile $file): void
{
    app()->call([new DeduplicateFile($file->uuid), 'handle']);
}

function expectSingleSurvivingCopy(array $files): void
{
    $rows = collect($files)->map(fn (MediaFile $f) => $f->fresh());
    $paths = $rows->pluck('path')->unique();

    expect($paths)->toHaveCount(1);

    $disk = Storage::disk($rows[0]->disk);
    expect(is_file($disk->path($paths->first())))->toBeTrue()
        ->and(is_file($disk->path($rows[0]->mac_path)))->toBeTrue();

    foreach ($rows as $row) {
        expect(app(VaultConsistency::class)->bytesIntact($row))->toBeTrue()
            ->and($row->ref_count)->toBe(count($files));
    }

    // Exactly one physical copy remains of everything these rows ever owned.
    $physical = collect($files)->filter(fn (MediaFile $f) => is_file($disk->path($f->path)))->count();
    expect($physical)->toBe(1);
}

test('the exact interleaving that destroyed two deposits: both hashed, A dedups, then B dedups', function () {
    [$a, $b] = dedupFiles($this, 2);

    runDedup($a);
    runDedup($b);

    expectSingleSurvivingCopy([$a, $b]);
    expect($b->fresh()->path)->toBe($a->path);
});

test('the reverse order (B first, then A) is just as safe', function () {
    [$a, $b] = dedupFiles($this, 2);

    runDedup($b);
    runDedup($a);

    expectSingleSurvivingCopy([$a, $b]);
    // The canonical copy is the lowest id, whatever order the jobs ran in.
    expect($b->fresh()->path)->toBe($a->path);
});

test('three identical files in every interleaving leave exactly one copy, with the right counts', function (array $order) {
    $files = dedupFiles($this, 3);

    foreach ($order as $index) {
        runDedup($files[$index]);
    }

    expectSingleSurvivingCopy($files);
})->with([
    'a b c' => [[0, 1, 2]],
    'a c b' => [[0, 2, 1]],
    'b a c' => [[1, 0, 2]],
    'b c a' => [[1, 2, 0]],
    'c a b' => [[2, 0, 1]],
    'c b a' => [[2, 1, 0]],
]);

test('running a dedup job twice (a retry) is harmless', function () {
    [$a, $b] = dedupFiles($this, 2);

    runDedup($b);
    runDedup($b);
    runDedup($a);

    expectSingleSurvivingCopy([$a, $b]);
});

test('if the canonical bytes cannot be verified nothing is deduplicated and every copy is kept', function () {
    [$a, $b] = dedupFiles($this, 2);
    // A (the would-be canonical) loses its bytes.
    @unlink(Storage::disk($a->disk)->path($a->path));

    runDedup($b);

    // B's own copy verifies, so B is canonical itself: nothing to link.
    expect($b->fresh()->path)->toBe($b->path)
        ->and(app(VaultConsistency::class)->bytesIntact($b->fresh()))->toBeTrue();

    // With no verifiable copy at all, still nothing is deleted or relinked.
    @unlink(Storage::disk($b->disk)->path($b->path));
    $pathBefore = $b->path;

    runDedup($b);

    expect($b->fresh()->path)->toBe($pathBefore);
});

test('a row that vanished mid-pipeline ends the job quietly and touches no bytes', function () {
    [$a, $b] = dedupFiles($this, 2);
    $bPath = Storage::disk($b->disk)->path($b->path);

    $b->delete(); // soft-deleted while queued
    runDedup($b);
    expect(is_file($bPath))->toBeTrue();

    hardDelete($a); // gone entirely, like a reset database
    runDedup($a);
    expect(is_file($bPath))->toBeTrue();

    // A uuid that never existed.
    app()->call([new DeduplicateFile('00000000-0000-7000-8000-000000000000'), 'handle']);
});

test('the releaser keeps bytes while any row — a soft-deleted one included — still points at the path', function () {
    [$a, $b] = dedupFiles($this, 2);
    runDedup($a);
    runDedup($b);
    $disk = Storage::disk($a->disk);
    $object = StoredObjectMapper::fromMediaFile($a->fresh());
    $releaser = app(VaultBytesReleaser::class);

    // Delete one row of the pair: the bytes stay.
    hardDelete($b);
    expect($releaser->releaseIfUnreferenced($a, $object, 'test'))->toBeFalse()
        ->and(is_file($disk->path($a->path)))->toBeTrue();

    // A soft-deleted row still protects them.
    $a->fresh()->delete();
    expect($releaser->releaseIfUnreferenced($a, $object, 'test'))->toBeFalse()
        ->and(is_file($disk->path($a->path)))->toBeTrue();

    // Only when the LAST claim is gone (purged) are they released.
    MediaFile::withTrashed()->where('path', $a->path)->update(['purged_at' => now()]);
    expect($releaser->releaseIfUnreferenced($a, $object, 'test'))->toBeTrue()
        ->and(is_file($disk->path($a->path)))->toBeFalse();
});

test('every release decision is written to the ledger', function () {
    [$a, $b] = dedupFiles($this, 2);

    runDedup($a);
    runDedup($b);

    $actions = FileAccessLog::where('media_file_id', $b->id)->pluck('action')->all();

    expect($actions)->toContain('bytes_released');

    $object = StoredObjectMapper::fromMediaFile($a->fresh());
    app(VaultBytesReleaser::class)->releaseIfUnreferenced($a, $object, 'test');

    expect(FileAccessLog::where('media_file_id', $a->id)->pluck('action')->all())->toContain('bytes_kept');
});

test('ref_count is recomputed from the real rows, not incremented', function () {
    [$a, $b, $c] = dedupFiles($this, 3);
    $a->forceFill(['ref_count' => 99])->save(); // drifted

    runDedup($b);
    runDedup($c);
    runDedup($a);

    expect($a->fresh()->ref_count)->toBe(3)
        ->and($b->fresh()->ref_count)->toBe(3);
});

test('RecordDeposit refuses a file whose bytes are missing: failed, no deposit row', function () {
    [$a] = dedupFiles($this, 1);
    $a->forceFill(['status' => 'processing'])->save();
    @unlink(Storage::disk($a->disk)->path($a->path));

    expect(fn () => app()->call([new RecordDeposit($a->uuid), 'handle']))
        ->toThrow(RuntimeException::class, 'bytes_missing');

    expect(FileAccessLog::where('media_file_id', $a->id)->where('action', 'deposit')->count())->toBe(0)
        ->and(FileAccessLog::where('media_file_id', $a->id)->where('action', 'bytes_missing')->count())->toBe(1);

    // Through the queue the same condition ends in status `failed`.
    RecordDeposit::dispatchSync($a->uuid);

    expect($a->fresh()->status)->toBe('failed')
        ->and(FileAccessLog::where('media_file_id', $a->id)->where('action', 'deposit')->count())->toBe(0);
});

test('RecordDeposit refuses a ciphertext of the wrong size', function () {
    [$a] = dedupFiles($this, 1);
    $a->forceFill(['status' => 'processing'])->save();
    file_put_contents(Storage::disk($a->disk)->path($a->path), 'truncated');

    expect(fn () => app()->call([new RecordDeposit($a->uuid), 'handle']))
        ->toThrow(RuntimeException::class, 'bytes_missing');
});

test('architecture: only VaultBytesReleaser may call the vault destroy method', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'VaultBytesReleaser.php') {
            continue;
        }

        if (preg_match('/->\s*destroy\s*\(|::destroy\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
