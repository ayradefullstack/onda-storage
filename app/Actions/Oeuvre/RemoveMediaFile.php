<?php

declare(strict_types=1);

namespace App\Actions\Oeuvre;

use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Domain\Deposit\RemovalGate;
use App\Models\MediaFile;

/**
 * Takes a file off a deposit.
 *
 * Soft delete only — see RemovalGate for why the ciphertext and the
 * `.mac` sidecar are deliberately left where they are, and why that means
 * the author's quota does not recover today.
 *
 * `storage_quotas.used_bytes` is deliberately NOT decremented here. That
 * counter is the running total of bytes the author occupies, and the
 * bytes are still occupied: CLAUDE.md defines disk usage as
 * `MediaFile::withTrashed()->whereNull('purged_at')->sum('size_bytes')`
 * and requires the counter to reconcile to it. Crediting the quota back
 * at removal time would break that reconciliation and let an author
 * upload-and-remove their way past their allocation while the disk fills.
 * The purge job is what credits it back, when the bytes actually go.
 *
 * A quarantined file stays quarantined and stays visible to an admin
 * through `withTrashed()`. Removal takes it off the author's deposit; it
 * does not erase the evidence of what was uploaded.
 *
 * @throws RemovalRefused
 */
final class RemoveMediaFile
{
    public function __construct(
        private readonly RemovalGate $gate,
    ) {}

    public function handle(MediaFile $mediaFile): void
    {
        $this->gate->assertFileRemovable($mediaFile);

        $mediaFile->delete();
    }
}
