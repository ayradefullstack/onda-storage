<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Models\ConsultationAsset;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes derivative rows and their bytes. A referenced poster / waveform
 * (`is_shared_variant`) belongs to `media_variants`: only the row goes.
 */
final class DeleteConsultationAssets
{
    /**
     * @param  iterable<ConsultationAsset>  $assets
     */
    public function handle(iterable $assets): void
    {
        foreach ($assets as $asset) {
            if (! $asset->is_shared_variant) {
                try {
                    Storage::disk('variants')->delete($asset->path);
                } catch (Throwable) {
                    // A missing file must not block removing its row.
                }
            }

            $asset->delete();
        }
    }
}
