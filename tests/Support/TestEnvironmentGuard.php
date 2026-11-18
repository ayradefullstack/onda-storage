<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Refuses to let the suite run against anything but a disposable database.
 *
 * Why: `RefreshDatabase` runs `migrate:fresh`. With a cached config
 * (`config:cache` / `optimize`) phpunit.xml's `<env>` values are ignored, so
 * the suite once ran against the developer's real MySQL database and wiped
 * it. This is checked BEFORE any trait boots, so no query is ever issued.
 */
final class TestEnvironmentGuard
{
    /**
     * @return list<string> every reason the run is unsafe; empty when safe
     */
    public static function violations(
        bool $configCached,
        bool $routesCached,
        string $appEnv,
        string $driver,
        string $database,
    ): array {
        $reasons = [];

        if ($configCached) {
            $reasons[] = 'the configuration is cached (run `php artisan config:clear`; never run config:cache or optimize on a dev machine)';
        }

        if ($routesCached) {
            $reasons[] = 'the routes are cached (run `php artisan route:clear`)';
        }

        if ($appEnv !== 'testing') {
            $reasons[] = "app.env is [{$appEnv}], not [testing]";
        }

        $disposable = ($driver === 'sqlite' && $database === ':memory:')
            || str_ends_with($database, '_test');

        if (! $disposable) {
            $reasons[] = "the default connection is [{$driver}] database [{$database}]: only sqlite :memory: or a database whose name ends in _test is allowed";
        }

        return $reasons;
    }

    public static function assertSafe(Application $app): void
    {
        $connection = (string) $app['config']->get('database.default');
        $reasons = self::violations(
            $app->configurationIsCached(),
            $app->routesAreCached(),
            (string) $app['config']->get('app.env'),
            (string) $app['config']->get("database.connections.{$connection}.driver"),
            (string) $app['config']->get("database.connections.{$connection}.database"),
        );

        if ($reasons !== []) {
            throw new \RuntimeException(
                "REFUSING TO RUN THE TEST SUITE: it would refresh a database that is not disposable.\n - ".implode("\n - ", $reasons),
            );
        }
    }
}
