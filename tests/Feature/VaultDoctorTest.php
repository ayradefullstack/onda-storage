<?php

use App\Console\Commands\VaultDoctorCommand;
use App\Domain\Vault\Doctor\CheckResult;
use App\Domain\Vault\Doctor\VaultDoctor;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

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

/*
|--------------------------------------------------------------------------
| Web endpoint outside `local`
|--------------------------------------------------------------------------
*/

const RUNTIME_CHECK_KEYS = [
    'php.int_size',
    'php.version',
    'php.sapi',
    'ini.post_max_size',
    'ini.upload_max_filesize',
    'ini.memory_limit',
    'ini.max_execution_time',
    'ini.max_input_time',
    'disable_functions',
];

function signedDoctorUrl(?DateTimeInterface $expires = null): string
{
    return URL::temporarySignedRoute('vault-doctor', $expires ?? now()->addMinutes(2));
}

test('production with the flag off returns 404, signed or not', function () {
    app()->instance('env', 'production');
    config(['vault.doctor_web_enabled' => false]);

    $this->get('/_vault-doctor')->assertNotFound();
    $this->get(signedDoctorUrl())->assertNotFound();
});

test('production with the flag on accepts a valid signature', function () {
    app()->instance('env', 'production');
    config(['vault.doctor_web_enabled' => true]);

    $this->get(signedDoctorUrl())
        ->assertOk()
        ->assertJsonStructure(['sapi', 'checks']);
});

test('production with the flag on refuses an admin session without a signature', function () {
    app()->instance('env', 'production');
    config(['vault.doctor_web_enabled' => true]);

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin'));

    $this->actingAs($admin)->get('/_vault-doctor')->assertNotFound();
});

test('production with the flag on rejects an expired or tampered signature', function () {
    app()->instance('env', 'production');
    config(['vault.doctor_web_enabled' => true]);

    $this->get(signedDoctorUrl(now()->subMinute()))->assertForbidden();

    $valid = signedDoctorUrl();
    $tampered = substr($valid, 0, -1).(str_ends_with($valid, 'a') ? 'b' : 'a');

    $this->get($tampered)->assertForbidden();
});

test('the non-local payload keys are exactly the runtime allowlist', function () {
    app()->instance('env', 'production');
    config(['vault.doctor_web_enabled' => true]);

    $keys = collect($this->get(signedDoctorUrl())->assertOk()->json('checks'))->pluck('key')->all();

    expect($keys)->toBe(RUNTIME_CHECK_KEYS);
});

test('the non-local payload touches no vault disk, the master key or the queue tables', function () {
    app()->instance('env', 'production');
    config(['vault.doctor_web_enabled' => true]);

    $disks = ['vault', 'incoming', 'work', 'variants'];

    foreach ($disks as $disk) {
        Storage::fake($disk);
        config(["filesystems.disks.$disk.root" => Storage::disk($disk)->path('')]);
    }

    $url = signedDoctorUrl();

    // Record every config key read while the request runs: resolving a disk
    // root or the master key path is the first step of every check that
    // would touch them, so "never read" is proof of "never touched".
    $recorder = new class(config()->all()) extends Repository
    {
        /** @var list<string> */
        public array $reads = [];

        public function get($key, $default = null)
        {
            if (is_string($key)) {
                $this->reads[] = $key;
            }

            return parent::get($key, $default);
        }
    };
    app()->instance('config', $recorder);
    Facade::clearResolvedInstance('config');

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->get($url)->assertOk();

    foreach ($disks as $disk) {
        expect(Storage::disk($disk)->allFiles())->toBeEmpty()
            ->and($recorder->reads)->not->toContain("filesystems.disks.$disk.root");
    }

    expect($recorder->reads)->not->toContain('vault.master_key_path')
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'jobs'))->all())->toBeEmpty();
});

test('the non-local endpoint is rate limited', function () {
    app()->instance('env', 'production');
    config(['vault.doctor_web_enabled' => true]);

    $url = signedDoctorUrl();

    foreach (range(1, 5) as $_) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertTooManyRequests();
});

test('local keeps the full payload for a valid signature', function () {
    app()->instance('env', 'local');

    $keys = collect($this->get(signedDoctorUrl())->assertOk()->json('checks'))->pluck('key');

    expect($keys->all())->toBe(app(VaultDoctor::class)->run()->pluck('key')->all())
        ->and($keys->all())->toContain('vault_key.set');
});

test('local still accepts an admin session without a signature', function () {
    app()->instance('env', 'local');

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin'));

    $this->actingAs($admin)->get('/_vault-doctor')->assertOk();
});

/*
|--------------------------------------------------------------------------
| vault:doctor --fpm resolve override
|--------------------------------------------------------------------------
*/

function fpmHttpOptions(string $url, ?string $ip): array
{
    $method = new ReflectionMethod(VaultDoctorCommand::class, 'fpmHttpOptions');

    return $method->invoke(new VaultDoctorCommand, $url, $ip);
}

test('no resolve override adds no curl options', function () {
    expect(fpmHttpOptions('https://onda-storage.ayrad.dz/_vault-doctor', null))->toBe([])
        ->and(fpmHttpOptions('https://onda-storage.ayrad.dz/_vault-doctor', ''))->toBe([]);
});

test('a resolve override pins the host on the default https port', function () {
    expect(fpmHttpOptions('https://onda-storage.ayrad.dz/_vault-doctor?signature=x', '10.10.10.135'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['onda-storage.ayrad.dz:443:10.10.10.135']]]);
});

test('a resolve override keeps a non-default port and defaults http to 80', function () {
    expect(fpmHttpOptions('https://onda.test:8443/_vault-doctor', '10.10.10.135'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['onda.test:8443:10.10.10.135']]])
        ->and(fpmHttpOptions('http://onda.test/_vault-doctor', '10.10.10.135'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['onda.test:80:10.10.10.135']]]);
});

test('a resolve override brackets an IPv6 address', function () {
    expect(fpmHttpOptions('https://onda.test/_vault-doctor', '::1'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['onda.test:443:[::1]']]]);
});

test('an invalid resolve IP fails the check with an explicit message and sends nothing', function () {
    Http::fake();
    config(['vault.doctor_resolve_ip' => '10.10.10.999']);

    $exit = Artisan::call('vault:doctor', ['--fpm' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("VAULT_DOCTOR_RESOLVE_IP is not a valid IP address: '10.10.10.999'");

    Http::assertNothingSent();
});

test('an active resolve override is shown in the output', function () {
    Http::fake(['*' => Http::response(['sapi' => 'fpm-fcgi', 'checks' => []])]);
    config(['vault.doctor_resolve_ip' => '10.10.10.135']);

    Artisan::call('vault:doctor', ['--fpm' => true]);

    expect(Artisan::output())->toContain('resolved via 10.10.10.135');
});

test('a 404 outside local prints the enable hint', function () {
    Http::fake(['*' => Http::response('', 404)]);

    Artisan::call('vault:doctor', ['--fpm' => true]);

    expect(Artisan::output())->toContain('Endpoint disabled — set VAULT_DOCTOR_WEB_ENABLED=true temporarily');
});
