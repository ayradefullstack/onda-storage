<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Infrastructure\Render\DeliveryMode;
use App\Infrastructure\Render\PreviewFamily;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;

/**
 * The one normalised description of "what can be shown for this file" — what
 * the page, the side Viewer and the assets endpoint all receive. It carries
 * signed asset URLs and metadata, never a storage path, and exposes NO url at
 * all unless the derivative is `ready` (an unsupported or failed file gets a
 * metadata card, never a "download instead").
 *
 * Shape (mirrored in resources/js/types/consultation.ts):
 *   { family, mode, original:{url,mime,extension}|null, status, reason, notice, assets:[{kind, pageIndex, url}],
 *     pageCount, watermark, meta:{filename,sizeBytes,mime,sha256,depositedAt,slotLabel},
 *     actions:{download} }
 */
final class ConsultationDescriptor
{
    public function __construct(
        private readonly PreviewFamilyResolver $resolver,
        private readonly IssueAssetUrl $urls,
        private readonly IssueOriginalUrl $originalUrls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Oeuvre $oeuvre, MediaFile $mediaFile, User $viewer): array
    {
        $consultation = $mediaFile->consultation()->first();

        $family = $consultation !== null
            ? PreviewFamily::tryFrom($consultation->family) ?? PreviewFamily::Other
            : $this->resolver->resolve($mediaFile);

        $status = $consultation === null ? MediaConsultation::PENDING : $consultation->status;
        $reason = $consultation === null
            ? 'not_generated'
            : (in_array($status, [MediaConsultation::FAILED, MediaConsultation::UNSUPPORTED], true) ? $consultation->reason : null);
        $mode = $this->resolver->deliveryMode($family, $mediaFile->extension);
        $assets = [];
        $original = null;

        if ($consultation !== null && $status === MediaConsultation::READY && $mode === DeliveryMode::OriginalInline) {
            $mime = $this->resolver->inlineMime($mediaFile);

            if ($mime === null) {
                [$status, $reason] = [MediaConsultation::UNSUPPORTED, 'format_unavailable'];
            } elseif ($family === PreviewFamily::Document && $mediaFile->size_bytes > (int) config('vault.consult.docx_max_bytes')) {
                // docx-preview loads the whole file in the browser.
                [$status, $reason] = [MediaConsultation::UNSUPPORTED, 'docx_too_large'];
            } else {
                $original = [
                    'url' => $this->originalUrls->handle($oeuvre, $mediaFile, $viewer),
                    'mime' => $mime,
                    'extension' => strtolower($mediaFile->extension),
                ];
            }
        } elseif ($consultation !== null && $status === MediaConsultation::READY && $mode !== DeliveryMode::Derivative) {
            // `ready` is stale: the file was prepared under another delivery
            // map. Re-run vault:generate-previews --all --force.
            [$status, $reason] = [MediaConsultation::PENDING, 'not_generated'];
        }

        if ($consultation !== null && $status === MediaConsultation::READY && $mode === DeliveryMode::Derivative) {
            foreach ($mediaFile->consultationAssets()->orderBy('kind')->orderBy('page_index')->get() as $asset) {
                $assets[] = [
                    'kind' => $asset->kind,
                    'pageIndex' => $asset->page_index,
                    'url' => $this->urls->handle($oeuvre, $mediaFile, $asset, $viewer),
                ];
            }

            if ($assets === []) {
                // Prepared as `original_inline` under an earlier delivery map.
                [$status, $reason] = [MediaConsultation::PENDING, 'not_generated'];
            }
        }

        return [
            'family' => $family->value,
            'mode' => $mode->value,
            // The signed consult-original URL; only for a ready
            // `original_inline` file. Never a storage path.
            'original' => $original,
            'status' => $status,
            // Unsupported / failed carry the reason. No row at all means the
            // derivative was never generated (a deposit older than the
            // feature): see vault:generate-previews.
            'reason' => $reason,
            // A ready file may carry a notice (e.g. "first N pages shown").
            'notice' => $status === MediaConsultation::READY ? $consultation?->reason : null,
            'assets' => $assets,
            'pageCount' => $consultation?->page_count,
            // Label for the CSS watermark overlay; null turns it off.
            'watermark' => config($viewer->hasRole('admin') ? 'vault.consult.watermark' : 'vault.consult.watermark_for_owner') ? $viewer->email : null,
            'meta' => [
                'filename' => $mediaFile->original_name,
                'sizeBytes' => $mediaFile->size_bytes,
                'mime' => $mediaFile->mime,
                'sha256' => $mediaFile->sha256_plain,
                'depositedAt' => $mediaFile->created_at?->toIso8601String(),
                'slotLabel' => $this->slotLabel($mediaFile),
            ],
            // An admin never downloads an original: not a toggle.
            'actions' => ['download' => false],
        ];
    }

    private function slotLabel(MediaFile $mediaFile): ?string
    {
        $slot = $mediaFile->collegeOeuvreFile;

        if ($slot === null) {
            return null;
        }

        return match (app()->getLocale()) {
            'ar' => $slot->title_ar ?: $slot->title,
            'en' => $slot->title_en ?: $slot->title,
            default => $slot->title,
        };
    }
}
