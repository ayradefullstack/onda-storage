<?php

namespace App\Http\Controllers;

use App\Domain\Localization\LanguageService;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class LocaleController extends Controller
{
    public function __construct(private readonly LanguageService $languages) {}

    /**
     * Switch the active locale and send the user back to the page they were on.
     *
     * Only the `home` route is locale-prefixed (`/{locale}`); every other
     * route resolves its locale from the `locale` cookie (see SetLocale).
     * Switching locale therefore means: update the cookie, then redirect back
     * to the same path, rewriting a leading locale segment only when the
     * target path actually has one. Only active languages are accepted.
     */
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless($this->languages->isActive($locale), 404);

        Cookie::queue(SetLocale::localeCookie($locale));

        return redirect()->to($this->resolveRedirectPath($request, $locale));
    }

    private function resolveRedirectPath(Request $request, string $locale): string
    {
        $redirect = $request->string('redirect')->value();

        if (! $this->isSafeLocalPath($redirect)) {
            return '/'.$locale;
        }

        $segments = explode('/', ltrim($redirect, '/'), 2);

        if ($this->languages->isActive($segments[0])) {
            $segments[0] = $locale;

            return '/'.implode('/', $segments);
        }

        return $redirect;
    }

    private function isSafeLocalPath(?string $path): bool
    {
        return $path !== null
            && $path !== ''
            && Str::startsWith($path, '/')
            && ! Str::startsWith($path, '//')
            && ! Str::contains($path, ['://', '\\']);
    }
}
