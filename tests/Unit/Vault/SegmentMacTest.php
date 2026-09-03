<?php

declare(strict_types=1);

use App\Domain\Vault\Crypto\SegmentMac;
use App\Domain\Vault\Exceptions\MacVerificationFailed;
use Illuminate\Support\Str;

/**
 * @return array{mac: SegmentMac, macKey: string, fileUuid: string, ciphertextPath: string, macPath: string, ciphertextHandle: resource, macHandle: resource}
 */
function makeSegmentFixture(int $segmentSize, int $segmentCount): array
{
    $mac = new SegmentMac($segmentSize);
    $macKey = random_bytes(32);
    $fileUuid = (string) Str::uuid7();

    $ciphertextPath = tempnam(sys_get_temp_dir(), 'vault_ct_');
    $macPath = tempnam(sys_get_temp_dir(), 'vault_mac_');

    $ciphertextHandle = fopen($ciphertextPath, 'r+b');
    $macHandle = fopen($macPath, 'r+b');

    for ($seg = 0; $seg < $segmentCount; $seg++) {
        $segment = random_bytes($segmentSize);
        fseek($ciphertextHandle, $seg * $segmentSize);
        fwrite($ciphertextHandle, $segment);

        $tag = $mac->tagFor($macKey, $fileUuid, $seg, $segment);
        $mac->writeTag($macHandle, $seg, $tag);
    }

    fflush($ciphertextHandle);
    fflush($macHandle);

    return compact('mac', 'macKey', 'fileUuid', 'ciphertextPath', 'macPath', 'ciphertextHandle', 'macHandle');
}

function cleanupSegmentFixture(array $fixture): void
{
    fclose($fixture['ciphertextHandle']);
    fclose($fixture['macHandle']);
    @unlink($fixture['ciphertextPath']);
    @unlink($fixture['macPath']);
}

test('verifyRange passes for untampered segments', function () {
    $f = makeSegmentFixture(64, 4);

    try {
        $f['mac']->verifyRange($f['ciphertextHandle'], $f['macHandle'], $f['macKey'], $f['fileUuid'], 0, (64 * 4) - 1);
        expect(true)->toBeTrue(); // no exception thrown
    } finally {
        cleanupSegmentFixture($f);
    }
});

test('flipping one ciphertext bit makes MAC verification fail', function () {
    $f = makeSegmentFixture(64, 4);

    try {
        $ct = $f['ciphertextHandle'];

        // Flip one bit inside segment 1's ciphertext.
        fseek($ct, 64 + 10);
        $byte = fread($ct, 1);
        fseek($ct, 64 + 10);
        fwrite($ct, chr(ord($byte) ^ 0x01));
        fflush($ct);

        expect(fn () => $f['mac']->verifyRange($ct, $f['macHandle'], $f['macKey'], $f['fileUuid'], 0, (64 * 4) - 1))
            ->toThrow(MacVerificationFailed::class);
    } finally {
        cleanupSegmentFixture($f);
    }
});

test('truncating the mac sidecar fails verification rather than passing silently', function () {
    $f = makeSegmentFixture(64, 4);

    try {
        // Truncate the mac file so the last segment's tag is missing.
        ftruncate($f['macHandle'], 3 * 32);

        expect(fn () => $f['mac']->verifyRange($f['ciphertextHandle'], $f['macHandle'], $f['macKey'], $f['fileUuid'], 0, (64 * 4) - 1))
            ->toThrow(MacVerificationFailed::class);
    } finally {
        cleanupSegmentFixture($f);
    }
});

test('verifyRange spanning a segment boundary checks every covering segment', function () {
    $f = makeSegmentFixture(64, 4);

    try {
        $ct = $f['ciphertextHandle'];

        // Range [50, 130] spans segments 0, 1, and 2 (segment size 64).
        $f['mac']->verifyRange($ct, $f['macHandle'], $f['macKey'], $f['fileUuid'], 50, 130);

        // Corrupting segment 2 (inside that range) must now be caught.
        fseek($ct, 128 + 5);
        $byte = fread($ct, 1);
        fseek($ct, 128 + 5);
        fwrite($ct, chr(ord($byte) ^ 0x01));
        fflush($ct);

        expect(fn () => $f['mac']->verifyRange($ct, $f['macHandle'], $f['macKey'], $f['fileUuid'], 50, 130))
            ->toThrow(MacVerificationFailed::class);
    } finally {
        cleanupSegmentFixture($f);
    }
});
