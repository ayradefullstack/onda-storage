<?php

declare(strict_types=1);

use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Exceptions\InvalidMasterKey;
use App\Domain\Vault\Exceptions\MacVerificationFailed;
use Tests\TestCase;

uses(TestCase::class);

test('wrap/unwrap round-trips a DEK', function () {
    $manager = new KeyManager;
    $dek = $manager->generateDek();

    $wrapped = $manager->wrap($dek);
    expect($wrapped)->not->toBe($dek);

    expect($manager->unwrap($wrapped))->toBe($dek);
});

test('unwrap fails on tampered wrapped material', function () {
    $manager = new KeyManager;
    $wrapped = $manager->wrap($manager->generateDek());

    $raw = base64_decode($wrapped, true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
    $tampered = base64_encode($raw);

    expect(fn () => $manager->unwrap($tampered))->toThrow(MacVerificationFailed::class);
});

test('refuses a master key file located inside base_path()', function () {
    $keyPath = base_path('vault-doctor-test-master.key');
    file_put_contents($keyPath, random_bytes(32));
    config(['vault.master_key_path' => $keyPath]);

    try {
        $manager = new KeyManager;
        expect(fn () => $manager->wrap($manager->generateDek()))->toThrow(InvalidMasterKey::class);
    } finally {
        @unlink($keyPath);
    }
});

test('refuses a missing master key file', function () {
    config(['vault.master_key_path' => base_path('../this-file-does-not-exist.key')]);

    $manager = new KeyManager;
    expect(fn () => $manager->wrap($manager->generateDek()))->toThrow(InvalidMasterKey::class);
});

test('refuses a master key file shorter than 32 bytes', function () {
    $keyPath = tempnam(sys_get_temp_dir(), 'vault_short_key_');
    file_put_contents($keyPath, random_bytes(16));
    config(['vault.master_key_path' => $keyPath]);

    try {
        $manager = new KeyManager;
        expect(fn () => $manager->wrap($manager->generateDek()))->toThrow(InvalidMasterKey::class);
    } finally {
        @unlink($keyPath);
    }
});

test('wrap() called twice on the identical DEK returns different ciphertexts', function () {
    $manager = new KeyManager;
    $dek = $manager->generateDek();

    $first = $manager->wrap($dek);
    $second = $manager->wrap($dek);

    expect($first)->not->toBe($second);
});

test('wrap/unwrap round-trips across 100 iterations', function () {
    $manager = new KeyManager;

    for ($i = 0; $i < 100; $i++) {
        $dek = $manager->generateDek();
        expect($manager->unwrap($manager->wrap($dek)))->toBe($dek);
    }
});

test('tampering with any byte of a wrapped value causes unwrap() to throw', function () {
    $manager = new KeyManager;
    $wrapped = $manager->wrap($manager->generateDek());
    $raw = base64_decode($wrapped, true);

    for ($i = 0; $i < strlen($raw); $i++) {
        $tamperedRaw = $raw;
        $tamperedRaw[$i] = chr(ord($tamperedRaw[$i]) ^ 0x01);
        $tampered = base64_encode($tamperedRaw);

        expect(fn () => $manager->unwrap($tampered))->toThrow(MacVerificationFailed::class);
    }
});

test('wrapped output length matches the nonce + tag + ciphertext format exactly', function () {
    $manager = new KeyManager;
    $wrapped = $manager->wrap($manager->generateDek());
    $raw = base64_decode($wrapped, true);

    expect(strlen($raw))->toBe(KeyManager::WRAPPED_LENGTH)
        ->and(KeyManager::WRAPPED_LENGTH)->toBe(KeyManager::NONCE_LENGTH + KeyManager::TAG_LENGTH + KeyManager::DEK_LENGTH);
});

test('caches the KEK per instance rather than re-reading the file on every call', function () {
    $keyPath = tempnam(sys_get_temp_dir(), 'vault_key_');
    file_put_contents($keyPath, random_bytes(32));
    config(['vault.master_key_path' => $keyPath]);

    try {
        $manager = new KeyManager;
        $dek = $manager->generateDek();
        $wrapped = $manager->wrap($dek);

        // Delete the key file after first use — if the manager re-read it,
        // this second call would fail.
        unlink($keyPath);

        expect($manager->unwrap($wrapped))->toBe($dek);
    } finally {
        @unlink($keyPath);
    }
});
