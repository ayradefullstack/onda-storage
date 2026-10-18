---
name: "source-command-author-review-experience"
description: "Migrated source command `author-review-experience`"
---

# source-command-author-review-experience

Use this skill when the user asks to run the migrated source command `author-review-experience`.

## Command Template

Task: refine the author experience of file review. This is an ADDENDUM to the "author file replacement" task: it replaces and extends Part 3 (author UI) of that brief, plus two small backend rules. If the task is already in progress, apply it at Checkpoint 2 (author UI). Read AGENTS.md first.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

Goal: while an oeuvre is `under_review`, the author understands in five seconds what was accepted, what is waiting, and what must be fixed, and can fix it without mistakes. Rejected files stand out in red and can be replaced. **Accepted files are visibly locked.**

## Step 0 — Report (continue unless something contradicts this brief)
1. The author file list and slot components today: how each file row and each slot's dropzone is built, and which props they receive (`can_remove`, `review_status`, `review_reason`, `replaces`, replacement state).
2. The accepted mime types and size limit of each slot (`college_oeuvre_files.mime_types`, `VAULT_MAX_FILE_SIZE`), and whether the dropzone already shows them.
3. How the existing motion primitives (`useReveal`, pulse, `AppDialog`) and the status badge tones can express: neutral, success, destructive.

## The file states the author sees (one visual language, used on the page, the dashboard and the bell)
Colour is never the only signal: every state has an icon and a text label. Existing tokens only (neutral / info / success / warning / destructive); no new colours or fonts.

| State | Treatment |
|---|---|
| Uploading / processing | The existing progress and pipeline steps. |
| `pending` (waiting for the ONDA) | Neutral row, clock icon, badge "En attente d'examen". Not removable (see the exceptions below). |
| `accepted` | Success accent, **lock icon**, badge "Accepté". No remove, no replace, no drag handle. A tooltip and an `aria-label` say "Accepté par l'ONDA : ce fichier ne peut plus être modifié". |
| `rejected` | **Red (destructive tokens): a start-side accent bar, a tinted background, a circle-x icon, badge "Rejeté".** Under the row, a **reason callout** (see below), then the actions "Remplacer" and "Supprimer". |
| Replacement waiting | Neutral row, badge "En attente d'examen", label "Remplace : {old file name}". |
| Replacement failed / quarantined | Red row with the existing failure message and a "Supprimer et réessayer" action (see the backend rules). |

## Part A — Page-level clarity (author oeuvre page, under review)
1. **Review summary at the top,** under the status banner "En cours d'examen par l'ONDA": three counters (accepted / waiting / to fix) with a segmented progress bar. Counters use `useCountUp`; zero shows as 0.
2. **"À corriger" panel,** shown only when something is rejected: the list of rejected files (name, slot) with a "Aller au fichier" link for each, which scrolls to the row, focuses it and plays one soft highlight pulse. The panel has `role="alert"` once, on first render, and never re-announces on refresh. A "Fichier suivant à corriger" button cycles through them.
3. **Mobile:** a sticky bottom bar "{n} fichier(s) à corriger" that jumps to the first rejected file; hidden when there is nothing to fix; it respects the safe area and reduced motion.
4. **Everything fixed:** when no file is rejected and replacements are pending, the panel turns into a calm confirmation: "Tous les remplacements ont été envoyés. L'ONDA va les examiner." with the date of the last upload. No fake "done" state while a replacement is still uploading.
5. **Grouping:** files are grouped by slot. Each slot header shows its own state (to fix / waiting / accepted), so the author reads the page slot by slot.

## Part B — The rejected file
- **Reason callout** (destructive tokens, icon, `role` none): the full reason, the decision date, the signature "ONDA" (never a reviewer name), and a one-line instruction: "Supprimez ce fichier, puis téléversez un nouveau fichier conforme." Long reasons collapse to about 4 lines with "Voir plus".
- **"Remplacer"** opens an `AppDialog` (sm): the file name, the reason recap, and the text "Le fichier actuel sera supprimé définitivement. Vous pourrez ensuite téléverser le nouveau." The confirm button is destructive, shows a spinner when busy, and Esc is blocked while busy. After success: close, toast, scroll the open slot into view, **move focus to its dropzone** and announce it through an `aria-live="polite"` region.
- **"Supprimer"** uses the same dialog with the text "Le fichier sera supprimé. L'emplacement restera ouvert pour un nouveau fichier."
- **The open-slot dropzone** ("Remplacement attendu"): a dashed destructive-tinted border, the slot's requirement shown as helper text (the accepted formats and the maximum size taken from the slot, plus the rejected file's reason as a reminder), one soft pulse when it appears. A client-side pre-check before the upload starts: a file of a wrong type or size is refused with a clear message, and never enters the queue.
- **Per-document history (collapsed by default, "Historique de ce document"):** a small timeline for the slot: file uploaded → rejected (date, reason) → removed by you → replacement uploaded → waiting / accepted. Reviewer identity is always "ONDA". It is read-only and built from `media_file_reviews` and the `replaces_media_file_id` chain.

