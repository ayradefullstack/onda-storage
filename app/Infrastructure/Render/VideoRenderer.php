<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

/**
 * Full-length 480p H.264/AAC MP4 with faststart (seekable before it is fully
 * loaded), metadata stripped. The poster is NOT regenerated: it reuses the
 * `poster` variant GenerateVariants already made. (The existing `preview`
 * variant is a 30 s clip and is not the consultation video.)
 */
final class VideoRenderer implements ConsultationRenderer
{
    public function __construct(
        private readonly ToolLocator $tools,
        private readonly ToolRunner $runner,
    ) {}

    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Video;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        $ffmpeg = $this->tools->find('ffmpeg_binary');

        if ($ffmpeg === null) {
            return RenderResult::unsupported('tool_missing:ffmpeg');
        }

        $out = $workspace.DIRECTORY_SEPARATOR.'video.mp4';

        $run = $this->runner->run(
            $this->command($ffmpeg, $sourcePath, $out),
            (int) config('vault.consult.render_timeout'),
        );

        if (! $run['ok'] || ! is_file($out) || filesize($out) === 0) {
            return RenderResult::failed($run['timedOut'] ? 'render_timeout' : 'render_failed');
        }

        $assets = [new RenderedAsset('video', 0, $out)];

        if ($mediaFile->variants()->where('kind', 'poster')->exists()) {
            $assets[] = new RenderedAsset('poster', 0, null, 'poster');
        }

        return RenderResult::ready($assets, 1);
    }

    /**
     * The ffmpeg argument array (never a shell string). Speed/size come from
     * config: `video_preset` (libx264 -preset) and `video_crf`.
     *
     * @return list<string>
     */
    public function command(string $ffmpeg, string $sourcePath, string $out): array
    {
        $height = (int) config('vault.consult.video_max_height');

        return [
            $ffmpeg, '-nostdin', '-y', '-i', $sourcePath,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', 'scale=-2:min(ih\,'.$height.')',
            '-c:v', 'libx264',
            '-preset', (string) config('vault.consult.video_preset'),
            '-crf', (string) config('vault.consult.video_crf'),
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '96k',
            '-map_metadata', '-1', '-movflags', '+faststart',
            $out,
        ];
    }
}
