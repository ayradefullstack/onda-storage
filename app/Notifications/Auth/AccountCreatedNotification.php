<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your account was created" — the verification mail of a NEW registration,
 * plus the matching row for the bell.
 *
 * It is the verification notification (same signed link, address binding,
 * expiry, locale, after-commit and retry policy), so the account can be
 * activated from this very mail. One notify() gives one notification id shared
 * by both channels; each channel is its own queued job and retries alone.
 *
 * The payload carries no personal data: the text is an i18n key resolved by the
 * client, and the time is the registration time, captured at construction (not
 * when a worker eventually runs).
 */
class AccountCreatedNotification extends VerifyEmailAddress
{
    /** Registration time, ISO 8601 UTC ("...Z"). A plain string: it serialises safely. */
    private string $occurredAt;

    public function __construct(string $addressBinding, ?string $locale = null)
    {
        parent::__construct($addressBinding, $locale);

        $this->occurredAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
    }

    public static function for(MustVerifyEmail $user, ?string $locale = null): self
    {
        return new self(self::bindingFor($user->getEmailForVerification()), $locale);
    }

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Both channels run on `default` (the parent only routes `database`).
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'default', 'database' => 'default'];
    }

    /**
     * Mail: only while the verification mail still makes sense (unverified and
     * the address unchanged). Bell row: only if it does not exist yet, so a
     * retried or re-run job never writes a second row for the same id.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel === 'database') {
            return method_exists($notifiable, 'notifications')
                && $notifiable->notifications()->whereKey($this->id)->doesntExist();
        }

        return parent::shouldSend($notifiable, $channel);
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject(__('account_created.subject'))
            ->view('mail.corporate', [
                'subject' => __('account_created.subject'),
                'locale' => $this->locale,
                'title' => __('account_created.title'),
                'greeting' => __('account_created.greeting', ['name' => (string) $notifiable->name]),
                'introLines' => [
                    __('account_created.intro'),
                    __('account_created.verify_intro'),
                    __('account_created.expires', ['minutes' => $minutes]),
                ],
                'showFeatures' => false,
                'actionText' => __('account_created.action'),
                'actionUrl' => $this->verificationUrl($notifiable),
                'subcopy' => __('account_created.subcopy', ['action' => __('account_created.action')]),
                'warningTitle' => __('account_created.warning_title'),
                'warningText' => __('account_created.warning_text'),
                'outroLines' => [
                    __('account_created.outro_support'),
                    __('account_created.outro_regards'),
                    __('account_created.outro_signature'),
                ],
            ]);
    }

    /**
     * Bell row. No PII: the client translates `i18n_key`.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_created',
            'i18n_key' => 'notifications.account.created',
            'params' => [],
            'occurred_at' => $this->occurredAt,
            'url' => null,
        ];
    }
}
