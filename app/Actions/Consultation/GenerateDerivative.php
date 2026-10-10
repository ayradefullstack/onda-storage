<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Domain\Deposit\PipelineWorkspace;
use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Vault\Contracts\VaultContract;
use App\Infrastructure\Render\AudioRenderer;
use App\Infrastructure\Render\ConsultationRenderer;
use App\Infrastructure\Render\CsvRenderer;
use App\Infrastructure\Render\ImageRenderer;
use App\Infrastructure\Render\OfficeRenderer;
use App\Infrastructure\Render\OtherRenderer;
use App\Infrastructure\Render\PdfRenderer;
use App\Infrastructure\Render\PreviewFamily;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Infrastructure\Render\RenderResult;
use App\Infrastructure\Render\SpreadsheetRenderer;
use App\Infrastructure\Render\TextRenderer;
use App\Infrastructure\Render\VideoRenderer;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Builds (or rebuilds) the consultation derivatives of one media file and
 * records the outcome in `media_consultations`. It NEVER throws: every
 * Throwable becomes `failed` with a short reason key, so a derivative can
 * never stop the upload chain or fail a deposit.
 *
 * `$force` regenerates: the new assets are stored first (new random nonces)
 * and the old ones are deleted only after that succeeded, so a failed
 * regeneration leaves the previous, working derivatives in place.
 */
final class GenerateDerivative
{
    /** @var list<class-string<ConsultationRenderer>> */
    private const RENDERERS = [
        PdfRenderer::class,
        OfficeRenderer::class,
        SpreadsheetRenderer::class,
        CsvRenderer::class,
        ImageRenderer::class,
        TextRenderer::class,
        VideoRenderer::class,
        AudioRenderer::class,
        OtherRenderer::class,
    ];

    public function __construct(
        private readonly PreviewFamilyResolver $resolver,
        private readonly StoreConsultationAsset $store,
        private readonly DeleteConsultationAssets $delete,
        private readonly VaultContract $vault,
    ) {}

    public function handle(MediaFile $mediaFile, bool $force = false): MediaConsultation
    {
        $consultation = MediaConsultation::forFile($mediaFile);

        if (! $force && $consultation->exists && $consultation->status === MediaConsultation::READY) {
            return $consultation;
        }

        $consultation->family = $this->resolver->resolve($mediaFile)->value;
        $consultation->status = MediaConsultation::PENDING;
        $consultation->reason = null;
        $consultation->save();

        // This job's OWN scratch directory and its OWN decrypted copy. It
        // never reads the upload chain's `{uuid}.tmp`, which CleanupTemp may
        // already have deleted by the time a queued preview runs.
        $workspace = PipelineWorkspace::makeDirectory($mediaFile->uuid, 'consult');

        try {
            $source = $this->decryptInto($mediaFile, $workspace);

            $family = $this->resolver->resolve($mediaFile, $this->detectMime($source));
            $consultation->family = $family->value;

            $result = $this->renderer($family)->render($mediaFile, $source, $workspace);
            $this->persist($mediaFile, $consultation, $result, $workspace);
        } catch (Throwable $e) {
            Log::warning("GenerateDerivative failed for [{$mediaFile->uuid}].", ['error' => $e->getMessage()]);

            $consultation->status = MediaConsultation::FAILED;
            $consultation->reason = $this->reasonFrom($e);
            $consultation->generated_at = null;
            $consultation->save();
        } finally {
            PipelineWorkspace::removeDirectory($workspace);
        }

        return $consultation;
    }

    private function persist(MediaFile $mediaFile, MediaConsultation $consultation, RenderResult $result, string $workspace): void
    {
        $previous = $mediaFile->consultationAssets()->get();

        if ($result->status !== 'ready') {
            // A failed REgeneration keeps the old derivatives; a first
            // attempt has none to keep. unsupported clears them.
            if ($result->status === 'unsupported') {
                $this->delete->handle($previous);
            }

            $consultation->status = $result->status;
            $consultation->reason = $result->reason;
            $consultation->page_count = null;
            $consultation->generated_at = null;
            $consultation->save();

            return;
        }

        // Store new first, delete old only after every new asset exists. The
        // unique (media_file_id, kind, page_index) would collide with the old
        // rows, so those are parked under an `old:` kind while the new ones
        // are written, and restored if anything goes wrong.
        foreach ($previous as $row) {
            $row->kind = 'old:'.$row->kind;
            $row->save();
        }

        $created = [];

        try {
            foreach ($result->assets as $asset) {
                $created[] = $this->store->handle($mediaFile, $asset, $workspace);
            }
        } catch (Throwable $e) {
            $this->delete->handle($created);

            foreach ($previous as $row) {
                $row->kind = substr($row->kind, 4);
                $row->save();
            }

            throw $e;
        }

        $this->delete->handle($previous);

        $consultation->status = MediaConsultation::READY;
        $consultation->reason = $result->reason;
        $consultation->page_count = $result->pageCount;
        $consultation->generated_at = now();
        $consultation->save();
    }

    private function renderer(PreviewFamily $family): ConsultationRenderer
    {
        foreach (self::RENDERERS as $class) {
            $renderer = app($class);

            if ($renderer->supports($family)) {
                return $renderer;
            }
        }

        return app(OtherRenderer::class);
    }

    private function detectMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }

    private function reasonFrom(Throwable $e): string
    {
        $message = $e->getMessage();

        foreach (['convert_timeout', 'render_timeout', 'convert_failed', 'tool_missing:soffice'] as $known) {
            if ($message === $known) {
                return $known;
            }
        }

        return 'render_failed';
    }

    /**
     * Decrypts the original into `$workspace` and returns the plaintext path.
     * The directory is deleted in `finally`, plaintext included.
     */
    private function decryptInto(MediaFile $mediaFile, string $workspace): string
    {
        $free = @disk_free_space($workspace);

        if ($free !== false && $free < $mediaFile->size_bytes * 1.2) {
            throw new RuntimeException('insufficient_space');
        }

        $decrypted = $this->vault->decryptToTemp(StoredObjectMapper::fromMediaFile($mediaFile));
        $source = $workspace.DIRECTORY_SEPARATOR.'source.bin';

        if (! @rename($decrypted->path, $source)) {
            throw new RuntimeException('Could not stage the decrypted copy.');
        }

        return $source;
    }
}
