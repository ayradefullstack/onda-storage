<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Value;

/**
 * The full result of evaluating the submission gate: every blocker at
 * once, never just the first one found.
 *
 * An author told "one more problem" five times in a row stops trusting the
 * button — so the gate collects, and the page renders the whole list.
 *
 * `advisories` do not block. They are the empty *conditional* required
 * slots: the author is told they may not apply, and the officer is shown
 * the same list at review time to judge. See SubmissionGate.
 */
final class SubmissionVerdict
{
    /**
     * @param  list<SubmissionReason>  $blockers
     * @param  list<SubmissionReason>  $advisories
     */
    public function __construct(
        public readonly array $blockers,
        public readonly array $advisories,
    ) {}

    public function passes(): bool
    {
        return $this->blockers === [];
    }

    /**
     * @return list<string>
     */
    public function blockerMessages(): array
    {
        return array_map(fn (SubmissionReason $reason): string => $reason->message(), $this->blockers);
    }

    /**
     * @return array{can_submit: bool, blockers: list<array{code: string, params: array<string, string|int|null>, message: string}>, advisories: list<array{code: string, params: array<string, string|int|null>, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'can_submit' => $this->passes(),
            'blockers' => array_map(fn (SubmissionReason $reason): array => $reason->toArray(), $this->blockers),
            'advisories' => array_map(fn (SubmissionReason $reason): array => $reason->toArray(), $this->advisories),
        ];
    }
}
