<?php

declare(strict_types=1);

/*
 * Keeps .env.example honest: no dead keys, no required key missing, no
 * secret value committed. Plain file parsing, no app boot.
 */

const ENV_EXAMPLE_BASE = __DIR__.'/../..';

/**
 * Keys read by config/ with no default that may stay out of .env.example:
 * optional overrides and drivers this project does not use, where null means
 * "not used". A new no-default key must go in .env.example, not here.
 */
const OPTIONAL_NO_DEFAULT_KEYS = [
    'AWS_ACCESS_KEY_ID', 'AWS_BUCKET', 'AWS_DEFAULT_REGION', 'AWS_ENDPOINT', 'AWS_SECRET_ACCESS_KEY', 'AWS_URL',
    'CACHE_STORAGE_DISK', 'DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION', 'DB_CACHE_LOCK_TABLE',
    'DB_QUEUE_CONNECTION', 'DB_URL', 'DYNAMODB_ENDPOINT', 'LOG_SLACK_WEBHOOK_URL', 'LOG_STDERR_FORMATTER',
    'MAIL_LOG_CHANNEL', 'MAIL_SCHEME', 'MAIL_URL', 'MEMCACHED_PASSWORD', 'MEMCACHED_PERSISTENT_ID',
    'MEMCACHED_USERNAME', 'MYSQL_ATTR_SSL_CA', 'PAPERTRAIL_PORT', 'PAPERTRAIL_URL', 'POSTMARK_API_KEY',
    'REDIS_URL', 'REDIS_USERNAME', 'RESEND_API_KEY', 'SESSION_CONNECTION', 'SESSION_STORE',
    'SLACK_BOT_USER_DEFAULT_CHANNEL', 'SLACK_BOT_USER_OAUTH_TOKEN', 'SQS_SUFFIX',
];

/**
 * @return list<array{key: string, value: string, tags: list<string>}>
 */
function envExampleEntries(): array
{
    $entries = [];
    $comments = [];

    foreach (file(ENV_EXAMPLE_BASE.'/.env.example', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m) === 1) {
            preg_match_all('/\[([a-z]+)\]/', implode(' ', $comments), $tags);
            $entries[] = ['key' => $m[1], 'value' => trim($m[2], " \"'"), 'tags' => $tags[1]];
            $comments = [];
        } elseif (str_starts_with($line, '#')) {
            $comments[] = $line;
        } else {
            $comments = [];
        }
    }

    return $entries;
}

/**
 * Source text of every place a key may legitimately be read.
 */
function envReaderSources(): string
{
    $files = array_merge(
        glob(ENV_EXAMPLE_BASE.'/config/*.php') ?: [],
        glob(ENV_EXAMPLE_BASE.'/vendor/laravel/framework/config/*.php') ?: [],
        glob(ENV_EXAMPLE_BASE.'/vendor/*/*/config/*.php') ?: [],
        glob(ENV_EXAMPLE_BASE.'/vite.config.*') ?: [],
        [ENV_EXAMPLE_BASE.'/phpunit.xml'],
    );

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ENV_EXAMPLE_BASE.'/resources/js', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (in_array($file->getExtension(), ['ts', 'js', 'vue'], true)) {
            $files[] = $file->getPathname();
        }
    }

    return implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $files));
}

it('reads every key in .env.example somewhere', function () {
    $sources = envReaderSources();

    $dead = array_values(array_filter(
        array_column(envExampleEntries(), 'key'),
        fn (string $key): bool => preg_match(
            '/env\(\s*[\'"]'.$key.'[\'"]|import\.meta\.env\.'.$key.'\b|name="'.$key.'"/',
            $sources,
        ) !== 1,
    ));

    expect($dead)->toBe([]);
});

it('lists every config env() key that has no default', function () {
    $example = array_column(envExampleEntries(), 'key');
    $required = [];

    foreach (glob(ENV_EXAMPLE_BASE.'/config/*.php') ?: [] as $file) {
        foreach (file($file) ?: [] as $line) {
            if (preg_match('~^\s*(//|\*|#)~', $line) === 1) {
                continue;
            }

            preg_match_all('/env\(\s*\'([A-Z0-9_]+)\'\s*\)/', $line, $m);
            array_push($required, ...$m[1]);
        }
    }

    $missing = array_values(array_diff(array_unique($required), $example, OPTIONAL_NO_DEFAULT_KEYS));

    expect($missing)->toBe([]);
});

it('keeps every [secret] key empty in .env.example', function () {
    $secrets = array_filter(envExampleEntries(), fn (array $e): bool => in_array('secret', $e['tags'], true));

    expect($secrets)->not->toBeEmpty();

    $leaked = array_values(array_map(
        fn (array $e): string => $e['key'],
        array_filter($secrets, fn (array $e): bool => ! in_array($e['value'], ['', 'null'], true)),
    ));

    expect($leaked)->toBe([]);
});
