<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Infrastructure\Render\RenderedAsset;
use App\Models\ConsultationAsset;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encrypts one derivative with the file's own DEK and a FRESH random 8-byte
 * nonce (stored, never derived: regenerating under the same DEK with a reused
 * nonce would be AES-CTR keystream reuse), then writes it to the `variants`
 * disk. Streams in 64 KiB blocks — a full-length 480p video can be hundreds
 * of MB and is never held in memory. Plaintext derivatives live only in the
 * job workspace and are deleted here once stored.
 *
 * A poster / waveform that GenerateVariants already produced is referenced,
 * not copied: the row points at the variant's own file and nonce.
 */
final class StoreConsultationAsset
{
    public function __construct(
        private readonly KeyManager $keys,
        private readonly CtrCipher $cipher,
    ) {}

    /**
     * @param  string  $workspace  where the temporary ciphertext copy is staged
     */
    public function handle(MediaFile $mediaFile, RenderedAsset $asset, string $workspace): ConsultationAsset
    {
        if ($asset->variantKind !== null) {
            return $this->reference($mediaFile, $asset);
        }

        if ($asset->path === null || ! is_file($asset->path)) {
            throw new RuntimeException("Derivative [{$asset->kind}] has no staged file.");
        }

        $encKey = HkdfKeys::encryptionKey($this->keys->unwrap($mediaFile->dek_wrapped));
        $nonce = random_bytes(8);

        $encryptedPath = $workspace.DIRECTORY_SEPARATOR.Str::uuid7().'.enc';
        $in = fopen($asset->path, 'rb');
        $out = fopen($encryptedPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Could not open derivative for encryption.');
        }

        try {
            $this->cipher->streamTransform($in, $out, $encKey, $nonce, 0);
        } finally {
            fclose($in);
            fclose($out);
        }

        $size = (int) filesize($encryptedPath);
        $uuid = (string) Str::uuid7();
        $relative = substr($mediaFile->uuid, 0, 2).'/'.substr($mediaFile->uuid, 2, 2)."/c-{$uuid}.bin";

        $stream = fopen($encryptedPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not reopen encrypted derivative.');
        }

        try {
            Storage::disk('variants')->writeStream($relative, $stream);
        } finally {
            fclose($stream);
            @unlink($encryptedPath);
            @unlink($asset->path);
        }

        $row = new ConsultationAsset;
        $row->uuid = $uuid;
        $row->media_file_id = $mediaFile->id;
        $row->kind = $asset->kind;
        $row->page_index = $asset->pageIndex;
        $row->path = $relative;
        $row->nonce = bin2hex($nonce);
        $row->size_bytes = $size;
        $row->is_shared_variant = false;
        $row->meta = $asset->meta === [] ? null : $asset->meta;
        $row->save();

        return $row;
    }

    private function reference(MediaFile $mediaFile, RenderedAsset $asset): ConsultationAsset
    {
        $variant = MediaVariant::where('media_file_id', $mediaFile->id)
            ->where('kind', $asset->variantKind)
            ->latest('id')
            ->first();

        if ($variant === null) {
            throw new RuntimeException("Variant [{$asset->variantKind}] vanished before it could be referenced.");
        }

        $row = new ConsultationAsset;
        $row->media_file_id = $mediaFile->id;
        $row->kind = $asset->kind;
        $row->page_index = $asset->pageIndex;
        $row->path = $variant->path;
        $row->nonce = $variant->nonce;
        $row->size_bytes = $variant->size_bytes;
        $row->is_shared_variant = true;
        $row->meta = ['variant_kind' => $variant->kind];
        $row->save();

        return $row;
    }
}
