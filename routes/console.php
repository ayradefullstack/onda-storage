<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Backstop for P5's plaintext temp files (`work/{uuid}.tmp`) and
 * GenerateVariants' staging files (`work/{uuid}-{kind}.{ext}`): CleanupTemp
 * is the normal path, but anything that crashes mid-chain before reaching
 * it (exhausts retries, worker killed, etc.) leaves an orphan here.
 * `onOneServer()` matters once more than one queue worker host exists —
 * two hosts sweeping the same shared `work/` disk concurrently is harmless
 * but wasteful, not unsafe.
 */
Schedule::call(function (): void {
    $root = Storage::disk('work')->path('');
    $cutoff = now()->subMinutes((int) config('vault.temp_ttl_minutes'))->getTimestamp();

    // `{uuid}.tmp` (DecryptToTemp's output) and GenerateVariants' staging
    // files (`{uuid}-{poster|preview|waveform}.{ext}` — deleted in its own
    // finally block under normal operation; this only catches a worker
    // that was killed mid-generation, before that block ran).
    $pattern = '/^[0-9a-f-]{20,}(\.tmp|-(poster|preview|waveform)\.[a-z0-9]+)$/i';

    foreach (scandir($root) ?: [] as $entry) {
        if (! preg_match($pattern, $entry)) {
            continue;
        }

        $path = $root.$entry;

        if (is_file($path) && (filemtime($path) ?: 0) < $cutoff) {
            @unlink($path);
        }
    }
})->hourly()->name('vault:purge-temp')->onOneServer();
