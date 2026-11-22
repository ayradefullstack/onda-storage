<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Value;

use App\Enums\MediaFile\StatusEnum as MediaFileStatus;

/**
 * One reason a submission is blocked, or one advisory note the officer
 * should see at review time.
 *
 * Structured rather than a rendered sentence: there is no server-side
 * `lang/` directory in this project — every translated string lives in
 * `resources/js/locales/*.json` and is rendered by vue-i18n. So the gate
 * emits a stable `code` plus its parameters, the page renders it in the
 * *reader's* locale, and `message()` is an English fallback for the
 * validation error bag and for anything reading this outside a browser.
 */
final class SubmissionReason
{
    public const NO_FILES = 'no_files';

    public const FILE_IN_PIPELINE = 'file_in_pipeline';

    public const FILE_FAILED = 'file_failed';

    public const FILE_QUARANTINED = 'file_quarantined';

    /** A file the ONDA rejected, still on a deposit returned to its author. */
    public const FILE_REJECTED = 'file_rejected';

    public const SLOT_EMPTY = 'slot_empty';

    public const SLOT_EMPTY_CONDITIONAL = 'slot_empty_conditional';

    /**
     * @param  array<string, string|int|null>  $params
     */
    public function __construct(
        public readonly string $code,
        public readonly array $params = [],
    ) {}

    /**
     * English fallback. Never shown to an author in the UI — the page
     * translates `code` — but it is what lands in a validation error bag
     * and in a test failure message, so it names the file or slot too.
     */
    public function message(): string
    {
        $name = (string) ($this->params['name'] ?? '');

        return match ($this->code) {
            self::NO_FILES => __('deposit.no_files'),
            self::FILE_IN_PIPELINE => __('deposit.file_in_pipeline', ['name' => $name, 'status' => $this->statusLabel()]),
            self::FILE_FAILED => __('deposit.file_failed', ['name' => $name]),
            self::FILE_QUARANTINED => __('deposit.file_quarantined', ['name' => $name]),
            self::FILE_REJECTED => __('deposit.file_rejected', ['name' => $name]),
            self::SLOT_EMPTY => __('deposit.slot_empty', ['name' => $name]),
            self::SLOT_EMPTY_CONDITIONAL => __('deposit.slot_empty_conditional', ['name' => $name]),
            default => $this->code,
        };
    }

    /** The pipeline status param (a key) as the reader's language shows it. */
    private function statusLabel(): string
    {
        $key = (string) ($this->params['status'] ?? '');

        return MediaFileStatus::fromKey($key)?->label() ?? $key;
    }

    /**
     * @return array{code: string, params: array<string, string|int|null>, message: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'params' => $this->params,
            'message' => $this->message(),
        ];
    }
}
