<?php

declare(strict_types=1);

use App\Actions\Consultation\GenerateDerivative;
use App\Domain\Deposit\PipelineWorkspace;
use App\Infrastructure\Render\ConsultationRenderer;
use App\Infrastructure\Render\PreviewFamily;
use App\Infrastructure\Render\RenderResult;
use App\Infrastructure\Render\TextRenderer;
use App\Infrastructure\Render\VideoRenderer;
use App\Jobs\CleanupTemp;
use App\Jobs\GenerateConsultationDerivative;
use App\Jobs\ProcessMediaFile;
use App\Models\FileAccessLog;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Pipeline/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->author = pipelineAuthor();
    $this->oeuvre = Oeuvre::factory()->create(['author_id' => $this->author->id]);
});

function previewJobsQueued(): int
{
    return DB::table('jobs')->where('queue', 'previews')->count();
}

test('the upload chain is exactly the original: previews are not part of it', function () {
    $classes = array_map(fn (object $job): string => $job::class, ProcessMediaFile::chainJobs('any-uuid'));

    expect($classes)->not->toContain(GenerateConsultationDerivative::class)
        ->and(end($classes))->toBe(CleanupTemp::class);
});

test('the job runs on its own connection and queue, with a timeout strictly below that connection\'s retry_after', function () {
    $job = new GenerateConsultationDerivative('00000000-0000-7000-8000-000000000000');

    expect($job->connection)->toBe('previews')
        ->and($job->queue)->toBe('previews')
        ->and(config('queue.connections.previews.retry_after'))->toBe(config('vault.consult.retry_after'))
        ->and(config('queue.connections.previews.retry_after'))->toBe(7200)
        ->and($job->timeout)->toBe(6600)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.previews.retry_after'));
});

test('the media connection keeps its own retry_after', function () {
    expect(config('queue.connections.database.retry_after'))->toBe(3600);
});

test('the ffmpeg render timeout stays below the job timeout, which stays below retry_after', function () {
    expect((int) config('vault.consult.render_timeout'))->toBeLessThan((int) config('vault.consult.job_timeout'))
        ->and((int) config('vault.consult.job_timeout'))->toBeLessThan((int) config('vault.consult.retry_after'));
});

test('the job timeout follows config', function () {
    config(['vault.consult.job_timeout' => 1234]);

    expect((new GenerateConsultationDerivative('x'))->timeout)->toBe(1234);
});

test('ffmpeg preset and crf come from config', function () {
    config(['vault.consult.video_preset' => 'superfast', 'vault.consult.video_crf' => 31]);

    $command = app(VideoRenderer::class)->command('/bin/ffmpeg', '/in.mp4', '/out.mp4');

    expect($command[array_search('-preset', $command, true) + 1])->toBe('superfast')
        ->and($command[array_search('-crf', $command, true) + 1])->toBe('31')
        ->and($command)->toContain('+faststart');

    config(['vault.consult.video_preset' => null, 'vault.consult.video_crf' => 28]);
});

test('defaults are veryfast and crf 28', function () {
    expect(config('vault.consult.video_preset'))->toBe('veryfast')
        ->and(config('vault.consult.video_crf'))->toBe(28);
});

test('a deposit is ready, with its deposit row written, before any derivative exists; the preview job is merely queued', function () {
    $file = pipelineFile($this->author, $this->oeuvre, consultFixture('sample.mp4'), 'clip.mp4', 'video/mp4', 'scanning');
    $file->forceFill(['sha256_plain' => null])->save();

    ProcessMediaFile::dispatch($file->uuid);

    expect($file->fresh()->status)->toBe('ready')
        ->and(FileAccessLog::where('media_file_id', $file->id)->where('action', 'deposit')->count())->toBe(1)
        ->and(MediaConsultation::where('media_file_id', $file->id)->exists())->toBeFalse()
        ->and(previewJobsQueued())->toBe(1);
});

