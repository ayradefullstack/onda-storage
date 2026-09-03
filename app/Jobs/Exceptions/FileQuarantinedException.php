<?php

declare(strict_types=1);

namespace App\Jobs\Exceptions;

use RuntimeException;

/**
 * Thrown by `ScanForMalware` via `$this->fail()` to stop the chain after a
 * positive verdict, WITHOUT going through the generic
 * `PipelineJob::failed()` handler (which would overwrite the
 * already-correctly-set `quarantined` status with `failed`, and would
 * pointlessly retry — rescanning identical bytes always reproduces the same
 * verdict).
 */
final class FileQuarantinedException extends RuntimeException {}
