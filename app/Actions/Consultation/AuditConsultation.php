<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Domain\Access\AccessLogger;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * One `admin_consult` ledger row per (admin, file, moment) per window
 * (`vault.consult.audit_window_minutes`, default 10): re-renders, polling
 * and page changes do not spam the chain. Written through the existing
 * hash-chained AccessLogger — no hand-written rows. Individual Range fetches
 * are NOT logged.
 *
 * `$moment` is `open` (the review page was opened) or `issue` (descriptors /
 * signed URLs were issued again), each windowed on its own.
 */
final class AuditConsultation
{
    public function handle(MediaFile $mediaFile, User $admin, Request $request, string $moment): bool
    {
        $window = max(1, (int) config('vault.consult.audit_window_minutes'));
        $key = "consult-audit:{$moment}:{$admin->id}:{$mediaFile->uuid}";

        if (! Cache::add($key, true, now()->addMinutes($window))) {
            return false;
        }

        AccessLogger::record(
            $mediaFile,
            $admin->id,
            'admin_consult',
            $request->ip() ?? 'unknown',
            $request->userAgent(),
        );

        return true;
    }
}
