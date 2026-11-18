<?php

declare(strict_types=1);

use App\Domain\Deposit\WritabilityChecks;
use App\Domain\Vault\Doctor\CheckResult;

test('every probed directory passes and leaves no probe file behind', function () {
    $results = app(WritabilityChecks::class)->run();

    expect($results->pluck('key')->all())->toContain('write.logs', 'write.bootstrap_cache', 'write.vault', 'write.incoming', 'write.work', 'write.variants')
        ->and($results->where('status', CheckResult::FAIL))->toHaveCount(0)
        ->and(glob(config('filesystems.disks.variants.root').'/.doctor-probe-*'))->toBe([]);
});

test('a directory that cannot be written is a FAIL carrying its path', function () {
    $missing = config('filesystems.disks.variants.root').'/does-not-exist';
    config(['filesystems.disks.variants.root' => $missing]);

    $row = app(WritabilityChecks::class)->run()->firstWhere('key', 'write.variants');

    expect($row->status)->toBe(CheckResult::FAIL)
        ->and($row->value)->toBe($missing);
});
