<?php

namespace App\Providers;

use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\UploadSession;
use App\Policies\MediaFilePolicy;
use App\Policies\OeuvrePolicy;
use App\Policies\UploadSessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureBlueprintMacros();
        $this->configurePolicies();
        $this->configureRateLimiting();
    }

    /**
     * Outside `local`, /_vault-doctor is reachable by signed URL alone, and a
     * signed URL replays freely until it expires — cap it. Resolved
     * per-request so the environment check stays live.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('vault-doctor', fn (Request $request): Limit => app()->environment('local')
            ? Limit::none()
            : Limit::perMinute(5)->by($request->ip()),
        );

        // Upload inits and chunks MUST NOT share a bucket. An unnamed
        // `throttle:N,M` keys on the user id alone, so `throttle:10,1` (init)
        // and `throttle:1200,1` (chunk) incremented ONE counter and the init
        // was judged against ITS ceiling of 10: a running upload at ~10
        // chunks/s pushed the counter past 10 within a second and every
        // other file's init got 429 until the minute rolled over.
        RateLimiter::for('upload-init', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('init:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        );

        // Chunks: one bucket per upload SESSION (a single 5 GB file at full
        // local speed is ~11 requests/s = ~660/min, so 1500 leaves room for
        // retries), plus a per-user ceiling sized for several files at once.
        RateLimiter::for('upload-chunk', function (Request $request): array {
            $user = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
            $session = $request->route('session');
            $session = $session instanceof UploadSession ? $session->uuid : (string) $session;

            return [
                Limit::perMinute(1500)->by('chunk-session:'.$user.':'.$session),
                Limit::perMinute(4000)->by('chunk-user:'.$user),
            ];
        });
    }

    /**
     * Explicit over relying on Laravel's naming-convention auto-discovery —
     * keeps every ownership check for the upload HTTP layer (P3) declared in
     * one place.
     */
    protected function configurePolicies(): void
    {
        Gate::policy(Oeuvre::class, OeuvrePolicy::class);
        Gate::policy(UploadSession::class, UploadSessionPolicy::class);
        Gate::policy(MediaFile::class, MediaFilePolicy::class);
    }

    /**
     * Register schema-building macros shared across ONDA vault migrations.
     */
    protected function configureBlueprintMacros(): void
    {
        Blueprint::macro('ondaKeys', function (): void {
            /** @var Blueprint $this */
            $this->id();
            $this->uuid('uuid')->unique();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
