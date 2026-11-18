<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

/**
 * Guard: a test run must never write under the vault roots configured in
 * .env. If someone bypasses Tests\TestCase (or points a disk back at the env
 * root), this fails before a real deposit can be touched.
 */
function isolationEnvRoots(): array
{
    return array_filter([
        'vault' => env('VAULT_DISK_ROOT'),
        'incoming' => env('INCOMING_DISK_ROOT'),
        'work' => env('WORK_DISK_ROOT'),
        'variants' => env('VARIANTS_DISK_ROOT'),
    ]);
}

function isolationNormalise(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

test('every vault disk root is a temporary directory, never an .env root', function () {
    $temp = isolationNormalise(sys_get_temp_dir());

    foreach (['vault', 'incoming', 'work', 'variants'] as $disk) {
        $root = isolationNormalise((string) config("filesystems.disks.{$disk}.root"));

        expect($root)->toStartWith($temp)
            ->and($root)->toContain('/onda-test-');

        foreach (isolationEnvRoots() as $real) {
            expect(isolationNormalise((string) $real))->not->toBe($root);
        }
    }
});

test('writing through every vault disk lands in the temporary root and leaves the real roots untouched', function () {
    $marker = 'isolation-guard/'.bin2hex(random_bytes(6)).'.tmp';
    $real = isolationEnvRoots();

    foreach (['vault', 'incoming', 'work', 'variants'] as $disk) {
        Storage::disk($disk)->put($marker, 'x');

        expect(is_file($this->storageRoot."/{$disk}/{$marker}"))->toBeTrue();

        if (isset($real[$disk])) {
            expect(is_file(isolationNormalise((string) $real[$disk]).'/'.$marker))->toBeFalse();
        }
    }
});

test('the temporary roots are removed after the test', function () {
    // tearDown of the earlier tests in this process already ran: only this
    // test's own root may remain.
    $mine = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'onda-test-'.getmypid().'-*') ?: [];

    expect($mine)->toHaveCount(1)
        ->and(isolationNormalise($mine[0]))->toBe($this->storageRoot);
});
