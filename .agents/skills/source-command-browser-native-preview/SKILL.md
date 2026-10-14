---
name: "source-command-browser-native-preview"
description: "Migrated source command `browser-native-preview`"
---

# source-command-browser-native-preview

Use this skill when the user asks to run the migrated source command `browser-native-preview`.

## Command Template

Task: browser-native file preview. No system tools on the production server. Read AGENTS.md first.

## Why this task exists

Production is cPanel/CloudLinux. The hosting team will not install ffmpeg, poppler or LibreOffice: they are unsupported by cPanel, and CloudLinux limits would kill heavy encodes anyway. The consultation module built so far depends on those tools for PDF, video, audio and Office previews, so on production those files show "unsupported".

Decision (approved by the product owner): admins are sworn, authenticated ONDA officials. For **PDF, DOCX, video and audio**, the admin's browser may receive the **decrypted original**, streamed inline, and render it client-side. Every other family keeps its server-side derivative, produced in **pure PHP**.

**Hard rule: production needs NO binary beyond standard cPanel PHP extensions (gd, zip, fileinfo, openssl, mbstring, xml).** Allowed new packages: npm `pdfjs-dist` and `docx-preview`, nothing else. No new Composer packages.

Baseline to hold: zero across eslint, prettier, vue-tsc, Pint, phpstan and the full Pest suite; `composer ci:check` passes end to end.

## Delivery map (one source of truth)

Add `config('vault.consult.delivery')`, a map from family to mode, read ONLY by the existing `PreviewFamilyResolver` / descriptor builder:

| family | mode | how it is shown |
|---|---|---|
| pdf | `original_inline` | PDF.js on canvas, plus a text layer (search and select), lazy pages, Range loading |
| document: docx only | `original_inline` | `docx-preview` rendered into a sandboxed iframe |
| document: doc, odt, rtf | `unsupported` | card: "aperçu indisponible pour ce format" |
| video | `original_inline` | native `<video>` with Range |
| audio | `original_inline` | native `<audio>` with Range |
| image | `derivative` | GD resize + EXIF strip (exists) |
| spreadsheet, csv | `derivative` | PhpSpreadsheet / CSV to JSON pages (exists) |
| text, svg, xml | `derivative` | escaped text (exists) |
| presentation: pptx | `derivative` | NEW: slide text outline extracted with ZipArchive (see below) |
| presentation: ppt, odp | `unsupported` | card |
| archive | `derivative` | zip listing, if built in Gate 2 |
| other | `unsupported` | card |

The existing ffmpeg, pdftoppm and soffice renderers stay in the code but are used ONLY if a family is explicitly set to `derivative` in config. Default production config never selects them. Do not delete them.

## Step 0 — Report (no code)
1. What exists from Gate 1 and Gate 2: the resolver, the descriptor, the viewer registry, `ConsultViewer`, `EnsureConsultationAccess`, the asset controller (Range), the renderers, `FileReview.vue`, the watermark, the audit window. With paths. Reuse all of it; build only the gap.
2. Where each family's mode is decided today. Everything must move to the delivery map.
3. Every test that asserts an admin never receives original bytes (including the SHA-256 test and the media.link / media.stream 403 tests). List them; Part 4 changes the SHA-256 test deliberately.
4. Which video and audio MIME types the format registry accepts.

Continue unless something contradicts this brief; then STOP and report.

## Part 1 — Server: inline original stream for the four families

