<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

/**
 * One output of a renderer. Either a plaintext staging file in the job's
 * workspace (`$path`, which the store step encrypts and deletes), or a
 * reference to an existing `media_variants` row (`$variantKind`: the poster
 * and waveform GenerateVariants already made) whose bytes are shared, not
 * copied.
 */
final readonly class RenderedAsset
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $kind,
        public ?int $pageIndex = null,
        public ?string $path = null,
        public ?string $variantKind = null,
        public array $meta = [],
    ) {}
}
