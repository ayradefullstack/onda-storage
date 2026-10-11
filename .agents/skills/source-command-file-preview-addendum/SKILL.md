---
name: "source-command-file-preview-addendum"
description: "Migrated source command `file-preview-addendum`"
---

# source-command-file-preview-addendum

Use this skill when the user asks to run the migrated source command `file-preview-addendum`.

## Command Template

Addendum to `admin-file-review.md`: preview module refinements. Read AGENTS.md first.

This addendum EXTENDS the consultation/review work. It does not replace it, and it does not create a second preview system.

You are in plan mode. Your plan must contain:
1. The full Step 0 report (and Step 0 of the addendum if both documents are given), with file paths.
2. For every Part: the files to add or modify, each marked reuse / extend / new, with one line why.
3. The tools detected on this machine (ffmpeg, pdftoppm, imagick, GD, soffice, phpspreadsheet) and what each missing one degrades to.
4. Any point where you would need a FROZEN file. List it as a blocker; do not plan around it.
5. The gate order: admin-file-review first, then addendum Parts 1–6, then Part 7, each stopping for my "go".
After I approve the plan, the git policy and STOP gates in the documents still apply.

- If `admin-file-review.md` has not been implemented yet, implement it first, then this addendum, with one gate per document.
- If it is implemented, Step 0 below reports what exists, and you build only the gap.

Baseline to hold: zero across eslint, prettier, vue-tsc, Pint, phpstan and the full Pest suite; `composer ci:check` passes end to end.

## Explicitly rejected — do NOT build any of this, even if a generic design doc suggests it

1. A parallel module: `app/Services/Files/*`, `FilePreviewController`, `FileDownloadController`, `FileMimeResolver`, `resources/js/Pages/Preview/*`, routes `/files/{id}/preview`. The project already has the consultation module, the format registry, `VerifyContentType`, uuid route keys and split admin/author routes. Reuse them.
2. Any plaintext preview cache (for example `storage/previews/{id}/preview.pdf`). Every derivative is encrypted with the file DEK and a stored random nonce, as already designed.
3. PDF.js, or any viewer, fed with the ORIGINAL file for an admin. Admin access to originals stays closed (Phase A).
4. A new `CLAMAV_ENABLED` / `CLAMAV_HOST` config. Scanning already goes through the `Scanner` port, `SCAN_DRIVER=null|clamav`, `NullScanner` and `ClamavScanner`. Document the existing switch; add nothing.
5. Monaco, CodeMirror or any heavy editor dependency. Read-only text needs no editor.
6. RAR/7z/TAR viewers, OnlyOffice, Collabora, OCR, full-text indexing, versioning, sharing.
7. Extension checks in Vue (`if (extension === 'pdf')`). The frontend never inspects extensions.

## Step 0 — Report (no code)
1. State of `admin-file-review.md`: implemented fully, partly, or not at all. List the pieces with paths.
2. Where the family decision lives today: one place, or several. Every place that maps extension or MIME to a viewer.
3. Whether the format registry accepts any archive format (zip). If not, Part 6 is skipped.
4. Current download paths for owners (tickets, original stream) and how they are authorised.

Continue unless something contradicts this addendum; then STOP and report.

## Part 1 — One source of truth for the preview family (backend)

- Exactly one resolver decides the family: `app/Infrastructure/Render/PreviewFamilyResolver.php`, or the existing equivalent if Step 0 finds one. Input: the format registry category plus the finfo-verified MIME stored on the media file. Output: an enum `PreviewFamily` (pdf, document, presentation, spreadsheet, csv, image, text, video, audio, archive, other).
- The derivative job, the review controller and the asset controller all call this resolver. No other PHP code maps extensions or MIME types to viewers.
- A normalized descriptor, built by one class (`ConsultationDescriptor`) and typed on the frontend in `resources/js/types/consultation.ts`:
  ```
  { family, status: 'pending'|'ready'|'failed'|'unsupported', reason: string|null,
    assets: Array<{ kind, pageIndex|null, url }>, pageCount|null,
    meta: { filename, sizeBytes, mime, sha256, depositedAt, slotLabel },
    actions: { download: boolean } }
  ```
  `actions.download` is always false for admins (see Part 4).

## Part 2 — Viewer registry instead of one large component (frontend)

- `ConsultViewer` becomes a thin shell: toolbar, states (pending, failed, unsupported) and layout. It picks the body from a map `family → defineAsyncComponent(() => import('./viewers/XxxViewer.vue'))`. Code-splitting is required: the PDF page viewer is not downloaded to play an audio file.
- One file per family under `resources/js/components/consult/viewers/`: `PageImagesViewer.vue` (pdf, document, presentation), `SheetViewer.vue` (spreadsheet, csv), `ImageViewer.vue`, `VideoViewer.vue`, `AudioViewer.vue`, `TextViewer.vue`, `ArchiveViewer.vue` (only if Part 6 applies), `UnsupportedCard.vue`.
- Every viewer takes the same props (`descriptor`, `compact`) and emits the same events (`asset-expired` → the shell refetches descriptors). There are no family-specific props in the shell.
- No `v-html` in any viewer (keep the static test).

