<?php

use App\Domain\Vault\Doctor\CheckResult;
use App\Domain\Vault\Doctor\VaultDoctor;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('the offset proof check fails when the target cannot be written to', function () {
    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'offsetProofCheck');
    $method->setAccessible(true);

    // Windows does not enforce directory ACLs through fopen() the way POSIX
    // chmod does, so a portable "cannot write here" stand-in is a root whose
    // parent path does not exist at all.
    $unwritableRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vault-doctor-missing-'.uniqid();

    $result = $method->invoke($doctor, 'vault', $unwritableRoot);

    expect($result)->toBeInstanceOf(CheckResult::class)
        ->and($result->status)->toBe(CheckResult::FAIL);
});

test('the partition identity check fails when disks are on different devices', function () {
    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'partitionResult');
    $method->setAccessible(true);

    $result = $method->invoke($doctor, ['vault' => 'C:', 'incoming' => 'D:']);

    expect($result->status)->toBe(CheckResult::FAIL);
});

test('the partition identity check passes when disks share a device', function () {
    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'partitionResult');
    $method->setAccessible(true);

    $result = $method->invoke($doctor, ['vault' => 'C:', 'incoming' => 'C:']);

    expect($result->status)->toBe(CheckResult::PASS);
});

test('--json output is valid JSON and includes every check', function () {
    Artisan::call('vault:doctor', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toBeArray()
        ->and($decoded)->not->toBeEmpty();

    foreach ($decoded as $row) {
        expect($row)->toHaveKeys(['key', 'label', 'status', 'value', 'rationale', 'sapi_sensitive']);
    }

    expect(count($decoded))->toBe(app(VaultDoctor::class)->run()->count());
});

function assetSourceCheckArgs(): array
{
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vault-doctor-assets-'.uniqid();
    mkdir($tmp, 0777, true);
    mkdir($tmp.'/js', 0777, true);

    return [
        'hot' => $tmp.'/hot',
        'manifest' => $tmp.'/manifest.json',
        'jsDir' => $tmp.'/js',
    ];
}

test('asset source check passes when a Vite dev server is active', function () {
    $paths = assetSourceCheckArgs();
    file_put_contents($paths['hot'], 'http://localhost:5173');

    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'assetSourceCheck');
    $method->setAccessible(true);

    $result = $method->invoke($doctor, $paths['hot'], $paths['manifest'], $paths['jsDir'], 'local');

    expect($result->status)->toBe(CheckResult::PASS)
        ->and($result->value)->toContain('dev server');
});

test('asset source check fails when neither a dev server nor a build exists', function () {
    $paths = assetSourceCheckArgs();

    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'assetSourceCheck');
    $method->setAccessible(true);

    $result = $method->invoke($doctor, $paths['hot'], $paths['manifest'], $paths['jsDir'], 'local');

    expect($result->status)->toBe(CheckResult::FAIL);
});

test('asset source check warns locally when a source file is newer than the build manifest', function () {
    $paths = assetSourceCheckArgs();
    file_put_contents($paths['manifest'], '{}');
    touch($paths['manifest'], time() - 3600);

    $sourceFile = $paths['jsDir'].'/app.ts';
    file_put_contents($sourceFile, '// edited after the build');
    touch($sourceFile, time());

    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'assetSourceCheck');
    $method->setAccessible(true);

    $result = $method->invoke($doctor, $paths['hot'], $paths['manifest'], $paths['jsDir'], 'local');

    expect($result->status)->toBe(CheckResult::WARN)
        ->and($result->value)->toContain('stale')
        ->and($result->rationale)->toContain('npm run build');
});

test('asset source check passes locally when the build is newer than every source file', function () {
    $paths = assetSourceCheckArgs();

    $sourceFile = $paths['jsDir'].'/app.ts';
    file_put_contents($sourceFile, '// old');
    touch($sourceFile, time() - 3600);

    file_put_contents($paths['manifest'], '{}');
    touch($paths['manifest'], time());

    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'assetSourceCheck');
    $method->setAccessible(true);

    $result = $method->invoke($doctor, $paths['hot'], $paths['manifest'], $paths['jsDir'], 'local');

    expect($result->status)->toBe(CheckResult::PASS);
});

test('asset source check never warns outside the local environment, even when stale', function () {
    $paths = assetSourceCheckArgs();
    file_put_contents($paths['manifest'], '{}');
    touch($paths['manifest'], time() - 3600);

    $sourceFile = $paths['jsDir'].'/app.ts';
    file_put_contents($sourceFile, '// edited after the build');
    touch($sourceFile, time());

    $doctor = new VaultDoctor;
    $method = new ReflectionMethod($doctor, 'assetSourceCheck');
    $method->setAccessible(true);

    $result = $method->invoke($doctor, $paths['hot'], $paths['manifest'], $paths['jsDir'], 'production');

    expect($result->status)->toBe(CheckResult::PASS)
        ->and($result->rationale)->toContain('expected outside local development');
});

test('the vault doctor endpoint returns 404 outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse();

    $response = $this->get('/_vault-doctor');

    $response->assertNotFound();
});

test('the vault doctor endpoint returns 403 for a non-admin user', function () {
    app()->instance('env', 'local');

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/_vault-doctor');

    $response->assertForbidden();
});
