<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Review\ApproveOeuvre;
use App\Actions\Review\OpenReview;
use App\Actions\Review\RejectOeuvre;
use App\Domain\Deposit\Exceptions\IllegalTransition;
use App\Domain\Deposit\Exceptions\ReasonRequired;
use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Http\Controllers\Controller;
use App\Models\Oeuvre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The officer's three decisions. Thin: each validates its own input, calls
 * one action, and maps the domain's refusals onto HTTP.
 *
 * The `role:admin` boundary is the route group's (routes/admin.php); the
 * transition table's own actor check runs again inside
 * OeuvreStatusMachine, so a future route that forgets the middleware still
 * cannot register a deposit.
 *
 * ReviewConflict becomes 409, not 422: it is not bad input — the officer
 * did nothing wrong, someone else got there first. The `holder` and
 * `current_status` it carries are what the page needs to offer a
 * take-over instead of just an error.
 */
final class OeuvreReviewController extends Controller
{
    public function review(Request $request, Oeuvre $oeuvre, OpenReview $open): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('decide', $oeuvre);

        $validated = $request->validate([
            'take_over' => ['sometimes', 'boolean'],
        ]);

        $this->guard(fn () => $open->handle(
            $oeuvre,
            $request->user(),
            (bool) ($validated['take_over'] ?? false),
        ));

        return back();
    }

    public function approve(Request $request, Oeuvre $oeuvre, ApproveOeuvre $approve): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('decide', $oeuvre);

        $validated = $request->validate([
            'take_over' => ['sometimes', 'boolean'],
        ]);

        $this->guard(fn () => $approve->handle(
            $oeuvre,
            $request->user(),
            (bool) ($validated['take_over'] ?? false),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The deposit has been registered.')]);

        return back();
    }

    public function reject(Request $request, Oeuvre $oeuvre, RejectOeuvre $reject): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('decide', $oeuvre);

        // Validated here as well as in the machine: `required` gives the
        // officer an inline field error as they type, where the domain
        // exception can only be a page-level refusal. The machine stays
        // authoritative — it is what a future API or console command would
        // hit.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'take_over' => ['sometimes', 'boolean'],
        ]);

        $this->guard(fn () => $reject->handle(
            $oeuvre,
            $request->user(),
            $validated['reason'],
            (bool) ($validated['take_over'] ?? false),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The author has been told why this deposit was rejected.')]);

        return back();
    }

    /**
     * @param  \Closure(): mixed  $decision
     */
    private function guard(\Closure $decision): void
    {
        try {
            $decision();
        } catch (ReviewConflict $e) {
            abort(409, $e->getMessage());
        } catch (ReasonRequired $e) {
            throw ValidationException::withMessages(['reason' => [$e->getMessage()]]);
        } catch (IllegalTransition $e) {
            // Includes every attempt to move a registered deposit. It is
            // 422 rather than 403: the officer has the right role, the
            // deposit simply cannot go there.
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }
    }
}
