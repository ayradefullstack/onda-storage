<?php

declare(strict_types=1);

use App\Domain\Vault\Contracts\Scanner;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\UploadIntent;
use App\Jobs\ProcessMediaFile;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Backs P4's manual gate items (a) and (e) at the layer a feature test can
 * actually reach: does `works.show`'s Inertia payload — the exact props
 * `Show.vue` renders `StatusBadge` from — correctly report `ready`,
 * `failed`, and `quarantined` after running the REAL P5 pipeline (not a
 * mocked status string)? This does not prove the browser paints the badge
 * correctly, nor that polling re-fetches it live; those remain unverified
 * except by code reading (Show.vue's poll loop) and, for (a)'s live
 * "watch it happen while a queue worker runs" claim and (d)'s RTL
 * rendering, by manual browser testing only — browser automation is
 * unavailable in this environment (see the phase report).
 */
function worksStatusAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function worksStatusUploadFixture(User $author, Work $work): MediaFile
{
    $vault = app(VaultContract::class);
    $bytes = file_get_contents(base_path('tests/fixtures/sample.mp4'));

    $session = $vault->beginUpload(new UploadIntent($work->id, $author->id, 'sample.mp4', strlen($bytes)));
    $vault->writeChunk($session, 0, $bytes);
    $object = $vault->finalize($session->fresh());

    $mediaFile = new MediaFile;
    $mediaFile->uuid = (string) Str::uuid7();
    $mediaFile->work_id = $work->id;
    $mediaFile->uploaded_by = $author->id;
    $mediaFile->original_name = 'sample.mp4';
    $mediaFile->extension = 'mp4';
    $mediaFile->mime = 'video/mp4';
    $mediaFile->size_bytes = $object->sizeBytes;
    $mediaFile->disk = $object->disk;
    $mediaFile->path = $object->path;
    $mediaFile->sha256_plain = null;
    $mediaFile->dek_wrapped = $object->dekWrapped;
    $mediaFile->nonce = $object->nonce;
    $mediaFile->mac_path = $object->macPath;
    $mediaFile->status = 'scanning';
    $mediaFile->ref_count = 1;
    $mediaFile->save();

    return $mediaFile;
}

function worksStatusCleanup(MediaFile $mediaFile): void
{
    $mediaFile->refresh();
    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
}

test('after the real pipeline reaches ready, works.show reports it verified — not just uploaded', function () {
    $user = worksStatusAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $mediaFile = worksStatusUploadFixture($user, $work);

    // QUEUE_CONNECTION=sync in tests — this runs the whole real chain
    // (DecryptToTemp..CleanupTemp) synchronously, not a mocked transition.
    ProcessMediaFile::dispatch($mediaFile->uuid);

    $this->actingAs($user)
        ->get(route('works.show', $work))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/works/Show')
            ->where('mediaFiles.0.status', 'ready')
            ->where('mediaFiles.0.sha256_plain', hash_file('sha256', base_path('tests/fixtures/sample.mp4')))
            ->where('mediaFiles.0.duration_sec', 2)
            ->where('mediaFiles.0.width', 320)
            ->where('mediaFiles.0.height', 240),
        );

    worksStatusCleanup($mediaFile);
});

test('a file the pipeline fails is reported as failed, not silently as ready', function () {
    $user = worksStatusAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $mediaFile = worksStatusUploadFixture($user, $work);

    // A real, deterministic mid-chain failure (ComputeContentHash's own
    // size-integrity check), not a mocked status write — see P5's
    // FailureAndAuditTest for the same technique.
    $mediaFile->size_bytes += 1;
    $mediaFile->save();

    try {
        ProcessMediaFile::dispatch($mediaFile->uuid);
    } catch (Throwable) {
        // QUEUE_CONNECTION=sync re-throws after calling failed() — expected.
    }

    $this->actingAs($user)
        ->get(route('works.show', $work))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/works/Show')
            ->where('mediaFiles.0.status', 'failed')
            ->where('mediaFiles.0.sha256_plain', null),
        );

    worksStatusCleanup($mediaFile);
});

test('a file the scanner flags is reported as quarantined, not silently as ready', function () {
    Role::findOrCreate('admin'); // ScanForMalware notifies every admin-role user

    app()->bind(Scanner::class, fn () => new class implements Scanner
    {
        public function scan(string $absolutePath): bool
        {
            return false; // infected
        }
    });

    $user = worksStatusAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $mediaFile = worksStatusUploadFixture($user, $work);

    ProcessMediaFile::dispatch($mediaFile->uuid);

    $this->actingAs($user)
        ->get(route('works.show', $work))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/works/Show')
            ->where('mediaFiles.0.status', 'quarantined'),
        );

    worksStatusCleanup($mediaFile);
});

test('a file still in the pipeline is reported honestly as scanning, not as done', function () {
    $user = worksStatusAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $mediaFile = worksStatusUploadFixture($user, $work);

    // No dispatch — the file has been finalized (P3) but P5 hasn't touched
    // it yet, matching the moment right after upload completes.
    $this->actingAs($user)
        ->get(route('works.show', $work))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/works/Show')
            ->where('mediaFiles.0.status', 'scanning')
            ->where('mediaFiles.0.sha256_plain', null),
        );

    worksStatusCleanup($mediaFile);
});
