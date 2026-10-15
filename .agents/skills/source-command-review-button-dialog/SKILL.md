---
name: "source-command-review-button-dialog"
description: "Migrated source command `review-button-dialog`"
---

# source-command-review-button-dialog

Use this skill when the user asks to run the migrated source command `review-button-dialog`.

## Command Template

Task: the review action does not work. Replace the dropdown with a direct "Examiner" button that opens a review popup. Read AGENTS.md first.

Manual test result: clicking "review" in the admin oeuvres Actions column does nothing useful. The product owner does not want a dropdown menu: they want **one visible button in the Actions column that opens a popup (dialog)**, where every decision happens.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Part 1 — Find why it does not work (report, then continue)
Reproduce in the browser as admin, on an oeuvre in `submitted` and one in `under_review`. Report with evidence:
- What happens on click: the console errors, the network request (method, URL, status, response body), or no request at all.
- Check these causes explicitly:
  1. A Dialog opened from a `DropdownMenuItem`. The menu closing steals focus or leaves `pointer-events: none` on `<body>`, so the dialog closes immediately or the page freezes. (`@click` vs `@select`, and a missing `modal`/`nextTick` handling.)
  2. Wrong route or method: a Wayfinder helper not regenerated with `--with-form`, GET vs POST, or a missing CSRF token on a manual fetch.
  3. A 403 from `OeuvrePolicy::decide` (draft, or not admin), or a 409 from the holder model, swallowed with no message.
  4. An Inertia visit that succeeds but does not refresh the row (`preserveState` without a reload of `oeuvres`).
- State the root cause in one sentence. It must be covered by a regression test in Part 3.

## Part 2 — The new interaction

### Actions column (`admin/oeuvres/Index.vue`)
- Remove the decision dropdown completely.
- Each row keeps **View**, and gets one more visible button: **"Examiner"** (ar "مراجعة", en "Review"). It has an icon and a label, the same size as View, and is keyboard reachable.
  - Shown only when the status is `submitted` or `under_review`.
  - For `registered` / `rejected`: no Examiner button. View is enough, and the history lives on the oeuvre page.
- The oeuvre page header (`admin/oeuvres/Show.vue`) gets the same "Examiner" button, opening the same dialog. One component, used in both places.

### Server: one source of truth for the dialog
- `GET admin/oeuvres/{oeuvre}/decision` (JSON, admin only, `inspect` policy), via `Admin/OeuvreReviewController@summary` or a small new invokable controller. It returns:
  ```
  { status, reference, title, author: {name}, college, submittedAt,
    holder: {id, name} | null, heldByMe: bool,
    checklist: { requiredMissing: [labels], filesNotReady: [{name, status}], conditionalMissing: [labels] },
    allowed: ['open_review' | 'take_over' | 'approve' | 'reject'],
    lastDecision: {to, by, at} | null }
  ```
- `allowed` is computed on the server from the status machine, the holder model and the accept guards (the same rules the POST actions enforce). The UI never decides on its own which buttons to show.

### `components/review/ReviewDecisionDialog.vue` (on top of `AppDialog`, size lg)
- **On open:** fetch the summary with a skeleton while loading. Fetch errors show inline with a "Réessayer" button.
- **Header:** title, `<bdi dir="ltr">` reference, status badge.
- **Body, review screen:**
  - author, college, submission date, and a link "Ouvrir l'œuvre" (the oeuvre page, new tab);
  - a checklist: required documents ✓/✗ (with the missing labels), all files ready ✓/✗ (with each file and its status), and conditional documents as an advisory note;
  - the holder line: "En cours d'examen par {name}" (or "par vous").
- **Footer, driven by `allowed`:**
  - `open_review` → "Commencer l'examen". On success the dialog **stays open** and refetches, so Accept/Reject appear right away;
  - `take_over` → "Reprendre l'examen", behind an inline confirm ("{name} examine ce dépôt. Le reprendre ?");
  - `approve` → "Accepter le dépôt" (primary). Disabled with the reason if the checklist fails;
  - `reject` → "Rejeter" (destructive). It switches the body to the **reject screen**, inside the same dialog.
- **Reject screen:**
  - a reason textarea (auto-grow, live counter, 20–2000 characters);
  - quick-reason chips that insert text at the cursor;
  - "Retour" goes back to the review screen without losing the text;
  - "Confirmer le rejet" stays disabled until the reason is valid.
- **Busy state:** spinner on the clicked button, the other buttons disabled, Esc and outside click blocked.
- **Responses:**
  - success (approve / reject): toast, close the dialog, refresh only the affected data (`router.reload({ only: [...] , meta: { silent: true } })`), badge pulse on the row;
  - 409 holder conflict: refetch the summary; the dialog then shows the holder and `take_over`;
  - 409 already decided: the message "Déjà traité par {admin} le {date}", then refetch and refresh the row;
  - 422: the server's problem list shown in the dialog, never a generic error.
- Locales ar/fr/en, RTL, dark/light, reduced motion. Existing tokens only.

## Part 3 — Tests
- **Regression test for the Part 1 root cause** (this is mandatory).
- **Pest:**
  - the summary endpoint returns the correct `allowed` set for: submitted; under_review held by me; under_review held by another admin; checklist failing (approve absent, or flagged); registered (empty `allowed`);
  - non-admin → 403;
  - it never returns data of another oeuvre.
- **Vitest (happy-dom):**
  - the Index row shows Examiner only for submitted / under_review, and the dropdown no longer exists;
  - clicking Examiner opens the dialog and fetches the summary;
  - "Commencer l'examen" keeps the dialog open and shows Accept/Reject after the refetch;
  - Reject switches screens and keeps the text after Retour;
  - the confirm button stays disabled under 20 characters;
  - the busy state blocks Esc;
  - 409 / 422 render their messages;
  - success closes the dialog and triggers a silent partial reload.
- A manual check in the browser, ar and fr: submitted → Examiner → Commencer l'examen → Accepter; a second oeuvre → Rejeter with a chip + text; a second admin account holding an oeuvre → Reprendre.

## Owned files — touch nothing else
```
resources/js/pages/admin/oeuvres/Index.vue, Show.vue
resources/js/components/review/** (ReviewDecisionDialog and its parts)
app/Http/Controllers/Admin/OeuvreReviewController.php (summary method) or a new invokable controller
routes/admin.php                    ← the summary route only
resources/js/locales/{ar,fr,en}.json
tests for the above
```
FROZEN: the status machine, the three review actions (beyond reading their rules), notifications, colour and font tokens.

## Acceptance gate
1. The Part 1 root cause with its evidence.
2. `composer test`: green, before/after counts. Vitest, types, lint and format all 0. `npm run build`.
3. The manual check described above.
4. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation.
- Leave every change unstaged. Stop only processes you started.
- End by printing `git status --short`.
