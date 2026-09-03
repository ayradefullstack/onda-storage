<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Upload\AbortUpload;
use App\Actions\Upload\CompleteUpload;
use App\Actions\Upload\InitUpload;
use App\Actions\Upload\StoreChunk;
use App\Domain\Vault\Contracts\ChunkTracker;
use App\Http\Requests\CompleteUploadRequest;
use App\Http\Requests\InitUploadRequest;
use App\Models\UploadSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Thin dispatch only — every check that isn't "does this request map to
 * this action" lives in a Policy or an Action. `{session:uuid}` route model
 * binding resolves the model before any method here runs (a missing/foreign
 * uuid 404s automatically, never reaching authorization).
 */
final class UploadController extends Controller
{
    public function init(InitUploadRequest $request, InitUpload $action): JsonResponse
    {
        $session = $action->handle(
            user: $request->user(),
            workId: (int) $request->validated('work_id'),
            filename: (string) $request->validated('filename'),
            sizeBytes: (int) $request->validated('size_bytes'),
            mime: (string) $request->validated('mime'),
        );

        return response()->json([
            'uuid' => $session->uuid,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'received' => [],
            'expires_at' => $session->expires_at,
        ], 201);
    }

    public function status(Request $request, UploadSession $session, ChunkTracker $tracker): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $session);

        return response()->json([
            'uuid' => $session->uuid,
            'status' => $session->status,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'received' => $tracker->receivedMask($session->uuid),
            'received_bytes' => $session->received_bytes,
            'expires_at' => $session->expires_at,
        ]);
    }

    public function chunk(Request $request, UploadSession $session, int $index, StoreChunk $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $session);

        return response()->json($action->handle($session, $index, $request));
    }

    public function complete(CompleteUploadRequest $request, UploadSession $session, CompleteUpload $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $session);

        $mediaFile = $action->handle($session);

        return response()->json([
            'uuid' => $mediaFile->uuid,
            'status' => $mediaFile->status,
        ], 201);
    }

    public function abort(Request $request, UploadSession $session, AbortUpload $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $session);

        $action->handle($session);

        return response()->json([
            'uuid' => $session->uuid,
            'status' => 'aborted',
        ]);
    }
}
