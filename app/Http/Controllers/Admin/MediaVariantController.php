<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessLogger;
use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Serves a single decrypted, watermarked variant (poster / preview clip /
 * waveform) to the review console — the cheap "form a judgement without
 * downloading anything" path CLAUDE.md's read-path split calls for.
 * Variants are small (a frame, a short clip, an image) by construction
 * (`GenerateVariants`), so — unlike the vault-scale original — decrypting
 * one fully into memory or a scratch temp file is the correct trade-off,
 * not a shortcut: this is exactly what keeps an admin from ever needing to
 * decrypt the multi-gigabyte original just to look at a deposit.
 *
 * Every response is watermarked with the requesting officer's name and a
 * timestamp (see admin-console task: "makes a leak attributable") and every
 * request is written to `file_access_logs` via the same chain the author
 * read-path uses — this is a privileged, logged act, not a casual one.
 */
final class MediaVariantController extends Controller
{
    private const MIME_BY_KIND = [
        'poster' => 'image/jpeg',
        'waveform' => 'image/png',
        'preview' => 'video/mp4',
    ];

    public function show(Request $request, MediaFile $mediaFile, string $kind, KeyManager $keyManager, CtrCipher $cipher): Response
    {
        abort_unless(array_key_exists($kind, self::MIME_BY_KIND), 404);

        /** @var User $admin */
        $admin = $request->user();

        Gate::forUser($admin)->authorize('view', $mediaFile);

        $variant = MediaVariant::where('media_file_id', $mediaFile->id)
            ->where('kind', $kind)
            ->latest('id')
            ->first();

        abort_if($variant === null, 404);

        $ciphertext = Storage::disk('variants')->get($variant->path);

        $nonce = hex2bin($variant->nonce);

        if ($nonce === false) {
            throw new RuntimeException("Malformed nonce for variant [{$variant->uuid}].");
        }

        $dek = $keyManager->unwrap($mediaFile->dek_wrapped);
        $encKey = HkdfKeys::encryptionKey($dek);
        $plaintext = $cipher->transformAt($ciphertext, $encKey, $nonce, 0);

        $watermark = $this->watermarkText($admin);

        $watermarked = $kind === 'preview'
            ? $this->watermarkVideo($plaintext, $mediaFile->uuid, $watermark)
            : $this->watermarkImage($plaintext, $kind, $watermark);

        AccessLogger::record(
            $mediaFile,
            $admin->id,
            'preview',
            $request->ip() ?? 'unknown',
            $request->userAgent(),
            strlen($watermarked),
        );

        return response($watermarked, 200, [
            'Content-Type' => self::MIME_BY_KIND[$kind],
            'Content-Length' => (string) strlen($watermarked),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Latin-only and colon-free by construction — a bitmap GD font can't
     * shape Arabic, and this text is also interpolated into an ffmpeg
     * `drawtext` filtergraph for the preview-clip path, where an
     * unescaped `:` would break the filter syntax.
     */
    private function watermarkText(User $admin): string
    {
        $name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $admin->name) ?? '';

        return trim($name).' - '.now()->format('Y-m-d H-i-s');
    }

    private function watermarkImage(string $plaintext, string $kind, string $text): string
    {
        $image = @imagecreatefromstring($plaintext);

        if ($image === false) {
            throw new RuntimeException("Could not decode '{$kind}' variant image for watermarking.");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $barHeight = 20;

        $bar = imagecolorallocatealpha($image, 0, 0, 0, 40);
        $white = imagecolorallocate($image, 255, 255, 255);

        if ($bar === false || $white === false) {
            throw new RuntimeException("Could not allocate watermark colors for '{$kind}' variant.");
        }

        imagefilledrectangle($image, 0, $height - $barHeight, $width, $height, $bar);
        imagestring($image, 3, 6, $height - $barHeight + 3, $text, $white);

        ob_start();

        if ($kind === 'poster') {
            imagejpeg($image, null, 85);
        } else {
            imagepng($image);
        }

        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function watermarkVideo(string $plaintext, string $mediaFileUuid, string $text): string
    {
        $inputPath = Storage::disk('work')->path("{$mediaFileUuid}-preview-in.mp4");
        $outputPath = Storage::disk('work')->path("{$mediaFileUuid}-preview-out.mp4");

        try {
            file_put_contents($inputPath, $plaintext);

            $escapedText = str_replace(['\\', "'"], ['\\\\', ''], $text);

            $result = Process::timeout((int) config('vault.variant_timeout_seconds'))->run([
                (string) config('vault.ffmpeg_binary'), '-y', '-i', $inputPath,
                '-vf', "drawtext=text='{$escapedText}':x=10:y=h-th-10:fontsize=16:fontcolor=white:box=1:boxcolor=black@0.5:boxborderw=6",
                '-codec:a', 'copy',
                $outputPath,
            ]);

            if (! $result->successful() || ! is_file($outputPath)) {
                throw new RuntimeException("Failed to watermark preview clip for [{$mediaFileUuid}]: {$result->errorOutput()}");
            }

            $bytes = file_get_contents($outputPath);

            if ($bytes === false) {
                throw new RuntimeException("Could not read watermarked preview clip for [{$mediaFileUuid}].");
            }

            return $bytes;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }
}
