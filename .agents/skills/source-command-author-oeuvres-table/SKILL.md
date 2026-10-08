---
name: "source-command-author-oeuvres-table"
description: "Migrated source command `author-oeuvres-table`"
---

# source-command-author-oeuvres-table

Use this skill when the user asks to run the migrated source command `author-oeuvres-table`.

## Command Template

Task — Author works: table view, row actions, and submission. Read AGENTS.md first.

Three changes to the author space, and nothing else:

1. `/author/oeuvres` opens as a **table** — a front-end table component, not a card grid.
   No schema change for the table itself.
2. Row actions: **View**, **Edit** (files only), **Delete**.
3. A **submit** action moving an oeuvre from `draft` to `submitted` — the author confirming
   every file is uploaded.

Admin notification and the review decision are **not** in this task. They come next.

Baseline: Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at
their current values. Hold all of it.

The decisions below are made. Build them as specified. STOP and report only if something in
the code contradicts one of them.

## Before you touch Index.vue

`resources/js/pages/author/oeuvres/Index.vue` holds substantial in-progress work by the project
owner. Read it first. Build the table inside it rather than replacing the file, keep what
already works, and do not reformat parts you do not otherwise change. Report what you kept and
what you changed.

---

## 1 — The table

| Column | Content |
|---|---|
| Œuvre | display label, linking to the oeuvre page |
| Collège | localised collège name |
| Documents | required slots satisfied — "4 / 6" |
| Statut | badge: Brouillon · En attente · En examen · Enregistrée · Rejetée |
| Créée le | creation date |
| Actions | View · Edit · Delete, per the rules below |

- Search by label, filter by status, paginate server-side.
- The documents column is an aggregate per row. Use eager loading and `withCount` so the page is
  a small fixed number of queries regardless of row count. State the number and how you
  measured it.
- Localised with `<bdi>` around Latin runs in Arabic, as the rest of the deposit UI does.
- Existing `components/ui` primitives and Tailwind v4. No new packages.
- An empty state that tells the author how to create their first oeuvre.
- A `rejected` row is visually impossible to miss — it is the only one asking the author to act.

---

## 2 — Actions

Each action is shown **only** when it is allowed, and enforced by the policy on the server —
never by the UI alone. A hidden button is not a permission.

### View — always

Opens the oeuvre page, read-only. Available in every status.

### Edit — files only, `draft` and `rejected` only

Edit means managing the oeuvre's **files**. The classification — type, gestion, collège,
qualité — is **never editable** after creation. This is deliberate: every uploaded file carries
a `college_oeuvre_file_id` bound to the current collège, so a fixed classification means that
link can never drift.

Available when the oeuvre is `draft` or `rejected`. Refused for `submitted`, `under_review` and
`registered`, by the policy and not only the UI.

Edit opens the step-2 page with management controls enabled. For a non-editable status the same
page shows no controls and the files are read-only.

**Adding files** — the existing per-slot upload, unchanged.

**Removing a file** — new, and required rather than optional: the submission gate refuses a
`failed` or `quarantined` file, so without a way to remove one, a single failed upload blocks
submission permanently.

Rules for removing a file:

- Only in a `draft` or `rejected` oeuvre.
- Only files at `ready`, `failed` or `quarantined`. A file in `uploading` uses the existing
  abort. A file in `scanning` or `processing` is refused until its pipeline finishes — the
  chain's jobs reference that row, and removing it mid-run leaves them working on a trashed
  record.
- Soft-deletes the `media_files` row. **Never touches the bytes.** The purge job frees them
  later and already respects `ref_count` — a deduplicated file shares its bytes with another
  row, so removing one must not destroy the other's content.
- A quarantined file removed by the author stays in quarantine and stays visible to admins
  through `withTrashed()`. Removal takes it off the author's oeuvre; it does not erase the
  evidence of what was uploaded.
- The confirmation says plainly that storage quota does not recover until the purge job runs.

There must be **no route and no request** that changes the classification of an existing
oeuvre.

### Delete — the whole oeuvre, `draft` and `rejected` only

- `draft` → deletable, with a confirmation.
- `rejected` → deletable, same confirmation.
- `submitted`, `under_review`, `registered` → **never**. A submitted deposit is under review; a
  registered one is a legal record. Neither may be deleted by its author.

Deleting soft-deletes the oeuvre **and its `media_files`**, subject to the same rules as
removing a single file: refused while any file is in `scanning` or `processing`.

The confirmation dialog must state two things, because both surprise people:

- **The bytes stay on disk.** Soft delete sets `deleted_at`; the encrypted files remain until the
  purge job runs after the retention window.
- **Storage quota does not recover immediately.** Quota counts files not yet purged, so deleting
  a 5 GB draft does not free 5 GB of the author's quota today.

Word it plainly in all three languages. An author who deletes a large draft to make room and
finds the quota unchanged will call support.

---

## 3 — Submit: draft → submitted

"Confirm all files are uploaded." Available on the oeuvre page and as a row action.

### The submission gate

Submission requires, all at once:

