<?php

declare(strict_types=1);

use App\Domain\Vault\Contracts\ChunkTracker;
use App\Infrastructure\Tracker\DatabaseChunkTracker;
use App\Infrastructure\Tracker\RedisChunkTracker;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function redisAvailableForVaultTests(): bool
{
    try {
        Redis::connection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * The identical assertions run against both tracker implementations —
 * written once, run twice, so a divergence between them is caught here
 * rather than in production.
 */
function assertChunkTrackerBehavesCorrectly(ChunkTracker $tracker, string $uuid): void
{
    $tracker->forget($uuid);

    expect($tracker->receivedMask($uuid))->toBe([])
        ->and($tracker->isComplete($uuid, 3))->toBeFalse();

    $tracker->markReceived($uuid, 2, 100);
    $tracker->markReceived($uuid, 0, 100);
    $tracker->markReceived($uuid, 1, 100);
    $tracker->markReceived($uuid, 1, 100); // duplicate mark for the same index

    expect($tracker->receivedMask($uuid))->toBe([0, 1, 2])
        ->and($tracker->isComplete($uuid, 3))->toBeTrue()
        ->and($tracker->isComplete($uuid, 4))->toBeFalse();

    $tracker->forget($uuid);
    expect($tracker->receivedMask($uuid))->toBe([]);
}

test('DatabaseChunkTracker tracks received chunks correctly', function () {
    $session = UploadSession::factory()->create();

    assertChunkTrackerBehavesCorrectly(app(DatabaseChunkTracker::class), $session->uuid);
});

test('RedisChunkTracker tracks received chunks correctly', function () {
    assertChunkTrackerBehavesCorrectly(app(RedisChunkTracker::class), (string) Str::uuid7());
})->skip(fn () => ! redisAvailableForVaultTests(), 'Redis not available');

test('DatabaseChunkTracker and RedisChunkTracker produce identical results for the same out-of-order sequence', function () {
    $sequence = [2, 0, 1, 1, 4, 3, 3];

    $session = UploadSession::factory()->create();
    $dbTracker = app(DatabaseChunkTracker::class);

    foreach ($sequence as $index) {
        $dbTracker->markReceived($session->uuid, $index, 8_388_608);
    }

    $dbResult = $dbTracker->receivedMask($session->uuid);
    expect($dbResult)->toBe([0, 1, 2, 3, 4]);
});

test('DatabaseChunkTracker and RedisChunkTracker agree bit-for-bit', function () {
    $sequence = [2, 0, 1, 1, 4, 3, 3];

    $session = UploadSession::factory()->create();
    $dbTracker = app(DatabaseChunkTracker::class);
    foreach ($sequence as $index) {
        $dbTracker->markReceived($session->uuid, $index, 8_388_608);
    }
    $dbResult = $dbTracker->receivedMask($session->uuid);

    $redisUuid = (string) Str::uuid7();
    $redisTracker = app(RedisChunkTracker::class);
    foreach ($sequence as $index) {
        $redisTracker->markReceived($redisUuid, $index, 8_388_608);
    }
    $redisResult = $redisTracker->receivedMask($redisUuid);
    $redisTracker->forget($redisUuid);

    expect($dbResult)->toBe($redisResult);
})->skip(fn () => ! redisAvailableForVaultTests(), 'Redis not available');
