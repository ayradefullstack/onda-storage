---
name: "source-command-admin-file-review"
description: "Migrated source command `admin-file-review`"
---

# source-command-admin-file-review

Use this skill when the user asks to run the migrated source command `admin-file-review`.

## Command Template

Task: full-page file review for admins. Open any deposited file in a new tab, and preview it in the side Viewer, whatever its format. Read AGENTS.md first.

Mode: DERIVATIVE only. The admin never receives original bytes, by any path, in any format. Every format is shown through a server-generated, encrypted derivative. There is no "original" mode in this task.

Baseline to hold: zero across eslint, prettier, vue-tsc, Pint, phpstan and the full Pest suite; `composer ci:check` passes end to end.

## Scope and reuse rule — read before anything else

This task adds three things: (1) a full review page opened in a new tab, (2) a Preview in the side Viewer of the oeuvre page, (3) previous/next navigation between files. Plus whatever renderers are missing so that every family in the table below is covered.

- REUSE and EXTEND whatever consultation Phase B already built: tables, job, renderers, serving controller, signed-URL middleware, viewers, backfill command. Do NOT rebuild, rename or replace any of it.
- Do NOT change the existing architecture to fit a viewer: no new port, no new disk, no change to the chain order beyond inserting the consultation job if it is absent, no change to the upload protocol.
- `app/Domain/Vault/**` stays FROZEN. If reading a derivative through HTTP Range proves impossible with the current vault reader, STOP and report with the evidence. Do not edit the vault yourself, even if you believe the change is small.

## Do NOT use the common Laravel "inline file" snippet

The usual pattern — an `<iframe>` pointing at `URL::temporarySignedRoute('files.view', …)` whose controller returns `Storage::disk(...)->response($path, $name, ['Content-Disposition' => 'inline'])` — must not be used here, for five reasons:

1. Vault files are AES-256-CTR ciphertext. `$disk->response()` streams ciphertext. Every vault read goes through the vault decrypt-Range reader (native fopen/fseek). Never `Storage::get`, `Storage::response`, `readfile` or `storage:link` on a vault path.
2. A temporary signed URL alone is a bearer token: anyone holding it can fetch the file until it expires. Our URLs are additionally bound to the authenticated admin whose id is inside the signature.
3. An inline PDF in an iframe opens the browser's native PDF viewer, which has download and print buttons.
4. Browsers cannot render docx/xlsx/pptx at all; setting `Content-Type` does not make Word viewable.
5. This is Inertia + Vue, not Blade. URLs are built server-side and passed as props.

Keep its good ideas: short-lived signed URLs, `Content-Disposition: inline`, and a full-page viewer.

## Step 0 — Report (no code)

1. Which parts of consultation Phase B already exist: `media_consultations`, `consultation_assets`, `GenerateConsultationDerivative`, renderers, the serving controller, viewers, `vault:generate-previews`, doctor WARNs. For each: present or absent, with path.
2. Tools on this machine, with version and absolute path: ffmpeg, ffprobe, pdftoppm, imagick (and its Ghostscript delegate), GD, LibreOffice `soffice`. Whether `phpoffice/phpspreadsheet` is installed.
3. Every extension in the format registry, each mapped to exactly one family in the table below. List any extension that fits no family.
4. Whether the Viewer selection bug is fixed (the selected file in the list must be the one shown in the Viewer).
5. A reuse plan: for each part of this task, "reuse as-is", "extend (what)", or "new (why it does not exist)".
6. Vault feasibility, only if no derivative is served through Range yet: prove that a derivative stored encrypted with the file DEK and a stored random nonce can be read back through HTTP Range with the current vault reader. Paste the proof.

Continue to the build unless something contradicts this brief, item 6 fails, or item 6 needs a frozen file; in those cases STOP and report.

## Rendering table (DERIVATIVE mode)

Pick the family from the registry category plus the finfo-verified MIME type, never from the client-supplied extension alone.

