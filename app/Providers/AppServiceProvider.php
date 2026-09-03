<?php

namespace App\Providers;

use App\Models\MediaFile;
use App\Models\UploadSession;
use App\Models\Work;
use App\Policies\MediaFilePolicy;
use App\Policies\UploadSessionPolicy;
use App\Policies\WorkPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
    }

    /**
     * Explicit over relying on Laravel's naming-convention auto-discovery —
     * keeps every ownership check for the upload HTTP layer (P3) declared in
     * one place.
     */
    protected function configurePolicies(): void
    {
        Gate::policy(Work::class, WorkPolicy::class);
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
