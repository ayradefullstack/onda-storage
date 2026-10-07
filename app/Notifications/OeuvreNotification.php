<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Oeuvre;
use Illuminate\Notifications\Notification;

/**
 * Shared shape for the three deposit-lifecycle notifications.
 *
 * ---------------------------------------------------------------------
 * Why the payload stores an i18n key and parameters, not a sentence.
 * ---------------------------------------------------------------------
 * The brief requires notification text in the *recipient's* locale, not
 * the actor's. This project has no server-side `lang/` directory — every
 * translated string lives in `resources/js/locales/*.json` and is
 * rendered by vue-i18n — and no `users.locale` column: the active locale
 * is a cookie, read per request (see CLAUDE.md).
 *
 * Rendering the sentence at write time would therefore freeze it in
 * whichever locale the *actor* happened to be using, which is exactly the
 * wrong one. Storing `i18n_key` plus its parameters defers rendering to
 * the moment the bell draws the row, in the reader's own locale — and
 * keeps already-stored notifications correct when a reader switches
 * language afterwards.
 *
 * `label_source` is the same input `resources/js/components/oeuvre/label.ts`
 * takes everywhere else, so the bell names a deposit exactly as the works
 * table does, with the date formatted in the reader's locale. The one
 * field captured at write time rather than read time is `college_name`:
 * that is deliberate and consistent with `oeuvres.code_college_snapshot` —
 * it records what the deposit was filed under, not what the collège is
 * called today.
 *
 * ---------------------------------------------------------------------
 * Channels
 * ---------------------------------------------------------------------
 * Database only. Mail is deliberately not added: the transport in `.env`
 * is unverified (`MAIL_FROM_ADDRESS` is still the `hello@example.com`
 * placeholder), and the brief forbids configuring one in this task.
 * Adding it later is a one-line change to `via()`.
 */
abstract class OeuvreNotification extends Notification
{
    public function __construct(
        protected readonly Oeuvre $oeuvre,
    ) {}

    /**
     * @return list<string>
     */
    final public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    final public function toArray(object $notifiable): array
    {
        return [
            'type' => 'deposit',
            'oeuvre_uuid' => $this->oeuvre->uuid,
            'label_source' => $this->labelSource(),
            ...$this->payload(),
        ];
    }

    /**
     * The key, its parameters, and where clicking the row goes.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return array{uuid: string, title: string|null, college_name: string|null, created_at: string|null}
     */
    private function labelSource(): array
    {
        $college = $this->oeuvre->registerTypeCollege;

        return [
            'uuid' => $this->oeuvre->uuid,
            'title' => $this->oeuvre->title,
            'college_name' => $college === null ? null : trim($college->name_global),
            'created_at' => $this->oeuvre->created_at?->toIso8601String(),
        ];
    }
}
