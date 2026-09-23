<?php

declare(strict_types=1);

use App\Domain\Vault\Value\ByteRange;

test('length is inclusive of both ends', function () {
    $range = new ByteRange(10, 19);
    expect($range->length())->toBe(10);
});

test('rejects end before start', function () {
    new ByteRange(10, 5);
})->throws(InvalidArgumentException::class);

test('rejects a negative start', function () {
    new ByteRange(-1, 5);
})->throws(InvalidArgumentException::class);

test('alignedDown rounds the start down to the block boundary and keeps the end', function () {
    $range = new ByteRange(23, 100);
    $aligned = $range->alignedDown(16);

    expect($aligned->start)->toBe(16)
        ->and($aligned->end)->toBe(100);
});

test('alignedDown is a no-op when already aligned', function () {
    $range = new ByteRange(32, 100);
    expect($range->alignedDown(16)->start)->toBe(32);
});

test('fromHeader parses a standard bytes range', function () {
    $range = ByteRange::fromHeader('bytes=10-19', 1000);

    expect($range)->not->toBeNull()
        ->and($range->start)->toBe(10)
        ->and($range->end)->toBe(19);
});

test('fromHeader parses an open-ended range to end of file', function () {
    $range = ByteRange::fromHeader('bytes=990-', 1000);

    expect($range->start)->toBe(990)
        ->and($range->end)->toBe(999);
});

test('fromHeader parses a suffix range (last N bytes)', function () {
    $range = ByteRange::fromHeader('bytes=-100', 1000);

    expect($range->start)->toBe(900)
        ->and($range->end)->toBe(999);
});

test('fromHeader returns null for absent input', function () {
    expect(ByteRange::fromHeader(null, 1000))->toBeNull();
});

test('fromHeader returns null for malformed input instead of throwing', function () {
    expect(ByteRange::fromHeader('not-a-range', 1000))->toBeNull()
        ->and(ByteRange::fromHeader('bytes=', 1000))->toBeNull()
        ->and(ByteRange::fromHeader('bytes=abc-def', 1000))->toBeNull();
});

test('fromHeader returns null when the start is beyond the file size', function () {
    expect(ByteRange::fromHeader('bytes=2000-3000', 1000))->toBeNull();
});
