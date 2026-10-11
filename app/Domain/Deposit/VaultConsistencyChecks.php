<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Vault\Doctor\CheckResult;
use Illuminate\Support\Collection;
use Throwable;

/**
 * `vault:doctor` additions: do the rows and the bytes agree? WARN level, with
 * counts and (a bounded list of) the offenders. Read-only — the orphan list is
 * for a human to look at, never for deleting.
 */
final class VaultConsistencyChecks
{
    private const LIST_LIMIT = 10;

    public function __construct(private readonly VaultConsistency $consistency) {}

    /**
     * @return Collection<int, CheckResult>
     */
    public function run(): Collection
    {
        try {
            return collect([
                $this->missing(),
                $this->markedMissing(),
                $this->refCounts(),
                $this->orphans(),
            ]);
        } catch (Throwable $e) {
            return collect([new CheckResult('vault.consistency', 'Rows vs bytes consistency', CheckResult::WARN, 'unknown', 'Could not run the check: '.$e->getMessage())]);
        }
    }

    private function missing(): CheckResult
    {
        // Rows already marked failed (vault:mark-missing-bytes) are resolved.
        $missing = $this->consistency->missing()->reject(fn (array $m): bool => $m['file']->status === 'failed')->values();

        if ($missing->isEmpty()) {
            return new CheckResult('vault.missing_bytes', 'Rows whose vault file is missing', CheckResult::PASS, '0', 'Every non-purged media file has its .bin and .mac on disk.');
        }

        $list = $missing->take(self::LIST_LIMIT)->map(fn (array $m): string => $m['file']->uuid.' ('.($m['bin'] ? '' : 'bin ').($m['mac'] ? '' : 'mac').' missing)')->implode('; ');

        return new CheckResult('vault.missing_bytes', 'Rows whose vault file is missing', CheckResult::WARN, (string) $missing->count(), "These deposits have no bytes: {$list}. Review, then run vault:mark-missing-bytes.");
    }

    /**
     * Rows already marked `failed` by vault:mark-missing-bytes: resolved, but
     * shown — a count of lost deposits is never hidden.
     */
    private function markedMissing(): CheckResult
    {
        $marked = $this->consistency->markedMissing();

        return new CheckResult(
            'vault.marked_missing',
            'Rows marked failed for missing bytes',
            'INFO',
            (string) $marked->count(),
            $marked->isEmpty()
                ? 'None.'
                : 'Already marked failed (bytes_missing): '.$marked->take(self::LIST_LIMIT)->map(fn (array $m): string => $m['file']->uuid)->implode('; ').'.',
        );
    }

    private function refCounts(): CheckResult
    {
        $mismatches = $this->consistency->refCountMismatches();

        if ($mismatches->isEmpty()) {
            return new CheckResult('vault.ref_count', 'ref_count matches referencing rows', CheckResult::PASS, '0', 'Informational counter agrees with the actual rows.');
        }

        $list = $mismatches->take(self::LIST_LIMIT)->map(fn (array $m): string => "{$m['file']->uuid} (ref_count {$m['file']->ref_count}, actual {$m['actual']})")->implode('; ');

        return new CheckResult('vault.ref_count', 'ref_count matches referencing rows', CheckResult::WARN, (string) $mismatches->count(), "ref_count is informational and never used to delete bytes: {$list}.");
    }

    private function orphans(): CheckResult
    {
        $orphans = $this->consistency->orphans();

        if ($orphans === []) {
            return new CheckResult('vault.orphans', 'Vault files referenced by no row', CheckResult::PASS, '0', 'No orphan files in the vault.');
        }

        $list = implode('; ', array_slice($orphans, 0, self::LIST_LIMIT));

        return new CheckResult('vault.orphans', 'Vault files referenced by no row', CheckResult::WARN, (string) count($orphans), "Never deleted automatically: {$list}.");
    }
}
