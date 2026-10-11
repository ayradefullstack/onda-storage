---
name: "source-command-accepted-formats-multiselect"
description: "Migrated source command `accepted-formats-multiselect`"
---

# source-command-accepted-formats-multiselect

Use this skill when the user asks to run the migrated source command `accepted-formats-multiselect`.

## Command Template

Task — Accepted formats and MIME types: one registry, a grouped multi-select, and real content
verification. Read AGENTS.md first.

Three connected changes:

1. The admin **Accepted formats** field on `/admin/referentiel/documents` becomes a searchable,
   grouped multi-select offering every supported format.
2. Each required-document row also records its **MIME types**, derived from the chosen formats.
3. Uploaded files are verified against those MIME types **by their actual content**, not by
   their name.

Baseline: Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at
their current values. Hold all of it.

---

## Why MIME needs two different checks

An extension is a claim made by the filename. Anyone can rename `malware.exe` to `paroles.pdf`,
and the extension check passes. Only the file's bytes tell the truth.

But the bytes are not available where the extension is checked:

- **At `InitUpload`** the server has a filename and a size. No bytes have arrived yet.
- The **browser-declared MIME** is unreliable. The step-2 work found that `.7z`, `.sql` and
  `.rar` routinely arrive empty or as `application/octet-stream`. Validating it would reject
  legitimate deposits.
- **Each chunk is encrypted in memory on arrival.** The server never holds the whole plaintext
  file during upload.

So:

| Where | Checks | Role |
|---|---|---|
| `InitUpload` | extension, case-insensitive | fast, cheap, bypassable — a first filter |
| The pipeline, after `DecryptToTemp` | **content**, via `finfo` on the plaintext temp file | authoritative |

The pipeline is the only place the real bytes exist in the clear, and it is the place that
catches a renamed file.

---

## Part 0 — Audit before building, and report

**0.1 Every extension known anywhere.** Collect from:

1. the union of all 69 rows' `extensions`
2. the extension→MIME map `InitUpload` uses
3. the global `ALLOWED` whitelist for unclassified oeuvres
4. whatever `uploadValidation.ts` accepts

Report the union and every extension present in one place but missing from another. That list
is where deposits silently fail today.

**0.2 What `finfo` actually returns — measured, not assumed.** For every format you put in the
registry, obtain a real sample file and run `finfo_file()` on it on this machine. Record the
exact MIME returned.

Do not take MIME types from documentation. Real detection disagrees with it constantly:

- `docx`, `xlsx` and `pptx` are ZIP containers. Depending on the libmagic version, `finfo`
  returns the full OpenXML type **or** `application/zip`.
- `csv`, `sql` and often `json` come back as `text/plain`.
- `mp3` may be `audio/mpeg` or `audio/mp3`; `mov` may be `video/quicktime`.
- `heic`, `ai`, `eps` and `psd` vary widely by version.

**0.3 The platform difference.** Windows PHP and cPanel's Linux PHP ship different libmagic
versions, and may return different MIMEs for the same file. Report the libmagic version here,
and mark every MIME in the registry as **measured locally, unverified on production**. The
production check must be re-run on cPanel before go-live — add it to the deployment checklist.

Report all three before writing code.

---

## The registry — one source of truth

A single PHP definition. A class of constants, a backed enum or a config file — your choice;
say which and why. **Not** a database table: this is code-level capability, not an admin
setting. Adding a row would not teach the pipeline a new type.

Each format carries:

```
extension     lowercase, no dot                        "docx"
category      documents | spreadsheets | presentations | images | audio |
              video | archives | data | ebooks | notation
label         display name                             "DOCX"
mimes         every MIME finfo may return for it       ["application/vnd.openxmlformats-
                                                        officedocument.wordprocessingml.document",
                                                        "application/zip"]
stored_mime   the single MIME written to media_files   the canonical one
inline_safe   bool — may it be served inline?
```

`mimes` is a **list**, because detection is not one-to-one. It must contain every value Part
0.2 measured, including container aliases like `application/zip` for OpenXML formats. A list
missing one alias rejects a legitimate file.

`inline_safe` is false for `svg`, `xml`, `html`, `htm` and anything a browser would execute.
Their `stored_mime` is `application/octet-stream`, preserving the step-2 stored-XSS fix exactly.
**That behaviour must survive this refactor** — verify it with a test.

### Categories

Reconcile against Part 0.1 rather than treating this as final:

