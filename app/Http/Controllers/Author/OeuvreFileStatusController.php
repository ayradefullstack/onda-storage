<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Domain\Deposit\MediaFileStatus;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Where each file of one oeuvre stands in the server pipeline: what the
 * author's oeuvre page polls between the upload's `complete` call and the
 * file being sealed (or failing), so the progress rail follows the queue
 * live instead of waiting for a page refresh.
 *
 * Deliberately tiny: one query on `media_files`, no gate, no counts. The
 * page reloads its heavier props (file list, submission gate, progress)
 * once, when a file reaches a terminal status.
 *
 * Owner only, and anyone else gets a 404, never a 403: a probe does not
 * learn that the oeuvre exists.
 */
final class OeuvreFileStatusController extends Controller
{
    /**
     * The rail step each status puts a file on — the same keys, in the same
     * order, as the rail itself (`upload.step.*`). The pipeline writes no
     * per-job progress today: `CompleteUpload` sets `scanning` and the chain
     * only writes again at its end (`ready`, `quarantined` or `failed`). So
     * `processing` is listed for completeness but is never observed.
     */
    public const STAGES = [
        MediaFileStatus::UPLOADING => 'upload',
        MediaFileStatus::ASSEMBLING => 'assembly',
        MediaFileStatus::SCANNING => 'antivirus',
        MediaFileStatus::PROCESSING => 'processing',
        MediaFileStatus::READY => 'sealed',
    ];

    /** A terminal failure's message, as a locale key the page already has. */
    public const ERRORS = [
        MediaFileStatus::FAILED => 'media.failedWithRef',
        MediaFileStatus::QUARANTINED => 'media.quarantineWithRef',
    ];

    public function __invoke(Request $request, Oeuvre $oeuvre): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $oeuvre), 404);

        $files = $oeuvre->mediaFiles()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['uuid', 'status'])
            ->map(fn (MediaFile $file): array => [
                'id' => $file->uuid,
                'status' => $file->status,
                'stage' => self::STAGES[$file->status] ?? null,
                'error' => self::ERRORS[$file->status] ?? null,
            ])
            ->values();

        return response()->json(['files' => $files])
            ->header('Cache-Control', 'private, no-store');
    }
}
