<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Domain\Vault\Contracts\ChunkTracker;
use App\Models\UploadSession;
use Illuminate\Support\Facades\Storage;

/**
 * Cancels an in-progress upload: removes the partial ciphertext and its MAC
 * sidecar from the `incoming` disk, forgets the chunk tracker's mask, and
 * marks the session aborted (left in place, not deleted, as an audit trail
 * of the attempt).
 */
final class AbortUpload
{
    public function __construct(
        private readonly ChunkTracker $tracker,
    ) {}

    public function handle(UploadSession $session): void
    {
        $absolutePath = Storage::disk('incoming')->path($session->temp_path);
        $absoluteMacPath = Storage::disk('incoming')->path($session->temp_path.'.mac');

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        if (is_file($absoluteMacPath)) {
            @unlink($absoluteMacPath);
        }

        $this->tracker->forget($session->uuid);

        $session->status = 'aborted';
        $session->save();
    }
}