| Category | Formats |
|---|---|
| Documents | pdf, doc, docx, odt, rtf, txt |
| Spreadsheets | xls, xlsx, ods, csv |
| Presentations | ppt, pptx, odp |
| Images | jpg, jpeg, png, gif, webp, tif, tiff, bmp, heic, psd, ai, eps, svg |
| Audio | mp3, wav, flac, aac, ogg, m4a, aiff, opus |
| Video | mp4, mov, avi, mkv, webm, m4v, mpeg, mpg |
| Archives | zip, rar, 7z, tar, gz |
| Data | json, xml, sql |
| E-books | epub |
| Music notation | mid, midi, mxl, musicxml |

The notation category is not decoration: this is a copyright office, and a composer depositing
a musical work may reasonably submit the score.

### Never offered

`exe`, `msi`, `bat`, `cmd`, `sh`, `ps1`, `js`, `php`, `jar`, `apk`, `dll`. A deposit has no reason
to require an executable directly — LOGICIEL and SITE_WEB receive source as archives, which the
seeded rows already do. Keep them out of the registry entirely, and test that none can be saved
even via a direct request.

**The content check also catches them.** A `.exe` renamed to `.pdf` passes the extension check,
but `finfo` reports `application/x-dosexec`, which is in no slot's list. That is the point of
Part 3.

---

## Part 1 — MIME types on each required-document row

Add to `college_oeuvre_files`:

```
mime_types      json    the union of `mimes` across the row's chosen extensions
```

**Derived, never edited.** It is recomputed from `extensions` through the registry every time a
row is saved — in a model `saving` hook, so no code path can write one without the other. The
admin UI shows it read-only beneath the format selection.

Stored derived data drifts when its source changes. So:

- An artisan command `referentiel:sync-mime-types` recomputes every row from the current
  registry. Run it when the registry changes; add that to the deployment checklist.
- A test asserts that for all 69 rows, stored `mime_types` equals what the registry derives from
  their `extensions`. That test fails the moment they diverge.

`database/migrations/**` opens for this one column only.

`CollegeOeuvreFileSeeder` must populate `mime_types` for all 69 rows through the same derivation,
not by hand.

---

## Part 2 — The multi-select

Built from existing `components/ui` primitives — `reka-ui` is installed and has the listbox and
combobox pieces. **No new packages.**

- Grouped by category, category as a heading.
- Searchable — fifty-odd formats; scrolling is not a selection method.
- A "select all in this category" shortcut. An admin configuring an audio slot wants every audio
  format in one action.
- Selected formats as removable chips.
- Beneath the selection, the derived MIME types, read-only, so the admin sees exactly what the
  pipeline will accept.
- `inline_safe = false` formats carry a marker explaining they are downloaded, not previewed.
- Keyboard-operable end to end.
- Full ar/fr/en. Format labels and MIME strings are Latin inside an Arabic layout — wrap them in
  `<bdi>`, as the rest of the admin UI does.

Server-side on save: every chosen extension must exist in the registry. A crafted request with
`exe` or `foo` is rejected.

Preserved from the reference-data work:

- Stored as a lowercase list without dots.
- Saving extensions on a `needs_review` row clears the flag.
- Tightening a slot's formats does **not** invalidate files already uploaded, and the UI says so
  before saving.

---

## Part 3 — Content verification in the pipeline

A new job, **`VerifyContentType`**, placed **immediately after `DecryptToTemp`** — before the hash,
the deduplication and the malware scan. It reads only the first bytes, so it is cheap, and a
wrong file should fail before anything more expensive runs on it.

It runs `finfo_file()` on the plaintext temp file and checks the result against the slot's
`mime_types`:

- **Match** → continue.
- **Mismatch** → the file goes to `failed`, **not** `quarantined`. Record the reason plainly:
  "This file's content is DOCX-ZIP but the slot accepts PDF." Quarantine is for suspected
  malware; a mismatched type is the author's to fix, and they can remove it and upload the right
  file.
- **Unclassified oeuvres** (no requirement slot) check against the registry's full list.
- Files uploaded **before** this change are not re-checked retroactively.

The file's `mime` column is then set from the registry's `stored_mime` for its extension, so the
`inline_safe` rule applies to what is served.

### This touches frozen areas — narrowly

- `app/Jobs/**` opens for the **one new job** and the `chainJobs()` definition only. No other job
  changes.
- `UploadLifecycleTest` pins the job order through `chainJobs()`. It will fail until updated.
  **Update the expected order to include the new job; do not weaken the assertion.** That test is
  the guard ensuring `complete()` does no heavy work, and an earlier session already "fixed" it
  once by inverting its meaning.
