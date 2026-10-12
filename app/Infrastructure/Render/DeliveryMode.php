<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

/**
 * How a preview family reaches the reviewer. Configured in
 * `vault.consult.delivery`, decided only by `PreviewFamilyResolver`.
 */
enum DeliveryMode: string
{
    case OriginalInline = 'original_inline';
    case Derivative = 'derivative';
    case Unsupported = 'unsupported';
}