| family | extensions | derivative | tool | when the tool is missing |
|---|---|---|---|---|
| pdf | pdf | page images (WebP, 1600 px long edge), lazy-loaded; cap `vault.consult.pdf_max_pages` (default 300), with a notice "first N pages shown" beyond the cap | pdftoppm, else imagick + Ghostscript | unsupported card |
| document | doc, docx, odt, rtf | LibreOffice → PDF in the workspace → same as pdf | soffice | unsupported card |
| presentation | ppt, pptx, odp | same as document | soffice | unsupported card |
| spreadsheet | xls, xlsx, ods | **JSON** `{sheets:[{name, rows:[[…]]}]}` with cell values only (cached values for formulas; no hyperlinks, images or macros), capped by `vault.consult.sheet_max_rows` / `sheet_max_cols`; input over `vault.consult.sheet_max_bytes` falls back to the LibreOffice → PDF path | PhpSpreadsheet (pure PHP, works on cPanel without root) | LibreOffice path, else unsupported card |
| csv | csv | same JSON shape, parsed with `SplFileObject` (encoding normalised to UTF-8) | PHP | — |
| image | jpg, jpeg, png, webp, gif, bmp, tif, tiff | one resized WebP, EXIF stripped; first frame or first page only, with a note | imagick, else GD (GD: no tiff) | unsupported card |
| text | txt, md, json, xml, svg, srt, vtt | escaped plain text, UTF-8, capped at `vault.consult.text_max_bytes` (default 2 MB). SVG and XML are shown as source text, never rendered as markup | PHP | — |
| video | mp4, mov, avi, mkv, webm, … | 480p H.264/AAC MP4 with faststart, full length, plus a poster | ffmpeg | unsupported card |
| audio | mp3, wav, flac, aac, ogg, m4a, … | 128 kbps AAC/MP3 plus a waveform PNG | ffmpeg | unsupported card |
| other | zip, rar, 7z, exe, unknown | metadata card only | — | — |

Every unsupported or failed card states the exact reason in the author-facing language: "LibreOffice is not installed on this server", "this file is larger than the preview limit", "this format cannot be previewed".

If `phpoffice/phpspreadsheet` is absent you may add it with `composer require phpoffice/phpspreadsheet`. Report the version added. No other new Composer or npm packages.

## LibreOffice rules (the input is untrusted)

- `Process` with an argument array (never a shell string). Absolute binary path from `config('vault.binaries.soffice')`.
- Arguments: `--headless --norestore --nologo --nodefault --nolockcheck --convert-to pdf --outdir <workspace>`, plus a per-job profile `-env:UserInstallation=file:///<workspace>/lo-profile`. This avoids the single-instance lock and isolates settings between jobs. Never enable macros.
- Hard timeout `vault.consult.convert_timeout` (default 180 s). On timeout, kill the whole process tree.
- The intermediate PDF lives only in the PipelineWorkspace. It goes through the pdf renderer and is then deleted. It is never stored on the vault or variants disk.
- Conversions run only on the `media` queue. State whether you serialise soffice with a `Cache::lock`, and why.
- Local Windows: `winget install TheDocumentFoundation.LibreOffice`, binary `C:\Program Files\LibreOffice\program\soffice.exe`. cPanel needs root or a user-space install. Do not attempt any install on production; report it. Until then, spreadsheets use the PhpSpreadsheet path, and documents and presentations show the unsupported card.

## Pipeline

- `GenerateConsultationDerivative`: build it per the Phase B rules if absent, extend it if present. Position: after `GenerateVariants`, before `RecordDeposit`. It catches every Throwable, writes `media_consultations.status` and a short error, and never stops the chain. Queue `media`, `ShouldBeUnique` on the media file uuid, tries 2, backoff [60, 300], timeout below `retry_after`.
- Renderers: one `ConsultationRenderer` interface (`supports(family)`, `render(TempFile, workspace): RenderResult`) and one class per family under `app/Infrastructure/Render/`. These are infrastructure classes selected inside the job, not a new domain port. The project stays at exactly 4 ports.
- Derivatives are encrypted with the file DEK and a fresh, stored, random nonce. No plaintext derivative is ever written to the vault or variants disk.
- If `UploadLifecycleTest` needs a new expected order, update the order only. Keep `Bus::fake()`, the `chainJobs()` order assertion, and the assertion that `complete()` does no heavy work.
- Backfill: `php artisan vault:generate-previews {--uuid=} {--all} {--only-missing} {--family=} {--force}`. `--force` stores new assets (new nonce) first and deletes the old ones only after that succeeds. It is idempotent and never touches originals.
- `vault:doctor` WARNs (not FAILs) for missing soffice, PhpSpreadsheet, pdftoppm/imagick and ffmpeg, and for consultations stuck in `pending` longer than the job timeout.

