<?php

declare(strict_types=1);

namespace App\Actions\Oeuvre;

use App\Actions\Consultation\DeleteConsultationAssets;
use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Domain\Deposit\RemovalGate;
use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Deposit\VaultBytesReleaser;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\Oeuvre;
use App\Models\StorageQuota;
use App\Policies\MediaFilePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes a file off a deposit. Two cases, decided by the oeuvre's status.
 *
 * ---------------------------------------------------------------------
 * Draft / rejected deposit (unchanged)
 * ---------------------------------------------------------------------
 * Soft delete only — see RemovalGate for why the ciphertext and the
 * `.mac` sidecar are deliberately left where they are, and why that means
 * the author's quota does not recover.
 *
 * `storage_quotas.used_bytes` is deliberately NOT decremented here. That
 * counter is the running total of bytes the author occupies, and the
 * bytes are still occupied: CLAUDE.md defines disk usage as
 * `MediaFile::withTrashed()->whereNull('purged_at')->sum('size_bytes')`
 * and requires the counter to reconcile to it. The purge job is what
 * credits it back, when the bytes actually go.
 *
 * ---------------------------------------------------------------------
 * Under review: a REJECTED file, or the author's own pending replacement
 * ---------------------------------------------------------------------
 * (MediaFilePolicy::reviewAllowsRemoval.) The officer refused the file and
 * the author is replacing it — or the author withdraws a replacement the
 * officer has not decided yet (a wrong upload, a failed one) — so its row
 * is purged at once, the way the purge job would: soft-deleted AND
 * `purged_at` set (the row and its `media_file_reviews` history stay —
 * nothing is hard-deleted, so the restrict foreign keys never fire), its
 * size credited back to the quota (it leaves the reconciliation sum at the
 * same moment), its derivatives deleted, and its bytes handed to
 * VaultBytesReleaser AFTER commit — which keeps them if any other row
 * (a dedup twin) still points at them.
 *
 * Locks the oeuvre row, then the file row — the order DecideMediaFile and
 * the oeuvre actions use — and re-checks inside the lock: still under
 * review (else 409), file still removable (an officer may have just
 * accepted it: 409 file_no_longer_rejected / file_not_removable), not
 * already removed (a repeat returns quietly). An accepted file is never
 * removable.
 *
 * The `delete` ledger entry stays as it is today (none is written by
 * removal); `media_file_reviews` is untouched.
 *
 * @throws RemovalRefused
 * @throws RemovalConflict
 */
final class RemoveMediaFile
{
    public function __construct(
        private readonly RemovalGate $gate,
        private readonly VaultBytesReleaser $releaser,
        private readonly DeleteConsultationAssets $deleteAssets,
    ) {}

    public function handle(MediaFile $mediaFile): void
    {
        $mediaFile->loadMissing('oeuvre');

        if ($mediaFile->oeuvre->status === OeuvreStatus::UNDER_REVIEW) {
            $this->removeRejectedUnderReview($mediaFile);

            return;
        }

        if ($mediaFile->trashed()) {
            return;
        }

        // A file the ONDA accepted stays, even once the whole deposit was
        // returned to the author (MediaFilePolicy::delete says the same).
        if ($mediaFile->review_status === ReviewStatusEnum::ACCEPTED) {
            throw RemovalConflict::notRemovable();
        }

        $this->gate->assertFileRemovable($mediaFile);

        $mediaFile->delete();
    }

    private function removeRejectedUnderReview(MediaFile $mediaFile): void
    {
        $purged = DB::transaction(function () use ($mediaFile): ?MediaFile {
            $oeuvre = Oeuvre::query()->whereKey($mediaFile->oeuvre_id)->lockForUpdate()->firstOrFail();
            $locked = MediaFile::withTrashed()->whereKey($mediaFile->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->trashed()) {
                return null;
            }

            if ($oeuvre->status !== OeuvreStatus::UNDER_REVIEW) {
                throw RemovalConflict::oeuvreDecided();
            }

            // A rejected file, or the author's own replacement still pending
            // (MediaFilePolicy::reviewAllowsRemoval). An officer may have
            // just decided it: an accepted replacement is part of the record
            // (409 file_not_removable); a file revised away from rejected is
            // the earlier race (409 file_no_longer_rejected).
            if (! MediaFilePolicy::reviewAllowsRemoval($locked)) {
                throw $locked->replaces_media_file_id !== null
                    ? RemovalConflict::notRemovable()
                    : RemovalConflict::noLongerRejected();
            }

            $this->gate->assertFileRemovable($locked);

            $now = Carbon::now();
            $locked->deleted_at = $now;
            $locked->purged_at = $now;
            $locked->save();

            $quota = StorageQuota::where('user_id', $locked->uploaded_by)->lockForUpdate()->first();

            if ($quota !== null) {
                $quota->decrement('used_bytes', min($locked->size_bytes, $quota->used_bytes));
            }

            return $locked;
        });

        if ($purged === null) {
            return;
        }

        $mediaFile->setRawAttributes($purged->getAttributes(), sync: true);

        // After commit: the derivatives, then the bytes — the releaser
        // re-counts the rows still claiming them and keeps shared ones.
        $this->deleteAssets->handle($purged->consultationAssets()->get());

        foreach (MediaVariant::where('media_file_id', $purged->id)->get() as $variant) {
            Storage::disk('variants')->delete($variant->path);
            $variant->delete();
        }

        $this->releaser->releaseIfUnreferenced($purged, StoredObjectMapper::fromMediaFile($purged), 'rejected-file-removed');
    }
}
