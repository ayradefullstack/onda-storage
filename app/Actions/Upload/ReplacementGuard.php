<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\Oeuvre;
use App\Models\UploadSession;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * An upload in flight is only valid while its oeuvre is still in the state
 * the upload was opened for:
 *  - a replacement (the session reserves a removed rejected file,
 *    `upload_sessions.replaces_media_file_id`) needs the oeuvre to STAY
 *    `under_review`. Registered or rejected meanwhile → 409 `oeuvre_decided`;
 *  - any other upload needs the oeuvre to stay author-editable (draft or
 *    rejected). Submitted or under review meanwhile → 409 `oeuvre_submitted`.
 *
 * Either way the session is aborted first (an aborted session reserves no
 * quota and no replacement slot) and nothing is added. The hot path costs
 * one primary-key read.
 */
final class ReplacementGuard
{
    public const OEUVRE_DECIDED = 'oeuvre_decided';

    public const OEUVRE_SUBMITTED = 'oeuvre_submitted';

    public function __construct(private readonly AbortUpload $abort) {}

    /**
     * @throws HttpResponseException
     */
    public function assertStillOpen(UploadSession $session): void
    {
        $status = Oeuvre::query()->whereKey($session->oeuvre_id)->value('status');

        if (! $status instanceof OeuvreStatus) {
            return;
        }

        $error = self::refusal($session, $status);

        if ($error === null) {
            return;
        }

        $this->abort->handle($session);

        throw self::conflict($error);
    }

    /**
     * Why this session may no longer add its file to an oeuvre in `$status`,
     * or null when it still may.
     */
    public static function refusal(UploadSession $session, OeuvreStatus $status): ?string
    {
        if ($session->replaces_media_file_id !== null) {
            return $status === OeuvreStatus::UNDER_REVIEW ? null : self::OEUVRE_DECIDED;
        }

        return $status->isAuthorEditable() ? null : self::OEUVRE_SUBMITTED;
    }

    public static function conflict(string $error): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'error' => $error,
            'message' => $error === self::OEUVRE_DECIDED
                ? 'This deposit has been decided; the file was not added.'
                : 'This deposit has been submitted; the file was not added.',
        ], 409));
    }
}
