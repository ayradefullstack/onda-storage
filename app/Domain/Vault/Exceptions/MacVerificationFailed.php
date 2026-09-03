<?php

declare(strict_types=1);

namespace App\Domain\Vault\Exceptions;

/**
 * Thrown when an HMAC tag does not match — a flipped ciphertext bit, a
 * truncated/missing sidecar file, or key-wrap tampering. CTR mode gives no
 * authenticity on its own, so this is the only thing standing between a
 * corrupted file and a silent bit-flip attack.
 */
final class MacVerificationFailed extends VaultException {}
