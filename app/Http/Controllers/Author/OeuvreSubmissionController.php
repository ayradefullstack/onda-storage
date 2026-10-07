<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Oeuvre\SubmitOeuvre;
use App\Domain\Deposit\Exceptions\IllegalTransition;
use App\Domain\Deposit\Exceptions\SubmissionRefused;
use App\Http\Controllers\Controller;
use App\Models\Oeuvre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Thin: authorise, call the action, redirect. Every rule about whether a
 * deposit may be submitted lives in SubmissionGate and
 * OeuvreStatusMachine, never here.
 */
final class OeuvreSubmissionController extends Controller
{
    public function store(Request $request, Oeuvre $oeuvre, SubmitOeuvre $submit): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('submit', $oeuvre);

        try {
            $submit->handle($oeuvre, $request->user());
        } catch (SubmissionRefused $e) {
            // Every blocker at once, never just the first: an author told
            // "one more problem" five times in a row stops trusting the
            // button. The error bag keeps all of them, and the oeuvre page
            // re-renders its `submission` prop with the same list in the
            // reader's own language (the messages here are the English
            // source strings — this project has no server-side lang/).
            throw ValidationException::withMessages([
                'submission' => $e->verdict->blockerMessages(),
            ]);
        } catch (IllegalTransition $e) {
            // Raced with something that already moved the deposit — the
            // policy passed when the page rendered, the machine refused
            // when it ran.
            throw ValidationException::withMessages([
                'submission' => [$e->getMessage()],
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your work has been submitted for review.')]);

        return to_route('oeuvres.show', $oeuvre);
    }
}
