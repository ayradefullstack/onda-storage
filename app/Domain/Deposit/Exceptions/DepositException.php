<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

use RuntimeException;

/**
 * Base for everything OeuvreStatusMachine refuses. Domain-level: it knows
 * nothing about HTTP status codes — the controllers map these.
 */
abstract class DepositException extends RuntimeException {}
