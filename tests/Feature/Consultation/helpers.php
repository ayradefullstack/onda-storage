<?php

declare(strict_types=1);

use App\Actions\Consultation\GenerateDerivative;
use App\Actions\Consultation\ReadConsultationAsset;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Value\UploadIntent;
use App\Infrastructure\Render\ToolLocator;
use App\Models\ConsultationAsset;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function consultAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

function consultAuthor(): User
{
    return User::factory()->withRole('author')->create();
}

/**
 * A real deposited file: the bytes go through the REAL VaultContract (the
 * same pre-allocate / write / finalize path an upload uses), so every test
 * proves the round trip through the actual crypto, not a mocked row.
 */
function consultFile(User $author, Oeuvre $oeuvre, string $bytes, string $name, string $mime, string $status = 'ready'): MediaFile
{
    $vault = app(VaultContract::class);

    $session = $vault->beginUpload(new UploadIntent($oeuvre->id, $author->id, $name, max(1, strlen($bytes))));
    $vault->writeChunk($session, 0, $bytes);
    $object = $vault->finalize($session->fresh());

    $mediaFile = new MediaFile;
    $mediaFile->uuid = (string) Str::uuid7();
    $mediaFile->oeuvre_id = $oeuvre->id;
    $mediaFile->uploaded_by = $author->id;
    $mediaFile->original_name = $name;
    $mediaFile->extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mediaFile->mime = $mime;
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

function consultFixture(string $relative): string
{
    return (string) file_get_contents(base_path('tests/fixtures/'.$relative));
}

/**
 * Removes the vault bytes, the MAC sidecar and every derivative file a test
 * left on disk. Shared poster/waveform variants are removed via their own row.
 */
function consultCleanup(MediaFile ...$files): void
{
    foreach ($files as $mediaFile) {
        foreach (ConsultationAsset::where('media_file_id', $mediaFile->id)->get() as $asset) {
            if (! $asset->is_shared_variant) {
                @unlink(Storage::disk('variants')->path($asset->path));
            }
        }

        foreach (MediaVariant::where('media_file_id', $mediaFile->id)->get() as $variant) {
            @unlink(Storage::disk('variants')->path($variant->path));
        }

        $fresh = MediaFile::withTrashed()->find($mediaFile->id);

        if ($fresh !== null) {
            @unlink(Storage::disk($fresh->disk)->path($fresh->path));
            @unlink(Storage::disk($fresh->disk)->path($fresh->mac_path));
        }
    }
}

function consultGenerate(MediaFile $mediaFile, bool $force = false): MediaConsultation
{
    return app(GenerateDerivative::class)->handle($mediaFile, $force);
}

function consultAssetRow(MediaFile $mediaFile, string $kind, ?int $page = null): ?ConsultationAsset
{
    return $mediaFile->consultationAssets()->where('kind', $kind)->where('page_index', $page)->first();
}

/**
 * Decrypts a stored derivative back to its plaintext through the same reader
 * the controller uses.
 */
function consultPlain(MediaFile $mediaFile, ConsultationAsset $asset): string
{
    $out = '';

    foreach (app(ReadConsultationAsset::class)->stream($mediaFile, $asset, null) as $piece) {
        $out .= $piece;
    }

    return $out;
}

/**
 * Stores a poster the way GenerateVariants does: encrypted with the file DEK
 * and a random stored nonce, as a `media_variants` row.
 */
function consultVariant(MediaFile $mediaFile, string $kind, string $plaintext): MediaVariant
{
    $encKey = HkdfKeys::encryptionKey(app(KeyManager::class)->unwrap($mediaFile->dek_wrapped));
    $nonce = random_bytes(8);
    $ciphertext = app(CtrCipher::class)->transformAt($plaintext, $encKey, $nonce, 0);

    $uuid = (string) Str::uuid7();
    $path = substr($mediaFile->uuid, 0, 2).'/'.substr($mediaFile->uuid, 2, 2)."/{$uuid}.bin";
    Storage::disk('variants')->put($path, $ciphertext);

    $variant = new MediaVariant;
    $variant->uuid = $uuid;
    $variant->media_file_id = $mediaFile->id;
    $variant->kind = $kind;
    $variant->path = $path;
    $variant->nonce = bin2hex($nonce);
    $variant->size_bytes = strlen($ciphertext);
    $variant->save();

    return $variant;
}

function consultBinary(string $configKey): ?string
{
    return app(ToolLocator::class)->find($configKey);
}
