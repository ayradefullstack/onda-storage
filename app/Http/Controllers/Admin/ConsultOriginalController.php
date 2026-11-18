<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Consultation\ConsultRange;
use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Exceptions\MacVerificationFailed;
use App\Domain\Vault\Value\ByteRange;
use App\Http\Controllers\Controller;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * CONSULTATION, NOT DOWNLOAD. Streams the decrypted ORIGINAL of a PDF, DOCX,
 * video or audio file inline, so the admin's browser can render it itself
 * (PDF.js, docx-preview, <video>, <audio>). It exists because production has
 * no ffmpeg / poppler / LibreOffice. Approved trade-off: the admin is a sworn,
 * authenticated official, the URL is signed, short-lived and bound to their
 * session, and every issuance is written to the audit chain. The original
 * still reaches the browser and can be saved from devtools.
 *
 * Gate (EnsureConsultationAccess, before this runs): signed, admin, `admin`
 * param == auth id, consultable oeuvre, the file belongs to it (scoped
 * binding: another oeuvre's file is a 404), consultation `ready`, and the
 * family's delivery mode is `original_inline` (otherwise 404).
 *
 * Reading: `VaultContract::readRange` (native fseek + CtrCipher + MAC check)
 * in 1 MiB calls — never `Storage::get`, never `readfile`, never the whole
 * file in memory. Every answer is a 206 capped at `vault.consult.range_max_bytes`
 * (see ConsultRange); `media.link` / `media.stream` stay owner-only.
 */
final class ConsultOriginalController extends Controller
{
    private const PIECE = 1024 * 1024;

    public function __invoke(
        Request $request,
        Oeuvre $oeuvre,
        MediaFile $mediaFile,
        VaultContract $vault,
        PreviewFamilyResolver $resolver,
    ): Response|StreamedResponse {
        $mime = $resolver->inlineMime($mediaFile);

        abort_if($mime === null, 404);
        abort_if(
            strtolower($mediaFile->extension) === 'docx'
                && $mediaFile->size_bytes > (int) config('vault.consult.docx_max_bytes'),
            404,
        );

        $total = $mediaFile->size_bytes;
        $range = ConsultRange::resolve($request->header('Range'), $total, (int) config('vault.consult.range_max_bytes'));

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="consultation.'.preg_replace('/[^a-z0-9]/', '', strtolower($mediaFile->extension)).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Accept-Ranges' => 'bytes',
        ];

        if ($range === null) {
            return response('', 416, $headers + ['Content-Range' => "bytes */{$total}"]);
        }

        $headers['Content-Length'] = (string) $range->length();
        $headers['Content-Range'] = "bytes {$range->start}-{$range->end}/{$total}";

        // HEAD: the headers of the GET, no body, and no vault read.
        if ($request->isMethod('HEAD')) {
            return response('', 206, $headers);
        }

        $object = StoredObjectMapper::fromMediaFile($mediaFile);

        // The first piece is read BEFORE any header is sent: a MAC failure
        // (tampered bytes) is then a clean 500, not a truncated 206.
        $first = new ByteRange($range->start, min($range->end, $range->start + self::PIECE - 1));

        try {
            $firstStream = $vault->readRange($object, $first);
        } catch (MacVerificationFailed) {
            abort(500, 'This deposit failed an integrity check and could not be read.');
        }

        return response()->stream(
            function () use ($vault, $object, $range, $first, $firstStream): void {
                $stream = $firstStream;
                $piece = $first;

                while (true) {
                    while (! $stream->eof()) {
                        echo $stream->read(65536);
                        flush();
                    }

                    $stream->close();

                    if ($piece->end >= $range->end) {
                        return;
                    }

                    $piece = new ByteRange($piece->end + 1, min($range->end, $piece->end + self::PIECE));

                    try {
                        $stream = $vault->readRange($object, $piece);
                    } catch (Throwable $e) {
                        // Headers are gone: the body stops short and the
                        // client's Content-Length check fails the response.
                        Log::error('Consult original stream aborted.', ['error' => $e->getMessage()]);

                        return;
                    }
                }
            },
            206,
            $headers,
        );
    }
}
