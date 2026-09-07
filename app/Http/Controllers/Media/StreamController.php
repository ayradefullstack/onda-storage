<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domain\Access\AccessLogger;
use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Exceptions\MacVerificationFailed;
use App\Domain\Vault\Value\ByteRange;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ~95% read path (CLAUDE.md): decrypt on the fly with Range support,
 * never buffer the whole file. The four access-control layers, in order —
 * every one is required, none is a substitute for another:
 *
 * 1. `signed` route middleware — the URL wasn't tampered with, and hasn't
 *    outlived its 15-minute TTL.
 * 2. `auth`/`role:author` route middleware — the requester is a logged-in
 *    author.
 * 3. `MediaFilePolicy::view` — this specific author owns the work.
 * 4. The `u` query param — this link was issued to *this* session's user,
 *    not merely to *an* authorized one (see `SignedMediaUrl`).
 *
 * Only once all four pass does a single byte leave the vault, and the
 * attempt is logged regardless of how it ends.
 */
final class StreamController extends Controller
{
    public function __invoke(Request $request, MediaFile $mediaFile, VaultContract $vault): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && (int) $request->query('u') === $user->id,
            403,
            'This link was issued to a different session.',
        );

        Gate::forUser($user)->authorize('view', $mediaFile);

        if ($mediaFile->status !== MediaFileStatus::READY) {
            abort(404);
        }

        $range = ByteRange::fromHeader($request->header('Range'), $mediaFile->size_bytes);
        $object = StoredObjectMapper::fromMediaFile($mediaFile);

        try {
            $stream = $vault->readRange($object, $range);
        } catch (MacVerificationFailed $e) {
            AccessLogger::record(
                $mediaFile,
                $user->id,
                'stream',
                $request->ip() ?? 'unknown',
                $request->userAgent(),
                null,
                $request->header('Range'),
            );

            abort(500, 'This deposit failed an integrity check and could not be read.');
        }

        $contentLength = $range?->length() ?? $mediaFile->size_bytes;

        AccessLogger::record(
            $mediaFile,
            $user->id,
            'stream',
            $request->ip() ?? 'unknown',
            $request->userAgent(),
            $contentLength,
            $request->header('Range'),
        );

        $headers = [
            'Content-Type' => $mediaFile->mime,
            'Content-Length' => (string) $contentLength,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
        ];

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$range->start}-{$range->end}/{$mediaFile->size_bytes}";
        }

        return response()->stream(
            function () use ($stream): void {
                while (! $stream->eof()) {
                    echo $stream->read(65536);
                    flush();
                }
            },
            $range !== null ? 206 : 200,
            $headers,
        );
    }
}