- New route `GET /admin/consult/{mediaFile}/original` (or extend the existing asset controller with an `original` kind; choose the smaller change and say why). Protected by the SAME `EnsureConsultationAccess`: authenticated admin; signed; not expired; `admin` parameter equals the auth id; the media file belongs to a consultable oeuvre (IDOR returns 404); consultation status `ready`; and the family's mode is `original_inline` (otherwise 404).
- It streams through the existing vault decrypt-Range reader: native fopen/fseek plus CtrCipher on the requested slice only. It never loads the whole file and never uses `Storage::get` or `readfile`. Range returns 206, plus `Accept-Ranges: bytes`.
- Headers:
  - `Content-Type`: the media file's verified stored MIME from the format registry; only MIME types of the four families are allowed, anything else is 404.
  - `Content-Disposition: inline; filename="consultation.<ext>"`. Never `attachment`.
  - `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, `Content-Security-Policy: sandbox; default-src 'none'`.
- `media.link` and `media.stream` stay owner-only. The owner download path is untouched. This endpoint is consultation, not download.
- Audit: descriptor issuance is logged once per file per window, as already done, with `mode = original_inline` in the row context, so original exposures are distinguishable in the trail. Range fetches are not logged individually.
- The pipeline: for `original_inline` families, `GenerateConsultationDerivative` writes `status = ready`, `kind = original_inline` and creates NO assets. It must not decrypt anything.
- Backfill: `php artisan vault:generate-previews --all --force` converts existing `unsupported (tool_missing)` rows of these families to `ready`.

## Part 2 — Server: pptx slide outline (pure PHP)

- `PptxOutlineRenderer`: open the decrypted temp file with ZipArchive. Read `ppt/slides/slide{N}.xml` in numeric order and extract `a:t` text runs per slide. Also read speaker notes (`ppt/notesSlides`) if present.
- Parse XML safely: no network access, no external entities (`LIBXML_NONET`; never `LIBXML_NOENT`).
- Caps: max slides `vault.consult.pptx_max_slides` (default 300), max total text 2 MB.
- Output JSON `{slides: [{index, texts: [], notes: []}]}`, stored as an encrypted asset like the other JSON derivatives.
- The viewer shows a numbered slide list with the text of each slide. A notice says this is a text outline, not a visual rendering.

## Part 3 — Client viewers (in the existing viewer registry)

**PdfViewer** (`pdfjs-dist`):
- Serve the worker from our own build (Vite `?url` import). No CDN.
- `getDocument({ url, isEvalSupported: false, enableXfa: false, disableAutoFetch: true, rangeChunkSize: 1 MiB })`, so large PDFs load progressively over Range.
- Render pages lazily on canvas. Add a text layer for selection and in-document search (search box: next/previous, match count).
- Zoom (fit width, 100 %, 150 %), page X / N, jump-to-page.
- **No download, no print, no "open file" controls**: build our own toolbar; never use the pdf.js default viewer UI. Block Ctrl+P / Ctrl+S inside the viewer (deterrent only).
- Annotation links: never open `javascript:` URLs; external links open with `noopener noreferrer` after a confirm, or are disabled. State which.

**DocxViewer** (`docx-preview`):
- Fetch the signed URL as a blob and render into an **iframe with `sandbox="allow-same-origin"` and WITHOUT `allow-scripts`**. Parent code writes into `iframe.contentDocument`; nothing inside the iframe can execute.
- Disable external resources: the iframe document gets a CSP meta `default-src 'none'; img-src blob: data:; style-src 'unsafe-inline'`.
- Hyperlinks rendered inert.

**VideoViewer / AudioViewer**:
- `<video>` / `<audio>` with the signed URL as `src`, `preload="metadata"`, `controlsList="nodownload noplaybackrate"`, `disablePictureInPicture`. Context menu disabled on the viewer area.
- Duration and dimensions are read from the element (no ffprobe needed).
- On a media `error` event (unsupported codec, e.g. HEVC MOV in a browser without HEVC, or AVI), show a card: "Ce format vidéo n'est pas lisible par ce navigateur" with the codec hint if known. Never fall back to a download.
- Signed URL expiry during long playback: reuse the existing refresh-on-403 flow, resuming at `currentTime`.

**All four:**
- The existing watermark overlay (admin email and timestamp) covers the canvas, the iframe and the video.
- Pending, failed and unsupported states keep their reasons.
- Locales ar/fr/en.

## Part 4 — Tests (update deliberately, never weaken)

- **SHA-256 test, changed and visible in the diff:**
  - for derivative families, every admin-obtainable response body still never equals the original's SHA-256;
  - for `original_inline` families, a body may equal the original ONLY when served by the consult original endpoint, with `inline` and never `attachment`.
  - Add a negative test: no other route returns original bytes to an admin.
- `media.link` and `media.stream` still return 403 for admins. Unchanged.
- Consult original endpoint:
  - another admin's signature: denied;
  - expired: denied;
  - a file of another oeuvre: 404;
  - consultation not ready: denied;
  - a family in `derivative` or `unsupported` mode: 404;
  - a non-admin: denied.
- Range returns 206 with the correct bytes. A Range read on a large file stays under the existing memory ceiling. Headers exactly as specified.
- The pipeline creates no assets and performs no decryption for `original_inline` families. The backfill flips `tool_missing` rows to `ready`.
- PptxOutlineRenderer: a fixture pptx gives the right slide order and texts. An XXE payload in a slide is not expanded. Caps are enforced.
- Vitest:
  - the PDF.js options include `isEvalSupported: false`;
  - the worker URL is local;
  - the docx iframe has `sandbox` without `allow-scripts`;
  - no `v-html` anywhere in the viewers;
  - a video `error` event renders the card;
  - the 403 refresh resumes playback position.
- `vault:doctor`:
  - ffmpeg, pdftoppm and soffice become INFO ("optional, used only if a family is configured as derivative"), not WARN;
  - a new INFO line prints the effective delivery map.

## Part 5 — Author guidance (small)

On upload slots that accept video, show one line: "Pour une consultation fluide, privilégiez le format MP4 (H.264)." Locales ar/fr/en. No validation change.

## Owned files — touch nothing else
```
config/vault.php                                    ← consult.delivery, pptx caps only
app/Infrastructure/Render/** (resolver, PptxOutlineRenderer)
app/Actions/Consultation/**, app/Jobs/GenerateConsultationDerivative.php
app/Http/Controllers/Admin/ConsultationAssetController.php (or a new ConsultOriginalController)
app/Http/Middleware/EnsureConsultationAccess.php     ← mode check only
routes/admin.php                                    ← the consult original route only
app/Console/Commands/VaultDoctorCommand.php          ← INFO lines only
resources/js/components/consult/**, resources/js/pages/admin/oeuvres/**
resources/js/components/upload/**                    ← the MP4 hint line only
resources/js/types/consultation.ts, resources/js/locales/{ar,fr,en}.json
package.json / package-lock.json                    ← pdfjs-dist and docx-preview only
tests/** for the above, tests/fixtures/consult/**
```
FROZEN — STOP and report instead of editing: `app/Domain/Vault/**`, `config/filesystems.php`, `app/Actions/Upload/**`, `app/Policies/MediaFilePolicy.php` (`streamOriginal` stays owner-only), `media.link` / `media.stream`, the processing chain order.

## Acceptance gate
1. The Step 0 report.
2. `php artisan test --compact`: 0 failures, before/after counts, every skip listed with its reason.
3. Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check`: all 0.
4. `composer ci:check` passes end to end. `npm run build`, with the PDF.js worker and the viewers as separate chunks.
5. Run with ffmpeg, pdftoppm and soffice REMOVED from `.env` (simulating production): `vault:doctor` shows 0 FAIL; `vault:generate-previews --all --force`; then manually confirm that a PDF (search works), a DOCX, an MP4, an MP3, a JPG, an XLSX and a PPTX all display. Paste the per-family results.
6. An honest paragraph: for PDF, DOCX, video and audio, the original reaches the admin's browser and can be saved through devtools; the trail records every consultation. Everything else remains derivative-only.
7. `git status --short`

Then STOP.

## Git policy — applies to this entire task
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
