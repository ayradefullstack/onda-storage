<?php

declare(strict_types=1);

use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

dataset('vault_models', [
    'Work' => [Work::class],
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
