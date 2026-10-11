<?php

declare(strict_types=1);

use App\Domain\Deposit\VaultConsistency;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->author = pipelineAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
});

test('vault:quarantine-orphans is a dry run by default and moves nothing', function () {
    $bin = orphanFile(ORPHAN_A.'.bin', 30);
    $mac = orphanFile(ORPHAN_A.'.mac', 30);

    $this->artisan('vault:quarantine-orphans')->assertSuccessful();

    expect(is_file($bin))->toBeTrue()
        ->and(is_file($mac))->toBeTrue()
        ->and(is_dir($this->storageRoot.'/orphans'))->toBeFalse();
});

test('--apply moves an old unreferenced pair into orphans/{date}/ with its layout, and deletes nothing', function () {
    $bin = orphanFile(ORPHAN_A.'.bin', 30);
    $mac = orphanFile(ORPHAN_A.'.mac', 30);
    $recent = orphanFile(ORPHAN_B.'.bin', 0);

    $this->artisan('vault:quarantine-orphans', ['--apply' => true, '--older-than' => 7])->assertSuccessful();

    $target = $this->storageRoot.'/orphans/'.now()->format('Y-m-d');

    expect(is_file($bin))->toBeFalse()
        ->and(is_file($mac))->toBeFalse()
        ->and(is_file($target.'/'.ORPHAN_A.'.bin'))->toBeTrue()
        ->and(is_file($target.'/'.ORPHAN_A.'.mac'))->toBeTrue()
        // Too young (and inside the mid-upload grace period): left alone.
        ->and(is_file($recent))->toBeTrue();
});

test('a referenced file, and anything outside the sharded layout, is never moved', function () {
    $file = pipelineFile($this->author, $this->oeuvre, 'referenced bytes', 'a.pdf', 'application/pdf');
    $disk = Storage::disk('vault');
    touch($disk->path($file->path), time() - 90 * 86400);
    touch($disk->path($file->mac_path), time() - 90 * 86400);
    $infected = orphanFile('quarantine/infected-sample.bin', 90);

    $this->artisan('vault:quarantine-orphans', ['--apply' => true])->assertSuccessful();

    expect(is_file($disk->path($file->path)))->toBeTrue()
        ->and(is_file($disk->path($file->mac_path)))->toBeTrue()
        ->and(is_file($infected))->toBeTrue();
});

test('vault:recount-refs is a dry run by default, then --apply corrects the counts', function () {
    $file = pipelineFile($this->author, $this->oeuvre, 'some bytes', 'a.pdf', 'application/pdf');
    $file->forceFill(['ref_count' => 7])->save();

    $this->artisan('vault:recount-refs')->assertSuccessful();
    expect($file->fresh()->ref_count)->toBe(7);

    $this->artisan('vault:recount-refs', ['--apply' => true])->assertSuccessful();
    expect($file->fresh()->ref_count)->toBe(1)
        ->and(app(VaultConsistency::class)->refCountMismatches())->toHaveCount(0);
});
