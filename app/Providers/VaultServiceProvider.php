<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Vault\Contracts\ChunkTracker;
use App\Domain\Vault\Contracts\Delivery;
use App\Domain\Vault\Contracts\MediaProbe;
use App\Domain\Vault\Contracts\Scanner;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Value\TempFile;
use App\Infrastructure\Probe\FfmpegProbe;
use App\Infrastructure\Probe\NullProbe;
use App\Infrastructure\Scanner\ClamavScanner;
use App\Infrastructure\Scanner\NullScanner;
use App\Infrastructure\Tracker\DatabaseChunkTracker;
use App\Infrastructure\Tracker\RedisChunkTracker;
use App\Infrastructure\Vault\EncryptedLocalVault;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Binds the four ports/adapters seams from CLAUDE.md (Delivery, Scanner,
 * ChunkTracker, MediaProbe) plus the overall VaultContract, all selected by
 * `config('vault.*_driver')` so local (Herd) and production (cPanel) never
 * need an `if (app()->environment(...))` scattered through calling code.
 *
 * Scanner and MediaProbe (P5) are both real, working implementations now —
 * `ClamavScanner`/`FfmpegProbe` for production/when the binary is present,
 * `NullScanner`/`NullProbe` as a genuine local/degraded fallback, not a
 * placeholder. Delivery (the HTTP full-download layer) is still out of
 * scope — see CLAUDE.md's phase log — and stays bound to a placeholder that
 * fails loudly if resolved before that phase lands.
 */
final class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyManager::class);

        $this->app->singleton(VaultContract::class, EncryptedLocalVault::class);

        $this->app->singleton(ChunkTracker::class, function (): ChunkTracker {
            $driver = config('vault.chunk_tracker_driver');

            return match ($driver) {
                'database' => new DatabaseChunkTracker,
                'redis' => new RedisChunkTracker,
                default => throw new RuntimeException("Unknown vault.chunk_tracker_driver [{$driver}]."),
            };
        });

        $this->app->singleton(Scanner::class, function (): Scanner {
            // An unquoted `SCAN_DRIVER=null` in .env parses as PHP null,
            // not the string 'null' — Dotenv treats the bareword specially.
            // This was already true for every driver key before P5; it was
            // just never exercised, since nothing resolved Scanner or
            // MediaProbe from the container until this phase's jobs did.
            $driver = config('vault.scan_driver') ?? 'null';

            return match ($driver) {
                'null' => new NullScanner,
                'clamav' => new ClamavScanner(
                    binary: (string) config('vault.clamdscan_binary'),
                    timeoutSeconds: (int) config('vault.scan_timeout_seconds'),
                ),
                default => throw new RuntimeException("Unknown vault.scan_driver [{$driver}]."),
            };
        });

        $this->app->singleton(MediaProbe::class, function (): MediaProbe {
            // Same null-vs-'null' Dotenv quirk as Scanner's driver above.
            $driver = config('vault.media_probe_driver') ?? 'null';

            return match ($driver) {
                'null' => new NullProbe,
                'ffmpeg' => new FfmpegProbe(
                    binary: (string) config('vault.ffprobe_binary'),
                    timeoutSeconds: (int) config('vault.probe_timeout_seconds'),
                ),
                default => throw new RuntimeException("Unknown vault.media_probe_driver [{$driver}]."),
            };
        });

        $this->app->singleton(Delivery::class, function (): Delivery {
            $driver = config('vault.delivery_driver');

            return match ($driver) {
                'stream', 'litespeed' => new class implements Delivery
                {
                    public function deliver(TempFile $file, string $downloadName, string $mime): mixed
                    {
                        throw new RuntimeException('Delivery is not implemented yet — stays out of scope until the HTTP layer phase.');
                    }
                },
                default => throw new RuntimeException("Unknown vault.delivery_driver [{$driver}]."),
            };
        });
    }
}
