<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Notifications\QueuedForAuthor;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * The verification mail, queued.
 *
 * It extends the framework's own notification on purpose: the signed URL
 * (`verification.verify`, `id` + `sha1(email)`, `auth.verification.expire`
 * minutes) is built by `VerifyEmail::verificationUrl()` exactly as before, so
 * Fortify's `VerifyEmailRequest` and the `signed` middleware keep validating
 * it unchanged. Only two things differ: it is queued, and it renders the
 * corporate mail view.
 *
 * What is serialised into the job is this object (the locale string only)
 * plus the notifiable as a model identifier (class + id). No attribute of
 * the user — password hash, TOTP secret, recovery codes — enters the payload.
 *
 * The URL and the recipient are resolved by the worker, at send time, from
 * the user's CURRENT email: a link can therefore never be mailed for an
 * address the user has since replaced.
 */
class VerifyEmailAddress extends VerifyEmail implements ShouldQueue
{
    use QueuedForAuthor;

    /** Attempts before the job is parked in failed_jobs. */
    public int $tries = 3;

    /**
     * Keyed fingerprint of the address this mail was issued FOR. It is what
     * ties the queued job to one address without putting the address (or a
     * plain sha1 of it, which is guessable offline) into the queue payload.
     */
    protected string $addressBinding;

    public function __construct(string $addressBinding, ?string $locale = null)
    {
        $this->addressBinding = $addressBinding;

        // A queued notification runs without the request's locale; carry it.
        $this->locale = $locale ?? app()->getLocale();

        // Registration and an email change both run inside a request that
        // may hold a transaction: the worker must never see the user row
        // before it is committed (or after it was rolled back).
        $this->afterCommit();
    }

    /**
     * Issue a notification for the address the user has RIGHT NOW.
     */
    public static function for(MustVerifyEmail $user, ?string $locale = null): self
    {
        return new self(self::bindingFor($user->getEmailForVerification()), $locale);
    }

    /**
     * HMAC under the app key, case-insensitive like the address itself.
     */
    public static function bindingFor(string $email): string
    {
        return hash_hmac('sha256', Str::lower($email), (string) config('app.key'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Skip a mail that has become pointless while it waited in the queue:
     *  - the user verified through an earlier link, or
     *  - the address changed since this mail was issued. Without that check
     *    the worker would resolve the CURRENT address and mail (and sign a
     *    link for) one the user never asked this notification for; the
     *    notification issued at the change covers the new address.
     *
     * Evaluated by the worker on every attempt, retries included.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof MustVerifyEmail || $notifiable->hasVerifiedEmail()) {
            return false;
        }

        return hash_equals(
            $this->addressBinding,
            self::bindingFor($notifiable->getEmailForVerification()),
        );
    }

    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject(__('verification.subject'))
            ->view('mail.corporate', [
                'subject' => __('verification.subject'),
                'locale' => $this->locale,
                'title' => __('verification.title'),
                'greeting' => __('verification.greeting', ['name' => (string) $notifiable->name]),
                'introLines' => [
                    __('verification.intro'),
                    __('verification.expires', ['minutes' => $minutes]),
                ],
                'showFeatures' => false,
                'actionText' => __('verification.action'),
                'actionUrl' => $url,
                'subcopy' => __('verification.subcopy', ['action' => __('verification.action')]),
                'warningTitle' => __('verification.warning_title'),
                'warningText' => __('verification.warning_text'),
                'outroLines' => [
                    __('verification.outro_support'),
                    __('verification.outro_regards'),
                    __('verification.outro_signature'),
                ],
            ]);
    }
}
