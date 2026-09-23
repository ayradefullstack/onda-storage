<?php

declare(strict_types=1);

namespace App\Infrastructure\Probe;

use App\Domain\Vault\Contracts\MediaProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * `ffprobe -v quiet -print_format json -show_format -show_streams` — reads
 * a real, seekable plaintext file (never a pipe: the moov atom an MP4
 * needs for duration/dimensions can sit at the end of the file, which
 * `pipe:0` can't seek back to).
 *
 * Every failure mode (binary missing, non-zero exit, malformed JSON) is
 * caught and degrades to null metadata rather than throwing — "never fail
 * a deposit over missing metadata" is an invariant regardless of which
 * probe driver is configured, not just the null one.
 */
final class FfmpegProbe implements MediaProbe
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeoutSeconds,
    ) {}

    public function probe(string $absolutePath): array
    {
        try {
            $result = Process::timeout($this->timeoutSeconds)->run([
                $this->binary, '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $absolutePath,
            ]);

            if (! $result->successful()) {
                Log::warning('ffprobe failed; proceeding with null metadata.', [
                    'path' => $absolutePath,
                    'exit_code' => $result->exitCode(),
                    'stderr' => $result->errorOutput(),
                ]);

                return $this->nullMetadata();
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

            $durationSec = isset($data['format']['duration']) && is_numeric($data['format']['duration'])
                ? (int) round((float) $data['format']['duration'])
                : null;

            $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
            $videoStream = null;

            foreach ($streams as $stream) {
                if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                    $videoStream = $stream;
                    break;
                }
            }

            return [
                'duration_sec' => $durationSec,
                'width' => is_int($videoStream['width'] ?? null) ? $videoStream['width'] : null,
                'height' => is_int($videoStream['height'] ?? null) ? $videoStream['height'] : null,
            ];
        } catch (Throwable $e) {
            Log::warning('ffprobe threw while extracting metadata; proceeding with null metadata.', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return $this->nullMetadata();
        }
    }

    /**
     * @return array{duration_sec: null, width: null, height: null}
     */
    private function nullMetadata(): array
    {
        return ['duration_sec' => null, 'width' => null, 'height' => null];
    }
}
