<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\User;
use App\Notifications\QueuedForAuthor;
use App\Services\Auth\LoginMethod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account was signed in to": a bell row plus, for a verified address,
 * a mail.
 *
 * Holds only the method, the instant the login was accepted and the locale
 * of that request. No IP, user agent, session id, code or address: the job
 * payload and the row stay free of anything an attacker could use.
 *
 * Mail goes only to the address verified AT LOGIN: the payload carries an
 * HMAC of that address (never the address), and the mail channel re-checks it
 * against the current verified address at delivery. A changed address, even
 * one verified before the worker runs, therefore gets no alert about a login
 * that happened under the old one.
 * Side effects: rotating APP_KEY between dispatch and delivery drops pending
 * alert mails (the bell row is still written); A -> B -> A with A re-verified
 * before the worker still mails A (same mailbox).
 *
 * mail and database are queued as separate jobs, so each one is retry-safe
 * on its own (the row is skipped once it exists).
 */
class LoginAlertNotification extends Notification implements ShouldQueue
{
    use QueuedForAuthor;

    public int $tries = 3;

    public function __construct(
        private readonly LoginMethod $method,
        private readonly CarbonImmutable $occurredAt,
        string $locale,
        private readonly ?string $mailBinding = null,
    ) {
        $this->locale = $locale;
        $this->afterCommit();
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'default', 'database' => 'default'];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel === 'mail') {
            return $this->mailBinding !== null
                && $notifiable instanceof MustVerifyEmail
                && $notifiable->hasVerifiedEmail()
                && hash_equals($this->mailBinding, VerifyEmailAddress::bindingFor($notifiable->getEmailForVerification()));
        }

        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        // Retry-safe: the row id is this notification's id.
        return $notifiable->notifications()->whereKey($this->id)->doesntExist();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = (string) config('app.display_timezone', 'UTC');
        $local = $this->occurredAt->setTimezone($timezone);
        $time = $local->format('d/m/Y H:i').' (UTC'.$local->format('P').')';

        return (new MailMessage)
            ->subject(__('login_alert.subject'))
            ->view('mail.corporate', [
                'subject' => __('login_alert.subject'),
                'locale' => $this->locale,
                'title' => __('login_alert.title'),
                'greeting' => __('login_alert.greeting', ['name' => $notifiable instanceof User ? $notifiable->name : '']),
                'introLines' => [
                    __('login_alert.intro'),
                    __('login_alert.time', ['time' => $time]),
                    __('login_alert.method.'.$this->method->value),
                ],
                'showFeatures' => false,
                'actionText' => __('login_alert.action'),
                'actionUrl' => route('security.edit'),
                'subcopy' => __('login_alert.subcopy'),
                'warningTitle' => __('login_alert.warning_title'),
                'warningText' => __('login_alert.warning_text'),
                'outroLines' => [
                    __('login_alert.outro_support'),
                    __('login_alert.outro_regards'),
                    __('login_alert.outro_signature'),
                ],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_login',
            'i18n_key' => 'notifications.account.login_'.$this->method->value,
            'params' => [],
            'method' => $this->method->value,
            'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'url' => route('security.edit'),
        ];
    }
}