## Part C — Two small backend rules (with tests)
1. **The author may also remove their own replacement** while it is not accepted: a file with `replaces_media_file_id` set and `review_status = pending`, or a replacement whose pipeline status is `failed` / `quarantined`. It uses the existing removal action and the same lock order (oeuvre row, then file row), with a re-check inside the lock: if the admin has just accepted it → 409 `file_not_removable`. This is what lets the author fix a wrong upload, or retry after a failed replacement, without waiting for the admin. **Accepted files are never removable.** After the removal the slot is open again.
2. **Because of rule 1, the unique index on `media_files.replaces_media_file_id` must become a plain index.** A soft-deleted replacement must not block the next replacement of the same removed-rejected file. "Unreplaced" now means: no **non-deleted** replacement row references it. The uniqueness of a live replacement is enforced under the oeuvre-row lock and by the session reservation, and tested. Edit the consolidated `create_media_files_table` migration, take a dump of the dev DB first, `migrate:fresh --seed` on the local dev DB only.
- Both rules keep the audit entry for deletion, and the admin side shows a removed replacement in "Fichiers retirés par l'auteur".
- If the admin had already been notified about that replacement, nothing is retracted. The admin page simply shows it as removed.

## Tests
- **Pest:**
  - the author can remove a pending replacement and a failed/quarantined replacement; cannot remove an accepted file, a pending original file, or a file of another author;
  - the 409 `file_not_removable` when the admin accepts at the same moment (tested through the committed-state order, as with the other race tests);
  - after removing a replacement, a new replacement can be initiated for the same slot and links to the same removed-rejected file; two live replacements for one removed file are impossible;
  - the page props carry the counters and the per-slot state, with no reviewer identity anywhere;
  - the query count of the author oeuvre page stays under the fixed ceiling.
- **Vitest:**
  - an accepted file shows the lock, the tooltip and no actions; a rejected file shows the red state, the callout and both actions; states render their icon and label (not colour alone);
  - the summary counters and the segmented bar render from props, zero shows as 0;
  - "À corriger" lists the rejected files; "Aller au fichier" focuses the row; "Fichier suivant à corriger" cycles;
  - the sticky mobile bar appears only when something is rejected;
  - "Remplacer" runs confirm → remove → focus on the dropzone; "Supprimer" leaves the slot open; Esc is blocked while busy;
  - the dropzone shows the slot's formats and size; a wrong type or size never enters the queue;
  - the "all replacements sent" state appears only when nothing is rejected and nothing is still uploading;
  - the history timeline renders in order and never shows a reviewer name;
  - ar (RTL) and fr, light and dark, reduced motion (no pulse, no smooth scroll).
- **Manual (browser, ar and fr, light and dark, a real upload with the media worker running):**
  - as admin: accept one file, reject two (one with a chip plus custom text);
  - as the author: the red rows and reason callouts, the lock on the accepted file, the "À corriger" panel and its jump links, the mobile bar at 390 px;
  - "Remplacer" the first file, upload a **wrong-type file** (refused before the upload), then a correct one;
  - remove the pending replacement and upload another;
  - "Supprimer" the second file, leave it for a while, come back, upload;
  - check that an accepted file offers no way to be changed (UI and by calling the endpoint directly → 403);
  - the confirmation state once all replacements are sent.

## Owned files — touch nothing else
```
resources/js/pages/author/oeuvres/**, resources/js/components/** (author file list, slot, dropzone, panel, bar, dialogs, history)
resources/js/lib/** (the client-side type and size pre-check only)
app/Actions/** (the existing removal action: the added authorization case only)
app/Http/Controllers/Author/** (props: counters, per-slot state, history)
database/migrations/** (create_media_files_table: the index change only)
resources/js/locales/{ar,fr,en}.json, lang/{ar,fr,en}/**
AGENTS.md (the removal rules and the index change)
tests/**
```
FROZEN: the upload protocol, chunking, scheduler and crypto; the pipeline; `OeuvreStatusMachine` and the oeuvre decision actions; the admin decision logic; the hash chains; the consultation security behaviour; colour and font tokens.

## Acceptance gate
1. The Step 0 report and any deviation, with the reason.
2. `composer test` with before/after counts; Vitest, types, lint and format all 0; `npm run build`.
3. The manual check above, described or with screenshots (at least: the author page with one accepted, one rejected and one replacement; the mobile bar; the dropzone with its helper text).
4. A schema diff showing only the index change.
5. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `git mv`, `--cached`, `update-index`).
- Never rewrite or empty a file with a script; use the Edit tool. If a file is ever damaged, restore it from the index first (`git show :<path>`), then compare with HEAD.
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
