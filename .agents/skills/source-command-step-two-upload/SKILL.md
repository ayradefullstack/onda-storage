---
name: "source-command-step-two-upload"
description: "Migrated source command `step-two-upload`"
---

# source-command-step-two-upload

Use this skill when the user asks to run the migrated source command `step-two-upload`.

## Command Template

Task — Step 2: per-requirement uploads. Read AGENTS.md first.

Step 1 is merged: an oeuvre is created with its `register_type_id`, `type_gestion_id`,
`register_type_college_id`, `register_type_member_id` and `code_college_snapshot`.
`college_oeuvre_files` holds the required-document definitions per collège.

Step 2 shows one labelled slot per required document, each with its own multiple-file input,
each file uploading through the **existing** chunked pipeline unchanged.

Baseline: Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at
their current values. Hold all of it.

## The core question: how a file knows which requirement it satisfies

Today a `MediaFile` belongs to an oeuvre and nothing more. After this task it must also
answer "which document slot is this?" — otherwise nothing can tell whether the required
`justificatif_exploitation` was uploaded, and the whole point of the matrix is lost.

Add to `media_files`:

```
college_oeuvre_file_id     FK → college_oeuvre_files, nullable, indexed
document_key_snapshot      string, nullable
```

Nullable because existing files predate this.

**Why the snapshot**, in a comment on the migration and for the same reason as
`code_college_snapshot`: requirement rows can be edited, re-keyed or retired. A file deposited
against `justificatif_exploitation` must still say so in three years, even if the requirement
row has since changed. The foreign key gives you joins; the snapshot gives you the truth as of
the upload.

One file satisfies exactly one slot — a foreign key, not a pivot.

`upload_sessions` needs `college_oeuvre_file_id` too, so `CompleteUpload` can carry it onto
the `MediaFile` row.

## Scope — two frozen areas are opened, narrowly

These have been frozen in every recent task. They open **only** for what is listed:

**`app/Actions/Upload/**`** — `InitUpload` accepts and validates a `college_oeuvre_file_id`;
`CompleteUpload` writes it and the snapshot onto the `MediaFile`.

Do **not** touch: the chunking, the 8 MiB chunk size, the offset write, the encryption, the
CRC check, idempotency, the retry semantics, `finalize`, or the quota increment. The protocol
is settled and verified against a real upload — this task adds one field to it, nothing more.

**`resources/js/stores/uploads.ts`** — files carry a requirement id, and the queue is grouped
by slot for display.

Do **not** touch: the 3-slot concurrency, the backoff schedule, the CRC computation, the
worker construction, the resume-from-mask logic, or the `sessionStorage` persistence. Those
cost this project two manual test cycles and a full misdiagnosis session to get right.

`app/Domain/Vault/**` stays fully frozen. If the vault contract needs a change, STOP and
report — it does not.

## Validation — per requirement, not global

`InitUpload` currently checks a global extension whitelist. It now checks **the requirement's
own list**:

- Extension must be in that slot's `extensions` array. Compare case-insensitively —
  `IMG_0967.MOV` is how iPhone and Windows name files, and a case-sensitive check would reject
  a large share of real deposits.
- `max_size_kb` where the requirement sets one. College 6's
  `oeuvres_plastiques_graphiques` caps at 102400 KB; most rows have no cap and fall back to
  the global 5 GB ceiling.
- The requirement must belong to the **oeuvre's own collège**. A crafted request naming a
  requirement from another collège is rejected.
- `allows_multiple = false` means a second file replaces or is refused — decide which, and say
  which in your report.

The 5 GB ceiling, the 8 MiB chunks and the whole resumable pipeline stay exactly as they are.

## The page

Step 1 creates the oeuvre and lands here. Rework the oeuvre show page into the step-2 upload
surface, or add a dedicated route — say which you chose and why.

Layout: one card per requirement, in `display_order`.

Each card carries: the localised title, a required marker where `is_required`, the accepted
extensions in plain words ("PDF, JPG, PNG"), a multiple-file input, and the files already
uploaded to that slot with their deposit state.

