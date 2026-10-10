<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Consultation\ReadConsultationAsset;
use App\Domain\Vault\Value\ByteRange;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams ONE derivative asset of a consultable file: decrypted in 64 KiB
 * pieces from the `variants` disk, honouring HTTP Range (206) so a video can
 * seek. It can only ever serve a `consultation_assets` row — there is no
 * code path here to the original.
 *
 * As on OeuvreFileReviewController: this protects the original; a displayed
 * derivative can still be captured from devtools or a screenshot.
 *
 * Content-Type comes from a fixed map by derivative kind, never from the
 * file; every response is `inline`, un-sniffable, sandboxed, uncacheable.
 */
final class ConsultationAssetController extends Controller
{
    private const TYPES = [
        'page' => 'image/webp',
        'image' => 'image/webp',
        'poster' => 'image/jpeg',
        'waveform' => 'image/png',
        'video' => 'video/mp4',
        'audio' => 'audio/mpeg',
        'sheet' => 'application/json',
        'text' => 'text/plain; charset=utf-8',
    ];

    public function __invoke(
        Request $request,
        Oeuvre $oeuvre,
        MediaFile $mediaFile,
        string $asset,
        ReadConsultationAsset $reader,
    ): StreamedResponse {
        // Resolved THROUGH the file: an asset uuid of another file is a 404
        // even under a validly signed URL.
        $row = $mediaFile->consultationAssets()->where('uuid', $asset)->firstOrFail();

        $type = self::TYPES[$row->kind] ?? null;
        abort_if($type === null, 404);

        $range = ByteRange::fromHeader($request->header('Range'), $row->size_bytes);
        $length = $range?->length() ?? $row->size_bytes;

        $headers = [
            'Content-Type' => $type,
            'Content-Length' => (string) $length,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'Accept-Ranges' => 'bytes',
        ];

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$range->start}-{$range->end}/{$row->size_bytes}";
        }

        return response()->stream(
            function () use ($reader, $mediaFile, $row, $range): void {
                foreach ($reader->stream($mediaFile, $row, $range) as $piece) {
                    echo $piece;
                    flush();
                }
            },
            $range !== null ? 206 : 200,
            $headers,
        );
    }
}
