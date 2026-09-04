<?php

declare(strict_types=1);

use App\Domain\Deposit\PipelineWorkspace;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Value\UploadIntent;
use App\Jobs\DecryptToTemp;
use App\Jobs\GenerateVariants;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Each variant now gets a fresh random nonce (media_variants.nonce) instead
 * of one derived deterministically from the file's own nonce plus the
 * variant kind (the old VariantEncryption::nonceFor()). The deterministic
 * scheme was safe across variants and against the main file, but not across
 * two generations of the SAME variant: vault:reprocess regenerates a variant
 * under the same DEK, and a deterministic nonce would reuse the same
 * keystream over different plaintext (a different ffmpeg build, a different
 * frame) — the AES-256-CTR keystream reuse CLAUDE.md prohibits.
 */
function variantEncryptionAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function variantEncryptionFixturePath(): string
{
    return base_path('tests/fixtures/sample.mp4');
}

function variantEncryptionUploadFixture(User $author, Work $work): MediaFile
{
    $vault = app(VaultContract::class);
    $bytes = file_get_contents(variantEncryptionFixturePath());

    $session = $vault->beginUpload(new UploadIntent($work->id, $author->id, 'sample-'.Str::random(8).'.mp4', strlen($bytes)));
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

function variantEncryptionDecrypt(MediaFile $mediaFile, MediaVariant $variant): string
{
    $dek = app(KeyManager::class)->unwrap($mediaFile->dek_wrapped);
    $encKey = HkdfKeys::encryptionKey($dek);
    $ciphertext = Storage::disk('variants')->get($variant->path);

    return app(CtrCipher::class)->transformAt($ciphertext, $encKey, hex2bin($variant->nonce), 0);
}

function variantEncryptionCleanup(MediaFile $mediaFile, array $variants): void
{
    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));

    foreach ($variants as $variant) {
        Storage::disk('variants')->delete($variant->path);
    }

    PipelineWorkspace::delete($mediaFile->uuid);
}

test('regenerating the same variant twice produces two different ciphertexts under two different random nonces', function () {
    if (config('vault.media_probe_driver') !== 'ffmpeg') {
        $this->markTestSkipped('ffmpeg not available — real variant generation cannot be exercised.');
    }

    $author = variantEncryptionAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = variantEncryptionUploadFixture($author, $work);

    (new DecryptToTemp($mediaFile->uuid))->handle(app(VaultContract::class));

    // Runs the whole job twice — exactly what vault:reprocess does on a
    // single media file: same DEK, same source plaintext, two separate
    // generations of the same 'poster' variant.
    (new GenerateVariants($mediaFile->uuid))->handle();
    (new GenerateVariants($mediaFile->uuid))->handle();

    $posters = MediaVariant::where('media_file_id', $mediaFile->id)->where('kind', 'poster')->orderBy('id')->get();
    expect($posters)->toHaveCount(2);

    [$first, $second] = $posters;

    expect($first->nonce)->not->toBe($second->nonce);

    $firstCiphertext = Storage::disk('variants')->get($first->path);
    $secondCiphertext = Storage::disk('variants')->get($second->path);
    expect($firstCiphertext)->not->toBe($secondCiphertext);

    // Both still decrypt correctly under their own nonce — the random nonce
    // is stored, not just generated and discarded.
    expect(variantEncryptionDecrypt($mediaFile, $first))->toBe(variantEncryptionDecrypt($mediaFile, $second));

    variantEncryptionCleanup($mediaFile, $posters->all());
    MediaVariant::where('media_file_id', $mediaFile->id)->delete();
});
