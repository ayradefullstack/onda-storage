<?php

declare(strict_types=1);

use App\Domain\Vault\Value\TempFile;

test('destructor removes the file when the object goes out of scope normally', function () {
    $path = tempnam(sys_get_temp_dir(), 'vault_temp_');
    file_put_contents($path, 'plaintext');

    (function () use ($path) {
        $file = new TempFile($path);
        expect(is_file($file->path))->toBeTrue();
    })();

    expect(is_file($path))->toBeFalse();
});

test('destructor removes the file even when the scope exits via an exception', function () {
    $path = tempnam(sys_get_temp_dir(), 'vault_temp_');
    file_put_contents($path, 'plaintext');

    try {
        (function () use ($path) {
            $file = new TempFile($path);
            throw new RuntimeException('boom mid-pipeline');
        })();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('boom mid-pipeline');
    }

    expect(is_file($path))->toBeFalse();
});

test('destructing a TempFile whose path was already deleted is a no-op', function () {
    $path = tempnam(sys_get_temp_dir(), 'vault_temp_');
    unlink($path);

    $file = new TempFile($path);
    unset($file);

    expect(is_file($path))->toBeFalse();
});
