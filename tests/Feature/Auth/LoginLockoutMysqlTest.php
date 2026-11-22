<?php

declare(strict_types=1);

use App\Models\Auth\LoginLockout;
use App\Services\Auth\LoginLockoutService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lockout behavior on production-compatible storage: real MySQL, the real
 * `database` cache lock, and INDEPENDENT PHP PROCESSES (tests/Support/
 * lockout_worker.php), coordinated with barrier files.
 *
 * OPT-IN. Skipped unless these are set (values are never printed):
 *   ONDA_LOCKOUT_MYSQL_TEST_DATABASE   must end in `_test`
 *   ONDA_LOCKOUT_MYSQL_TEST_HOST / _PORT / _USERNAME / _PASSWORD
 *
 * phpunit.xml still forces sqlite :memory: for the suite and
 * TestEnvironmentGuard still runs first; this file leaves the default
 * connection alone except inside a test body, where it points the default
 * connection and the `database` cache store at the isolated database, and it
 * restores everything in a finally. Every child process re-asserts the same
 * guards (mysql, *_test, cache store `database`) before it writes anything.
 */
const LMT_DB = 'ONDA_LOCKOUT_MYSQL_TEST_DATABASE';

function lmtEnabled(): bool
{
    return (string) getenv(LMT_DB) !== '';
}

/** @return array<string, string> */
function lmtChildEnv(): array
{
    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_URL' => '',
        'DB_HOST' => (string) getenv('ONDA_LOCKOUT_MYSQL_TEST_HOST'),
        'DB_PORT' => (string) (getenv('ONDA_LOCKOUT_MYSQL_TEST_PORT') ?: '3306'),
        'DB_USERNAME' => (string) getenv('ONDA_LOCKOUT_MYSQL_TEST_USERNAME'),
        'DB_PASSWORD' => (string) getenv('ONDA_LOCKOUT_MYSQL_TEST_PASSWORD'),
        'DB_DATABASE' => (string) getenv(LMT_DB),
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ];
}

/**
 * Points the default connection and the `database` cache store at the isolated
 * database, after proving it is that database. Returns the undo closure.
 */
function lmtUse(): Closure
{
    $database = (string) getenv(LMT_DB);
    expect(str_ends_with($database, '_test'))->toBeTrue('the MySQL test database must end in _test');

    $env = lmtChildEnv();
    $previous = [config('database.default'), config('cache.default'), config('database.connections.mysql')];

    config(['database.connections.mysql' => array_merge((array) config('database.connections.mysql'), [
        'url' => null,
        'host' => $env['DB_HOST'],
        'port' => $env['DB_PORT'],
        'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'],
        'database' => $database,
        'unix_socket' => '',
    ])]);
    DB::purge('mysql');
    config([
        'database.default' => 'mysql',
        'cache.default' => 'database',
        'cache.stores.database.connection' => null,
        'cache.stores.database.lock_connection' => null,
    ]);
    DB::setDefaultConnection('mysql');
    Cache::forgetDriver('database');

    // Confirm the RESOLVED target before any write.
    $resolved = (string) DB::selectOne('select database() as name')->name;
    expect($resolved)->toBe($database)
        ->and(str_ends_with($resolved, '_test'))->toBeTrue()
        ->and(DB::connection()->getDriverName())->toBe('mysql')
        ->and(config('auth-security.lockout.lock_store'))->toBe('database');

    Schema::dropIfExists('login_lockouts');
    Schema::dropIfExists('cache_locks');
    (require database_path('migrations/2026_11_19_090000_create_login_lockouts_table.php'))->up();
    Schema::create('cache_locks', function ($table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration')->index();
    });

    return function () use ($previous): void {
        Schema::dropIfExists('login_lockouts');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('lockout_email_probe_unicode');
        Schema::dropIfExists('lockout_email_probe_0900');
        config([
            'database.default' => $previous[0],
            'cache.default' => $previous[1],
            'database.connections.mysql' => $previous[2],
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection($previous[0]);
        Cache::forgetDriver('database');
    };
}

/**
 * Runs the scenario's workers as independent PHP processes and returns their
 * result files, keyed by role.
 *
 * @param  list<string>  $roles
 * @return array<string, array<string, mixed>>
 */
function lmtRun(string $scenario, array $roles, string $email, string $ip, ?Closure $afterReady = null): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'onda-lockout-'.Str::random(10);
    mkdir($dir);
    $script = base_path('tests/Support/lockout_worker.php');
    $env = array_merge(getenv(), $_ENV, lmtChildEnv());
    $procs = [];

    foreach ($roles as $role) {
        $procs[$role] = proc_open(
            [PHP_BINARY, $script, $scenario, $role, $dir, $email, $ip],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );
        $procs[$role] = [$procs[$role], $pipes];
    }

    try {
        if ($scenario === 'attempt') {
            $until = microtime(true) + 40;
            while (count(glob("{$dir}/ready-*") ?: []) < count($roles)) {
                if (microtime(true) > $until) {
                    throw new RuntimeException('workers did not become ready');
                }
                usleep(20_000);
            }
            $afterReady?->__invoke();
            file_put_contents("{$dir}/go", '1');
        }

        $until = microtime(true) + 90;
        foreach ($procs as [$proc]) {
            while (proc_get_status($proc)['running']) {
                if (microtime(true) > $until) {
                    throw new RuntimeException('workers did not finish');
                }
                usleep(50_000);
            }
        }

        $results = [];
        foreach ($roles as $role) {
            $file = "{$dir}/result-{$role}.json";
            $stderr = stream_get_contents($procs[$role][1][2]);
            expect(is_file($file))->toBeTrue("worker {$role} produced no result: ".substr((string) $stderr, 0, 300));
            $results[$role] = json_decode((string) file_get_contents($file), true);
        }

        return $results;
    } finally {
        foreach ($procs as [$proc, $pipes]) {
            if (is_resource($proc)) {
                if (proc_get_status($proc)['running']) {
                    proc_terminate($proc);
                }
                foreach ($pipes as $pipe) {
                    is_resource($pipe) && fclose($pipe);
                }
                proc_close($proc);
            }
        }
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
    }
}

