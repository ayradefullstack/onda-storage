<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Wraps Laravel's own temporary-signed-route mechanism with the one extra
 * binding CLAUDE.md requires beyond a bare signature: the `u` query
 * parameter ties the link to the specific user it was issued to. A
 * signature alone only proves the URL wasn't tampered with — it says
 * nothing about who it's for. `StreamController` checks `u` against the
 * currently authenticated user on every request, so a leaked link (a
 * pasted URL, a browser cache, a screenshot) is useless to anyone else,
 * even one who could otherwise legitimately view the file some other way.
 */
final class SignedMediaUrl
{
    /** CLAUDE.md: "signed URL (15 min)". */
    private const TTL_MINUTES = 15;

    public static function forStreaming(MediaFile $mediaFile, User $user): string
    {
        return URL::temporarySignedRoute(
            'media.stream',
            now()->addMinutes(self::TTL_MINUTES),
            ['mediaFile' => $mediaFile->uuid, 'u' => $user->id],
        );
    }
}
