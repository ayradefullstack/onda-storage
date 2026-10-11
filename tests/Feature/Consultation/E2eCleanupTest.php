<?php

declare(strict_types=1);

use App\Domain\Deposit\VaultConsistency;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Pipeline/helpers.php';

beforeEach(function () {
    $this->author = pipelineAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
});

test('e2e:cleanup purges only the e2e author\'s e2e-* deposits, frees their bytes and returns the quota', function () {
    $e2e = User::factory()->withRole('author')->create(['email' => 'author1@onda.dz']);
    $oeuvre = Oeuvre::factory()->create(['author_id' => $e2e->id]);
    $mine = pipelineFile($e2e, $oeuvre, 'e2e upload bytes', 'e2e-abcd-large.mp4', 'video/mp4');
    $real = pipelineFile($e2e, $oeuvre, 'a real deposit by the same author', 'contract.pdf', 'application/pdf');
    $someoneElses = pipelineFile($this->author, $this->oeuvre, 'other', 'e2e-lookalike.mp4', 'video/mp4');
    StorageQuota::factory()->create(['user_id' => $e2e->id, 'limit_bytes' => 10 ** 9, 'used_bytes' => $mine->size_bytes + $real->size_bytes]);
    $disk = Storage::disk('vault');

    $this->artisan('e2e:cleanup')->assertSuccessful();
    expect(is_file($disk->path($mine->path)))->toBeTrue();

    $this->artisan('e2e:cleanup', ['--apply' => true])->assertSuccessful();

    expect(is_file($disk->path($mine->path)))->toBeFalse()
        ->and($mine->fresh()?->purged_at ?? MediaFile::withTrashed()->find($mine->id)->purged_at)->not->toBeNull()
        ->and(is_file($disk->path($real->path)))->toBeTrue()
        ->and(is_file($disk->path($someoneElses->path)))->toBeTrue()
        ->and(StorageQuota::where('user_id', $e2e->id)->value('used_bytes'))->toBe($real->size_bytes)
        ->and(MediaFile::withTrashed()->find($real->id)->purged_at)->toBeNull();
});

test('e2e:cleanup keeps bytes that another row still references', function () {
    $e2e = User::factory()->withRole('author')->create(['email' => 'author1@onda.dz']);
    $oeuvre = Oeuvre::factory()->create(['author_id' => $e2e->id]);
    $mine = pipelineFile($e2e, $oeuvre, 'shared bytes', 'e2e-shared.pdf', 'application/pdf');
    $other = pipelineFile($this->author, $this->oeuvre, 'shared bytes', 'shared.pdf', 'application/pdf');
    // The other deposit was deduplicated onto the e2e file's bytes.
    $other->forceFill(['disk' => $mine->disk, 'path' => $mine->path, 'mac_path' => $mine->mac_path, 'dek_wrapped' => $mine->dek_wrapped, 'nonce' => $mine->nonce])->save();

    $this->artisan('e2e:cleanup', ['--apply' => true])->assertSuccessful();

    expect(is_file(Storage::disk('vault')->path($mine->path)))->toBeTrue()
        ->and(app(VaultConsistency::class)->bytesIntact($other->fresh()))->toBeTrue();
});
