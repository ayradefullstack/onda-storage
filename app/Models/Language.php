<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Localization\LanguageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A language the portal is offered in. The `languages` table is the single
 * source of truth for which locales exist, which are selectable, which is
 * the default and which way each one is written — see LanguageService.
 *
 * Not soft-deleted and never deleted through the app: retiring a language
 * is `is_active = false`. The default language can never be deleted at all
 * (see the `deleting` guard). Keyed by `code` in routes.
 */
class Language extends Model
{
    public const DIRECTIONS = ['ltr', 'rtl'];

    /** Lowercase BCP-47-ish: `ar`, `fr`, `pt-br`. Also the route `{locale}` shape. */
    public const CODE_PATTERN = '[a-z]{2,3}(?:-[a-z0-9]{2,8})?';

    /**
     * `is_default` is deliberately absent — it changes only through
     * LanguageService::setDefault(), so a crafted request can never create
     * a second default. `code` is writable here (needed at creation) but
     * UpdateLanguageRequest never validates it, so it is immutable over HTTP.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'code', 'native_name', 'direction', 'is_active', 'sort_order'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => app(LanguageService::class)->flush());
        static::deleted(fn () => app(LanguageService::class)->flush());

        static::deleting(function (Language $language): void {
            if ($language->is_default) {
                throw new LogicException('The default language cannot be deleted.');
            }
        });
    }

    /**
     * @param  Builder<Language>  $query
     * @return Builder<Language>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Language>  $query
     * @return Builder<Language>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }
}
