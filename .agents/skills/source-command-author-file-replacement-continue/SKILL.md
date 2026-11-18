---
name: "source-command-author-file-replacement-continue"
description: "Migrated source command `author-file-replacement-continue`"
---

# source-command-author-file-replacement-continue

Use this skill when the user asks to run the migrated source command `author-file-replacement-continue`.

## Command Template

Task: CONTINUE the "author file replacement" task that was interrupted by a usage limit. Read AGENTS.md first, then the original brief `author-file-replacement` (attached below this message; it is still the specification for Parts 3 and 4, the tests and the acceptance gate). This message adds the current state, the decisions on the deviations, and the extra work.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Working rules for this session
- **Work in checkpoints** so that nothing is lost if the session stops again. After each checkpoint, print a 5–10 line status (done / failing / next) and continue without waiting, unless a decision below does not hold.
- **Never rewrite or empty a file with a script.** Use the Edit tool. If a file is ever damaged, restore it from the **index first** (`git show :<path>`, read-only), then compare with `git show HEAD:<path>`. Never restore from HEAD alone: the index can hold staged work from earlier tasks.
- Git policy as in the original brief: no commit, push, merge, rebase, reset, checkout, restore, stash, clean, tag, revert or index operation. Leave everything unstaged. Stop only processes you started.

## State at the interruption (from the previous agent's report)
Done, unstaged:
- `media_files.replaces_media_file_id` (nullable self-FK, unique, restrict), dev DB rebuilt.
- `RemoveMediaFile`: locks the oeuvre row, then the file row; keeps the row with `deleted_at` and `purged_at`; credits the quota; hands the bytes to `VaultBytesReleaser` after commit; idempotent; 409 `file_no_longer_rejected`; 403 for a file that was never rejected.
- `DecideMediaFile` refuses a removed file (422).
- `InitUpload` accepts a replacement only for a slot with an unreplaced removed-rejected file, under the user lock and the oeuvre-row lock. `CompleteUpload` links the new file to the oldest such file. A decided oeuvre makes the next chunk or complete call answer 409 `oeuvre_decided`, abort the session and release the reservation.
- `ReplacementFileReady` event after commit, a listener that sends `ReplacementFileUploaded` once, to the review holder or, with no holder, to every reviewing admin.
- Page props for the author, the admin and the dashboard.

Known problems and gaps:
- One failing test: `tests/Feature/Review/MediaFileDecisionTest.php` (94 of 95 passing in the targeted run). Suspected cause: the lock-order test, because `DecideMediaFile` now reads the file row including removed ones.
- The full `composer test`, Vitest and `npm run build` have not been run.
- Parts 3 and 4 (author and admin UI), the Pest tests for removal, replacement, race, quota, notification, dedup and query ceiling, the Vitest tests, the locale strings and the AGENTS.md note: not started.
- **Mishap:** a script emptied `app/Http/Controllers/Author/OeuvreController.php` and it was rewritten from `HEAD`. That file may have had staged or unstaged work from earlier tasks (the author oeuvres table, filters, dashboard). See checkpoint 0.

## Decisions on the reported deviations
1. **Immediate purge of a removed rejected file's bytes: approved.** The row, its hash and its review history stay. Document it in AGENTS.md as a narrow exception to the retention window.
2. **Files outside the owned list: approved:** `StoreChunk.php` (one guard line), `ReplacementGuard.php`, `RemovalConflict.php`, `NotifyReplacementFileReady.php`, `withTrashed()` on one route each in `routes/author.php` and `routes/admin.php`, and, for the oeuvre_decided handling on the client, `lib/upload/failure.ts` and `types/upload.ts`.
3. **Replacement detection from the `oeuvre_reviews` history: REJECTED.** A heuristic on a path that links legal evidence is not acceptable. Replace it, deterministically:
   - at init, the server picks the oldest unreplaced removed-rejected file of the slot and **reserves it on the session**: `upload_sessions.replaces_media_file_id` (nullable FK to `media_files`, `restrictOnDelete`; edit the consolidated `create_upload_sessions_table` migration, then `migrate:fresh --seed` on the local dev DB only, after a dump);
   - init refuses (409/422) if another non-aborted session already reserves that file, which also makes two parallel inits for one open slot safe;
   - `CompleteUpload` copies the id from the session to `media_files.replaces_media_file_id` (never recomputes it);
   - an aborted or expired session releases the reservation by itself, because "reserved" means a session that is not aborted and not expired.
   - Remove the `oeuvre_reviews` heuristic and its code. Add the tests below.
4. **Behaviour change (an upload begun on a draft that is under review by the time it completes is refused with 409): approved.** Add a test and one line in AGENTS.md.
5. **Gap: the oeuvre accept guard does not block an open replacement slot that is optional:** accepted as it is. An optional slot whose rejected file was removed and not replaced is the author's choice. The oeuvre decision actions stay frozen. Add the safety net instead:
   - the decision summary (read-only data, not the action) gets an **advisory** `openReplacementSlots: [labels]`, shown in the checklist of `ReviewDecisionDialog` as "Remplacement non fourni" and never blocking;
   - a test that a replacement cannot be initiated on an oeuvre without a classification, or in a slot that does not exist for it.