## Part 3 — On-demand generation and paging

- Opening a file whose consultation row is missing, or `failed` with a retryable reason, dispatches `GenerateConsultationDerivative` (it is already `ShouldBeUnique`, so repeated opens never queue duplicates). The page shows `pending` and polls as already specified. `vault:generate-previews` stays for bulk backfill.
- Spreadsheet and CSV derivatives are stored as pages of `vault.consult.sheet_page_rows` rows (default 500), one `consultation_assets` row per page via `page_index`. `SheetViewer` loads pages lazily as the user scrolls or changes page. The browser never holds a whole large sheet.
- `TextViewer`: line numbers, a wrap toggle, a copy button for the visible text, monospace, lazy rendering of long files. Native Ctrl+F is enough for search. No syntax highlighting.

## Part 4 — Download policy: explicit, per role, server-enforced

- Admin: no download of originals. This is a design decision, not a toggle: there is no config flag that enables admin downloads. `actions.download` is false for admins.
- Owner (author): keeps downloading their own originals through the existing ticket flow. Add `vault.download.owner_enabled` (env `VAULT_OWNER_DOWNLOAD_ENABLED`, default true). It is enforced server-side in ticket issuance AND redemption (403 when false), and exposed to the UI only through `actions.download`. Hiding a button is never the enforcement.
- Tests: owner allowed when true, 403 on both endpoints when false, admin 403 regardless of the flag.

## Part 5 — Details and Activity in the review page (admin)

Add tabs to `FileReview.vue`: **Preview** | **Details** | **Activity**.
- **Details:** filename, slot, format, verified MIME, size, deposit date, full SHA-256, oeuvre classification, preview status and reason.
- **Activity:** `file_access_logs` rows for this file (who, action, when, IP), and the oeuvre's review decisions. Server-paginated, newest first, read-only. It only reads; it never writes rows and never alters hash-chain fields.
- In compact mode (side Viewer) only the Preview tab exists.

## Part 6 — Archive listing (ONLY if the registry accepts zip; otherwise skip and say so)

- Derivative = JSON listing built with `ZipArchive` WITHOUT extraction: path, uncompressed size, compressed size, modified time.
- Caps: at most `vault.consult.archive_max_entries` entries (default 2000), with a notice beyond that. Paths are displayed as escaped text (no traversal semantics). Encrypted entries are flagged.
- Zip-bomb guard: a declared total uncompressed size over `vault.consult.archive_max_ratio` × the archive size is flagged, not opened.
- No nested archives, and no previews of entries inside the archive.
- `ArchiveViewer` shows a collapsible tree.

## Part 7 — Author side (reuse; separate gate — do it only after Parts 1–6 are green)

- `author/oeuvres/{oeuvre}/files/{mediaFile}/preview` (scoped bindings, owner policy) renders the same `ConsultViewer` with the same descriptor, for the author's own files.
- Assets use the same signed-URL middleware, generalised from "issuing admin" to "issuing user, who must be the owner or an admin". Keep every existing admin assertion passing.
- Author download stays on the existing ticket flow, governed by Part 4.
- Tests: an author cannot preview another author's file (404); the owner can.

## Owned files — touch nothing else
```
app/Infrastructure/Render/** (resolver + renderers), app/Actions/Consultation/**
app/Http/Controllers/Admin/OeuvreFileReviewController.php + consultation asset controller
app/Http/Controllers/Author/*Preview*  (Part 7), download ticket controller/action (Part 4 enforcement only)
app/Http/Middleware/EnsureConsultationAccess.php (or the existing equivalent)
app/Jobs/GenerateConsultationDerivative.php        ← paging + archive listing only
config/vault.php                                   ← consult.*, download.owner_enabled only
routes/admin.php, routes/author.php                ← preview routes only
resources/js/components/consult/**, resources/js/pages/admin/oeuvres/**, resources/js/pages/author/oeuvres/** (preview entry points only)
resources/js/types/consultation.ts, resources/js/locales/{ar,fr,en}.json
.env.example, README.md                            ← VAULT_OWNER_DOWNLOAD_ENABLED + scanner switch docs only
tests/** for the above
```
FROZEN — STOP and report instead of editing: `app/Domain/Vault/**`, `config/filesystems.php`, `app/Actions/Upload/**`, the chunk protocol, the processing chain order, the `Scanner` port.

## Acceptance gate (Parts 1–6, then again for Part 7)
1. The Step 0 report.
2. `php artisan test --compact`: 0 failures, before/after counts, every skip listed with its reason.
3. Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check`: all 0.
4. `composer ci:check` passes end to end. `npm run build`, with the per-viewer chunks shown in the build output.
5. A grep proving that no Vue file compares extensions, and no PHP file outside the resolver maps extensions or MIME types to viewers.
6. A one-screen manual script covering every family present in the database.
7. `git status --short`

Then STOP.

## Git policy — applies to this entire task
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
