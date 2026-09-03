<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Media\TransitionMediaFileStatus;
use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\Value\RowHash;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The legal record. Appends a row to `file_access_logs` (see this phase's
 * report for why that table rather than a new one — the short version:
 * `database/migrations/**` is frozen, so a dedicated table wasn't buildable
 * either, and reusing the existing prev_hash/row_hash columns needed only a
 * one-value enum addition) with `action = 'deposit'`, chained by
 * `row_hash = sha256(prev_hash . canonical_json_of_this_row)` — tampering
 * with an old row is then detectable, since its stored row_hash would no
 * longer match what a later row's chain was actually built against.
 *
 * The chain is GLOBAL (ordered by insertion across every file, not
 * per-file) — `Cache::lock()` serializes concurrent RecordDeposit runs so
 * two inserts can't both read the same "latest" row and fork the chain.
 *
 * Every successfully-processed upload gets its own deposit row, even a
 * deduplicated one: the row_hash / audit trail records THIS author's claim
 * of having deposited THIS content at THIS time, independent of whether the
 * underlying bytes happen to be shared with another row.
 */
final class RecordDeposit extends PipelineJob
{
    public int $timeout = 300;

    public function handle(TransitionMediaFileStatus $transition): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->firstOrFail();

        if ($mediaFile->sha256_plain === null) {
            throw new RuntimeException("Cannot record deposit for [{$this->mediaFileUuid}]: sha256_plain is not set.");
        }

        Cache::lock('deposit-audit-chain', (int) config('vault.dedup_lock_seconds'))
            ->block(10, fn () => $this->appendDepositRow($mediaFile));

        $transition->handle($mediaFile, MediaFileStatus::READY);
    }

    private function appendDepositRow(MediaFile $mediaFile): void
    {
        DB::transaction(function () use ($mediaFile): void {
            $previousRowHash = FileAccessLog::orderByDesc('id')->value('row_hash');

            $payload = [
                'action' => 'deposit',
                'media_file_uuid' => $mediaFile->uuid,
                'sha256_plain' => $mediaFile->sha256_plain,
                'size_bytes' => $mediaFile->size_bytes,
                'author_id' => $mediaFile->work->author_id,
                'recorded_at' => now()->toISOString(),
            ];

            $log = new FileAccessLog;
            $log->media_file_id = $mediaFile->id;
            $log->user_id = $mediaFile->uploaded_by;
            $log->action = 'deposit';
            // No HTTP request context in a queued job — 'system' is a
            // deliberate non-IP sentinel, not a real client address.
            $log->ip = 'system';
            $log->prev_hash = $previousRowHash;
            $log->row_hash = RowHash::compute($previousRowHash, $payload);
            $log->save();
        });
    }
}