## Checkpoint 0 — integrity (do this first, before anything else)
1. `git status --short` and `git diff --stat`. Check `app/Http/Controllers/Author/OeuvreController.php` against **both** baselines:
   - `git diff --cached -- app/Http/Controllers/Author/OeuvreController.php`: is there staged work that the working copy lacks?
   - `git diff HEAD -- app/Http/Controllers/Author/OeuvreController.php`.
   Anything that belonged to an earlier task (list filters and their prop names, the oeuvre table props, the dashboard props, status keys from the enums) must be present in the working copy. If staged content is missing, restore it from `git show :<path>` and re-apply only this task's edits with the Edit tool. If the loss was in unstaged work that exists nowhere else, say so plainly and rebuild it from the tests and the pages that consume it.
2. Run the **full** `composer test`, Vitest, types, lint, format and `npm run build`. List every failure, including the one in `MediaFileDecisionTest.php`. Failures in the author oeuvre list, filters or dashboard tests are the first sign of the controller loss.
3. Fix the `MediaFileDecisionTest.php` failure at its cause. If the lock-order test is simply stale because removed files are now read, update the test and say why. If a real lock-order or removed-file bug exists, fix the code.
4. Report the result in 10 lines. Continue only when the suite is green.

## Checkpoint 1 — backend completion
- The `upload_sessions.replaces_media_file_id` change from decision 3.
- The summary advisory from decision 5.
- Pest tests, as listed in the original brief (removal, replacement init, in-flight race, quota, notification, dedup, purge/retention path, query ceiling), plus:
  - two parallel inits for one open slot: only one reserves the file, the other is refused;
  - an aborted or expired session frees the reservation;
  - `CompleteUpload` takes the id from the session and never recomputes it;
  - an init for an oeuvre without a classification, or for a slot that does not exist → refused;
  - a draft upload that completes after the oeuvre went under review → 409.
- A schema diff showing only `media_files.replaces_media_file_id` (with its index and FK) and `upload_sessions.replaces_media_file_id` (with its FK).

## Checkpoint 2 — author UI (Part 3 of the original brief)
As specified there: the review badge on every file, the rejection callout with the reason and the date ("ONDA", never a reviewer name), "Remplacer" / "Supprimer" following the server flags (`can_remove`, `canUploadReplacement`), the "Remplacement attendu" dropzone on open slots only, the replacement's "Remplace : …" label and pending badge, the dashboard item, the bell link scrolling to the file, the client handling of the 409 `oeuvre_decided` (message "Le dépôt a été traité, le fichier n'a pas été ajouté", the upload entry marked failed with no retry), the silent partial reloads, and the locale strings in ar/fr/en. Then the Vitest tests listed in the brief.

## Checkpoint 3 — admin UI (Part 4)
As specified there: the "Remplacement" badge and "Remplace : …" label, the read-only "Fichiers retirés par l'auteur" section, the "{n} fichier(s) de remplacement à examiner" banner, the replacements included in "Suivant à examiner", the admin bell rendering `replacement_uploaded`. Vitest tests.

## Checkpoint 4 — documentation and gate
- AGENTS.md: the narrowed freeze rule, the immediate purge exception, the reservation on `upload_sessions`, the 409 on the late upload.
- The full gate from the original brief: `composer test` with before/after counts, Vitest, types, lint, format, `npm run build`, the manual browser check in ar and fr (including the in-flight race), and `git status --short`.

## Owned files (the original list, plus the approved additions)
```
database/migrations/**  (create_media_files_table and create_upload_sessions_table only)
app/Models/MediaFile.php, app/Models/UploadSession.php
app/Actions/**, app/Policies/**, app/Http/Controllers/Author/** and Admin/** (props, flags, summary advisory)
app/Events/ReplacementFileReady.php, app/Listeners/NotifyReplacementFileReady.php, app/Notifications/ReplacementFileUploaded.php
app/Http/** StoreChunk (the guard line), ReplacementGuard, RemovalConflict
routes/author.php and routes/admin.php (withTrashed on the one route each)
resources/js/pages/author/oeuvres/**, resources/js/pages/admin/oeuvres/**, resources/js/components/**
resources/js/stores/uploads.ts, resources/js/lib/upload/failure.ts, resources/js/types/upload.ts (replacement mapping and oeuvre_decided only)
resources/js/locales/{ar,fr,en}.json, lang/{ar,fr,en}/**
AGENTS.md, tests/**
```
FROZEN: the upload protocol, chunking, scheduler and crypto; the pipeline job order and logic (beyond the existing one event dispatch); `OeuvreStatusMachine` and the oeuvre decision actions; the hash chains; the consultation security behaviour; colour and font tokens.

## Report at the end
- The Checkpoint 0 findings, in particular the state of `Author/OeuvreController.php`.
- The final deviations list.
- The gate results and the manual check.
- `git status --short`.

Then STOP.
