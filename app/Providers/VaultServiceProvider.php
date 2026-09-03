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
 * Delivery/Scanner/MediaProbe drivers that require the HTTP layer, external
 * binaries, or job infrastructure (StreamDelivery, LiteSpeedDelivery,
 * ClamavScanner, FfmpegProbe) are out of scope for this phase — see
 * CLAUDE.md's phase log — and are bound to placeholders that fail loudly if
 * resolved before that phase lands. The `null` drivers are real, working
 * implementations since they're trivial and already a valid production
 * fallback.
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
            $driver = config('vault.scan_driver');

            return match ($driver) {
                'null' => new class implements Scanner
                {
                    public function scan(string $absolutePath): bool
                    {
                        return true;
                    }
                },
                'clamav' => throw new RuntimeException('ClamavScanner is not implemented yet — stays out of scope until the scanning phase.'),
                default => throw new RuntimeException("Unknown vault.scan_driver [{$driver}]."),
            };
        });

        $this->app->singleton(MediaProbe::class, function (): MediaProbe {
            $driver = config('vault.media_probe_driver');

            return match ($driver) {
                'null' => new class implements MediaProbe
                {
                    public function probe(string $absolutePath): array
                    {
                        return ['duration_sec' => null, 'width' => null, 'height' => null];
                    }
                },
                'ffmpeg' => throw new RuntimeException('FfmpegProbe is not implemented yet — stays out of scope until the media-processing phase.'),
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
