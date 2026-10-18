<?php

declare(strict_types=1);

/*
 * The chunk size and MAC segment size are part of the ciphertext format.
 * They are literals in config/vault.php, never read from the environment.
 */

it('fixes the chunk and MAC segment sizes', function () {
    $config = require __DIR__.'/../../../config/vault.php';

    expect($config['chunk_size'])->toBe(8_388_608)
        ->and($config['chunk_size'] % 16)->toBe(0)
        ->and($config['mac_segment_size'])->toBe(1_048_576);
});

it('does not read either size from the environment', function () {
    $source = (string) file_get_contents(__DIR__.'/../../../config/vault.php');

    expect($source)->not->toContain('VAULT_CHUNK_SIZE')
        ->and($source)->not->toContain('VAULT_MAC_SEGMENT_SIZE');
});
