<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

/**
 * What a renderer hands back. `reason` is a short machine key the UI
 * translates (e.g. `tool_missing:soffice`, `too_large`, `unsupported_format`),
 * never an exception message. A `ready` result may still carry a `notice`
 * key (e.g. `pages_truncated`).
 */
final readonly class RenderResult
{
    /**
     * @param  list<RenderedAsset>  $assets
     */
    private function __construct(
        public string $status,
        public ?string $reason,
        public array $assets,
        public ?int $pageCount,
    ) {}

    /**
     * @param  list<RenderedAsset>  $assets
     */
    public static function ready(array $assets, ?int $pageCount = null, ?string $notice = null): self
    {
        return new self('ready', $notice, $assets, $pageCount);
    }

    public static function unsupported(string $reason): self
    {
        return new self('unsupported', $reason, [], null);
    }

    public static function failed(string $reason): self
    {
        return new self('failed', $reason, [], null);
    }
}
