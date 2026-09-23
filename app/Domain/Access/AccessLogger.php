<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Deposit\Value\RowHash;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Appends one row per access attempt to `file_access_logs`, chained into
 * the SAME global `row_hash`/`prev_hash` sequence `RecordDeposit` (P5)
 * writes into — one append-only, tamper-evident ledger for the whole
 * vault, not a separate chain per concern. Reuses that job's exact lock
 * name and hash formula (`RowHash`) deliberately: two independent chains
 * both computed from "the current last row" would race and silently fork
 * if they didn't share the same lock.
 */
final class AccessLogger
{
    public static function record(
        MediaFile $mediaFile,
        ?int $userId,
        string $action,
        string $ip,
        ?string $userAgent,
        ?int $bytesSent = null,
        ?string $rangeHeader = null,
    ): void {
        Cache::lock('deposit-audit-chain', (int) config('vault.dedup_lock_seconds'))
            ->block(10, function () use ($mediaFile, $userId, $action, $ip, $userAgent, $bytesSent, $rangeHeader): void {
                DB::transaction(function () use ($mediaFile, $userId, $action, $ip, $userAgent, $bytesSent, $rangeHeader): void {
                    $previousRowHash = FileAccessLog::orderByDesc('id')->value('row_hash');

                    $payload = [
                        'action' => $action,
                        'media_file_uuid' => $mediaFile->uuid,
                        'user_id' => $userId,
                        'ip' => $ip,
                        'range_header' => $rangeHeader,
                        'logged_at' => now()->toISOString(),
                    ];

                    $log = new FileAccessLog;
                    $log->media_file_id = $mediaFile->id;
                    $log->user_id = $userId;
                    $log->action = $action;
                    $log->ip = $ip;
                    $log->user_agent = $userAgent;
                    $log->bytes_sent = $bytesSent;
                    $log->range_header = $rangeHeader;
                    $log->prev_hash = $previousRowHash;
                    $log->row_hash = RowHash::compute($previousRowHash, $payload);
                    $log->save();
                });
            });
    }
}
