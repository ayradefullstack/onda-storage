---
name: "source-command-author-file-replacement"
description: "Migrated source command `author-file-replacement`"
---

# source-command-author-file-replacement

Use this skill when the user asks to run the migrated source command `author-file-replacement`.

## Command Template

Task: author side of per-file review. When an admin rejects a file, the author sees the reason, removes the rejected file, uploads a replacement into the same slot, and the admin is notified so they can review the replacement. Read AGENTS.md first.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Context (what already exists)
- Admin per-file decisions: `media_files.review_status` (`MediaFile\ReviewStatusEnum`: pending / accepted / rejected), `review_reason`, the append-only `media_file_reviews`, the `DecideMediaFile` action, and the `MediaFileRejected` database notification to the author.
- The oeuvre stays `under_review` while files are reviewed. A submitted oeuvre is frozen for the author today. **This task relaxes that rule in one narrow way, and only in that way** (see below).
- Soft deletes on `media_files`; `VaultBytesReleaser` is the only deleter of bytes; the upload protocol, scheduler and pipeline are frozen.

## The rule being added
While an oeuvre is `under_review`, the author may do exactly two things, and only for files the admin rejected:
1. **Remove a REJECTED file** (soft delete, bytes released by the existing releaser when no other row references them).
2. **Upload a replacement into the slot of a removed rejected file.**

Everything else stays frozen: no edit of the form, no removal of pending or accepted files, no upload into a slot that has no unreplaced removed-rejected file, nothing at all on `submitted`, `registered` or `rejected` oeuvres beyond what exists today. The oeuvre status never changes because of a replacement. The admin decision on the oeuvre stays separate.

## Step 0 — Report (continue unless something contradicts this brief)
1. **Authorization today:** where the upload init (`InitUpload`), the file removal action and the author policies decide that an oeuvre is editable. List the exact checks and the files.
2. **Slots:** can a slot (`college_oeuvre_file`) hold several files? Is there a per-slot file limit? How does `SubmissionGate` count files per slot (does it ignore soft-deleted rows)?
3. **READY transition:** where a file becomes `ready` (`TransitionMediaFileStatus`, `RecordDeposit`…). Propose the least invasive place for one event dispatch.
4. **Retention and purge:** how soft-deleted files and their bytes are purged. Check whether the `restrictOnDelete` foreign keys on `media_file_reviews` and on the new self-reference break any `forceDelete` / purge path. If they do, propose the fix (usually: the purge removes bytes and keeps the row).
5. **Admin notifiables:** how to find the holder of an oeuvre review, and the admins that can review (roles/permissions).
6. **Author oeuvre page:** the file list component, how each slot's dropzone is rendered, how the uploads store maps uploads to slots, and what the author sees for a rejected file today.
7. **Author dashboard:** the "Actions requises" block, to add the new item.

## Part 1 — Schema (edit the consolidated migration; development only)
Same rules as before: a dump of the dev DB first, `php artisan optimize:clear`, `migrate:fresh --seed` on the local dev DB only, no alter migrations, do not delete local vault files (report their path).
- `media_files` gets `replaces_media_file_id`: nullable self-referencing FK, **`restrictOnDelete`**, with a **unique** index (one replacement per removed file).
- A comment above the column: "set only on a replacement uploaded during review".
- No new enum is needed. If the audit logging needs an action value, add the next free `FileAccessLog\ActionEnum` value, never renumber.

## Part 2 — Backend

### Removing a rejected file
- Extend the existing removal action. Its authorization gains one case: oeuvre `under_review` AND file `review_status = rejected` AND the file belongs to the author's oeuvre. Everything that exists today for draft/rejected oeuvres stays as it is.
- **Lock order: the oeuvre row first, then the file row** (the same order as `DecideMediaFile` and the oeuvre actions). Re-check inside the lock:
  - the oeuvre is still `under_review`;
  - the file is still `rejected` (if the admin just revised it to accepted → 409 "this file is no longer rejected");
  - the file is not already deleted (idempotent: return the current state).
- The existing audit entry for deletion is kept (the author removed it). The `media_file_reviews` history is untouched.
- **`DecideMediaFile` must refuse a soft-deleted file** (422 "file removed by the author"). Add the test.

