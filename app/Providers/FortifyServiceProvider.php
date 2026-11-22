<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\EnforceLoginLockout;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Models\Country;
use App\Models\Wilaya;
use App\Services\Auth\LoginMethod;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        // Replaces the package's own binding (app providers register after it).
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fortify consumes a recovery code (and fires this event, synchronously,
        // before logging in) only when the recovery code was the accepted proof.
        // The response reads the marker; the code itself is never looked at.
        Event::listen(RecoveryCodeReplaced::class, function (): void {
            app('request')->attributes->set(LoginMethod::RECOVERY_MARKER, true);
        });

        $this->configureActions();
        $this->configureLoginPipeline();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Fortify's login pipeline with ONE extra stage, EnforceLoginLockout.
     *
     * `authenticateThrough` replaces Fortify's whole default list, so the
     * list below repeats Fortify's own conditions verbatim
     * (AuthenticatedSessionController::loginPipeline); only the lockout stage
     * is new. It sits right after CanonicalizeUsername and before BOTH
     * password-verification stages (the 2FA redirect stage verifies the
     * password for every user while 2FA is on; AttemptToAuthenticate does it
     * otherwise), so a failed request is counted once, and a locked scope is
     * refused before any password is checked.
     *
     * Order of everything on POST /login:
     *   throttle:login (IP budget, then email+IP budget; request throttling)
     *   -> LoginRequest validation (malformed requests stop here)
     *   -> this pipeline (the failed-password lockout).
     * Requests rejected by the first two never reach the lockout and are
     * never counted as password failures.
     */
    private function configureLoginPipeline(): void
    {
        Fortify::authenticateThrough(fn (Request $request): array => array_filter([
            config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            EnforceLoginLockout::class,
            Features::enabled(Features::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'countries' => Country::active()->visible()->orderByRaw("CASE WHEN alpha2 = 'DZ' THEN 0 ELSE 1 END, name ASC")->get(['id', 'alpha2', 'name', 'native_name', 'arabic_name', 'phone_code', 'flag_url']),
            'wilayas' => Wilaya::active()->visible()->orderBy('code')->get(['id', 'code', 'name_fr', 'name_ar', 'country_id']),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Fortify's `POST /login` carries exactly one `throttle:login`
        // middleware, so the two independent buckets live in this one named
        // limiter (ThrottleRequests accepts a list of limits):
        //  1. email + IP — the original limiter, unchanged until the lockout
        //     phase replaces it;
        //  2. IP only — every submission counts, whatever the email and
        //     whether the password was right. Different emails from one IP
        //     share it; a different IP has its own.
        // Each limit is its own cache key (md5('login'.<key>)); the IP key is
        // prefixed `ip:` and the email key always contains `|`, so they can
        // never collide. The middleware checks all limits first and only
        // then counts, so a blocked request is not counted again and never
        // extends a window. `$request->ip()` is the direct peer unless
        // trusted proxies are explicitly configured (config/trustedproxy.php).
        RateLimiter::for('login', function (Request $request) {
            // This runs BEFORE Fortify's LoginRequest validation, so the field
            // is untrusted: `email[]=x` or a JSON number is not a string. Such a
            // request is malformed (validation answers 422 afterwards); it only
            // spends the per-IP budget and a shared "malformed" bucket, and
            // never reaches the lockout.
            $username = $request->input(Fortify::username());
            $throttleKey = Str::transliterate(Str::lower(is_string($username) ? $username : '[malformed]').'|'.($request->ip() ?? 'unknown'));

            if (config('auth-security.invalid') !== []) {
                // Key names only. The values fell back to safe defaults.
                Log::warning('auth-security: invalid settings replaced by defaults.', [
                    'keys' => config('auth-security.invalid'),
                ]);
            }

            return [
                Limit::perMinute(5)->by($throttleKey),
                new Limit(
                    'ip:'.($request->ip() ?? 'unknown'),
                    (int) config('auth-security.login.ip_max_requests'),
                    (int) config('auth-security.login.ip_window_seconds'),
                ),
            ];
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
