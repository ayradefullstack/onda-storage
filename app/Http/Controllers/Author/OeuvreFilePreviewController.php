<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Consultation\AuditConsultation;
use App\Actions\Consultation\ConsultationDescriptor;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
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
 * An author previewing their OWN deposited file: the same consultation module
 * as the admin review (descriptor, assets, inline original), reached through
 * routes that only the owner can use. Anyone else gets a 404, never a 403, so
 * a probe does not confirm that a file exists. Read-only, so it works in every
 * oeuvre status; it never touches the submitted freeze.
 *
 * The page props carry no audit data: the activity trail stays admin-only.
 */
final class OeuvreFilePreviewController extends Controller
{
    public function show(
        Request $request,
        Oeuvre $oeuvre,
        MediaFile $mediaFile,
        ConsultationDescriptor $descriptor,
        AuditConsultation $audit,
    ): Response {
        $user = $this->owner($request, $oeuvre);

        $audit->handle($mediaFile, $user, $request, 'open');

        // Stepping with the arrow keys only visits files that can be previewed.
        $files = $oeuvre->mediaFiles()
            ->with('collegeOeuvreFile')
            ->where(fn ($query) => $query->where('status', MediaFileStatus::READY)->orWhere('id', $mediaFile->id))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('author/oeuvres/FilePreview', [
            'oeuvre' => [
                'uuid' => $oeuvre->uuid,
                'title' => $oeuvre->title,
                'created_at' => $oeuvre->created_at,
            ],
            'file' => [
                'uuid' => $mediaFile->uuid,
                'original_name' => $mediaFile->original_name,
                'extension' => $mediaFile->extension,
                'status' => $mediaFile->status,
            ],
            'siblings' => $files->map(fn (MediaFile $sibling): array => [
                'uuid' => $sibling->uuid,
                'original_name' => $sibling->original_name,
                'slot' => $sibling->collegeOeuvreFile?->title,
            ])->values(),
            'consultation' => fn (): array => $descriptor->handle($oeuvre, $mediaFile, $user),
        ]);
    }

    /**
     * Fresh descriptor: the side Viewer's source, and what a player calls
     * after a signed URL expires.
     */
    public function assets(
        Request $request,
        Oeuvre $oeuvre,
        MediaFile $mediaFile,
        ConsultationDescriptor $descriptor,
        AuditConsultation $audit,
    ): JsonResponse {
        $user = $this->owner($request, $oeuvre);

        $audit->handle($mediaFile, $user, $request, 'issue');

        return response()->json(['consultation' => $descriptor->handle($oeuvre, $mediaFile, $user)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function owner(Request $request, Oeuvre $oeuvre): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(Gate::forUser($user)->allows('preview', $oeuvre), 404);

        return $user;
    }
}