- `app/Actions/Upload/InitUpload.php` opens for replacing its hardcoded map with a registry lookup.
  Nothing else — not chunking, encryption, CRC, retry, resume, finalize or quota.

### A tradeoff to report, not to implement

Detecting a wrong type after a 5 GB upload wastes hours of the author's bandwidth. The magic
bytes are in the first chunk, and `StoreChunk` holds that chunk's plaintext in memory before
encrypting it — so an early sniff on chunk 0 is technically possible.

**Do not build it.** `StoreChunk` is the hot path, frozen, and verified against real uploads.
Report whether it is feasible and what it would cost, so it can be decided separately.

---

## Existing data

After building the registry, check all 69 rows:

- An extension in a row but absent from the registry is a finding. **Do not drop it silently** —
  report the row, the collège and the extension, and decide per case: add it to the registry, or
  leave it in the row flagged `needs_review`.
- Every row must validate against the registry after seeding. Test it.

---

## Owned files

```
app/Support/FileFormats.php  (or the location you choose)
app/Models/CollegeOeuvreFile.php                  the saving hook and cast only
app/Console/Commands/SyncMimeTypesCommand.php
app/Jobs/VerifyContentType.php                    new
app/Jobs/<wherever chainJobs() is defined>        the chain entry only
app/Actions/Upload/InitUpload.php                 the registry lookup only
app/Http/Requests/Admin/**
app/Http/Controllers/Admin/Referentiel/**
app/Http/Middleware/HandleInertiaRequests.php     the shared prop, if you choose that route
database/migrations/*                             mime_types column only
database/seeders/CollegeOeuvreFileSeeder.php      mime_types derivation only
resources/js/lib/uploadValidation.ts              read from the registry
resources/js/components/admin/**
resources/js/components/ui/**                     the multi-select, if composed there
resources/js/pages/admin/referentiel/**
resources/js/locales/{ar,fr,en}.json
tests/**
docs/deployment-cpanel.md                         the two checklist lines only
```

FROZEN — STOP and report if you need these:

```
app/Domain/Vault/**
app/Actions/Upload/{StoreChunk,CompleteUpload,AbortUpload}.php
app/Jobs/*  (every existing job)
resources/js/stores/**
config/vault.php   config/filesystems.php
```

---

## Tests

**Registry**
- No duplicate extensions; every entry has a category, label, non-empty `mimes` and a `stored_mime`.
- No executable or script extension exists in it.
- The client receives exactly the server's list — no second copy in TypeScript.

**Admin**
- Saving `exe`, `php` or an unknown extension is rejected, even via a direct request.
- A valid selection stores a lowercase, dot-free list.
- Saving recomputes `mime_types` from the registry.
- For all 69 rows, stored `mime_types` equals the registry derivation.
- Saving extensions on a `needs_review` row clears the flag.

**Content verification** — with real fixture files, not synthetic bytes:
- A real PDF in a PDF slot → `ready`.
- A real DOCX in a DOCX slot → `ready`, whichever MIME `finfo` returns for it here.
- A DOCX **renamed to `.pdf`** in a PDF slot → `failed`, with a reason naming both types.
- A Windows executable renamed to `.pdf` → `failed`.
- `svg` and `xml` are still stored as `application/octet-stream`.
- An uppercase `.MOV` still passes the extension check where `mov` is listed.
- A full chunked upload still reaches `ready` — the new job did not break the chain.
- `UploadLifecycleTest` still asserts `complete()` dispatches without executing, with the new job
  in the expected order.

---

## Acceptance gate

1. Part 0: the extension union and disagreements; the measured `finfo` result for every format;
   the libmagic version.
2. The registry location you chose, and why.
3. Every seeded extension absent from the registry, and what you decided for each.
4. The chunk-0 early-detection assessment — feasible or not, and at what cost.
5. In a real browser:
   - pick formats across two categories with the search and a category shortcut; see the derived
     MIME types appear; save; reopen and confirm both persisted
   - upload a real file of a newly allowed format into that slot and watch it reach `ready`
   - rename a DOCX to `.pdf`, upload it into a PDF slot, and watch it reach `failed` with a
     readable reason
6. Screens in Arabic and French.
7. Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — at baseline or better.
8. **Run `npm run build` before the browser check.** A stale `public/build` has already cost this
   project a full misdiagnosis session and 12 phantom test failures.
9. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
