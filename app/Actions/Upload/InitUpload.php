<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Quota\QuotaPolicy;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Exceptions\InsufficientStorage;
use App\Domain\Vault\Value\UploadIntent;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Validates the declared upload against the caller's quota, verifies the
 * target work belongs to them, then delegates to
 * `VaultContract::beginUpload()` for the actual pre-allocation.
 *
 * What the file itself is checked against depends on the oeuvre:
 * - classified (it has a collège): the required-document slot it is
 *   uploaded into — `college_oeuvre_file_id` is mandatory and must belong to
 *   the oeuvre's own collège, and that slot's `extensions`, `max_size_kb` and
 *   `allows_multiple` apply;
 * - unclassified (filed before classification existed): the global
 *   extension/mime whitelist below, unchanged.
 */
final class InitUpload
{
    /**
     * Accepted deposit types per CLAUDE.md ("video, audio, PDF, PPTX").
     * Extension => accepted declared mime types, canonical value first.
     * CompleteUpload re-derives the canonical mime from this same table
     * rather than trusting a client-declared value carried across 640
     * requests, since `upload_sessions` has no `mime` column to hold it.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'mp4' => ['video/mp4'],
        'mov' => ['video/quicktime'],
        'avi' => ['video/x-msvideo', 'video/avi'],
        'mkv' => ['video/x-matroska'],
        'webm' => ['video/webm'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'aac' => ['audio/aac', 'audio/x-aac'],
        'ogg' => ['audio/ogg'],
        'pdf' => ['application/pdf'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    /**
     * Canonical mime for the extensions a required-document slot may list
     * that ALLOWED does not cover. Derived server-side from the extension,
     * never taken from the browser's declared type — which for `.7z`, `.sql`
     * or `.rar` is routinely empty or `application/octet-stream`, so a slot
     * upload checks the extension only.
     *
     * `svg` and `xml` are deliberately `application/octet-stream`: both can
     * carry script, and the stream endpoint serves `Content-Type` from the
     * stored mime — an author-supplied SVG stored as `image/svg+xml` would
     * execute against the session of the admin reviewing it.
     *
     * @var array<string, string>
     */
    private const REQUIREMENT_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'txt' => 'text/plain',
        'sql' => 'text/plain',
        'json' => 'application/json',
        'svg' => 'application/octet-stream',
        'xml' => 'application/octet-stream',
    ];

    public function __construct(
        private readonly VaultContract $vault,
        private readonly QuotaPolicy $quotaPolicy,
    ) {}

    public function handle(User $user, int $oeuvreId, string $filename, int $sizeBytes, string $mime, ?int $collegeOeuvreFileId = null): UploadSession
    {
        $oeuvre = Oeuvre::findOrFail($oeuvreId);

        // Ownership first, so a stranger probing oeuvre ids learns nothing
        // about a work's status.
        if ($user->id !== $oeuvre->author_id) {
            throw new AuthorizationException('This work does not belong to you.');
        }

        // The freeze, enforced on the server. Once a deposit is submitted
        // it belongs to the review queue: no new files until a decision
        // comes back. A rejected one reopens, which is what makes "fix it
        // and resubmit" possible. Hiding the upload button is not a
        // freeze — this is.
        if (! OeuvreStatus::isAuthorEditable($oeuvre->status)) {
            throw ValidationException::withMessages([
                'oeuvre_id' => ['This work has been submitted and can no longer receive files.'],
            ]);
        }

        if (! $user->can('update', $oeuvre)) {
            throw new AuthorizationException('This work does not belong to you.');
        }

        $requirement = null;

        if ($oeuvre->register_type_college_id === null) {
            if ($collegeOeuvreFileId !== null) {
                throw ValidationException::withMessages([
                    'college_oeuvre_file_id' => ['This work has no collège, so it has no document requirements.'],
                ]);
            }

            $this->assertAllowedFile($filename, $mime);
        } else {
            $requirement = $this->resolveRequirement($oeuvre, $collegeOeuvreFileId);
            $this->assertFileMatchesRequirement($requirement, $filename, $sizeBytes);
            $this->assertSlotAcceptsAnotherFile($oeuvre, $requirement);
        }

        $quota = StorageQuota::where('user_id', $user->id)->first();

        if (! $this->quotaPolicy->canAccept($quota, $sizeBytes)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This upload would exceed your storage quota.',
                'remaining_bytes' => $this->quotaPolicy->remainingBytes($quota) ?? 0,
            ], 413));
        }

        try {
            $session = $this->vault->beginUpload(new UploadIntent(
                oeuvreId: $oeuvre->id,
                userId: $user->id,
                filename: $filename,
                sizeBytes: $sizeBytes,
            ));
        } catch (InsufficientStorage $e) {
            // The quota check above already passed, so a domain-level
            // InsufficientStorage reaching here is the free-disk-space
            // check inside beginUpload() — not the (redundant) quota
            // check it also performs. Distinguishing 413 vs 507 this way
            // avoids re-parsing the frozen domain's exception message.
            throw new HttpResponseException(response()->json([
                'message' => $e->getMessage(),
            ], 507));
        }

        // Set after beginUpload() rather than carried in UploadIntent: the
        // vault contract has no notion of document requirements and needs
        // none. CompleteUpload reads it back off the session.
        if ($requirement !== null) {
            $session->college_oeuvre_file_id = $requirement->id;
            $session->save();
        }

        return $session;
    }

    public static function canonicalMimeFor(string $extension): ?string
    {
        $extension = strtolower($extension);

        return self::ALLOWED[$extension][0] ?? self::REQUIREMENT_MIMES[$extension] ?? null;
    }

    private function assertAllowedFile(string $filename, string $mime): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedMimes = self::ALLOWED[$extension] ?? null;

        if ($allowedMimes === null || ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'filename' => ["The file type \".{$extension}\" (declared as \"{$mime}\") is not an accepted deposit type."],
            ]);
        }
    }

    /**
     * The slot must exist, not be retired, and belong to the oeuvre's own
     * collège. Both ids can be individually valid and the pair still wrong —
     * no foreign key expresses that constraint, so it is enforced here.
     */
    private function resolveRequirement(Oeuvre $oeuvre, ?int $collegeOeuvreFileId): CollegeOeuvreFile
    {
        if ($collegeOeuvreFileId === null) {
            throw ValidationException::withMessages([
                'college_oeuvre_file_id' => ['Choose which required document this file is for.'],
            ]);
        }

        $requirement = CollegeOeuvreFile::query()
            ->whereKey($collegeOeuvreFileId)
            ->where('register_type_college_id', $oeuvre->register_type_college_id)
            ->first();

        if ($requirement === null) {
            throw ValidationException::withMessages([
                'college_oeuvre_file_id' => ["This document is not a requirement of this work's collège."],
            ]);
        }

        return $requirement;
    }

    /**
     * Case-insensitive: `IMG_0967.MOV` is how iPhones and Windows name files.
     */
    private function assertFileMatchesRequirement(CollegeOeuvreFile $requirement, string $filename, int $sizeBytes): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $accepted = array_map(strtolower(...), $requirement->extensions);

        if (! in_array($extension, $accepted, true)) {
            $formats = implode(', ', array_map(strtoupper(...), $accepted));

            throw ValidationException::withMessages([
                'filename' => ["The file type \".{$extension}\" is not accepted for this document. Accepted formats: {$formats}."],
            ]);
        }

        if ($requirement->max_size_kb !== null && $sizeBytes > $requirement->max_size_kb * 1024) {
            $limit = round($requirement->max_size_kb / 1024, 1);

            throw ValidationException::withMessages([
                'size_bytes' => ["This file exceeds the {$limit} MB limit for this document."],
            ]);
        }
    }

    /**
     * `allows_multiple = false` refuses a second file rather than replacing
     * the first: replacing would mean deleting a legal deposit. A slot whose
     * files all failed or were quarantined can be retried; an upload still in
     * flight for the slot occupies it.
     */
    private function assertSlotAcceptsAnotherFile(Oeuvre $oeuvre, CollegeOeuvreFile $requirement): void
    {
        if ($requirement->allows_multiple) {
            return;
        }

        $occupied = MediaFile::query()
            ->where('oeuvre_id', $oeuvre->id)
            ->where('college_oeuvre_file_id', $requirement->id)
            ->whereNotIn('status', ['failed', 'quarantined'])
            ->exists()
            || UploadSession::query()
                ->where('oeuvre_id', $oeuvre->id)
                ->where('college_oeuvre_file_id', $requirement->id)
                ->where('status', '!=', 'aborted')
                ->where('expires_at', '>', now())
                ->exists();

        if ($occupied) {
            throw ValidationException::withMessages([
                'college_oeuvre_file_id' => ['This document accepts a single file, and one is already deposited.'],
            ]);
        }
    }
}
