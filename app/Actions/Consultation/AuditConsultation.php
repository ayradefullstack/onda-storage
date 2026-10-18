<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Domain\Access\AccessLogger;
use App\Enums\FileAccessLog\ActionEnum;
use App\Infrastructure\Render\DeliveryMode;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * One `admin_consult` ledger row per (admin, file, moment) per window
 * (`vault.consult.audit_window_minutes`, default 10): re-renders, polling
 * and page changes do not spam the chain. Written through the existing
 * hash-chained AccessLogger — no hand-written rows. Individual Range fetches
 * are NOT logged. A file delivered `original_inline` is logged as
 * `consult_original` instead of `admin_consult`, so original exposures stand
 * out in the trail.
 *
 * `$moment` is `open` (the review page was opened) or `issue` (descriptors /
 * signed URLs were issued again), each windowed on its own.
 */
final class AuditConsultation
{
    public function __construct(private readonly PreviewFamilyResolver $resolver) {}

    public function handle(MediaFile $mediaFile, User $admin, Request $request, string $moment): bool
    {
        $window = max(1, (int) config('vault.consult.audit_window_minutes'));
        $key = "consult-audit:{$moment}:{$admin->id}:{$mediaFile->uuid}";

        if (! Cache::add($key, true, now()->addMinutes($window))) {
            return false;
        }

        // An ORIGINAL exposure is its own action, so the ledger tells it
        // apart from a derivative consultation. `range_header` stays for
        // real Range data only (it is null here).
        $original = $this->resolver->modeFor($mediaFile) === DeliveryMode::OriginalInline;

        // The owner previewing their own file is recorded the way the owner's
        // media.stream access already is: action `stream`.
        $action = ! $admin->hasRole('admin')
            ? ActionEnum::STREAM
            : ($original ? ActionEnum::CONSULT_ORIGINAL : ActionEnum::ADMIN_CONSULT);

        AccessLogger::record(
            $mediaFile,
            $admin->id,
            $action,
            $request->ip() ?? 'unknown',
            $request->userAgent(),
        );

        return true;
    }
}
