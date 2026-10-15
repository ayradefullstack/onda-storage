---
name: "source-command-upload-progress-stall"
description: "Migrated source command `upload-progress-stall`"
---

# source-command-upload-progress-stall

Use this skill when the user asks to run the migrated source command `upload-progress-stall`.

## Command Template

Task: the upload progress freezes at step 4 ("Analyse antivirus") and only completes after a manual page refresh. Fix it so the progress follows the server pipeline to the end, live, with no refresh. Read AGENTS.md first.

## Symptom (manual test)
- Author uploads a PDF (8.6 MB) to a required slot. Steps 1–3 complete, step 4 (virus scan) becomes active, then the UI stays on step 4 forever.
- After F5, the file shows as finished. So **the backend pipeline completes; the frontend stops following it.**

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Part 1 — Find the root cause (with evidence; continue after reporting)
Reproduce in the browser (Playwright) on `onda-storage.test`, as an author, with the `media` and `previews` queue workers running. Record a timeline (timestamps) of: every network request made after the upload `complete` call (URL, status, response body excerpt), the file row's status in the DB at each moment, and the step shown in the UI.

Check these causes explicitly and say which one it is:
1. **Polling stops too early:** a max-attempts / max-duration cap, or polling tied to the upload store entry, which ends at `complete` (step 3) while steps 4–6 are server-side.
2. **Polling stops on a "terminal-looking" status:** e.g. `scanning` or `processing` treated as final, or an unknown status breaking the loop.
3. **Status mapping mismatch:** the server's final status string (`ready` / `verified` / `registered`…) does not map to step 6 in the component, so the UI never advances even though the data arrives.
4. **Data arrives but the UI does not re-render:** the poll uses `router.reload({ only: [...] })` with a prop name that does not match, or the component reads a stale copy (store entry, local ref copied once from props) instead of the refreshed props.
5. **Regression from the global-loader work:** the `meta: { silent: true }` change, or an exclusion rule, cancelling or swallowing the poll visits.
6. **Tab visibility:** polling paused when the tab is hidden and never resumed.
7. **Production timing:** on cPanel the queue may run later (cron-driven worker), so any time cap shorter than a few minutes breaks there even if it works locally. Report how the workers run in production per the README.

State the root cause in one sentence. It must be covered by a regression test in Part 3.

## Part 2 — The fix: one reliable status loop
Keep it simple and server-driven. No WebSockets (cPanel has no Reverb).

### Server
- A lightweight, author-scoped JSON endpoint for the files of one oeuvre, e.g. `GET author/oeuvres/{oeuvre}/files/status` (reuse an existing endpoint if one already does this). It returns, per file: `id`, `status`, `stage` (the step index/key the UI shows), `error` (localized key) if failed.
  - Owner only (another author → 404). One query, no N+1, no heavy work.
  - `stage` comes from the real pipeline state. If no per-stage information is stored today, report it in Part 1 and propose the smallest additive way (e.g. each job updating a `processing_stage` column at its start). **Do not change the pipeline order or logic** without my approval.

### Client (one composable, e.g. `useFileStatusPoll`)
- Starts as soon as any file of the page is non-terminal (after `complete`, and also on page load if a file is already processing, so a refresh during processing keeps working).
- Polls that endpoint with a gentle backoff: 1.5 s → 3 s → 5 s (max 5 s while active).
- **Never gives up silently.** After 3 minutes still processing: a soft hint "Le traitement prend plus de temps que prévu…", polling continues at 10 s. After 15 minutes: a "Actualiser" button, polling still continues at 30 s.
- Terminal statuses stop the loop for that file: ready, failed, quarantined/infected, rejected (use the real enum values from the code).
- Visibility-aware: pause while hidden; on visible, fetch immediately and resume.
- On each response, the step UI updates from `stage`/`status` (the single source of truth: no client-side guessing, no timer-based fake progress).
- When a file reaches a terminal status: **one** silent partial reload of the page props that depend on files (file list, submission gate / "Soumettre" button, slot completeness), with `meta: { silent: true }` so the global loader never shows.
- A failed/infected result shows its existing error state immediately (never stuck on step 4).
- Stop and clean up timers on unmount and on navigation. Use the same composable on the author oeuvre page and anywhere else this step component is shown (list the places).

## Part 3 — Tests
- **Regression test for the Part 1 root cause** (mandatory).
- **Vitest (fake timers):**
  - the loop continues past step 4 and reaches the final step when the server returns the final status;
  - the backoff intervals, and that polling continues after 3 min and 15 min (hint and button shown, loop still alive);
  - terminal statuses stop the loop; a failed/infected result renders the error state;
  - hidden tab pauses, visible tab fetches immediately;
  - a page loaded with a file already processing starts polling;
  - exactly one silent partial reload on the terminal transition;
  - timers cleared on unmount.
- **Pest:**
  - the status endpoint returns the correct status/stage for each pipeline state;
  - another author's oeuvre → 404; guest → redirect/401;
  - query count under a fixed ceiling.
- **Manual (Playwright on onda-storage.test):** upload one PDF and one large file (> 500 MB); the steps advance to the end without refresh. Also: switch tab during step 4 and come back; refresh during processing. Report the timeline for each.

## Owned files — touch nothing else
```
the upload step/progress component(s) (find them; list them in the report)
resources/js/composables/useFileStatusPoll.ts (new) or the existing poll composable
resources/js/pages/author/oeuvres/** ← wire the composable only
app/Http/Controllers/Author/*FileStatus* (new) or the existing status endpoint
routes/author.php                     ← the status route only
database/migrations/**                ← only an additive processing_stage column, if Part 1 proves it is needed and I approve
resources/js/locales/{ar,fr,en}.json
tests for the above
```
FROZEN: the upload protocol and scheduler, the vault/encryption, the pipeline job order and logic (beyond the approved stage update), the consultation viewers, colour and font tokens.

## Acceptance gate
1. The Part 1 timeline and root cause.
2. `composer test`: green, before/after counts. Vitest, types, lint and format all 0. `npm run build`.
3. The manual Playwright timelines.
4. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `--cached`, `update-index`).
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
