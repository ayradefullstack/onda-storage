<?php

declare(strict_types=1);

use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Exceptions\UnalignedOffset;

test('round-trips plaintext at offset 0', function () {
    $cipher = new CtrCipher;
    $key = random_bytes(32);
    $nonce = random_bytes(8);
    $plaintext = random_bytes(1024);

    $ciphertext = $cipher->transformAt($plaintext, $key, $nonce, 0);
    expect($ciphertext)->not->toBe($plaintext);

    $decrypted = $cipher->transformAt($ciphertext, $key, $nonce, 0);
    expect($decrypted)->toBe($plaintext);
});

test('round-trips plaintext at chunk index 5 (offset 41943040), proving offset independence', function () {
    $cipher = new CtrCipher;
    $key = random_bytes(32);
    $nonce = random_bytes(8);
    $plaintext = random_bytes(8_388_608);
    $offset = 5 * 8_388_608;

    expect($offset)->toBe(41_943_040);

    $ciphertext = $cipher->transformAt($plaintext, $key, $nonce, $offset);
    $decrypted = $cipher->transformAt($ciphertext, $key, $nonce, $offset);

    expect($decrypted)->toBe($plaintext);

    // Encrypting the same plaintext at a different offset must produce a
    // different keystream, proving the offset genuinely selects a distinct
    // position in the counter stream rather than being ignored.
    $ciphertextAtZero = $cipher->transformAt($plaintext, $key, $nonce, 0);
    expect($ciphertextAtZero)->not->toBe($ciphertext);
});

test('transformAt throws UnalignedOffset for a non-block-aligned offset', function () {
    $cipher = new CtrCipher;
    $key = random_bytes(32);
    $nonce = random_bytes(8);

    $cipher->transformAt('data', $key, $nonce, 7);
})->throws(UnalignedOffset::class);

test('streamTransform round-trips through a stream in 64 KiB blocks', function () {
    $cipher = new CtrCipher;
    $key = random_bytes(32);
    $nonce = random_bytes(8);
    $plaintext = random_bytes(200_000);

    $plainStream = fopen('php://temp', 'r+b');
    fwrite($plainStream, $plaintext);
    rewind($plainStream);

    $cipherStream = fopen('php://temp', 'r+b');
    $processed = $cipher->streamTransform($plainStream, $cipherStream, $key, $nonce, 0);
    expect($processed)->toBe(strlen($plaintext));

    rewind($cipherStream);
    $outStream = fopen('php://temp', 'r+b');
    $cipher->streamTransform($cipherStream, $outStream, $key, $nonce, 0);

    rewind($outStream);
    $decrypted = stream_get_contents($outStream);

    expect($decrypted)->toBe($plaintext);

    fclose($plainStream);
    fclose($cipherStream);
    fclose($outStream);
});
