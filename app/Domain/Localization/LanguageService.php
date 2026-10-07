<?php

declare(strict_types=1);

namespace App\Domain\Localization;

use App\Models\Language;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads and guards the `languages` table.
 *
 * Every consumer — SetLocale, LocaleController, the Inertia shared props,
 * the admin screen — goes through here, so "which locales exist" has one
 * answer. The active list is cached forever and flushed by the Language
 * model's `saved`/`deleted` events (and explicitly after setDefault()).
 *
 * Invariants kept here, not in the controller: exactly one default, and the
 * default is always active.
 *
 * Rule violations are ValidationExceptions whose message is an
 * `admin.languages.error.*` key; the admin page translates them.
 */
final class LanguageService
{
    private const CACHE_KEY = 'languages.active';

    /**
     * Active languages, ordered. Plain arrays so the cached payload is
     * independent of model serialization.
     *
     * @return Collection<int, array{code: string, name: string, native_name: string, direction: string, is_default: bool}>
     */
    public function active(): Collection
    {
        return collect(Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->load()));
    }

    public function defaultCode(): string
    {
        $default = $this->active()->firstWhere('is_default', true) ?? $this->active()->first();

        return $default['code'] ?? (string) config('app.fallback_locale');
    }

    public function isActive(mixed $code): bool
    {
        return is_string($code) && $this->active()->contains('code', $code);
    }

    /** The code if it is an active language, otherwise the default. */
    public function resolve(mixed $code): string
    {
        return $this->isActive($code) ? $code : $this->defaultCode();
    }

    public function direction(string $code): string
    {
        return $this->active()->firstWhere('code', $code)['direction'] ?? 'ltr';
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function setDefault(Language $language): void
    {
        if (! $language->is_active) {
            $this->fail('defaultInactive');
        }

        DB::transaction(function () use ($language): void {
            Language::query()->lockForUpdate()->get();
            Language::query()->where('id', '!=', $language->id)->where('is_default', true)->update(['is_default' => false]);
            Language::query()->whereKey($language->id)->update(['is_default' => true]);
        });

        $this->flush();
    }

    public function setActive(Language $language, bool $active): void
    {
        if (! $active && $language->is_default) {
            $this->fail('deactivateDefault');
        }

        $language->forceFill(['is_active' => $active])->save();
    }

    /**
     * @return list<array{code: string, name: string, native_name: string, direction: string, is_default: bool}>
     */
    private function load(): array
    {
        try {
            return array_values(Language::query()->active()->ordered()->get()
                ->map(fn (Language $l): array => [
                    'code' => $l->code,
                    'name' => $l->name,
                    'native_name' => $l->native_name,
                    'direction' => $l->direction,
                    'is_default' => $l->is_default,
                ])->all());
        } catch (Throwable) {
            // Table missing (before `migrate`) — fall back to the one locale
            // the environment names so the app can still render an error page.
            $code = (string) config('app.fallback_locale');

            return [['code' => $code, 'name' => $code, 'native_name' => $code, 'direction' => 'ltr', 'is_default' => true]];
        }
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['language' => "admin.languages.error.{$key}"]);
    }
}
