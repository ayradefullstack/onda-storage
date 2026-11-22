<?php

declare(strict_types=1);

/**
 * Child process of LoginLockoutMysqlTest: one independent PHP process, with its
 * own PDO connection, running ONE login-lockout attempt against the isolated
 * MySQL test database, coordinated with the parent through barrier FILES (no
 * sleeps except where the scenario needs a lease to expire).
 *
 * usage: php lockout_worker.php <scenario> <role> <dir> <email> <ip>
 *
 * It refuses to do anything unless the environment it booted into is the
 * disposable one: mysql, database name ending in _test, cache store `database`.
 */

use App\Exceptions\Auth\LoginLeaseLostException;
use App\Exceptions\Auth\LoginLockedException;
use App\Exceptions\Auth\LoginLockoutBusyException;
use App\Services\Auth\LoginLockoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

[, $scenario, $role, $dir, $email, $ip] = $argv + [null, '', '', '', '', ''];

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");

if ($connection !== 'mysql'
    || ! str_ends_with($database, '_test')
    || config('cache.default') !== 'database'
    || config('auth-security.lockout.lock_store') !== 'database'
    || config('app.env') !== 'testing'
    || (config('cache.stores.database.connection') !== null && config('cache.stores.database.connection') !== $connection)
    || (config('cache.stores.database.lock_connection') !== null && config('cache.stores.database.lock_connection') !== $connection)) {
    fwrite(STDERR, "worker guard: refusing to run outside an isolated mysql *_test database\n");
    exit(3);
}

// Trust the database actually connected to, not only the configured name.
$connected = (string) $app['db']->connection()->selectOne('select database() as name')->name;

if ($connected !== $database) {
    fwrite(STDERR, "worker guard: connected database differs from the configured one\n");
    exit(3);
}

function waitFor(string $file, int $seconds = 40): void
{
    $until = microtime(true) + $seconds;

    while (! is_file($file)) {
        if (microtime(true) > $until) {
            fwrite(STDERR, "worker timeout waiting for {$file}\n");
            exit(4);
        }
        usleep(20_000);
    }
}

function signal(string $file): void
{
    file_put_contents($file, '1');
}

$service = $app->make(LoginLockoutService::class);
$result = ['role' => $role, 'ran' => false];

try {
    if ($scenario === 'attempt') {
        config(['auth-security.lockout.lock_wait_seconds' => 10]);
        signal("{$dir}/ready-{$role}");
        waitFor("{$dir}/go");

        try {
            $service->run($email, $ip, function () use (&$result) {
                $result['ran'] = true;

                throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
            });
            $result['outcome'] = 'verified';
        } catch (LoginLockedException $e) {
            $result['outcome'] = 'locked';
            $result['locked_until'] = $e->lockedUntil;
        } catch (ValidationException) {
            $result['outcome'] = 'failed';
        } catch (LoginLockoutBusyException) {
            $result['outcome'] = 'busy';
        }
    } elseif ($scenario === 'delayed' || $scenario === 'stolen') {
        // delayed: the lease (5 s) runs out by itself. stolen: it is taken over at once
        // (as a host with a skewed clock would), so only the refresh() fence can notice.
        config(['auth-security.lockout.lock_ttl_seconds' => $scenario === 'delayed' ? 5 : 30, 'auth-security.lockout.lock_wait_seconds' => 3]);

        if ($role === 'owner') {
            // A correct password, slowly verified: the lease (5 s) runs out while it works.
            try {
                $service->run($email, $ip, function () use ($dir, &$result) {
                    $result['ran'] = true;
                    signal("{$dir}/owner-in");
                    waitFor("{$dir}/other-done");

                    return 'verified';
                });
                $result['outcome'] = 'admitted';
            } catch (LoginLeaseLostException) {
                $result['outcome'] = 'lease_lost';
            }
        } else {
            waitFor("{$dir}/owner-in");
            if ($scenario === 'delayed') {
                sleep(6); // the owner's 5 s lease is over; its request is still running
            } else {
                Cache::store('database')->getStore()
                    ->lock('login-lockout:'.$service->scopeHash($email, $ip), 30)
                    ->forceRelease();
            }

            try {
                $service->run($email, $ip, function () use (&$result) {
                    $result['ran'] = true;

                    throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
                });
            } catch (ValidationException) {
                $result['outcome'] = 'failed';
            } catch (LoginLockoutBusyException) {
                $result['outcome'] = 'busy';
            } finally {
                signal("{$dir}/other-done");
            }
        }
    }
} catch (Throwable $e) {
    $result['outcome'] = 'error';
    $result['error'] = get_class($e);
}

file_put_contents("{$dir}/result-{$role}.json", json_encode($result));
