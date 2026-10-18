<?php

declare(strict_types=1);

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every enum column, as the migrations declare it:
 * `unsignedTinyInteger('col')… ->comment(SomeEnum::dbComment())` inside
 * `Schema::create('table', …)`, the alias resolved through that migration's
 * own `use` lines.
 *
 * @return list<array{table: string, column: string, enum: class-string}>
 */
function enumColumnsFromMigrations(): array
{
    $columns = [];

    foreach (glob(database_path('migrations/*.php')) as $file) {
        $source = (string) file_get_contents($file);

        preg_match_all('/^use (App\\\\Enums\\\\[\w\\\\]+?)(?: as (\w+))?;/m', $source, $uses, PREG_SET_ORDER);
        $aliases = [];
        foreach ($uses as $use) {
            $aliases[$use[2] ?? '' ?: class_basename($use[1])] = $use[1];
        }

        preg_match_all("/Schema::create\\('(\\w+)'(.*?)\\n        \\}\\);/s", $source, $tables, PREG_SET_ORDER);

        foreach ($tables as [, $table, $body]) {
            preg_match_all("/->unsignedTinyInteger\\('(\\w+)'\\)[^;]*?->comment\\((\\w+)::dbComment\\(\\)\\)/s", $body, $found, PREG_SET_ORDER);

            foreach ($found as [, $column, $alias]) {
                $columns[] = ['table' => $table, 'column' => $column, 'enum' => $aliases[$alias] ?? $alias];
            }
        }
    }

    return $columns;
}

/** @return array<string, Model> table => model instance */
function modelsByTable(): array
{
    $models = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $model = new $class;
            $models[$model->getTable()] = $model;
        }
    }

    return $models;
}

test('every class under App\Enums is an int-backed enum that implements HasPresentation and uses EnumHelpers', function () {
    $files = glob(app_path('Enums/*/*.php'));
    $files = array_values(array_filter($files, fn (string $f): bool => ! str_contains($f, 'Contracts') && ! str_contains($f, 'Concerns')));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $relative = Str::after(str_replace('\\', '/', $file), str_replace('\\', '/', app_path()).'/');
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

        expect(enum_exists($class))->toBeTrue("{$class} must be an enum (path = namespace)");

        $reflection = new ReflectionEnum($class);

        expect((string) $reflection->getBackingType())->toBe('int', "{$class} must be int-backed")
            ->and($reflection->implementsInterface(HasPresentation::class))->toBeTrue("{$class} must implement HasPresentation")
            ->and(in_array(EnumHelpers::class, $reflection->getTraitNames(), true))->toBeTrue("{$class} must use EnumHelpers")
            ->and(class_basename($class))->toEndWith('Enum');

        foreach ($class::cases() as $case) {
            expect($case->name)->toMatch('/^[A-Z][A-Z0-9_]*$/')
                ->and($case->value)->toBeGreaterThanOrEqual(1);
        }
    }
});

test('no app migration declares a native enum() column', function () {
    foreach (glob(database_path('migrations/*.php')) as $file) {
        expect((string) file_get_contents($file))->not->toContain('->enum(', basename($file));
    }
});

test('every enum column is a commented tinyint whose owning model casts it to that enum', function () {
    $columns = enumColumnsFromMigrations();
    $models = modelsByTable();

    // The approved set (Phase 2a): 16 columns over 13 tables.
    expect($columns)->toHaveCount(16)
        ->and(collect($columns)->pluck('table')->unique())->toHaveCount(13);

    foreach ($columns as ['table' => $table, 'column' => $column, 'enum' => $enum]) {
        expect(enum_exists($enum))->toBeTrue("{$table}.{$column}: {$enum} does not exist")
            ->and($models)->toHaveKey($table)
            ->and($models[$table]->getCasts()[$column] ?? null)->toBe($enum, "{$table}.{$column} must be cast to {$enum}");
    }

    // On MySQL the live schema is checked too (SQLite keeps no column comments).
    if (DB::getDriverName() === 'mysql') {
        foreach ($columns as ['table' => $table, 'column' => $column, 'enum' => $enum]) {
            $row = DB::selectOne(
                'select column_type, column_comment from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                [$table, $column],
            );

            expect($row->column_type)->toBe('tinyint unsigned')
                ->and($row->column_comment)->toBe($enum::dbComment());
        }
    }
});

test('every model cast to an application enum has a migration column declaring it', function () {
    $declared = collect(enumColumnsFromMigrations())->map(fn (array $c): string => "{$c['table']}.{$c['column']}={$c['enum']}")->all();

    foreach (modelsByTable() as $table => $model) {
        foreach ($model->getCasts() as $column => $cast) {
            if (is_string($cast) && is_subclass_of($cast, HasPresentation::class)) {
                expect($declared)->toContain("{$table}.{$column}={$cast}");
            }
        }
    }
});

test('app code holds no status, action, kind or direction string literal for an enum column', function () {
    $values = implode('|', [
        'draft', 'submitted', 'under_review', 'registered', 'rejected',
        'uploading', 'assembling', 'scanning', 'processing', 'ready', 'failed', 'quarantined',
        'pending', 'aborted', 'completed', 'unsupported',
        'stream', 'download', 'preview', 'delete', 'purge', 'deposit', 'inspect', 'admin_consult',
        'bytes_released', 'bytes_kept', 'bytes_missing', 'consult_original',
        'poster', 'waveform', 'thumbnail', 'ltr', 'rtl',
    ]);
    $columns = 'status|from_status|to_status|action|direction|type_gestion';

    $patterns = [
        // ->where('status', 'ready'), ->whereIn('oeuvres.status', ['draft', …])
        "/->(?:where|orWhere|whereNot|whereIn|whereNotIn)\\(\\s*'(?:\\w+\\.)?(?:{$columns})'\\s*,\\s*(?:'!?=',\\s*)?\\[?\\s*'(?:{$values})'/",
        // $model->status === 'ready', $session->status = 'aborted'
        "/->(?:{$columns})\\s*(?:===|!==|==|!=|=)\\s*'(?:{$values})'/",
        // 'status' => 'ready' (a write or a query array)
        "/'(?:{$columns})'\\s*=>\\s*'(?:{$values})'/",
        // raw SQL: mf.status = 'ready', status in ('failed', …)
        "/\\b(?:{$columns})\\s*(?:=|!=|<>|in|not in)\\s*\\(?\\s*'(?:{$values})'/i",
        // media_variants.kind (consultation_assets.kind is a documented plain string)
        "/MediaVariant::[^;]*->where\\('kind',\\s*'/",
    ];

    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Enums'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('/^\s*(\*|\/\/)/', $line)) {
                continue; // comments may name a status
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $offenders[] = Str::after($file->getPathname(), base_path()).':'.($number + 1).': '.trim($line);
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