function lmtScope(string $email, string $ip): string
{
    return app(LoginLockoutService::class)->scopeHash($email, $ip);
}

test('simultaneous first attempts on one scope are serialized: exactly 3 verifications, 1 lock', function () {
    if (! lmtEnabled()) {
        $this->markTestSkipped('set ONDA_LOCKOUT_MYSQL_TEST_* to run against an isolated MySQL *_test database');
    }

    $undo = lmtUse();

    try {
        $results = lmtRun('attempt', ['w1', 'w2', 'w3', 'w4'], 'first@example.com', '198.51.100.1');
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);

        expect($outcomes)->toBe(['failed', 'failed', 'locked', 'locked'])
            ->and(count(array_filter(array_column($results, 'ran'))))->toBe(3);

        $rows = LoginLockout::query()->where('scope_hash', lmtScope('first@example.com', '198.51.100.1'))->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->failed_count)->toBe(3)
            ->and($rows[0]->locked_until)->not->toBeNull();

        // Everyone who reported a lock saw the SAME locked_until.
        $untils = array_unique(array_filter(array_column($results, 'locked_until')));
        expect($untils)->toHaveCount(1);
    } finally {
        $undo();
    }
});

test('simultaneous attempts at the threshold: one verification crosses it, the rest are refused without verifying', function () {
    if (! lmtEnabled()) {
        $this->markTestSkipped('set ONDA_LOCKOUT_MYSQL_TEST_* to run against an isolated MySQL *_test database');
    }

    $undo = lmtUse();

    try {
        LoginLockout::create([
            'scope_hash' => lmtScope('edge@example.com', '198.51.100.2'),
            'failed_count' => 2,
            'window_started_at' => now()->getTimestamp(),
        ]);

        $results = lmtRun('attempt', ['w1', 'w2', 'w3'], 'edge@example.com', '198.51.100.2');

        expect(array_unique(array_column($results, 'outcome')))->toBe(['locked'])
            ->and(count(array_filter(array_column($results, 'ran'))))->toBe(1);

        $row = LoginLockout::query()->where('scope_hash', lmtScope('edge@example.com', '198.51.100.2'))->first();
        expect($row->failed_count)->toBe(3)->and($row->locked_until)->not->toBeNull();
    } finally {
        $undo();
    }
});

