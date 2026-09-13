<?php

declare(strict_types=1);

use App\Domain\Access\SignedMediaUrl;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Value\UploadIntent;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function adminMediaTestAuthor(): User
{
    return User::factory()->withRole('author')->create();
}

function adminMediaTestFile(User $author, Work $work, string $status = 'ready'): MediaFile
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
    $mediaFile->sha256_plain = hash('sha256', $bytes);
    $mediaFile->dek_wrapped = $object->dekWrapped;
    $mediaFile->nonce = $object->nonce;
    $mediaFile->mac_path = $object->macPath;
    $mediaFile->status = $status;
    $mediaFile->ref_count = 1;
    $mediaFile->save();

    return $mediaFile;
}

function adminMediaTestCleanup(MediaFile $mediaFile): void
{
    $mediaFile->refresh();
    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
}

/**
 * Encrypts a tiny real JPEG with the file's own DEK exactly the way
 * `GenerateVariants::encryptAndStoreVariant()` does, and writes it to the
 * `variants` disk with a matching `MediaVariant` row — a genuine round trip
 * through the same crypto the controller under test decrypts, not a mock.
 */
function adminMediaTestPosterVariant(MediaFile $mediaFile): MediaVariant
{
    $image = imagecreatetruecolor(20, 20);
    ob_start();
    imagejpeg($image);
    $plaintext = (string) ob_get_clean();
    imagedestroy($image);

    $dek = app(KeyManager::class)->unwrap($mediaFile->dek_wrapped);
    $encKey = HkdfKeys::encryptionKey($dek);
    $nonce = random_bytes(8);
    $ciphertext = app(CtrCipher::class)->transformAt($plaintext, $encKey, $nonce, 0);

    $variantUuid = (string) Str::uuid7();
    $relativePath = substr($mediaFile->uuid, 0, 2).'/'.substr($mediaFile->uuid, 2, 2)."/{$variantUuid}.bin";
    Storage::disk('variants')->put($relativePath, $ciphertext);

    $variant = new MediaVariant;
    $variant->uuid = $variantUuid;
    $variant->media_file_id = $mediaFile->id;
    $variant->kind = 'poster';
    $variant->path = $relativePath;
    $variant->nonce = bin2hex($nonce);
    $variant->size_bytes = strlen($ciphertext);
    $variant->save();

    return $variant;
}

test('an admin can stream another author\'s deposit', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = adminMediaTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = adminMediaTestFile($author, $work);

    $url = SignedMediaUrl::forStreaming($mediaFile, $admin);

    $this->actingAs($admin)->get($url)->assertOk();

    adminMediaTestCleanup($mediaFile);
});

test('a second author cannot stream a deposit they do not own', function () {
    $owner = adminMediaTestAuthor();
    $stranger = adminMediaTestAuthor();
    $work = Work::factory()->create(['author_id' => $owner->id]);
    $mediaFile = adminMediaTestFile($owner, $work);

    $url = SignedMediaUrl::forStreaming($mediaFile, $stranger);

    $this->actingAs($stranger)->get($url)->assertForbidden();

    adminMediaTestCleanup($mediaFile);
});

test('an admin previewing a variant writes a file_access_logs row naming the admin', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = adminMediaTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = adminMediaTestFile($author, $work);
    adminMediaTestPosterVariant($mediaFile);

    $response = $this->actingAs($admin)->get(route('admin.media.variant', ['mediaFile' => $mediaFile, 'kind' => 'poster']));

    $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect(substr($response->getContent(), 0, 2))->toBe("\xFF\xD8");

    $log = FileAccessLog::where('media_file_id', $mediaFile->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('preview')
        ->and($log->user_id)->toBe($admin->id);

    adminMediaTestCleanup($mediaFile);
});

test('a variant preview never touches the vault original', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = adminMediaTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = adminMediaTestFile($author, $work);
    adminMediaTestPosterVariant($mediaFile);

    // Fixture setup above already used the real VaultContract; swap it out
    // now so any attempt by the controller under test to read or decrypt
    // the full original fails the test rather than silently succeeding.
    $this->partialMock(VaultContract::class, function ($mock) {
        $mock->shouldNotReceive('readRange');
        $mock->shouldNotReceive('decryptToTemp');
    });

    $this->actingAs($admin)
        ->get(route('admin.media.variant', ['mediaFile' => $mediaFile, 'kind' => 'poster']))
        ->assertOk();

    adminMediaTestCleanup($mediaFile);
});

test('previewing a variant kind with no stored variant 404s instead of falling back to the original', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = adminMediaTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = adminMediaTestFile($author, $work);

    $this->actingAs($admin)
        ->get(route('admin.media.variant', ['mediaFile' => $mediaFile, 'kind' => 'waveform']))
        ->assertNotFound();

    adminMediaTestCleanup($mediaFile);
});

test('an author cannot reach the admin variant preview endpoint', function () {
    $author = adminMediaTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = adminMediaTestFile($author, $work);
    adminMediaTestPosterVariant($mediaFile);

    $this->actingAs($author)
        ->get(route('admin.media.variant', ['mediaFile' => $mediaFile, 'kind' => 'poster']))
        ->assertForbidden();

    adminMediaTestCleanup($mediaFile);
});
