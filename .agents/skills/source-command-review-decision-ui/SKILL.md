---
name: "source-command-review-decision-ui"
description: "Migrated source command `review-decision-ui`"
---

# source-command-review-decision-ui

Use this skill when the user asks to run the migrated source command `review-decision-ui`.

## Command Template

Task: admin review decisions (start review, accept, reject with reason), end to end. Read AGENTS.md first.

Finding from the UI audit: the routes `admin.oeuvres.review` and `admin.oeuvres.reject` exist, but **no page calls them**. Admins cannot accept or reject an oeuvre from the interface. This task builds the full decision flow on top of what exists. It reuses the `AppDialog` and the motion primitives from the UI-polish work.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Status machine (the existing rule; do not change it)
`draft → submitted → under_review → registered | rejected(reason)`, and `rejected → submitted` (the author resubmits). `registered` is terminal. A submitted oeuvre is frozen for the author.

## Step 0 — Report (no code)
1. The existing backend: routes, controller methods, actions, policies, validation, and the `oeuvre_reviews` table (columns, append-only?), with paths. Which transitions the backend allows today, including whether `submitted → registered/rejected` directly is allowed.
2. Notifications: which notification classes and channels exist (mail, database), and what the author/admin receive today on submit.
3. Where the author sees status and the rejection reason today.
4. The gaps between the existing code and this brief.

Continue unless something contradicts this brief; then STOP and report.

## Part 1 — Backend (reuse; fill gaps only)
- **One action class** (e.g. `Actions/Review/DecideOeuvre`) handles every transition. It is used by both routes, and no controller holds its own logic.
- **Transitions:**
  - `submitted → under_review` ("Commencer l'examen");
  - `under_review → registered` ("Accepter");
  - `under_review → rejected` ("Rejeter", reason required);
  - direct `submitted → registered | rejected` only if Step 0 shows the backend already allows it.
- **Concurrency and idempotency:**
  - work inside a DB transaction with `lockForUpdate` on the oeuvre row;
  - re-check the current status inside the lock;
  - a second identical request returns the current state, with no duplicate review row;
  - a conflicting request (another admin already decided) returns 409 with who decided and when.
- **Accept guards (re-checked server-side, never trusted from the UI):**
  - every unconditional required slot has at least one file (conditional slots stay advisory);
  - every file of the oeuvre has status `ready`. None may be pending, scanning, failed or quarantined.
  - A refusal returns 422 with the exact list of problems.
- **Reject:** the reason is required, trimmed, 20–2000 characters, stored, and shown to the author.
- **Record:** append a row to `oeuvre_reviews` (reviewer id, from status, to status, reason, decided_at) for every transition. Never update or delete rows.
- **Notifications:** queued on `default`, never sent inside the request.
  - The author is notified on `under_review`, `registered` and `rejected` (with the reason).
  - Use the existing channels (database + mail if mail is configured). Reuse existing notification classes where they exist.
- **Policy:** admin only. Authors and guests get 403.
- `registered` is immutable: any transition from it returns 422.

## Part 2 — Admin UI
- **Oeuvre page header (`admin/oeuvres/Show.vue`):**
  - the status badge, plus only the actions valid for the current status;
  - a disabled action keeps a visible reason (tooltip and an inline hint), e.g. "2 fichiers en cours de traitement" or "1 document obligatoire manquant".
- **Accept dialog (`AppDialog`, size md):**
  - a summary checklist: required documents ✓, all files ready ✓, file count, author, reference;
  - a primary "Accepter le dépôt" button; while busy, the button shows a spinner and Esc is blocked;
  - on success, close, show a toast, play the badge pulse, and update the status in place with no full reload flash (Inertia partial reload).
