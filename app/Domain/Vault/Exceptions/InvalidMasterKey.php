<?php

declare(strict_types=1);

namespace App\Domain\Vault\Exceptions;

/**
 * Thrown when the master KEK cannot be safely loaded: missing, unreadable,
 * too short, or stored somewhere that would end up in a backup (inside the
 * repo). The message is actionable but must never include key material.
 */
final class InvalidMasterKey extends VaultException {}
