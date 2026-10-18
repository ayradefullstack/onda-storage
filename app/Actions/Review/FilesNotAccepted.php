<?php

declare(strict_types=1);

namespace App\Actions\Review;

use RuntimeException;

/**
 * The deposit is complete but not every file has been accepted by the
 * officer. Carries the exact list, so the refusal names each file.
 */
final class FilesNotAccepted extends RuntimeException
{
    /**
     * @param  list<array{name: string, reviewStatus: string}>  $files
     */
    public function __construct(
        public readonly array $files,
    ) {
        parent::__construct('Every file must be accepted before this deposit can be registered.');
    }
}
