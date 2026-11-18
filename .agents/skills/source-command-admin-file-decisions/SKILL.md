---
name: "source-command-admin-file-decisions"
description: "Migrated source command `admin-file-decisions`"
---

# source-command-admin-file-decisions

Use this skill when the user asks to run the migrated source command `admin-file-decisions`.

## Command Template

Task: per-file review decisions for admins. An admin reviews each file of an oeuvre on its own, accepts it or rejects it with a reason, and the author is notified of each rejection. This is the admin side only; the author side (seeing the reason, replacing the file) is the next task. Read AGENTS.md first.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Design decisions (already made; deviate only if Step 0 shows a contradiction, and say so)
1. **A new, separate review state per file.** `media_files.status` is the technical pipeline state (uploading … ready/failed/quarantined) and stays untouched. The admin decision is a new column, `review_status`.
2. **The oeuvre status machine does not change.** A file rejection does not change `oeuvres.status`. The oeuvre stays `under_review` while the admin works through the files. The only change to the oeuvre review is a **new accept guard**: the oeuvre can be accepted only when every file is `ready` AND `review_status = accepted`.
3. **File decisions are possible only while the oeuvre is `under_review`,** and only by the admin who holds the review (the existing holder model; `take_over` works as today). On `registered` or `rejected` oeuvres the file decisions are read-only.
4. **Each file is decided individually. There is no bulk "accept all".** Navigation helps the admin go fast: "next file to review".
5. **A decision can be revised while the oeuvre is under review,** e.g. accepted → rejected, rejected → accepted, or reset to pending. Every change appends a history row. An identical repeat creates nothing.
6. **Accept requires the file to be `ready`.** Reject is allowed for `ready`, `failed` and `quarantined` files (the reason is required). Files still uploading, assembling, scanning or processing cannot be decided.
7. **Notification:** one database notification to the author for each file **rejection**, queued, sent after commit. Acceptance of a file sends nothing; the oeuvre outcome is already notified. Mail stays deferred, as decided earlier.
8. **Reject the oeuvre with rejected files:** the existing oeuvre reject dialog gets a quick-insert chip, "Inclure les fichiers rejetés", which writes the list of rejected files and their reasons into the reason textarea. That is how rejected files reach the author inside the oeuvre decision.
9. **Dedup:** review state is per `media_files` row. It is never copied between rows that share the same hash.

## Step 0 — Report (continue unless something contradicts the decisions above)
1. The admin pages that show files: the oeuvre page file list, `FileReviewLayout` (full review page), the viewer, and the props each receives. Which already know the file's `status`, slot and consultation family?
2. The existing review backend: `OeuvreStatusMachine`, `OpenReview` / `ApproveOeuvre` / `RejectOeuvre`, the holder model (how `heldByMe` and 409s work), the `GET admin/oeuvres/{oeuvre}/decision` summary and its `allowed` computation, `OeuvreReview` model (how append-only is enforced). Which lines hold the accept guards today?
3. Notifications: the existing classes, how the author's bell and `/notifications` page render a notification by type, and whether an unknown type would render badly.
4. How a file with an unsupported preview family (doc, odt, rtf, ppt, odp, avi) appears on the admin review page today. Can the admin see anything useful (name, size, type, hash)?
5. The lock order used by the oeuvre actions, so the new action never deadlocks with them.

## Part 1 — Schema (edit the consolidated migrations; development only)
No alter migrations. Edit `create_media_files_table`, add one new create migration in sequence, and rebuild with `migrate:fresh --seed`.

Safety first: a dump of the dev database into `storage/backups/`, `php artisan optimize:clear`, and `migrate:fresh` only on the local dev DB. Do not delete local vault files; report their path so I can clean it.

- **`media_files`** gets, in the column order of our convention:
  - `review_status`: `unsignedTinyInteger`, default `ReviewStatusEnum::PENDING->value`, comment `ReviewStatusEnum::dbComment()`;
  - `reviewed_by`: FK to users, `nullOnDelete`;
  - `reviewed_at`: nullable timestamp;
  - `review_reason`: nullable text (the latest rejection reason, for fast display);
  - a composite index `(oeuvre_id, review_status)` with a comment naming the query it serves (the oeuvre page counters and the accept guard).
- **New table `media_file_reviews`** (append-only, like `oeuvre_reviews`; file `2026_01_04_000700_create_media_file_reviews_table`):
  - `id`; `media_file_id` FK (`cascadeOnDelete` is NOT allowed: use `restrictOnDelete`, the history is legal evidence); `oeuvre_id` FK restrict; `reviewer_id` FK users `nullOnDelete`;
  - `from_status`, `to_status`: `unsignedTinyInteger` with the `ReviewStatusEnum` comment;
  - `reason`: nullable text;
  - `was_consulted`: boolean, computed server-side (see Part 2);
  - `decided_at`; `timestamps()`;
  - index `(media_file_id, decided_at)`.
  Same model-level append-only guard as `OeuvreReview` (no update, no delete).
