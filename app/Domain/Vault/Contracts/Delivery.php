<?php

declare(strict_types=1);

namespace App\Domain\Vault\Contracts;

use App\Domain\Vault\Value\TempFile;

/**
 * Hands an already-decrypted file off to the client for the ~5% full-download
 * read path. Local (`StreamDelivery`) streams the bytes through PHP;
 * production (`LiteSpeedDelivery`) hands the web server an X-Sendfile-style
 * header instead. Deliberately not typed against a concrete HTTP response —
 * that binding belongs to the HTTP layer built in a later phase.
 */
interface Delivery
{
    public function deliver(TempFile $file, string $downloadName, string $mime): mixed;
}
