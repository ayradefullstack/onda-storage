<?php

declare(strict_types=1);

use App\Domain\Deposit\VaultConsistency;
use App\Domain\Deposit\VaultConsistencyChecks;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->author = pipelineAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
});

function consistencyFile(object $test): MediaFile
{
    return pipelineFile($test->author, $test->oeuvre, 'some bytes to deposit', 'a.pdf', 'application/pdf');
}

function checkStatus(string $key): string
{
    return app(VaultConsistencyChecks::class)->run()->firstWhere('key', $key)->status;
}

test('a healthy deposit passes every consistency check', function () {
    consistencyFile($this);

    expect(app(VaultConsistency::class)->missing())->toHaveCount(0)
        ->and(checkStatus('vault.missing_bytes'))->toBe('PASS');
});

test('a row whose bin or mac is missing is reported, not deleted', function () {
    $file = consistencyFile($this);
    @unlink(Storage::disk($file->disk)->path($file->mac_path));

    $missing = app(VaultConsistency::class)->missing();

    expect($missing)->toHaveCount(1)
        ->and($missing[0]['bin'])->toBeTrue()
        ->and($missing[0]['mac'])->toBeFalse()
        ->and(checkStatus('vault.missing_bytes'))->toBe('WARN')
        ->and(MediaFile::find($file->id))->not->toBeNull();
});

test('a ref_count that disagrees with the real rows is reported', function () {
    $file = consistencyFile($this);
    $file->forceFill(['ref_count' => 5])->save();

    $mismatches = app(VaultConsistency::class)->refCountMismatches();

    expect($mismatches)->toHaveCount(1)
        ->and($mismatches[0]['actual'])->toBe(1)
        ->and(checkStatus('vault.ref_count'))->toBe('WARN');
});

test('an orphan vault file is listed and never deleted; a fresh one is given a grace period', function () {
    $old = orphanFile('ab/cd/01a00000-0000-7000-8000-0000000000aa.bin', 2);
    orphanFile('ab/cd/01a00000-0000-7000-8000-0000000000bb.bin', 0);

    $orphans = app(VaultConsistency::class)->orphans();

    expect($orphans)->toContain('ab/cd/01a00000-0000-7000-8000-0000000000aa.bin')
        ->and($orphans)->not->toContain('ab/cd/01a00000-0000-7000-8000-0000000000bb.bin')
        ->and(checkStatus('vault.orphans'))->toBe('WARN')
        ->and(is_file($old))->toBeTrue();
});

test('vault:mark-missing-bytes is a dry run by default and changes nothing', function () {
    $file = consistencyFile($this);
    $file->forceFill(['status' => 'ready'])->save();
    @unlink(Storage::disk($file->disk)->path($file->path));

    $this->artisan('vault:mark-missing-bytes')->assertSuccessful();
    $this->artisan('vault:mark-missing-bytes', ['--dry-run' => true])->assertSuccessful();

    expect($file->fresh()->status)->toBe('ready')
        ->and(FileAccessLog::where('media_file_id', $file->id)->where('action', 'bytes_missing')->count())->toBe(0);
});

test('vault:mark-missing-bytes --apply marks failed, audits once per file and deletes no row', function () {
    $file = consistencyFile($this);
    $healthy = consistencyFile($this);
    $file->forceFill(['status' => 'ready'])->save();
    $healthy->forceFill(['status' => 'ready'])->save();
    @unlink(Storage::disk($file->disk)->path($file->path));

    $this->artisan('vault:mark-missing-bytes', ['--apply' => true])->assertSuccessful();

    expect($file->fresh()->status)->toBe('failed')
        ->and($healthy->fresh()->status)->toBe('ready')
        ->and(FileAccessLog::where('media_file_id', $file->id)->where('action', 'bytes_missing')->count())->toBe(1)
        ->and(MediaFile::withTrashed()->count())->toBe(2);

    // Running it again finds nothing left to mark.
    $this->artisan('vault:mark-missing-bytes', ['--apply' => true])->assertSuccessful();

    expect(FileAccessLog::where('media_file_id', $file->id)->where('action', 'bytes_missing')->count())->toBe(1);
});

test('rows already marked bytes_missing are shown as their own INFO count, not hidden', function () {
    $file = pipelineFile($this->author, $this->oeuvre, 'some bytes', 'a.pdf', 'application/pdf');
    $file->forceFill(['status' => 'failed'])->save();
    @unlink(Storage::disk($file->disk)->path($file->path));

    $checks = app(VaultConsistencyChecks::class)->run();

    expect($checks->firstWhere('key', 'vault.marked_missing')->status)->toBe('INFO')
        ->and($checks->firstWhere('key', 'vault.marked_missing')->value)->toBe('1')
        // ... and it no longer raises the WARN for unresolved rows.
        ->and($checks->firstWhere('key', 'vault.missing_bytes')->status)->toBe('PASS');
});
