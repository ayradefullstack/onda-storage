<?php

declare(strict_types=1);

namespace App\Domain\Vault\Exceptions;

/**
 * Thrown when a cipher operation is asked to start at a byte offset that is
 * not a multiple of the AES block size (16). CTR mode's seekability depends
 * entirely on every operation starting on a block boundary.
 */
final class UnalignedOffset extends VaultException {}
