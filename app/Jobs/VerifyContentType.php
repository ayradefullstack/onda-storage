<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\PipelineWorkspace;
use App\Models\MediaFile;
use App\Support\FileFormats;
use RuntimeException;

/**
 * Checks what the file ACTUALLY is against what its slot accepts.
 *
 * ---------------------------------------------------------------------
 * Why this job exists, and why here
 * ---------------------------------------------------------------------
 * An extension is a claim made by the filename. Renaming `malware.exe` to
 * `paroles.pdf` passes every check `InitUpload` can make, because at that
 * point the server has a filename and a size and not one byte of content.
 * The browser-declared MIME is no better: step 2 measured `.7z`, `.sql` and
 * `.rar` arriving empty or as `application/octet-stream`, so validating it
 * would reject legitimate deposits.
 *
 * The bytes only exist in the clear here. Each chunk is encrypted in memory
 * as it arrives, so during upload the server never holds the whole
 * plaintext; `DecryptToTemp` is the first moment it does.
 *
 * Placed IMMEDIATELY after `DecryptToTemp` and before the hash, the
 * deduplication and the malware scan: `finfo` reads only the first bytes,
 * so this is the cheapest check in the chain, and a wrong file should fail
 * before anything expensive runs over it.
 *
 * ---------------------------------------------------------------------
 * Failed, not quarantined
 * ---------------------------------------------------------------------
 * A type mismatch is the author's mistake to fix — they remove the file and
 * upload the right one. Quarantine means "suspected malware" and carries a
 * different meaning for the officer reviewing the deposit and for the
 * retention rules. Conflating the two would make a mistyped upload look
 * like an attack.
 *
 * ---------------------------------------------------------------------
 * Why `$tries = 1`
 * ---------------------------------------------------------------------
 * PipelineJob's default is 2 tries with a 60-second backoff, which is right
 * for a job that can fail transiently. Nothing here can: re-reading the same
 * bytes cannot produce a different answer, and the only other failure mode
 * is a missing temp file — which, since `DecryptToTemp` runs immediately
 * before this, means the chain is already broken and retrying wastes a
 * minute before reaching the same conclusion.
 *
 * So a mismatch throws, `$tries = 1` sends it straight to `failed()`, and
 * PipelineJob::failed() hands it to MarkMediaFileFailed — which sets the
 * status, logs the reason and notifies the uploader. `$this->fail()` was
 * the obvious alternative and is wrong here: it is a no-op unless the job
 * is running under a real queue worker (it needs `InteractsWithQueue::$job`),
 * so it would silently let the chain continue in any other context.
 *
 * Files uploaded before this job existed are not re-checked retroactively;
 * the chain only runs on upload (and on an explicit `vault:reprocess`).
 */
final class VerifyContentType extends PipelineJob
{
    public int $timeout = 60;

    /** See the class docblock — nothing here fails transiently. */
    public int $tries = 1;

    public function handle(): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->firstOrFail();
        $path = PipelineWorkspace::tempPath($this->mediaFileUuid);

        // Transient: throw, so the chain's normal retry applies.
        if (! is_file($path)) {
            throw new RuntimeException("Expected decrypted temp file for [{$this->mediaFileUuid}] at [{$path}] but it is missing.");
        }

        $detected = $this->detect($path);

        if ($detected === null) {
            throw new RuntimeException(
                "The content of \"{$mediaFile->original_name}\" could not be identified, so it cannot be accepted. ".
                'Re-export the file in a standard format and upload it again.'
            );
        }

        $accepted = $this->acceptedMimes($mediaFile);

        if (! in_array($detected, $accepted, true)) {
            throw new RuntimeException($this->mismatchMessage($mediaFile, $detected, $accepted));
        }

        // The stored mime is what the streaming endpoint serves as
        // Content-Type, so it comes from the registry — NOT from `$detected`.
        // That is what keeps an author-supplied SVG from being served as
        // image/svg+xml and executing against the reviewing admin's session.
        $storedMime = FileFormats::storedMimeFor($mediaFile->extension);

        if ($storedMime !== null && $storedMime !== $mediaFile->mime) {
            $mediaFile->mime = $storedMime;
            $mediaFile->save();
        }
    }

    /**
     * `FILEINFO_MIME_TYPE` returns the bare type with no charset suffix.
     * Returns null when libmagic cannot open or identify the file.
     */
    private function detect(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException('Could not initialise fileinfo — the extension may be missing.');
        }

        try {
            $detected = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        if ($detected === false || $detected === '') {
            return null;
        }

        // libmagic returns `application/octet-stream` when it identifies
        // nothing. Treating that as a real answer would accept anything,
        // so it is normalised to "unknown" — see FileFormats' docblock for
        // why no format lists it.
        return $detected === 'application/octet-stream' ? null : $detected;
    }

    /**
     * The slot's derived `mime_types` for a classified oeuvre; the whole
     * registry for an unclassified one, which has no requirement slots and
     * is governed only by the global whitelist.
     *
     * A slot whose `mime_types` is somehow empty falls back to the registry
     * rather than rejecting everything — an empty list is a data problem,
     * and failing every deposit under it would be the wrong response.
     *
     * @return list<string>
     */
    private function acceptedMimes(MediaFile $mediaFile): array
    {
        $slot = $mediaFile->collegeOeuvreFile()->withTrashed()->first();

        if ($slot !== null && $slot->mime_types !== []) {
            return $slot->mime_types;
        }

        return FileFormats::mimeTypesFor(FileFormats::extensions());
    }

    /**
     * Names both sides in words an author can act on: what the file
     * actually is, and what the slot wanted.
     *
     * @param  list<string>  $accepted
     */
    private function mismatchMessage(MediaFile $mediaFile, string $detected, array $accepted): string
    {
        $slot = $mediaFile->collegeOeuvreFile()->withTrashed()->first();

        $detectedLabel = $this->labelFor($detected) ?? $detected;

        $expected = $slot !== null
            ? implode(', ', array_map(strtoupper(...), $slot->extensions))
            : strtoupper($mediaFile->extension);

        return sprintf(
            'The content of "%s" is %s, but this document accepts %s. '
                .'The file appears to have been renamed rather than converted — '
                .'remove it and upload a real %s file.',
            $mediaFile->original_name,
            $detectedLabel,
            $expected,
            $expected,
        );
    }

    /**
     * A human label for a detected MIME, by reverse lookup through the
     * registry — "DOCX" reads better than the 78-character OpenXML type.
     * Null when nothing in the registry claims it, which is the case for a
     * renamed executable and is exactly the situation worth naming raw.
     */
    private function labelFor(string $mime): ?string
    {
        foreach (FileFormats::all() as $format) {
            if (in_array($mime, $format['mimes'], true)) {
                return $format['label'];
            }
        }

        return null;
    }
}