The existing `DepositCard` and its custody rail render each file's state — reuse them rather
than building a second representation. A file in a slot behaves exactly as a file does today:
chunked upload, then `scanning` → `processing` → `ready`.

At the top: how many required slots are satisfied out of the total. That is the one number
telling the author whether they can move on.

**Conditions are not evaluated in this task.** The `conditions` column holds raw rules from
the source system, but the declaration fields they reference do not exist yet. Render every
requirement for the collège. Note the limitation in your report and leave the column unread.

**`needs_review` rows** — those seeded without a real server rule — still render. Say in your
report how many appear for a typical collège, since their extension lists were inferred
rather than derived.

## Completion

A required slot is satisfied when it holds at least one file at `ready`. Not `scanning`, not
`processing` — the pipeline must have finished, because a quarantined or failed file satisfies
nothing.

Expose that as a computed state on the oeuvre. The submit action is a later task; this one
only needs to report the state honestly.

## Design and i18n

Match the existing deposit UI — the author-facing work already established the vocabulary and
the custody rail. Existing `components/ui` primitives, Tailwind v4, no new packages.

Full ar/fr/en. Titles from `college_oeuvre_files` are French and Arabic; use the localised
accessor with its fallback to `title`. Wrap French titles, extension lists and filenames in
`<bdi>` for the Arabic layout, as established.

## Tests

- Opening step 2 for a MUSIQUE oeuvre renders exactly its 6 requirement slots, in order.
- A PRESTATION_AUDIOVISUELLE oeuvre renders its 6; EDITEUR_MUSICAL renders its 4.
- A file uploaded to a slot stores `college_oeuvre_file_id` and the snapshot.
- An extension outside the slot's list is rejected at init.
- The same extension in uppercase is **accepted**.
- A file exceeding `max_size_kb` is rejected where the slot sets one.
- A requirement id belonging to another collège is rejected.
- Required-slot completion counts only files at `ready` — assert that a `scanning` file does
  not satisfy, and a `quarantined` one does not either.
- An existing `MediaFile` with a null requirement id still loads and displays.
- A full upload through the real pipeline still reaches `ready` with the new field set.

## Owned files

```
database/migrations/*                                  new only
app/Models/{MediaFile,Oeuvre,UploadSession}.php        relations, casts, fillable
app/Actions/Upload/{InitUpload,CompleteUpload}.php     the requirement field only
app/Http/Controllers/Author/OeuvreController.php
app/Http/Requests/Author/**
resources/js/pages/author/oeuvres/Show.vue
resources/js/components/upload/**
resources/js/components/oeuvre/**
resources/js/stores/uploads.ts                         the requirement dimension only
resources/js/locales/{ar,fr,en}.json
tests/**
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/Vault/**
app/Jobs/**
app/Actions/Upload/StoreChunk.php
app/Models/{RegisterType,TypeGestion,RegisterTypeCollege,RegisterTypeMember,CollegeOeuvreFile}.php
database/seeders/**
config/vault.php   config/filesystems.php
```

## Deliver a plan first

The page structure with a wireframe, your route decision, your `allows_multiple = false`
decision, and the migration. Wait for approval.

## Acceptance gate

1. The plan, approved, then the build.
2. A full deposit end to end in a real browser: classify in step 1, land on step 2, upload into
   two different slots, both reaching `ready`. Paste the `media_files` rows showing
   `college_oeuvre_file_id` and the snapshot.
3. Upload a file with an **uppercase** extension and confirm it is accepted.
4. Upload a file with a wrong extension and confirm the rejection message names the accepted
   formats.
5. Confirm the completion counter moves only when a file reaches `ready`.
6. Screens in Arabic and French, including a collège with a required slot still empty.
7. Confirm chunking, retry and resume are untouched — a network interruption mid-upload still
   resumes from the received mask.
8. Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at baseline
   or better.
9. **Run `npm run build` before the browser check.** A stale `public/build` has already cost
   this project a full misdiagnosis session and 12 phantom test failures.
10. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
