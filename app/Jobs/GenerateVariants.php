<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\PipelineWorkspace;
use App\Domain\Deposit\Value\VariantEncryption;
use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Poster frame, 480p/30s preview clip, audio waveform PNG — each generated
 * plaintext with ffmpeg, then encrypted with the file's own DEK before
 * being written to the `variants` disk (small, write-once files — exactly
 * where Flysystem, via `Storage`, is the right tool; see CLAUDE.md). A PDF
 * first-page thumbnail is NOT implemented — no PDF rasterizer (poppler/
 * ghostscript) is installed or requested this phase; skipped like any
 * other unavailable tool, per the same "log and skip, not fatal" rule.
 *
 * `media_variants` has no `nonce` column (frozen) — see
 * `VariantEncryption`'s docblock for how each variant still gets its own,
 * non-reused nonce without one.
 */
final class GenerateVariants extends PipelineJob
{
    public int $timeout = 3600;

    public function handle(): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->firstOrFail();
        $sourcePath = PipelineWorkspace::tempPath($this->mediaFileUuid);

        if (! is_file($sourcePath)) {
            throw new RuntimeException("Expected decrypted temp file for [{$this->mediaFileUuid}] at [{$sourcePath}] but it is missing.");
        }

        if (str_starts_with($mediaFile->mime, 'video/')) {
            $this->tryGenerate($mediaFile, 'poster', 'jpg', fn (string $out) => $this->generatePosterFrame($sourcePath, $out, $mediaFile->duration_sec));
            $this->tryGenerate($mediaFile, 'preview', 'mp4', fn (string $out) => $this->generatePreviewClip($sourcePath, $out));

            return;
        }

        if (str_starts_with($mediaFile->mime, 'audio/')) {
            $this->tryGenerate($mediaFile, 'waveform', 'png', fn (string $out) => $this->generateWaveform($sourcePath, $out));
        }
    }

    private function tryGenerate(MediaFile $mediaFile, string $kind, string $extension, Closure $generate): void
    {
        $stagingPath = Storage::disk('work')->path("{$mediaFile->uuid}-{$kind}.{$extension}");

        try {
            if (! $generate($stagingPath) || ! is_file($stagingPath) || filesize($stagingPath) === 0) {
                Log::info("GenerateVariants: skipped '{$kind}' for [{$mediaFile->uuid}] — tool unavailable or produced no output.");

                return;
            }

            $this->encryptAndStoreVariant($mediaFile, $kind, $stagingPath);
        } catch (Throwable $e) {
            // An individual variant failing is logged and skipped, not fatal.
            Log::warning("GenerateVariants: '{$kind}' failed for [{$mediaFile->uuid}].", ['error' => $e->getMessage()]);
        } finally {
            @unlink($stagingPath);
        }
    }

    private function encryptAndStoreVariant(MediaFile $mediaFile, string $kind, string $plainPath): void
    {
        $dek = app(KeyManager::class)->unwrap($mediaFile->dek_wrapped);
        $encKey = HkdfKeys::encryptionKey($dek);
        $nonce = VariantEncryption::nonceFor($mediaFile->nonce, $kind);

        // Variant files are small (a single frame, a short clip, a
        // waveform image) — safe to hold fully in memory, unlike the
        // vault-scale streaming used for the main file.
        $plaintext = file_get_contents($plainPath);

        if ($plaintext === false) {
            throw new RuntimeException("Could not read generated variant at [{$plainPath}].");
        }

        $ciphertext = app(CtrCipher::class)->transformAt($plaintext, $encKey, $nonce, 0);

        $variantUuid = (string) Str::uuid7();
        $shard1 = substr($mediaFile->uuid, 0, 2);
        $shard2 = substr($mediaFile->uuid, 2, 2);
        $relativePath = "{$shard1}/{$shard2}/{$variantUuid}.bin";

        Storage::disk('variants')->put($relativePath, $ciphertext);

        $variant = new MediaVariant;
        $variant->uuid = $variantUuid;
        $variant->media_file_id = $mediaFile->id;
        $variant->kind = $kind;
        $variant->path = $relativePath;
        $variant->size_bytes = strlen($ciphertext);
        $variant->save();
    }

    private function generatePosterFrame(string $sourcePath, string $outputPath, ?int $durationSec): bool
    {
        $offsetSeconds = $durationSec !== null && $durationSec > 0
            ? max(0.0, $durationSec * (float) config('vault.poster_frame_percent'))
            : 0.0;

        return Process::timeout((int) config('vault.variant_timeout_seconds'))->run([
            (string) config('vault.ffmpeg_binary'), '-y',
            '-ss', (string) $offsetSeconds, '-i', $sourcePath,
            '-frames:v', '1', '-q:v', '2', $outputPath,
        ])->successful();
    }

    private function generatePreviewClip(string $sourcePath, string $outputPath): bool
    {
        return Process::timeout((int) config('vault.variant_timeout_seconds'))->run([
            (string) config('vault.ffmpeg_binary'), '-y', '-i', $sourcePath,
            '-t', (string) config('vault.preview_max_seconds'),
            '-vf', 'scale=-2:'.(string) config('vault.preview_max_height'),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28',
            '-c:a', 'aac', '-b:a', '96k',
            $outputPath,
        ])->successful();
    }

    private function generateWaveform(string $sourcePath, string $outputPath): bool
    {
        return Process::timeout((int) config('vault.variant_timeout_seconds'))->run([
            (string) config('vault.ffmpeg_binary'), '-y', '-i', $sourcePath,
            '-filter_complex', 'showwavespic=s=800x200:colors=white',
            '-frames:v', '1', $outputPath,
        ])->successful();
    }
}
