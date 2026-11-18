<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Domain\Quota\QuotaPolicy;
use App\Domain\Vault\Contracts\ChunkTracker;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\StoredObject;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finalizes a fully-received upload: renames the ciphertext into the vault,
 * records the `MediaFile` row, charges the quota, and hands off to the P5
 * processing chain (guarded — P5 doesn't exist yet).
 */
final class CompleteUpload
{
    public function __construct(
        private readonly VaultContract $vault,
        private readonly ChunkTracker $tracker,
        private readonly ReplacementGuard $replacementGuard,
        private readonly AbortUpload $abort,
    ) {}

    public function handle(UploadSession $session): MediaFile
    {
        if (! $this->tracker->isComplete($session->uuid, $session->total_chunks)) {
            $missing = array_values(array_diff(
                range(0, $session->total_chunks - 1),
                $this->tracker->receivedMask($session->uuid),
            ));

            throw new HttpResponseException(response()->json([
                'message' => 'This upload is missing chunks and cannot be completed.',
                'missing' => $missing,
            ], 409));
        }

        // The oeuvre left the state this upload was opened for (a replacement
        // whose deposit was decided, a draft upload whose deposit was
        // submitted): 409, session aborted, nothing added.
        $this->replacementGuard->assertStillOpen($session);

        $extension = strtolower(pathinfo($session->filename, PATHINFO_EXTENSION));

        // The oeuvre row is locked first (the order every review action
        // uses) and held across finalize(), so an officer's decision or a
        // submission cannot land between the re-check and the new row:
        // either the file is recorded, or nothing reaches the vault.
        $refusal = null;

        $mediaFile = DB::transaction(function () use ($session, $extension, &$refusal): ?MediaFile {
            $oeuvre = Oeuvre::query()->whereKey($session->oeuvre_id)->lockForUpdate()->firstOrFail();
            $refusal = ReplacementGuard::refusal($session, $oeuvre->status);

            if ($refusal !== null) {
                return null;
            }

            // A replacement under review links to the file its session
            // reserved at init, copied as is, never recomputed.
            return $this->record($session, $this->vault->finalize($session), $extension, $session->replaces_media_file_id ?? self::rejectedOeuvreLink($oeuvre, $session));
        });

        if ($refusal !== null || $mediaFile === null) {
            $this->abort->handle($session);

            throw ReplacementGuard::conflict($refusal ?? ReplacementGuard::OEUVRE_SUBMITTED);
        }

        return $mediaFile;
    }

    /**
     * A deposit returned to the author (`rejected`) is editable: its uploads
     * are ordinary ones, with no reservation and no limit. But when the
     * file lands in a slot holding a removed rejected file not replaced
     * yet, it is that file's replacement, and is linked to it so the
     * "Remplace : …" label and the officer's history read the same in both
     * statuses. Picked here, under the oeuvre-row lock the caller holds, so
     * two completes into one slot cannot both take the same removed file
     * (the second finds it replaced). Oldest first, like InitUpload.
     */
    private static function rejectedOeuvreLink(Oeuvre $oeuvre, UploadSession $session): ?int
    {
        if ($oeuvre->status !== OeuvreStatus::REJECTED || $session->college_oeuvre_file_id === null) {
            return null;
        }

        $id = InitUpload::unreservedReplacements($oeuvre->id, $session->college_oeuvre_file_id)->value('media_files.id');

        return $id === null ? null : (int) $id;
    }

    private function record(UploadSession $session, StoredObject $object, string $extension, ?int $replaces): MediaFile
    {
        $mediaFile = new MediaFile;
        $mediaFile->uuid = (string) Str::uuid7();
        $mediaFile->oeuvre_id = $session->oeuvre_id;
        // The requirement slot InitUpload validated, and its key as of
        // this upload. withTrashed: a requirement retired mid-upload still
        // names the slot the file was deposited against.
        $mediaFile->college_oeuvre_file_id = $session->college_oeuvre_file_id;
        $mediaFile->document_key_snapshot = $session->college_oeuvre_file_id === null
            ? null
            : CollegeOeuvreFile::withTrashed()->whereKey($session->college_oeuvre_file_id)->value('document_key');
        $mediaFile->uploaded_by = $session->user_id;
        $mediaFile->original_name = $session->filename;
        $mediaFile->extension = $extension;
        $mediaFile->mime = InitUpload::canonicalMimeFor($extension) ?? 'application/octet-stream';
        $mediaFile->size_bytes = $object->sizeBytes;
        $mediaFile->disk = $object->disk;
        $mediaFile->path = $object->path;
        // sha256_plain is populated asynchronously by P5's
        // ComputeContentHash job — computing it here would mean
        // decrypting the whole file (minutes, for a large one) inside
        // this HTTP request. Nothing may read this column before
        // status reaches 'ready'.
        $mediaFile->sha256_plain = null;
        $mediaFile->dek_wrapped = $object->dekWrapped;
        $mediaFile->nonce = $object->nonce;
        $mediaFile->mac_path = $object->macPath;
        $mediaFile->status = MediaFileStatus::SCANNING;
        $mediaFile->ref_count = 1;
        // Set only on a replacement: uploaded during review, or into a slot
        // of a rejected deposit whose rejected file the author removed.
        $mediaFile->replaces_media_file_id = $replaces;
        $mediaFile->save();

        // Charged here, not in P5: the bytes occupy the disk as soon as
        // finalize() renames them into the vault, regardless of whether
        // processing later succeeds. Tying the counter to pipeline
        // success would let an author fill the disk with files that
        // deliberately fail processing. Reconciling this counter
        // against actual disk usage (in case a row is later purged, or
        // this transaction is rolled back after finalize() already
        // moved the bytes) is P7's vault:audit job, not built here.
        $this->chargeQuota($session->user_id, $object->sizeBytes);

        $this->dispatchProcessingChain($mediaFile);

        $session->delete();

        return $mediaFile;
    }

    /**
     * increment() issues a single atomic `UPDATE ... SET used_bytes =
     * used_bytes + ?` — concurrent completes for the same user can't lose
     * an update the way a read-modify-write (`$quota->used_bytes += X;
     * $quota->save()`) could. The lockForUpdate() first-or-create guards
     * the far more common case (a quota row already exists — every demo
     * author is seeded with one); a user's very first-ever completed
     * upload racing another completion for that same brand-new user is a
     * narrower window this does not fully close.
     */
    private function chargeQuota(int $userId, int $sizeBytes): void
    {
        $quota = StorageQuota::where('user_id', $userId)->lockForUpdate()->first();

        if ($quota === null) {
            $quota = new StorageQuota;
            $quota->user_id = $userId;
            $quota->limit_bytes = QuotaPolicy::DEFAULT_LIMIT_BYTES;
            $quota->used_bytes = 0;
            $quota->save();
        }

        $quota->increment('used_bytes', $sizeBytes);
    }

    /**
     * P5 (scan / probe / variants) hasn't landed yet. Guarding with
     * class_exists() lets this phase ship the hand-off point without
     * inventing the job or its chain.
     */
    private function dispatchProcessingChain(MediaFile $mediaFile): void
    {
        $job = 'App\\Jobs\\ProcessMediaFile';

        if (class_exists($job)) {
            $job::dispatch($mediaFile->uuid)->onQueue('media');
        }
    }
}
