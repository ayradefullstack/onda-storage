<?php

declare(strict_types=1);

namespace App\Domain\Vault\Exceptions;

/**
 * Thrown by beginUpload() when the user's quota or the incoming disk's free
 * space cannot accommodate the declared upload size.
 */
final class InsufficientStorage extends VaultException {}
