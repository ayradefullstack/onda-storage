<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Domain\Access\SignedMediaUrl;
use App\Models\MediaFile;
use App\Models\User;

/**
 * Issued fresh on demand, not baked into the `works/Show` page props: the
 * link is only valid 15 minutes, but a `ready` file stops the page's
 * polling loop, so a props-embedded link could easily go stale before the
 * author clicks "View". The frontend calls this right before opening a
 * preview instead.
 */
final class IssueStreamUrl
{
    public function handle(MediaFile $mediaFile, User $user): string
    {
        return SignedMediaUrl::forStreaming($mediaFile, $user);
    }
}
