<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Domain\Quota\QuotaPolicy;
use App\Http\Controllers\Controller;
use App\Models\StorageQuota;
use App\Models\Work;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin page-route dispatch for the works UI (P4). No upload logic lives
 * here — that's the P3 UploadController; this only lists/creates/shows
 * `Work` records and hands the P3 session data to the page so the upload
 * store can act on it.
 */
final class WorkController extends Controller
{
    public function index(Request $request): Response
    {
        $works = Work::query()
            ->where('author_id', $request->user()->id)
            ->withCount('mediaFiles')
            ->latest()
            ->get(['id', 'uuid', 'title', 'description', 'status', 'created_at']);

        return Inertia::render('author/works/Index', [
            'works' => $works,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('author/works/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $work = new Work;
        $work->author_id = $request->user()->id;
        $work->title = $validated['title'];
        $work->description = $validated['description'] ?? null;
        $work->status = 'draft';
        $work->save();

        return to_route('works.show', $work);
    }

    /**
     * Also the status-polling target: the frontend re-requests this same
     * page via Inertia's partial-reload mechanism (only: ['mediaFiles']) on
     * a backing-off interval rather than a bespoke JSON endpoint — it's the
     * same data, and Inertia already knows how to fetch just that prop.
     */
    public function show(Request $request, Work $work): Response
    {
        Gate::forUser($request->user())->authorize('view', $work);

        $quota = StorageQuota::where('user_id', $request->user()->id)->first();

        return Inertia::render('author/works/Show', [
            // 'id' (the numeric FK) is included deliberately — the P3
            // InitUpload endpoint takes work_id as an integer, and this is
            // a page prop, not a URL, so it doesn't touch the
            // never-expose-sequential-ids-in-URLs rule.
            'work' => $work->only(['id', 'uuid', 'title', 'description', 'status', 'created_at']),
            // Read-only: lets the upload UI show remaining quota before a
            // file consumes it. No row yet means the same "unlimited until
            // the default allocation" convention QuotaPolicy already uses.
            'quota' => [
                'used_bytes' => $quota->used_bytes ?? 0,
                'limit_bytes' => $quota->limit_bytes ?? QuotaPolicy::DEFAULT_LIMIT_BYTES,
            ],
            'mediaFiles' => $work->mediaFiles()
                ->orderBy('created_at')
                ->get()
                ->map(fn ($mediaFile) => [
                    'uuid' => $mediaFile->uuid,
                    'original_name' => $mediaFile->original_name,
                    'extension' => $mediaFile->extension,
                    'mime' => $mediaFile->mime,
                    'size_bytes' => $mediaFile->size_bytes,
                    'status' => $mediaFile->status,
                    'duration_sec' => $mediaFile->duration_sec,
                    'width' => $mediaFile->width,
                    'height' => $mediaFile->height,
                    'sha256_plain' => $mediaFile->sha256_plain,
                    'variant_count' => $mediaFile->variants()->count(),
                    'created_at' => $mediaFile->created_at,
                ]),
        ]);
    }
}
