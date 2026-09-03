<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Domain\Quota\QuotaPolicy;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Exceptions\InsufficientStorage;
use App\Domain\Vault\Value\UploadIntent;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\Work;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Validates the declared upload against the extension whitelist and the
 * caller's quota, verifies the target work belongs to them, then delegates
 * to `VaultContract::beginUpload()` for the actual pre-allocation.
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

    public function __construct(
        private readonly VaultContract $vault,
        private readonly QuotaPolicy $quotaPolicy,
    ) {}

    public function handle(User $user, int $workId, string $filename, int $sizeBytes, string $mime): UploadSession
    {
        $work = Work::findOrFail($workId);

        if (! $user->can('update', $work)) {
            throw new AuthorizationException('This work does not belong to you.');
        }

        $this->assertAllowedFile($filename, $mime);

        $quota = StorageQuota::where('user_id', $user->id)->first();

        if (! $this->quotaPolicy->canAccept($quota, $sizeBytes)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This upload would exceed your storage quota.',
                'remaining_bytes' => $this->quotaPolicy->remainingBytes($quota) ?? 0,
            ], 413));
        }

        try {
            return $this->vault->beginUpload(new UploadIntent(
                workId: $work->id,
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
    }

    public static function canonicalMimeFor(string $extension): ?string
    {
        return self::ALLOWED[strtolower($extension)][0] ?? null;
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
}
