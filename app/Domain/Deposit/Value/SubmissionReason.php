<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Value;

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
            self::NO_FILES => 'This work has no files yet.',
            self::FILE_IN_PIPELINE => "\"{$name}\" is still being processed ({$this->params['status']}). Wait for it to finish.",
            self::FILE_FAILED => "\"{$name}\" failed to process. Remove it or upload it again.",
            self::FILE_QUARANTINED => "\"{$name}\" was quarantined by the malware scan. Remove it.",
            self::SLOT_EMPTY => "The required document \"{$name}\" has no file yet.",
            self::SLOT_EMPTY_CONDITIONAL => "The document \"{$name}\" is empty; it may not apply to this work.",
            default => $this->code,
        };
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
