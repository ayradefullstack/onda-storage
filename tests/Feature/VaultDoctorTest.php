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
