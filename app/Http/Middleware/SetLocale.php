<?php

namespace App\Http\Middleware;

use App\Domain\Localization\LanguageService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Cookie as CookieValueObject;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly LanguageService $languages) {}

    /**
     * Handle an incoming request.
     *
     * Resolves the active locale from the {locale} route segment when present
     * (public, crawlable routes), falling back to the `locale` cookie for
     * routes that are not locale-prefixed (authenticated app area). The set
     * of valid locales, the default and each locale's direction all come
     * from the `languages` table (LanguageService). Shares the resolved
     * locale and direction with both the Blade root view and Inertia.
     *
     * A `{locale}` segment that has the right shape but is not an active
     * language is a 404, not a silent fallback: `/es` must not render the
     * site under a different language's URL.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');

        if ($routeLocale !== null && ! $this->languages->isActive($routeLocale)) {
            abort(404);
        }

        $locale = $routeLocale ?? $this->languages->resolve($request->cookie('locale'));

        App::setLocale($locale);
        App::setFallbackLocale($this->languages->defaultCode());

        View::share('locale', $locale);
        View::share('direction', $this->languages->direction($locale));

        if ($routeLocale !== null) {
            Cookie::queue($this->localeCookie($locale));
        }

        return $next($request);
    }

    /**
     * `httpOnly: false` is deliberate: the language switcher applies a locale
     * change instantly client-side (no round trip) and persists it by writing
     * this same cookie directly via `document.cookie`. An httpOnly cookie
     * (Laravel's default) would silently block that write, so the next full
     * page load would revert to whatever locale was last set server-side.
     */
    public static function localeCookie(string $locale): CookieValueObject
    {
        return Cookie::make(
            name: 'locale',
            value: $locale,
            minutes: 60 * 24 * 365,
            httpOnly: false,
        );
    }
}