## The review page (opens in a new tab)

- Route `GET /admin/oeuvres/{oeuvre}/files/{mediaFile}/review`, named `admin.oeuvres.files.review`, in `routes/admin.php`, with scoped bindings: a media file not belonging to the oeuvre returns 404. Controller `Admin/OeuvreFileReviewController@show`, page `pages/admin/oeuvres/FileReview.vue`. Same visibility rule as the admin oeuvre list, plus a policy check.
- Header: back link to the oeuvre, oeuvre reference and title, slot label, filename (bidi-isolated and middle-truncated so the extension stays visible), format, size, deposit date, full SHA-256 (copyable), preview status. Previous/next buttons across the oeuvre's files, with ← → keyboard shortcuts.
- Body, full height:
  - pdf, document, presentation: vertical page scroller; zoom (fit width, 100 %, 150 %); "page X / N"; jump-to-page.
  - spreadsheet, csv: sheet tabs, sticky header row, horizontal scroll inside the table only.
  - image: zoom and pan.
  - video, audio: player (with the waveform for audio).
  - text: monospace with line numbers.
- Entry points on the oeuvre page, per file:
  - "Open in new tab": a real `<a :href target="_blank" rel="noopener noreferrer">`, generated with Wayfinder (`--with-form`), so middle-click works.
  - "Preview": selects the file into the side Viewer.
- The side Viewer and the review page use ONE component, `ConsultViewer`, with a `compact` prop. No duplicated rendering logic.
- Selection fix: the selected file is highlighted in the list and is always the file shown in the Viewer. The default selection is the first file.
- States:
  - pending: poll every 5 s for up to 2 minutes through an Inertia partial reload (`only: ['consultation']`), then stop and say "still preparing, refresh later".
  - failed and unsupported: the card with its reason.
- Locales ar/fr/en. Reuse the upload UX bidi conventions.

## Serving

- The page receives asset descriptors (`kind`, `page_index`, signed `url`), never paths.
- Signed URL: `URL::temporarySignedRoute` with an `admin` parameter equal to the issuing user's id. The middleware checks, in order: authenticated; role admin; valid signature; not expired; `admin` == auth id; the asset belongs to a media file of a consultable oeuvre; consultation status `ready`. A URL copied into another admin's session fails.
- TTL `vault.consult.url_ttl` (default 30 min). For long video or audio playback, the viewer handles a 403 by fetching fresh descriptors from `GET …/review/assets` and resuming at the current position. Do not raise the TTL to hours instead.
- Streamed through the vault decrypt-Range reader, with HTTP Range support (206) for media.
- Headers:
  - `Content-Type` from a fixed map per derivative kind (`image/webp`, `image/png`, `video/mp4`, `audio/mpeg` or `audio/aac`, `application/json`, `text/plain; charset=utf-8`), never from the file itself.
  - `Content-Disposition: inline`
  - `X-Content-Type-Options: nosniff`
  - `Content-Security-Policy: sandbox; default-src 'none'`
  - `Cache-Control: private, no-store`
  - `Referrer-Policy: no-referrer`
- In the viewers: no `v-html` anywhere; no iframe, `<embed>` or `<object>` pointing at a file. Spreadsheet and text content are rendered with Vue's escaped interpolation only.
- Deterrents (not protection): context menu disabled on the viewer area; `draggable="false"` and `user-select: none` on page images; `controlsList="nodownload noplaybackrate"` and `disablePictureInPicture` on media.
- A CSS watermark overlay (admin email and current timestamp, low opacity, repeated) over page images and video, behind `vault.consult.watermark` (default true). It is an overlay, not burned into the derivative.
- Write this as a comment on the controller and repeat it in the report: this protects the original; a displayed derivative can still be captured from devtools or a screenshot.

