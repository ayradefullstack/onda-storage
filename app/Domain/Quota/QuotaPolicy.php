<?php

declare(strict_types=1);

namespace App\Domain\Quota;

use App\Models\StorageQuota;

/**
 * Pure quota arithmetic, decoupled from persistence — callers fetch the
 * `StorageQuota` row (Eloquent is the data layer) and hand it here.
 *
 * A `null` quota means "no row for this user" — mirrors the convention
 * already established in `EncryptedLocalVault::beginUpload()` (the domain's
 * own belt-and-suspenders check), where the absence of a quota row means no
 * limit is enforced rather than blocking every upload.
 */
final class QuotaPolicy
{
    /**
     * Bytes granted to a user who completes an upload with no `StorageQuota`
     * row yet — matches the demo allocation in VaultDemoSeeder. A missing
     * row is only "unlimited" for the purposes of the pre-upload check in
     * InitUpload (see canAccept()); CompleteUpload creates the row from
     * this default rather than leaving the user permanently unmetered.
     */
    public const DEFAULT_LIMIT_BYTES = 50 * 1024 * 1024 * 1024;

    public function canAccept(?StorageQuota $quota, int $sizeBytes): bool
    {
        if ($quota === null) {
            return true;
        }

        return ($quota->used_bytes + $sizeBytes) <= $quota->limit_bytes;
    }

    /**
     * Bytes left before the limit is hit. Null quota reports no bound.
     */
    public function remainingBytes(?StorageQuota $quota): ?int
    {
        if ($quota === null) {
            return null;
        }

        return max(0, $quota->limit_bytes - $quota->used_bytes);
    }
}
