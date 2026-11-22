<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a profile update and, when (and only when) the email address
 * actually changes, drops the verification and mails a new link to the NEW
 * address. Until that link is used the `verified` middleware keeps every
 * protected route closed.
 */
class EmailChangeService
{
    /**
     * @param  array<string, mixed>  $attributes  validated profile fields
     * @return bool whether the email address changed
     */
    public function update(User $user, array $attributes): bool
    {
        $changed = false;

        if (isset($attributes['email'])) {
            $submitted = Str::lower(trim((string) $attributes['email']));
            $changed = $submitted !== Str::lower((string) $user->email);

            // A casing-only difference is the same mailbox: keep the stored
            // value and the verification untouched.
            $attributes['email'] = $changed ? $submitted : $user->email;
        }

        DB::transaction(function () use ($user, $attributes, $changed): void {
            $user->fill($attributes);

            if ($changed) {
                $user->email_verified_at = null;
            }

            $user->save();
        });

        if ($changed) {
            // After-commit and queued (see VerifyEmailAddress). The signed
            // link embeds sha1(new email), so any link issued for the
            // previous address no longer validates.
            $user->sendEmailVerificationNotification();
        }

        return $changed;
    }
}