### Uploading a replacement
- `InitUpload` authorization gains one case: oeuvre `under_review` AND the target slot has at least one **unreplaced removed-rejected file** (a soft-deleted file in that slot with `review_status = rejected` and no row pointing to it through `replaces_media_file_id`). Check it inside the same per-user lock and the oeuvre-row lock as the quota reservation.
- The server links the new file by setting `replaces_media_file_id` to the oldest unreplaced removed-rejected file of that slot. The client never sends the id.
- The new file starts `review_status = pending` and goes through the normal pipeline. The upload protocol, chunking, scheduler and retry are unchanged.
- **If the oeuvre leaves `under_review` while a replacement upload is in flight** (the admin accepted or rejected it meanwhile): the next chunk or complete call answers 409 with a clear code, the session is aborted, and the quota reservation is released. The client shows "Le dépôt a été traité, le fichier n'a pas été ajouté".
- **The oeuvre accept guard already blocks** an oeuvre with an open slot, with a file not ready, or with a pending file. Add a test that accept is refused while a replacement is uploading or pending.

### Notifying the admin
- A new event, `ReplacementFileReady`, is dispatched **after commit, when a replacement reaches `ready`** (not when the upload starts: the admin cannot decide before). Dispatch it with the smallest possible change in the READY transition, only for files that have `replaces_media_file_id`. No other pipeline behaviour changes.
- `App\Notifications\ReplacementFileUploaded`: queued on `default`, database channel only, sent to **the holder of the oeuvre review**. If there is no active holder, fall back to every admin who can review. Data: `type = replacement_uploaded`, oeuvre id, reference and title, the author's name (this is the admin side), the slot label, the new file's name, the replaced file's name and rejection reason excerpt, and the link target (the admin review page of the new file). One notification per replacement. No duplicate for a retry.
- The admin bell renders the new type (minimal change, like the `file_rejected` type on the author side).

## Part 3 — Author UI
Existing primitives and tokens only: `AppDialog`, `Skeleton`, `EmptyState`, `useReveal`, status badges, toasts, the uploads store. ar/fr/en, RTL, light and dark, reduced motion. The author sees "ONDA" and never a reviewer's name.
- **Author oeuvre page, under review:**
  - a status banner "En cours d'examen par l'ONDA", plus, when files are rejected, an alert "{n} fichier(s) rejeté(s) à remplacer";
  - **every file** shows its review badge (pending / accepted / rejected), so the author knows where the deposit stands;
  - a rejected file shows its reason in a clearly styled callout, the decision date, and two actions: **"Remplacer"** and **"Supprimer"**;
  - **"Remplacer"** opens a confirm dialog ("Le fichier actuel sera supprimé. Vous pourrez ensuite téléverser le nouveau."), removes the file, and then focuses the slot's dropzone, labelled "Remplacement attendu";
  - **"Supprimer"** removes it, and the slot stays open with the same "Remplacement attendu" dropzone, so the author can upload at any time while the oeuvre is under review;
  - a slot without an open replacement does **not** show a dropzone, and pending or accepted files show no remove button (the UI follows server-computed flags, `canRemove` and `canUploadReplacement` per file/slot, never its own guess);
  - a replacement shows the normal upload progress and pipeline steps (the existing status polling), then the badge "En attente d'examen" with the label "Remplace : {old file name}".
- **Author dashboard, "Actions requises":** a new item for oeuvres under review with rejected files: title, the count of files to replace, and "Remplacer les fichiers". When all replacements are pending, show "En attente de l'ONDA" instead.
- **Author bell:** the `file_rejected` notification opens the oeuvre page scrolled to the file. Opening it does not change any data.
- **Error and race messages:** 409 file no longer rejected → the message, then a refresh of the props; 409 oeuvre already decided → "Le dépôt a été traité", then refresh; 422 → the server's message.
- **State refresh:** silent partial reload of only the affected props (`meta: { silent: true }`) after a removal and when a replacement becomes `ready`.

## Part 4 — Admin UI (small additions)
- On the oeuvre page, a replacement file row shows a badge "Remplacement" and "Remplace : {old file name}", with the previous rejection reason one click away.
- A collapsed section "Fichiers retirés par l'auteur" lists removed rejected files (name, rejection reason, removal date, "remplacé par {new file}" with a link). It is evidence, read-only.
- A banner on the oeuvre page, "{n} fichier(s) de remplacement à examiner", while replacements are pending.
- "Suivant à examiner" (already built) includes the replacements.

