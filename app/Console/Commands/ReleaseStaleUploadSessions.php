<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Upload\AbortUpload;
use App\Actions\Upload\InitUpload;
use App\Models\UploadSession;
use Illuminate\Console\Command;

/**
 * One-off: releases the quota reserved by upload sessions that are still
 * "live" (not aborted, not expired) but have seen no chunk for a while — a
 * closed tab, a crashed browser, a retry that started a second session.
 *
 * It goes through `AbortUpload`, the same path a user's Cancel takes, so the
 * partial ciphertext, the chunk mask and the reservation are all released
 * together; nothing is deleted with raw SQL. This is deliberately NOT the
 * scheduled abandoned-session sweeper — that is a separate piece of work.
 */
final class ReleaseStaleUploadSessions extends Command
{
    protected $signature = 'uploads:release-stale
        {--idle=30 : Minutes without chunk activity after which a live session is stale}
        {--user= : Only this user id}
        {--dry-run : List what would be released without releasing it}';

    protected $description = 'Abort live-but-idle upload sessions so their reserved quota is released';

    public function handle(AbortUpload $abort): int
    {
        $idle = max(1, (int) $this->option('idle'));
        $userId = $this->option('user') === null ? null : (int) $this->option('user');

        $stale = InitUpload::liveSessions($userId)
            ->where('updated_at', '<', now()->subMinutes($idle))
            ->orderBy('id')
            ->get();

        $bytes = (int) $stale->sum('size_bytes');

        foreach ($stale as $session) {
            $this->line(sprintf(
                '%s  user=%d  %s  %d bytes  idle since %s',
                $session->uuid,
                $session->user_id,
                $session->filename,
                $session->size_bytes,
                $session->updated_at,
            ));
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d stale session(s) holding %d reserved bytes.', $stale->count(), $bytes));

            return self::SUCCESS;
        }

        $stale->each(fn (UploadSession $session) => $abort->handle($session));

        $this->info(sprintf('Released %d stale session(s), %d reserved bytes.', $stale->count(), $bytes));

        return self::SUCCESS;
    }
}
