<?php

declare(strict_types=1);

use App\Enums\FileAccessLog\ActionEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\MediaVariant\KindEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Enums\UploadSession\StatusEnum as UploadSessionStatus;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The P1 correction: WithoutModelEvents (and Model::withoutEvents(),
 * saveQuietly(), a bulk insert(), or any queued job bypassing events)
 * silently skips HasUuidColumn's `creating` hook. A raw insert with no uuid
 * must fail loudly at the database, not produce a row with an empty uuid.
 */
dataset('uuid_enforced_tables', [
    'oeuvres' => ['oeuvres', fn () => ['author_id' => User::factory()->create()->id, 'title' => 'x', 'status' => OeuvreStatus::DRAFT->value]],
    'media_files' => ['media_files', function () {
        $oeuvre = Oeuvre::factory()->create();

        return [
            'oeuvre_id' => $oeuvre->id,
            'uploaded_by' => $oeuvre->author_id,
            'original_name' => 'x.mp4',
            'extension' => 'mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 1,
            'disk' => 'vault',
            'path' => 'x',
            'sha256_plain' => str_repeat('a', 64),
            'dek_wrapped' => 'x',
            'nonce' => str_repeat('a', 16),
            'mac_path' => 'x',
            'status' => MediaFileStatus::READY->value,
        ];
    }],
    'upload_sessions' => ['upload_sessions', function () {
        $oeuvre = Oeuvre::factory()->create();

        return [
            'user_id' => $oeuvre->author_id,
            'oeuvre_id' => $oeuvre->id,
            'filename' => 'x',
            'size_bytes' => 1,
            'chunk_size' => 1,
            'total_chunks' => 1,
            'dek_wrapped' => 'x',
            'nonce' => str_repeat('a', 16),
            'temp_path' => 'x',
            'status' => UploadSessionStatus::PENDING->value,
            'expires_at' => now(),
        ];
    }],
    'media_variants' => ['media_variants', function () {
        $oeuvre = Oeuvre::factory()->create();
        $mediaFile = MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id]);

        return ['media_file_id' => $mediaFile->id, 'kind' => KindEnum::THUMBNAIL->value, 'path' => 'x', 'size_bytes' => 1];
    }],
    'file_access_logs' => ['file_access_logs', function () {
        $oeuvre = Oeuvre::factory()->create();
        $mediaFile = MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id]);

        return ['media_file_id' => $mediaFile->id, 'action' => ActionEnum::STREAM->value, 'ip' => '127.0.0.1', 'row_hash' => str_repeat('a', 64)];
    }],
    'storage_quotas' => ['storage_quotas', fn () => ['user_id' => User::factory()->create()->id, 'limit_bytes' => 1, 'used_bytes' => 0]],
]);

test('a raw DB insert without uuid fails at the database', function (string $table, Closure $attributes) {
    expect(fn () => DB::table($table)->insert($attributes()))
        ->toThrow(QueryException::class);
})->with('uuid_enforced_tables');