- **Enum** `App\Enums\MediaFile\ReviewStatusEnum` (int-backed, `HasPresentation`, `EnumHelpers`): `PENDING=1`, `ACCEPTED=2`, `REJECTED=3`. `color()`: neutral / success / destructive. `icon()`: lucide names (`clock`, `circle-check`, `circle-x`). Labels in `lang/{ar,fr,en}/enums.php` under `enums.media_file.review_status.*`. Cast on `MediaFile` and `MediaFileReview`, the `SerializesEnumsAsKeys` trait on both (props emit `pending` / `accepted` / `rejected`).
- Neither the new column nor the new table enters any hash chain. Say so in the report.

## Part 2 — Backend
- **One action class, `App\Actions\Review\DecideMediaFile`,** is the only writer of `review_status`. It takes the file, the target `ReviewStatusEnum`, the reviewer and the reason. In one DB transaction:
  1. lock the **oeuvre row first**, then the file row (the same order the oeuvre actions use), so a file decision and an oeuvre approve/reject can never interleave;
  2. re-check inside the lock: the oeuvre is `under_review`, the reviewer holds the review, the file belongs to that oeuvre, and the file `status` allows the target (accept → `ready`; reject → `ready` / `failed` / `quarantined`);
  3. if the target equals the current state with the same reason: return the current state and write nothing (idempotent);
  4. update the file columns, set `reviewed_by` / `reviewed_at`, and `review_reason` (set on reject, cleared on accept/reset);
  5. append the `media_file_reviews` row, with `was_consulted` = whether `file_access_logs` holds an `admin_consult` or `consult_original` entry by this reviewer for this file. This is audit evidence only, with no UI and no blocking;
  6. dispatch the notification after commit (`afterCommit`), only on a transition to `rejected`.
