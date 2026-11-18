<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Enums\MediaConsultation\StatusEnum as ConsultationStatus;

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
        public ConsultationStatus $status,
        public ?string $reason,
        public array $assets,
        public ?int $pageCount,
    ) {}

    /**
     * @param  list<RenderedAsset>  $assets
     */
    public static function ready(array $assets, ?int $pageCount = null, ?string $notice = null): self
    {
        return new self(ConsultationStatus::READY, $notice, $assets, $pageCount);
    }

    public static function unsupported(string $reason): self
    {
        return new self(ConsultationStatus::UNSUPPORTED, $reason, [], null);
    }

    public static function failed(string $reason): self
    {
        return new self(ConsultationStatus::FAILED, $reason, [], null);
    }
}