test('a delayed owner whose lease expired is not admitted, and the newer state survives', function () {
    if (! lmtEnabled()) {
        $this->markTestSkipped('set ONDA_LOCKOUT_MYSQL_TEST_* to run against an isolated MySQL *_test database');
    }

    $undo = lmtUse();

    try {
        LoginLockout::create([
            'scope_hash' => lmtScope('slow@example.com', '198.51.100.3'),
            'failed_count' => 1,
            'window_started_at' => now()->getTimestamp(),
        ]);

        $results = lmtRun('delayed', ['owner', 'other'], 'slow@example.com', '198.51.100.3');

        expect($results['owner']['outcome'])->toBe('lease_lost')
            ->and($results['other']['outcome'])->toBe('failed');

        // The newer failure survived the late owner (1 seeded + 1 from the other request).
        $row = LoginLockout::query()->where('scope_hash', lmtScope('slow@example.com', '198.51.100.3'))->first();
        expect($row)->not->toBeNull()->and($row->failed_count)->toBe(2);
    } finally {
        $undo();
    }
});

test('a lease taken over while the owner is still young is caught by the refresh fence, not by elapsed time', function () {
    if (! lmtEnabled()) {
        $this->markTestSkipped('set ONDA_LOCKOUT_MYSQL_TEST_* to run against an isolated MySQL *_test database');
    }

    $undo = lmtUse();

    try {
        LoginLockout::create([
            'scope_hash' => lmtScope('stolen@example.com', '198.51.100.4'),
            'failed_count' => 1,
            'window_started_at' => now()->getTimestamp(),
        ]);

        $results = lmtRun('stolen', ['owner', 'other'], 'stolen@example.com', '198.51.100.4');

        expect($results['owner']['outcome'])->toBe('lease_lost')
            ->and($results['other']['outcome'])->toBe('failed');

        $row = LoginLockout::query()->where('scope_hash', lmtScope('stolen@example.com', '198.51.100.4'))->first();
        expect($row->failed_count)->toBe(2);
    } finally {
        $undo();
    }
});

test('every email spelling MySQL treats as the same account has the same lockout scope', function () {
    if (! lmtEnabled()) {
        $this->markTestSkipped('set ONDA_LOCKOUT_MYSQL_TEST_* to run against an isolated MySQL *_test database');
    }

    $undo = lmtUse();

    try {
        $pairs = [
            ['user@example.com', 'user@example.com '],
            ['user@example.com', "user@example.com\u{00A0}"],
            ['user@example.com', "us\u{00AD}er@example.com"],
            ['user@example.com', "us\u{200B}er@example.com"],
            ['user@example.com', "\u{FEFF}user@example.com"],
            ['user@example.com', 'usér@example.com'],
            ['user@example.com', 'ùser@example.com'],
            ['user@example.com', 'üser@example.com'],
            ['user@example.com', 'ｕser@example.com'],
            ['user@example.com', 'user@exämple.com'],
            ['strasse@example.com', 'straße@example.com'],
            ['user@example.com', "user@example.com\t"],
            ['user@example.com', 'user@example.com.'],
            ['user@example.com', 'user+tag@example.com'],
        ];
        $service = app(LoginLockoutService::class);
        $report = [];

        foreach (['lockout_email_probe_unicode' => 'utf8mb4_unicode_ci', 'lockout_email_probe_0900' => 'utf8mb4_0900_ai_ci'] as $table => $collation) {
            DB::statement("create table {$table} (email varchar(255) collate {$collation} not null) default charset utf8mb4");

            foreach ($pairs as [$canonical, $variant]) {
                DB::table($table)->truncate();
                DB::table($table)->insert(['email' => $canonical]);

                // The real login lowercases the username (CanonicalizeUsername) then looks it up with `where email = ?`.
                $matches = DB::table($table)->where('email', Str::lower($variant))->count() === 1;
                $sameScope = $service->scopeHash($canonical, '203.0.113.9') === $service->scopeHash($variant, '203.0.113.9');
                $report[] = [$collation, bin2hex($variant), $matches, $sameScope];

                if ($matches) {
                    expect($sameScope)->toBeTrue("{$collation}: MySQL matches a spelling the lockout scope keeps apart: ".bin2hex($variant));
                }
            }
        }

        // The compare must have exercised both outcomes, or it proves nothing.
        expect(array_filter($report, fn ($r) => $r[2]))->not->toBeEmpty()
            ->and(array_filter($report, fn ($r) => ! $r[2]))->not->toBeEmpty();
    } finally {
        $undo();
    }
});
