<?php

declare(strict_types=1);

use App\Domain\Vault\Crypto\CtrCipher;

/**
 * Proves CtrCipher::streamTransform never loads a large file whole: a 200
 * MiB round-trip must grow peak memory by well under 64 MiB. We measure the
 * *growth* in memory_get_peak_usage(true) around the operation rather than
 * its absolute value, because `php artisan test` bootstraps the full
 * framework before this test ever runs, so the process's absolute peak
 * already reflects that unrelated bootstrap cost. The growth attributable to
 * this operation is the honest measure of "does this load the file whole".
 *
 * Kept to 200 MiB (not multi-GB) since this machine has only one drive —
 * see CLAUDE.md phase 2 notes. The fixture is written and deleted within
 * this test.
 */
test('a 200 MiB file round-trips through streamTransform with bounded peak memory growth', function () {
    $cipher = new CtrCipher;
    $key = random_bytes(32);
    $nonce = random_bytes(8);

    $plainPath = tempnam(sys_get_temp_dir(), 'vault_plain_');
    $cipherPath = tempnam(sys_get_temp_dir(), 'vault_cipher_');
    $outPath = tempnam(sys_get_temp_dir(), 'vault_out_');

    try {
        $size = 200 * 1024 * 1024;
        $writeBlock = 1 * 1024 * 1024;
        $hashCtx = hash_init('sha256');

        $fh = fopen($plainPath, 'wb');
        $remaining = $size;

        while ($remaining > 0) {
            $block = random_bytes(min($writeBlock, $remaining));
            hash_update($hashCtx, $block);
            fwrite($fh, $block);
            $remaining -= strlen($block);
        }
        fclose($fh);

        $expectedHash = hash_final($hashCtx);

        $before = memory_get_peak_usage(true);

        $in = fopen($plainPath, 'rb');
        $out = fopen($cipherPath, 'wb');
        $processed = $cipher->streamTransform($in, $out, $key, $nonce, 0);
        fclose($in);
        fclose($out);

        expect($processed)->toBe($size);

        $in = fopen($cipherPath, 'rb');
        $out = fopen($outPath, 'wb');
        $cipher->streamTransform($in, $out, $key, $nonce, 0);
        fclose($in);
        fclose($out);

        $after = memory_get_peak_usage(true);
        $deltaMib = ($after - $before) / 1024 / 1024;

        fwrite(STDERR, sprintf(
            "\n[vault] 200 MiB round-trip peak memory — before: %.2f MiB, after: %.2f MiB, delta: %.2f MiB\n",
            $before / 1024 / 1024,
            $after / 1024 / 1024,
            $deltaMib,
        ));

        expect(hash_file('sha256', $outPath))->toBe($expectedHash);
        expect($deltaMib)->toBeLessThan(64.0);
    } finally {
        @unlink($plainPath);
        @unlink($cipherPath);
        @unlink($outPath);
    }
})->group('slow');
