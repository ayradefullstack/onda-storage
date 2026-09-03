<?php

declare(strict_types=1);

namespace App\Domain\Vault\Exceptions;

/**
 * Thrown when fwrite() returns fewer bytes than requested. A silent short
 * write into a pre-allocated vault file is the worst possible failure mode
 * here — it must always surface loudly instead of producing a corrupt file.
 */
final class ShortWrite extends VaultException {}