- **Reject dialog (`AppDialog`, size lg):**
  - a reason textarea: auto-grows to a max height then scrolls, with a live character count and the min/max shown;
  - optional quick-reason chips that insert text at the cursor: "Document obligatoire manquant", "Fichier illisible ou corrompu", "Informations du formulaire incorrectes", "Format non conforme". Locales ar/fr/en;
  - a destructive-style confirm button, disabled until the reason is valid. Validation errors appear inline;
  - the footer stays visible above the mobile keyboard.
- **"Commencer l'examen":** a one-click action with a small confirm popover, no full dialog.
- **Index (`admin/oeuvres/Index.vue`):**
  - in the Actions column, next to View, a compact menu with the valid transitions. Accept and Reject open the same dialogs;
  - a filter chip "À examiner" (submitted + under_review) with a count.
- **Review history:** a vertical timeline on the oeuvre page, newest first, built from `oeuvre_reviews`. Each entry shows the from → to badges, the reviewer, a relative date with the exact date in a tooltip, and the reason. Entries reveal with the existing motion.
- 409 conflict: a toast "Déjà traité par {admin} le {date}", and the page refreshes the status.
- 422 accept refusal: the dialog shows the server's problem list. It is never a generic error.

## Part 3 — Author UI
- **Oeuvre page:**
  - a status banner;
  - for `rejected`: the reason in a clearly styled callout, the decision date, and a primary "Modifier et soumettre à nouveau" action (existing resubmit flow);
  - for `under_review`: "En cours d'examen par l'ONDA";
  - for `registered`: a success banner with the registration date (and the existing certificate action, if one exists).
- The same history timeline, read-only, without reviewer identities. Show "ONDA" instead of the admin's name.
- Notification entries (database channel) link to the oeuvre page.

## Tests
- **Pest:**
  - every valid transition;
  - every invalid transition → 422, including any transition from `registered`;
  - reject without a reason, or with a too-short reason → 422;
  - accept blocked: a missing unconditional slot → 422 with the list; a file not `ready` → 422 with the list; a conditional slot missing → accept still allowed;
  - concurrent double-accept → exactly one review row, and the second request gets 409 or an idempotent result (state which);
  - an identical repeat → no duplicate row;
  - non-admin → 403;
  - `Notification::fake()`: the author is notified once per transition, rejection with the reason, and notifications are queued;
  - the author page props contain the reason, and no reviewer identity.
- **Vitest:**
  - the accept dialog shows the checklist and disables the button when a check fails;
  - the reject dialog validates min/max, the chips insert text, and the counter updates;
  - the busy state blocks Esc;
  - the timeline renders the from/to badges;
  - a 409 shows the conflict toast.

## Owned files — touch nothing else
```
app/Actions/Review/**, app/Http/Controllers/Admin/*Review* (or the existing oeuvre decision controller)
app/Policies/OeuvrePolicy.php            ← decision abilities only
app/Notifications/**                     ← oeuvre decision notifications only
routes/admin.php                         ← only if a route is missing
database/migrations/**                   ← only if oeuvre_reviews lacks a needed column (additive only)
resources/js/pages/admin/oeuvres/**, resources/js/pages/author/oeuvres/**
resources/js/components/review/** (new), resources/js/components/ui/app-dialog/** (use; extend props only if needed)
resources/js/locales/{ar,fr,en}.json
tests for the above
```
FROZEN: the vault, upload, consultation and preview code, the status machine itself, colour and font tokens.

## Acceptance gate
1. The Step 0 report.
2. `composer test`: green, with before/after counts and every skip listed with its reason. Vitest, types, lint and format all 0. `npm run build`.
3. A manual script, in ar and fr, light and dark:
   - admin: an oeuvre with a file still processing shows Accept disabled with its reason;
   - once ready: Commencer l'examen → Accepter → the author sees the success banner;
   - a second oeuvre: Rejeter with a chip plus a custom text → the author sees the reason and resubmits → the admin sees it back in "À examiner";
   - two admin tabs accepting at the same time → one succeeds, the other gets the conflict toast.
4. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation.
- Leave every change unstaged. Stop only processes you started.
- End by printing `git status --short`.