test('with a slow renderer the file is already ready and recorded while the derivative is still being built', function () {
    $file = pipelineFile($this->author, $this->oeuvre, 'plain text body', 'notes.txt', 'text/plain', 'scanning');
    $file->forceFill(['sha256_plain' => null])->save();

    ProcessMediaFile::dispatch($file->uuid);

    $observed = new ArrayObject;

    app()->bind(TextRenderer::class, fn () => new class($observed, $file->id) implements ConsultationRenderer
    {
        public function __construct(private ArrayObject $observed, private int $fileId) {}

        public function supports(PreviewFamily $family): bool
        {
            return true;
        }

        public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
        {
            usleep(300_000); // slow

            $this->observed['status'] = MediaFile::find($this->fileId)->status;
            $this->observed['deposit_rows'] = FileAccessLog::where('media_file_id', $this->fileId)->where('action', 'deposit')->count();
            $this->observed['consultation_exists'] = MediaConsultation::where('media_file_id', $this->fileId)->where('status', 'ready')->exists();

            return RenderResult::unsupported('unsupported_format');
        }
    });

    app()->call([new GenerateConsultationDerivative($file->uuid), 'handle']);

    expect($observed->getArrayCopy())->toBe(['status' => 'ready', 'deposit_rows' => 1, 'consultation_exists' => false])
        ->and(MediaConsultation::where('media_file_id', $file->id)->value('status'))->toBe('unsupported');
});

test('CleanupTemp queues previews only for a ready deposit', function () {
    $ready = pipelineFile($this->author, $this->oeuvre, 'a', 'a.txt', 'text/plain', 'ready');
    $failed = pipelineFile($this->author, $this->oeuvre, 'b', 'b.txt', 'text/plain', 'failed');

    app()->call([new CleanupTemp($failed->uuid), 'handle']);
    expect(previewJobsQueued())->toBe(0);

    app()->call([new CleanupTemp($ready->uuid), 'handle']);
    expect(previewJobsQueued())->toBe(1);
});

test('the job decrypts into its OWN directory and never reads the chain\'s temp file', function () {
    $file = pipelineFile($this->author, $this->oeuvre, 'the real deposit text', 'notes.txt', 'text/plain');

    // A decoy where the chain would have left its plaintext; if the job used
    // it, the derivative would contain this instead of the real bytes.
    $decoy = PipelineWorkspace::tempPath($file->uuid);
    file_put_contents($decoy, 'DECOY — NOT THE DEPOSIT');

    app(GenerateDerivative::class)->handle($file);

    $asset = $file->consultationAssets()->where('kind', 'text')->first();

    expect(consultPlain($file, $asset))->toBe('the real deposit text')
        ->and(file_get_contents($decoy))->toBe('DECOY — NOT THE DEPOSIT');
});

test('it works when the chain temp file is already gone, and leaves no scratch directory behind', function () {
    $file = pipelineFile($this->author, $this->oeuvre, 'the real deposit text', 'notes.txt', 'text/plain');
    PipelineWorkspace::delete($file->uuid);

    app(GenerateDerivative::class)->handle($file);

    expect(MediaConsultation::where('media_file_id', $file->id)->value('status'))->toBe('ready')
        ->and(glob(Storage::disk('work')->path('consult-*')) ?: [])->toBe([]);
});

test('the scratch directory (plaintext included) is removed even when the render fails', function () {
    $file = pipelineFile($this->author, $this->oeuvre, 'the real deposit text', 'notes.txt', 'text/plain');

    app()->bind(TextRenderer::class, fn () => new class implements ConsultationRenderer
    {
        public function supports(PreviewFamily $family): bool
        {
            return true;
        }

        public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
        {
            throw new RuntimeException('boom');
        }
    });

    app(GenerateDerivative::class)->handle($file);

    expect(MediaConsultation::where('media_file_id', $file->id)->value('status'))->toBe('failed')
        ->and(glob(Storage::disk('work')->path('consult-*')) ?: [])->toBe([]);
});