- At least one file.
- **No file in `uploading`, `scanning` or `processing`.** An officer must not receive a deposit
  whose files are still being checked.
- **No file `failed` or `quarantined`.** Name each one, and point the author at Edit to remove it.
- Every **unconditional** required slot holds at least one `ready` file.

**Conditional slots do not block.** `college_oeuvre_files.conditions` is stored but not
evaluated. MUSIQUE marks `autorisation_sample` required, yet it only applies when a sample was
actually used — if the gate demanded it, an author who used no sample could **never submit**.
So a required slot that carries `conditions` is advisory: shown as "may not apply to your work",
never a blocker. Put this rule in a comment where the gate lives; it tightens on its own once
conditions are evaluated.

A refused submission returns **every** reason at once, each naming the file or slot. An author
told "one more problem" five times in a row stops trusting the button.

### The confirmation

Before submitting, a dialog stating exactly what the author gives up: no more files, no removal,
no deletion, until a decision comes back.

### After submission — frozen, on the server

- `status = submitted`, `submitted_at` set. Add the column if it does not exist.
- The policy denies Edit and Delete for `submitted`, `under_review` and `registered`.
- **`InitUpload` refuses to start an upload into an oeuvre that is not `draft` or `rejected`.**
  This is the one change permitted in `app/Actions/Upload/` — a status guard at the top of
  `InitUpload`. Do not touch chunking, encryption, CRC, retry, resume or finalize.

A frozen oeuvre enforced only by hiding the upload area is not frozen.

Implement the transition through one guarded method on a status class, not as
`$oeuvre->update(['status' => 'submitted'])` in a controller. The review task that follows adds
the admin transitions to that same place.

---

## Owned files

```
resources/js/pages/author/oeuvres/{Index,Show}.vue
resources/js/components/oeuvre/**
resources/js/components/upload/**           remove-file control only
resources/js/locales/{ar,fr,en}.json
app/Http/Controllers/Author/{OeuvreController,MediaFileController}.php
app/Http/Requests/Author/**
app/Policies/{OeuvrePolicy,MediaFilePolicy}.php
app/Domain/Deposit/**                        the status transition
app/Actions/Upload/InitUpload.php            the status guard ONLY
app/Models/Oeuvre.php                        scopes, casts, the submission check
database/migrations/*                        submitted_at only, if missing
routes/author.php
tests/**
```

FROZEN — STOP and report if you need these:

```
app/Domain/Vault/**   app/Jobs/**
app/Actions/Upload/{StoreChunk,CompleteUpload,AbortUpload}.php
resources/js/stores/**
database/seeders/**   config/vault.php   config/filesystems.php
```

---

## Tests

**Table**
- Lists only the author's own oeuvres, never another author's.
- Filter by status and search return the right rows.
- The query count stays fixed as rows are added.

**Edit (files)**
- An author can remove a `ready`, `failed` or `quarantined` file from a draft.
- Removing a file in `scanning` or `processing` is refused.
- Removing a file from a `submitted` or `registered` oeuvre is refused, even via a direct request.
- A removed file is soft-deleted; its bytes are still on disk afterwards.
- Removing one of a deduplicated pair leaves the other file's bytes intact.
- A removed quarantined file is still visible to an admin via `withTrashed()`.
- After removing the only `failed` file, the oeuvre becomes submittable.
- There is no route or request that changes the classification of an existing oeuvre.
- Removing a file from another author's oeuvre is refused.

**Delete**
- Allowed on `draft` and `rejected`.
- Refused on `submitted`, `under_review` and `registered`, even via a direct request.
- Refused while any file is in `scanning` or `processing`.
- Soft-deletes the oeuvre and its media files; the vault bytes are still on disk afterwards.
- Refused on another author's oeuvre.

**Submit**
- Refused with a file still `scanning`, naming it.
- Refused with a `quarantined` file, naming it.
- Refused with an empty unconditional required slot.
- **Succeeds** with an empty conditional required slot.
- Returns every reason at once when several apply.
- Sets `status` and `submitted_at`.
- Refused on another author's oeuvre.

**Freezing**
- `InitUpload` refuses an upload into a `submitted` oeuvre.
- `InitUpload` still accepts an upload into a `draft` and a `rejected` oeuvre.
- A full chunked upload into a draft still reaches `ready` — the guard did not break the pipeline.

---

## Acceptance gate

1. What you kept and changed in the existing `Index.vue`.
2. The table's query count and how you measured it.
3. In a real browser:
   - the works list opens as a table
   - on a draft, remove a `ready` file, then a `failed` one
   - confirm removing a file still `processing` is refused
   - delete a draft; confirm the dialog explains the bytes and the quota
   - try to submit with a file still processing — see the reason
   - submit a complete oeuvre; confirm it shows "En attente" and the row loses Edit and Delete
   - try to upload into it and confirm the server refuses
4. Screens in Arabic and French, with rows in several statuses.
5. Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — at baseline or
   better.
6. **Run `npm run build` before the browser check.** A stale `public/build` has already cost this
   project a full misdiagnosis session and 12 phantom test failures.
7. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
