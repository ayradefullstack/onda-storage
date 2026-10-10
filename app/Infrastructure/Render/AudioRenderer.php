<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

/**
 * 128 kbps MP3 (plays everywhere), cover art and tags stripped. The waveform
 * is NOT regenerated: it reuses the `waveform` variant GenerateVariants made.
 */
final class AudioRenderer implements ConsultationRenderer
{
    public function __construct(
        private readonly ToolLocator $tools,
        private readonly ToolRunner $runner,
    ) {}

    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Audio;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        $ffmpeg = $this->tools->find('ffmpeg_binary');

        if ($ffmpeg === null) {
            return RenderResult::unsupported('tool_missing:ffmpeg');
        }

        $out = $workspace.DIRECTORY_SEPARATOR.'audio.mp3';

        $run = $this->runner->run([
            $ffmpeg, '-nostdin', '-y', '-i', $sourcePath,
            '-vn', '-map', '0:a:0',
            '-c:a', 'libmp3lame', '-b:a', (string) config('vault.consult.audio_bitrate'),
            '-map_metadata', '-1',
            $out,
        ], (int) config('vault.consult.render_timeout'));

        if (! $run['ok'] || ! is_file($out) || filesize($out) === 0) {
            return RenderResult::failed($run['timedOut'] ? 'render_timeout' : 'render_failed');
        }

        $assets = [new RenderedAsset('audio', 0, $out)];

        if ($mediaFile->variants()->where('kind', 'waveform')->exists()) {
            $assets[] = new RenderedAsset('waveform', 0, null, 'waveform');
        }

        return RenderResult::ready($assets, 1);
    }
}
