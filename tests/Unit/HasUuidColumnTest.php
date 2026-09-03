<?php

declare(strict_types=1);

use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\MediaVariant;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

dataset('uuid_models', [
    'Work' => [Work::class],
    'MediaFile' => [MediaFile::class],
    'UploadSession' => [UploadSession::class],
    'MediaVariant' => [MediaVariant::class],
    'FileAccessLog' => [FileAccessLog::class],
    'StorageQuota' => [StorageQuota::class],
]);

test('uuid is auto-assigned on creation as a UUIDv7', function (string $modelClass) {
    /** @var Model $model */
    $model = $modelClass::factory()->create();

    expect($model->uuid)->not->toBeNull();

    // UUIDv7 encodes its version in the first nibble of the third group:
    // xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx.
    $versionNibble = $model->uuid[14];

    expect($versionNibble)->toBe('7');
})->with('uuid_models');

test('getRouteKeyName returns uuid', function (string $modelClass) {
    /** @var Model $model */
    $model = new $modelClass;

    expect($model->getRouteKeyName())->toBe('uuid');
})->with('uuid_models');

test('an explicitly assigned uuid is not overwritten', function () {
    $uuid = (string) Str::uuid7();

    $work = Work::factory()->create(['uuid' => $uuid]);

    expect($work->uuid)->toBe($uuid);
});
