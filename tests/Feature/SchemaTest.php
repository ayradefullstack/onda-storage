<?php

declare(strict_types=1);

use App\Models\Commune;
use App\Models\Country;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\Oeuvre;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\Wilaya;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

dataset('vault_models', [
    'Oeuvre' => [Oeuvre::class],
    'MediaFile' => [MediaFile::class],
    'UploadSession' => [UploadSession::class],
    'MediaVariant' => [MediaVariant::class],
    'FileAccessLog' => [FileAccessLog::class],
    'StorageQuota' => [StorageQuota::class],
]);

test('every vault model persists via its factory', function (string $modelClass) {
    /** @var Model $model */
    $model = $modelClass::factory()->create();

    expect($model->exists)->toBeTrue()
        ->and($modelClass::query()->find($model->getKey()))->not->toBeNull();
})->with('vault_models');

test('upload_sessions has no deleted_at column and does not use SoftDeletes', function () {
    expect(Schema::hasColumn('upload_sessions', 'deleted_at'))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(UploadSession::class), true))->toBeFalse();
});

test('file_access_logs has no deleted_at column and does not use SoftDeletes', function () {
    expect(Schema::hasColumn('file_access_logs', 'deleted_at'))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(FileAccessLog::class), true))->toBeFalse();
});

dataset('soft_deletable_tables', [
    'users' => ['users', User::class],
    'oeuvres' => ['oeuvres', Oeuvre::class],
    'media_files' => ['media_files', MediaFile::class],
    'countries' => ['countries', Country::class],
    'wilayas' => ['wilayas', Wilaya::class],
    'communes' => ['communes', Commune::class],
]);

test('soft-deletable tables have both the deleted_at column and the SoftDeletes trait', function (string $table, string $modelClass) {
    expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue()
        ->and(in_array(SoftDeletes::class, class_uses_recursive($modelClass), true))->toBeTrue();
})->with('soft_deletable_tables');

test('media_variants and storage_quotas models do not use SoftDeletes', function (string $modelClass) {
    expect(in_array(SoftDeletes::class, class_uses_recursive($modelClass), true))->toBeFalse();
})->with([
    'MediaVariant' => [MediaVariant::class],
    'StorageQuota' => [StorageQuota::class],
]);

// See the soft-delete classification table in CLAUDE.md before changing this list.
test('deleted_at exists on exactly the classified soft-deletable tables', function () {
    $tablesWithDeletedAt = collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $table): bool => Schema::hasColumn($table, 'deleted_at'))
        ->sort()
        ->values()
        ->all();

    expect($tablesWithDeletedAt)->toBe([
        'communes', 'countries', 'media_files', 'oeuvres',
        'register_role_auteurs', 'register_type_colleges', 'register_type_members', 'register_types',
        'type_gestions', 'users', 'wilayas',
    ]);
});

test('file_access_logs has no updated_at column usage', function () {
    $log = FileAccessLog::factory()->create();

    expect($log->updated_at)->toBeNull();
});

test('two media_files rows can share one sha256_plain', function () {
    $hash = hash('sha256', 'identical-bytes');

    $first = MediaFile::factory()->create(['sha256_plain' => $hash]);
    $second = MediaFile::factory()->create(['sha256_plain' => $hash]);

    expect(MediaFile::where('sha256_plain', $hash)->count())->toBe(2)
        ->and($first->id)->not->toBe($second->id);
});

test('soft-deleting a media file keeps it in the quota sum until purged_at is set', function () {
    $mediaFile = MediaFile::factory()->create(['size_bytes' => 1_000_000, 'purged_at' => null]);

    $mediaFile->delete();

    expect($mediaFile->trashed())->toBeTrue();

    $quotaSum = MediaFile::withTrashed()->whereNull('purged_at')->sum('size_bytes');
    expect($quotaSum)->toBe(1_000_000);

    $mediaFile->forceFill(['purged_at' => now()])->save();

    $quotaSumAfterPurge = MediaFile::withTrashed()->whereNull('purged_at')->sum('size_bytes');
    expect($quotaSumAfterPurge)->toBe(0);
});

test('queue retry_after is set to 3600 to survive a long-running vault job', function () {
    expect(config('queue.connections.database.retry_after'))->toBe(3600);
});

test('storage_quotas.user_id is unique', function () {
    $quota = StorageQuota::factory()->create();

    expect(fn () => StorageQuota::factory()->create(['user_id' => $quota->user_id]))
        ->toThrow(QueryException::class);
});