## Tests
- **Pest:**
  - removal: allowed only for a rejected file of an `under_review` oeuvre of the author; refused for pending and accepted files, for other authors' files (404/403), for `submitted`, `registered` and `rejected` oeuvres as before; idempotent repeat; 409 when the admin has just accepted the file;
  - `DecideMediaFile` refuses a removed file;
  - replacement init: allowed only for a slot with an unreplaced removed-rejected file; refused for a slot without one, for an oeuvre not under review, for another author; `replaces_media_file_id` is set by the server and unique; two parallel inits for one open slot cannot both link to the same removed file;
  - in-flight upload when the oeuvre is decided → 409, session aborted, reservation released;
  - the quota is freed on removal and reserved on the replacement init (use the real numbers);
  - the replacement starts `pending` and the oeuvre accept stays blocked until it is accepted;
  - `Notification::fake()`: `ReplacementFileUploaded` is queued, goes to the holder (fallback: the reviewing admins), is sent once, only when the file reaches `ready`, and contains no author-only data beyond what the admin may see;
  - the purge/retention path still works with the new FKs (per the Step 0 finding);
  - the dedup case: removing a rejected file whose bytes are shared with another row does not release the shared bytes;
  - the query count of the author and admin oeuvre pages stays under a fixed ceiling.
- **Vitest:**
  - a rejected file shows the reason and exactly the allowed actions; pending and accepted files show none;
  - "Remplacer" removes, then focuses the open slot's dropzone; "Supprimer" leaves the slot open;
  - a slot without an open replacement has no dropzone;
  - the replacement shows "Remplace : …" and the pending badge;
  - 409 and 422 messages; the silent reload after removal and after ready;
  - the dashboard item for rejected files;
  - the admin bell renders `replacement_uploaded`; the author bell link goes to the file.
- **Manual (browser, ar and fr, a real upload with the media worker running):**
  - as admin: reject two files of an oeuvre under review (one with a chip plus custom text);
  - as the author: the bell shows two rejections; open the oeuvre page; "Remplacer" the first file and upload the replacement; "Supprimer" the second one and upload later;
  - as admin: the notification arrives only when the replacement is `ready`; review it and accept it;
  - try to accept the oeuvre while the second slot is still empty: blocked with the reason;
  - after the second replacement and its acceptance: accept the oeuvre;
  - a race: the author starts a replacement upload while the admin rejects the whole oeuvre → the 409 message, nothing added;
  - the author cannot remove a pending or accepted file, and cannot see a dropzone on a slot that has no open replacement.

## Owned files — touch nothing else
```
database/migrations/**  (create_media_files_table only)
app/Models/MediaFile.php
app/Actions/** (the existing removal action, DecideMediaFile's removed-file refusal, InitUpload's new authorization case only)
app/Policies/** (the removal and upload authorization for the new case)
app/Http/Controllers/Author/** and Admin/** (props: canRemove, canUploadReplacement, replacement and removed-file props)
app/Events/ReplacementFileReady.php, app/Notifications/ReplacementFileUploaded.php, the one-line dispatch in the READY transition
resources/js/pages/author/oeuvres/**, resources/js/pages/admin/oeuvres/**, resources/js/components/** (file list, dropzone, bell, dashboard block)
resources/js/stores/uploads.ts (only to map a replacement upload to its slot, if needed)
resources/js/locales/{ar,fr,en}.json, lang/{ar,fr,en}/**
AGENTS.md (document the narrowed "frozen" rule), tests/**
```
FROZEN: the upload protocol, chunking, scheduler and crypto; the pipeline job order and logic (beyond the one event dispatch); `OeuvreStatusMachine` and the oeuvre decision actions; the hash chains; the consultation security behaviour; colour and font tokens.

## Acceptance gate
1. The Step 0 report, and any deviation, with the reason.
2. `composer test`: green, with before/after counts. Vitest, types, lint and format all 0. `npm run build`.
3. The manual check above, described or with screenshots, including the in-flight race.
4. A schema diff showing only `replaces_media_file_id` and its index.
5. `git status --short`

Then STOP.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `git mv`, `--cached`, `update-index`).
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