## Audit

- Opening the review page writes one `admin_consult` row per file per window (state the window), through the existing `file_access_logs` writer.
- Issuing descriptors is logged once per file per window. Individual Range fetches are not logged.

## Original bytes stay out of the admin path

- No endpoint reachable by an admin streams, embeds or redirects to the original, inline or as an attachment.
- Unsupported and failed files show a metadata card. There is never a "download instead" fallback.
- The original stream and download tickets remain owner-only (Phase A). Do not loosen them.

## Tests

- Each family, with small fixtures deleted in `afterEach`: the derivative reaches `ready` and decrypts to a valid output. A test that needs a missing binary is skipped with a stated reason.
- A docx with soffice missing: `unsupported` with the LibreOffice reason, and the deposit still reaches `ready`.
- An xlsx through PhpSpreadsheet: JSON shape correct, caps enforced, a formula cell shows its cached value, a hyperlink is removed.
- A csv with Arabic content: UTF-8 preserved.
- An svg and an xml are served as `text/plain`; nothing is rendered as markup.
- A forced renderer exception: status `failed` and the chain still reaches `ready`.
- A LibreOffice timeout kills the process and marks `failed` (simulate with a fake binary).
- Review route: IDOR with a file from another oeuvre returns 404; non-admin 403; visibility parity with the admin list.
- Signed URL denied for another admin, after expiry, for an asset of another oeuvre, and when status is not `ready`.
- Range returns 206 with the correct bytes. Response headers exactly as listed.
- The SHA-256 of every admin-obtainable response body (review page, assets endpoint, every asset URL, side-viewer props) never equals the SHA-256 of the original.
- IDOR on assets: an asset uuid of a file from oeuvre B, requested under oeuvre A, returns 404 even with a valid signature for the same admin.
- An unsupported file exposes no URL at all in its descriptor.
- Backfill: idempotent; `--force` rotates the nonce.
- Vitest: `ConsultViewer` selection sync, compact vs full mode, pending poll stops after 2 minutes, no `v-html` in the viewer components (static assertion).

## Owned files — touch nothing else
```
routes/admin.php                                   ← review + assets routes only
app/Http/Controllers/Admin/OeuvreFileReviewController.php, consultation asset controller
app/Http/Middleware/EnsureConsultationAccess.php   (or the existing equivalent)
app/Jobs/GenerateConsultationDerivative.php
app/Infrastructure/Render/**
app/Actions/Consultation/**
app/Models/{MediaConsultation,ConsultationAsset}.php, MediaFile.php (relations only)
app/Console/Commands/{VaultGeneratePreviewsCommand,VaultDoctorCommand}.php
config/vault.php                                   ← consult.* and binaries.soffice only
database/migrations/**                             ← consultation tables only, if absent; no softDeletes
composer.json / composer.lock                      ← phpoffice/phpspreadsheet only, if absent
resources/js/pages/admin/oeuvres/**, resources/js/components/consult/**
resources/js/locales/{ar,fr,en}.json
tests/Feature/Consultation/**, tests/fixtures/consult/**, Vitest files for the above
tests/Feature/Pipeline/UploadLifecycleTest.php     ← expected order only
```
FROZEN — STOP and report instead of editing: `app/Domain/Vault/**`, `config/filesystems.php`, `app/Actions/Upload/**`, author routes, controllers and pages.

## Acceptance gate

1. The Step 0 report.
2. `php artisan test --compact`: 0 failures. Before/after counts; every skip listed with its reason.
3. `composer ci:check` passes end to end.
4. `npm run types:check`, `npm run lint:check`, `npm run format:check`: all 0.
5. `php artisan vault:generate-previews --all --only-missing` run on the existing deposits. Paste the per-family result (ready / unsupported / failed, with reasons).
6. A one-screen manual script: open an oeuvre → Preview a PDF in the side Viewer → Open in new tab → step through every file with ← → → confirm that no path downloads an original.
7. The honest paragraph on what this protects and what it does not.
8. `git status --short`

Then STOP.

## Git policy — applies to this entire task
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