- **Rejection reason:** required, trimmed, 20–2000 characters. For accept and reset the reason is ignored.
- **Errors:** 409 if the reviewer is not the holder (the body names the holder, like the oeuvre flow); 409 "oeuvre already decided" (who and when); 422 for a file status that does not allow the decision, with the exact message; 404 if the file does not belong to the route's oeuvre (scoped binding); 403 for non-admins.
- **Policy:** `MediaFilePolicy::review`, admin only. The holder and status rules live in the action; the policy covers the role.
- **Route** (`routes/admin.php`): `POST admin/oeuvres/{oeuvre}/files/{file}/review` with `decision` = enum key (`accepted` | `rejected` | `pending`) and `reason`. Resolved with `fromKey()`; an unknown key → 422. Regenerate the Wayfinder helpers with `--with-form`.
- **`allowed` per file, computed on the server,** exposed in the props of the oeuvre page and of the review page: a list of `accept` / `reject` / `reset`, plus, when empty, a `reason` key explaining why (not under review, not the holder, file not ready, oeuvre decided). The UI never decides this on its own.
- **Oeuvre accept guard (the one allowed change in the review actions):** `ApproveOeuvre` and the `GET admin/oeuvres/{oeuvre}/decision` summary also require every file `review_status = accepted`. The summary's checklist gets a new entry `filesNotAccepted: [{name, reviewStatus}]`. When it is not empty, `approve` is flagged unavailable with that reason (422 with the exact list when forced). Nothing else in the oeuvre actions changes.
- **Notification** `App\Notifications\MediaFileRejected`: queued on `default`, database channel only. Data: `type = file_rejected`, oeuvre id, reference and title, file id and display name, the slot label, the reason, the date, and the link target (the author's oeuvre page). It never contains the reviewer's identity: the author sees "ONDA". One notification per rejection event. No duplicates for an idempotent repeat. A rejected → rejected change with a **different** reason counts as a new decision and notifies again.
- Make the author's bell and `/notifications` page render the new type correctly (title, file name, reason excerpt, link). Minimal change only, because the full author flow comes next.

## Part 3 — Admin UI
Use the existing primitives: `AppDialog`, `Skeleton`, `EmptyState`, `useReveal`, the status badge, toasts. Existing tokens only. ar/fr/en, RTL, light and dark, reduced motion.
- **Oeuvre page, file list:** each file row shows its review badge, and the two actions "Accepter" and "Rejeter" (only the ones in `allowed`; a disabled action keeps its visible reason). A header counter, "3 / 5 fichiers acceptés", with a thin progress bar. A rejected file shows its reason under the row.
- **Full review page (`FileReviewLayout`):**
  - the same review badge and the same actions in the page header;
  - "Fichier précédent / suivant", and "Suivant à examiner", which jumps to the next file still `pending`; after a successful decision an inline prompt offers "Passer au fichier suivant à examiner";
  - for a file whose preview family is unsupported: an `Alert` "Aperçu indisponible pour ce format" that still shows name, size, type and hash, and does not block the decision.
- **Reject dialog (`AppDialog` md):** a reason textarea (auto-grow, live counter, 20–2000), quick-reason chips that insert text at the cursor ("Fichier illisible ou corrompu", "Le document ne correspond pas au type demandé", "Document incomplet", "Format non conforme", "Fichier en double"), a destructive confirm button disabled until the reason is valid, the busy state blocks Esc and outside click.
- **Accept:** one click with a small confirm popover ("Accepter ce fichier ?"), with a spinner while busy.
- **Revising a decision:** a rejected or accepted file offers "Modifier la décision" (reset to pending, or the opposite decision), still only while the oeuvre is under review.
- **Responses:** success → toast, silent partial reload of only the affected props (`meta: { silent: true }`), badge pulse; 409 holder → the message, then refetch (the oeuvre dialog's holder / `take_over` flow already exists); 409 already decided → "Déjà traité par {admin} le {date}"; 422 → the server's message, never a generic error.
- **Oeuvre decision dialog** (`ReviewDecisionDialog`): the checklist shows the files not yet accepted; the reject screen has the chip "Inclure les fichiers rejetés".
- Use the generated TS enum constants if they exist (Phase 2b); otherwise one local constant for the three keys. No scattered literals.

## Tests
- **Pest:**
  - every valid transition, and idempotent repeats (no new row, no new notification);
  - reject: no reason, a short reason and an over-long reason → 422; the reason is trimmed;
  - guards: oeuvre not `under_review` → 409/422; a non-holder → 409; non-admin → 403; a file of another oeuvre → 404; accept of a non-`ready` file → 422; reject of an `uploading` file → 422;
  - concurrency: a file reject racing an oeuvre approve cannot produce an accepted oeuvre with a rejected file (state how you tested it);
  - `media_file_reviews` is append-only (update and delete throw);
  - `was_consulted` is true only after an admin consult of that file by that reviewer;
  - `Notification::fake()`: queued, once per rejection, not on accept, carries the reason, has no reviewer identity, and a changed reason notifies again;
  - the oeuvre accept guard: pending, rejected and accepted files → 422 with the exact list, or allowed; the summary's `allowed` and `filesNotAccepted` are correct;
  - dedup: two rows with the same hash have independent review states;
  - the query count of the oeuvre page and the summary stays under a fixed ceiling (no N+1 from the new columns).
- **Enum tests** per the established convention: the value ↔ key snapshot, labels in 3 languages, `fromKey` round trip, the schema-comment test, the cast test, the serialization-as-keys test.
- **Vitest:**
  - file rows show badges and only the allowed actions, with the disabled reason;
  - the reject dialog validates min/max, chips insert text, the busy state blocks Esc;
  - "Suivant à examiner" skips decided files;
  - 409 / 422 render their messages;
  - success triggers a silent partial reload;
  - the review page header actions follow `allowed`;
  - the notification list renders the `file_rejected` type.
- **Manual (browser, ar and fr):**
  - an oeuvre in review with 3–4 files: accept one, reject one with a chip plus custom text, revise a decision;
  - accepting the oeuvre is blocked with the file list until all files are accepted;
  - the author account's bell shows the file rejection (database), and no reviewer name appears;
  - the oeuvre reject dialog with "Inclure les fichiers rejetés";
  - a second admin account: not the holder, so blocked with the holder's name, then `take_over` works;
  - an unsupported-preview file can still be decided.

## Owned files — touch nothing else
```
database/migrations/**  (create_media_files_table edit + the new create_media_file_reviews_table only)
app/Enums/MediaFile/ReviewStatusEnum.php, lang/{ar,fr,en}/enums.php
app/Models/MediaFile.php, app/Models/MediaFileReview.php (new)
app/Actions/Review/DecideMediaFile.php (new), app/Actions/Review/ApproveOeuvre.php (the accept guard only)
app/Http/Controllers/Admin/** (a new MediaFileReviewController; the summary endpoint), app/Policies/MediaFilePolicy.php
app/Notifications/MediaFileRejected.php (new), routes/admin.php, Wayfinder generated files
resources/js/pages/admin/oeuvres/**, resources/js/components/review/**, FileReviewLayout and its header
resources/js/pages/** notification list + bell (rendering the new type only)
resources/js/locales/{ar,fr,en}.json
tests/**
```
FROZEN: `OeuvreStatusMachine` behaviour and the transitions, the pipeline job order, the vault, encryption and upload protocol, the consultation security behaviour (derivative only, no download), the hash chains, colour and font tokens.

## Acceptance gate
1. The Step 0 report, and any deviation from the decisions, with the reason.
2. `composer test`: green, with before/after counts. Vitest, types, lint and format all 0. `npm run build`.
3. The manual check above, described or with screenshots.
4. A schema diff showing only the new `media_files` columns and the new table.
5. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `git mv`, `--cached`, `update-index`).
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
