<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Consultation\AuditConsultation;
use App\Actions\Consultation\ConsultationDescriptor;
use App\Actions\Review\DecideMediaFile;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Full-page review of one deposited file, opened in its own tab, plus the
 * JSON descriptor the side Viewer uses.
 *
 * WHAT THIS PROTECTS, AND WHAT IT DOES NOT. An admin only ever receives a
 * server-generated, encrypted-at-rest DERIVATIVE through short-lived URLs
 * bound to the issuing admin's session; no route reachable by an admin
 * streams, embeds or redirects to the original bytes. That protects the
 * ORIGINAL. It is not DRM: a derivative that a browser displays can still be
 * captured from devtools or a screenshot — the CSS watermark and the
 * disabled context menu are deterrents that make a leak attributable, not
 * barriers.
 *
 * Visibility is exactly `OeuvrePolicy::inspect` — the rule that already
 * governs the admin oeuvre page — and the media file is resolved THROUGH the
 * oeuvre (scoped bindings), so a file of another oeuvre is a 404.
 */
final class OeuvreFileReviewController extends Controller
{
    public function show(
        Request $request,
        Oeuvre $oeuvre,
        MediaFile $mediaFile,
        ConsultationDescriptor $descriptor,
        AuditConsultation $audit,
    ): Response {
        /** @var User $admin */
        $admin = $request->user();

        Gate::forUser($admin)->authorize('inspect', $oeuvre);

        $audit->handle($mediaFile, $admin, $request, 'open');

        $files = $oeuvre->mediaFiles()
            ->with('collegeOeuvreFile')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/oeuvres/FileReview', [
            'oeuvre' => [
                'uuid' => $oeuvre->uuid,
                'title' => $oeuvre->title,
                'created_at' => $oeuvre->created_at,
                'status' => $oeuvre->status,
            ],
            'file' => [
                'uuid' => $mediaFile->uuid,
                'original_name' => $mediaFile->original_name,
                'extension' => $mediaFile->extension,
                'status' => $mediaFile->status,
                'size_bytes' => $mediaFile->size_bytes,
                'mime' => $mediaFile->mime,
                'sha256_plain' => $mediaFile->sha256_plain,
                // The officer's decision on this file, and what may be done
                // to it now (server-computed; DecideMediaFile re-checks).
                'review_status' => $mediaFile->review_status,
                'review_reason' => $mediaFile->review_reason,
                'allowed' => DecideMediaFile::allowed($oeuvre, $mediaFile, $admin),
                // A replacement uploaded during review: what it replaces, and
                // why that file was rejected.
                'replaces' => $mediaFile->replaces === null ? null : [
                    'original_name' => $mediaFile->replaces->original_name,
                    'review_reason' => $mediaFile->replaces->review_reason,
                ],
            ],
            'siblings' => $files->map(fn (MediaFile $sibling): array => [
                'uuid' => $sibling->uuid,
                'original_name' => $sibling->original_name,
                'slot' => $sibling->collegeOeuvreFile?->title,
                // For "Suivant à examiner": the next sibling still pending.
                'review_status' => $sibling->review_status,
            ])->values(),
            // Re-evaluated on every partial reload (`only: ['consultation']`),
            // which is what the pending poll uses.
            'consultation' => fn (): array => $descriptor->handle($oeuvre, $mediaFile, $admin),
        ]);
    }

    /**
     * Fresh descriptors: the side Viewer's source, and what a player calls
     * after a signed URL expires mid-playback. Issuing is audited once per
     * file per window; individual asset fetches are not.
     */
    public function assets(
        Request $request,
        Oeuvre $oeuvre,
        MediaFile $mediaFile,
        ConsultationDescriptor $descriptor,
        AuditConsultation $audit,
    ): JsonResponse {
        /** @var User $admin */
        $admin = $request->user();

        Gate::forUser($admin)->authorize('inspect', $oeuvre);

        $audit->handle($mediaFile, $admin, $request, 'issue');

        return response()->json(['consultation' => $descriptor->handle($oeuvre, $mediaFile, $admin)])
            ->header('Cache-Control', 'private, no-store');
    }
}
