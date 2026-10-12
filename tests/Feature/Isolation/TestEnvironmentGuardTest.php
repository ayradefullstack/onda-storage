<?php

declare(strict_types=1);

use Tests\Support\TestEnvironmentGuard;

test('the real suite environment passes the guard', function () {
    TestEnvironmentGuard::assertSafe($this->app);

    expect(true)->toBeTrue();
});

test('a cached config trips the guard', function () {
    $reasons = TestEnvironmentGuard::violations(true, false, 'testing', 'sqlite', ':memory:');

    expect($reasons)->toHaveCount(1)->and($reasons[0])->toContain('configuration is cached');
});

test('cached routes trip the guard', function () {
    expect(TestEnvironmentGuard::violations(false, true, 'testing', 'sqlite', ':memory:')[0])->toContain('routes are cached');
});

test('a non-testing environment trips the guard', function () {
    expect(TestEnvironmentGuard::violations(false, false, 'local', 'sqlite', ':memory:')[0])->toContain('not [testing]');
});

test('the incident: a cached config pinning the dev mysql database trips every check', function () {
    $reasons = TestEnvironmentGuard::violations(true, true, 'local', 'mysql', 'onda_storage');

    expect($reasons)->toHaveCount(4);
});

test('only sqlite memory or a *_test database is disposable', function () {
    expect(TestEnvironmentGuard::violations(false, false, 'testing', 'mysql', 'onda_storage_test'))->toBe([])
        ->and(TestEnvironmentGuard::violations(false, false, 'testing', 'sqlite', ':memory:'))->toBe([])
        ->and(TestEnvironmentGuard::violations(false, false, 'testing', 'sqlite', 'database/database.sqlite'))->toHaveCount(1)
        ->and(TestEnvironmentGuard::violations(false, false, 'testing', 'mysql', 'onda_storage'))->toHaveCount(1);
});

test('assertSafe refuses a live app whose default connection is the dev mysql database', function () {
    $original = config('database.default');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'onda_storage']);

    try {
        expect(fn () => TestEnvironmentGuard::assertSafe($this->app))
            ->toThrow(RuntimeException::class, 'REFUSING TO RUN THE TEST SUITE');
    } finally {
        // RefreshDatabase rolls back on the default connection at teardown:
        // it must still be the sqlite one that holds the open transaction.
        config(['database.default' => $original]);
    }
});
