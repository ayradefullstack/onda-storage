<?php

declare(strict_types=1);

namespace App\Domain\Vault\Exceptions;

/**
 * Thrown by finalize() when the chunk tracker's mask is not yet complete —
 * refuses to rename a partially-written file into the vault.
 */
final class IncompleteUpload extends VaultException {}
