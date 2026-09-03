<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Work;
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
    'works' => ['works', fn () => ['author_id' => User::factory()->create()->id, 'title' => 'x', 'status' => 'draft']],
    'media_files' => ['media_files', function () {
        $work = Work::factory()->create();

        return [
            'work_id' => $work->id,
            'uploaded_by' => $work->author_id,
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
            'status' => 'ready',
        ];
    }],
    'upload_sessions' => ['upload_sessions', function () {
        $work = Work::factory()->create();

        return [
            'user_id' => $work->author_id,
            'work_id' => $work->id,
            'filename' => 'x',
            'size_bytes' => 1,
            'chunk_size' => 1,
            'total_chunks' => 1,
            'dek_wrapped' => 'x',
            'nonce' => str_repeat('a', 16),
            'temp_path' => 'x',
            'status' => 'pending',
            'expires_at' => now(),
        ];
    }],
    'media_variants' => ['media_variants', function () {
        $work = Work::factory()->create();
        $mediaFile = \App\Models\MediaFile::factory()->create(['work_id' => $work->id]);

        return ['media_file_id' => $mediaFile->id, 'kind' => 'thumbnail', 'path' => 'x', 'size_bytes' => 1];
    }],
    'file_access_logs' => ['file_access_logs', function () {
        $work = Work::factory()->create();
        $mediaFile = \App\Models\MediaFile::factory()->create(['work_id' => $work->id]);

        return ['media_file_id' => $mediaFile->id, 'action' => 'stream', 'ip' => '127.0.0.1', 'row_hash' => str_repeat('a', 64)];
    }],
    'storage_quotas' => ['storage_quotas', fn () => ['user_id' => User::factory()->create()->id, 'limit_bytes' => 1, 'used_bytes' => 0]],
]);

test('a raw DB insert without uuid fails at the database', function (string $table, Closure $attributes) {
    expect(fn () => DB::table($table)->insert($attributes()))
        ->toThrow(QueryException::class);
})->with('uuid_enforced_tables');
