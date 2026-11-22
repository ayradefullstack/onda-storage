<?php

namespace App\Models;

use App\Concerns\HasUuidColumn;
use App\Notifications\Auth\AccountCreatedNotification;
use App\Notifications\Auth\VerifyEmailAddress;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $first_name_ar
 * @property string|null $last_name_ar
 * @property string $name
 * @property string $email
 * @property int|null $country_id
 * @property int|null $wilaya_id
 * @property int|null $commune_id
 * @property string|null $city
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'uuid',
    'name',
    'first_name',
    'last_name',
    'first_name_ar',
    'last_name_ar',
    'email',
    'country_id',
    'wilaya_id',
    'commune_id',
    'city',
    'phone',
    'password',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    use HasRoles, HasUuidColumn, SoftDeletes;

    /** Transient, in-memory only (see markNewlyRegistered). */
    private bool $newlyRegistered = false;

    /**
     * Queued, after-commit verification mail (see VerifyEmailAddress). Called
     * by the framework's `Registered` listener, by Fortify's resend route
     * and by EmailChangeService — one implementation for all three.
     */
    public function sendEmailVerificationNotification(): void
    {
        // Read and reset first: the flag is single-use, so a later resend or
        // an email change on this same instance gets the plain mail.
        $newlyRegistered = $this->newlyRegistered;
        $this->newlyRegistered = false;

        $this->notify($newlyRegistered
            ? AccountCreatedNotification::for($this)
            : VerifyEmailAddress::for($this));
    }

    /**
     * Mark this instance as just registered, so the next verification mail is
     * the "account created" one. Called by RegistrationService after commit.
     *
     * Relies on the framework's `Registered` listener
     * (SendEmailVerificationNotification) being synchronous and on Fortify
     * handing the SAME instance to `Registered` that CreateNewUser returned.
     * It is a plain property, not an attribute: never persisted, serialised
     * or part of a queued model identifier.
     */
    public function markNewlyRegistered(): void
    {
        $this->newlyRegistered = true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<Wilaya, $this>
     */
    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }
}
