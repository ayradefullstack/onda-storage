<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        return redirect()->to(self::redirectPath($request));
    }

    /**
     * The single source of truth for "where does this authenticated user
     * belong" — also reused by `bootstrap/app.php`'s `redirectUsersTo` (an
     * already-authenticated user hitting a guest-only route, e.g. visiting
     * `/login` again) so the two never drift apart.
     *
     * A user holding both roles lands on the admin dashboard — a
     * deliberate default, not an oversight: admin is the more privileged
     * surface, so an operator who is also a registered author should not
     * be dropped into the lower-privilege view by default.
     *
     * A user holding NEITHER role is not sent to a role-gated dashboard —
     * `role:author`/`role:admin` middleware would 403 them immediately,
     * with no explanation, the moment they land. `CreateNewUser` now
     * assigns `author` on self-registration, so this should only ever be
     * reached for a manually-created or role-stripped account; it still
     * needs a real, reachable destination rather than a guaranteed 403.
     */
    public static function redirectPath(Request $request): string
    {
        $user = $request->user();

        return match (true) {
            $user?->hasRole('admin') => route('admin.dashboard'),
            $user?->hasRole('author') => route('author.dashboard'),
            default => route('account.pending'),
        };
    }
}
