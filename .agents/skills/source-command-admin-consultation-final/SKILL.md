---
name: "source-command-admin-consultation-final"
description: "Migrated source command `admin-consultation-final`"
---

# source-command-admin-consultation-final

Use this skill when the user asks to run the migrated source command `admin-consultation-final`.

## Command Template

Task: admin consultation of an oeuvre. Every file viewable in the browser, none downloadable. Read AGENTS.md first.

PHASE = A

Run Phase A only. Phase B starts only when I re-send this prompt with `PHASE = B`. If PHASE is A, do not build anything listed under Phase B.

Baseline to hold: zero across eslint, prettier, vue-tsc, Pint, phpstan and the full Pest suite; `composer ci:check` passes end to end. Hold all of it.

## Core design (non-negotiable)

D1. An admin consults DERIVATIVES, never the original. The original ciphertext and its plaintext stream are never reachable by an admin session.
D2. PDF is shown as page images (a browser PDF viewer has its own download button — we do not use it). Images, audio and video are served as web-safe derivatives. Anything else gets a metadata card, not a file.
D3. This is original-protection, not DRM. A derivative shown in a browser can still be extracted from devtools. State this honestly in the final report and in a code comment on the controller. Do not claim more.
D4. Every consultation session is audited (who, which oeuvre, which file, when, from which IP).

## Part 0 — Report before any code

Report all of the following from reading the code, with file and line references:

1. **Chain semantics (A1).** Prove from the code, not from memory, how `Bus::chain` behaves in this project when a job throws or calls `fail()`: does the chain stop, do later jobs run, does `failed()` fire on the failing job only? Your new job (Phase B) must catch all of its own failures and never stop the chain — state how that is guaranteed.
2. The exact admin oeuvre list visibility rule (A2). Which statuses does an admin see in `/admin/oeuvres` today? The consultation page MUST reuse that rule. Do NOT invent a new draft-visibility rule.
3. Every route, controller method and ticket mechanism that can currently deliver original bytes or a download to anyone: original stream, download tickets, variants endpoint. List who may call each.
4. Current state of `UploadLifecycleTest` and `chainJobs()` expected order.

Then continue to Phase A without waiting, unless Part 0 item 3 shows something that contradicts this design — in that case STOP and report.

## Authorization rules

A2. Visibility of an oeuvre on the consultation page = exactly the admin list rule found in Part 0 item 2. No second rule.
A3. IDOR: every consultation endpoint resolves the oeuvre first, authorizes the admin against THAT oeuvre, then resolves the media file THROUGH that oeuvre (`$oeuvre->mediaFiles()->where('uuid', …)`). A media file uuid from another oeuvre returns 404. Never resolve a media file globally.
A4. Signed URLs for derivative assets (Phase B) are valid only when ALL hold: user authenticated; user is admin; signature valid and not expired; the issuing admin's id (embedded in the signed parameters) equals the current user id; the asset belongs to a consultable oeuvre by A2/A3; the `media_consultations` row status is `ready`. A URL copied to another admin's session must fail.

## Phase A — page, authorization, closing paths to the original, tables

A-1. **Page** `admin/oeuvres/{oeuvre}` (consult). Controller under `Admin/`, page under `pages/admin/`, route in `routes/admin.php`. Shows:
- Classification: type d'adhésion, gestion, collège, quality, role d'auteur — from the stored ids; show `code_college_snapshot` and flag clearly when the snapshot differs from the live college code.
- Oeuvre data and author identity.
- The document slots of the college, each with its files (filename, format label, size, status, uploaded-at). Slots with no file are shown as missing; required-unconditional ones are marked.
- The SHA-256 fingerprint in full, copyable, per file when it exists.
- Status and review history (reuse existing review components; do not rebuild).
In Phase A the viewer area shows a metadata card only ("preview not yet available") — no bytes of any kind.

A-2. **Close every path to the original for admins.**
- The original plaintext stream endpoint becomes owner-only: an admin (non-owner) gets 403. Verify the policy, not only the route middleware.
- Download tickets become owner-only: an admin cannot create or redeem one.
- Variants endpoint: admin access only if the variant is a derivative already designated for consultation; otherwise 403. If in doubt, 403.
- No admin-reachable response may contain original bytes, and none may set `Content-Disposition: attachment` for an original.
- Do not break the author's own access; author tests must stay green.

A-3. **Audit.** One row per consultation session in `file_access_logs` (reuse; keep hash-chain fields consistent with the existing writer — use the existing writer, do not hand-write rows) with action `admin_consult`, admin id, oeuvre id, media file id, ip. Opening the page logs once per oeuvre per session window (state your window, e.g. 10 minutes); each file preview logs once per file per window. No log spam on re-render.

A-4. **Two new tables** (migrations; conventions: id + uuid UUIDv7, NO softDeletes, NO soft-delete trait — add the tests asserting both column and trait absence, as for the other forbidden tables):
- `media_consultations`: media_file_id (FK), status (`pending|ready|failed|unsupported`), kind, page_count nullable, error nullable, generated_at nullable, timestamps. Unique on media_file_id.
- `consultation_assets`: media_file_id (FK), kind, page_index nullable, path, nonce (random, stored), size_bytes, meta (json nullable), timestamps. Unique (media_file_id, kind, page_index).
Models in `app/Models` (this task owns these two only). Relationships on MediaFile. No behaviour yet.

### Phase A tests
- Admin can open the page for every oeuvre the admin list shows; cannot for any it does not (A2 parity test iterating all statuses).
- IDOR: media file uuid of oeuvre B under oeuvre A's URL → 404.
- Non-admin author of another oeuvre → 403/404 as the existing policy dictates.
- Original stream: owner 200, admin 403, other author 403/404.
- Download ticket: owner can, admin cannot.
- For EVERY response an admin can obtain on this feature (page JSON/HTML, variants, any asset endpoint that exists in this phase), compute SHA-256 of the body and assert it never equals the SHA-256 of the original plaintext. Use a small fixture; the test must fail if any admin endpoint ever streams the original.
- Audit rows written once per window, with correct fields.
- Migration tests: both tables exist, no `deleted_at`, models have no SoftDeletes.
- `UploadLifecycleTest` untouched.

## Phase B — derivatives pipeline, viewers, serving (only when PHASE = B)

B-0. **Plan gate, then vault feasibility stop (Part 4.2).** Before building, deliver a short plan and WAIT for approval. Then, before writing the job, prove on this machine that a derivative can be produced from a decrypted temp file and stored encrypted under a fresh random nonce with the file DEK, and read back through Range. If anything about the vault contract prevents that (e.g. derivative storage would need a change in `app/Domain/Vault/**`, which is FROZEN), STOP and report. Do not edit frozen files.

B-1. **Rendering table** (state it in the plan):
| input | derivative | tool |
| PDF | page images (WebP/PNG, max 1600 px long edge, cap on pages: state it) | pdftoppm / imagick, whichever exists; absent → `unsupported` |
| image | one web-safe resized image (EXIF stripped) | GD/imagick |
| video | 480p H.264/AAC MP4 preview (full length unless config cap says otherwise) | ffmpeg; absent → `unsupported` |
| audio | MP3/AAC preview + waveform | ffmpeg; absent → `unsupported` |
| pptx/docx/other | `unsupported` metadata card only (no office conversion in this task) | — |
Input is untrusted: convert via `Process` with argument arrays (never a shell string), absolute binary paths from config, hard timeouts, memory/time limits, no network, output to the deterministic PipelineWorkspace path, and strip all metadata. SVG/XML are never rendered or passed through — `unsupported`.

B-2. **Job `GenerateConsultationDerivative`.** Position: after `GenerateVariants`, before `RecordDeposit`. It must catch every Throwable, write `media_consultations.status = failed|unsupported` with a short error, and return normally so the chain continues (per A1 proof). A failed derivative never fails a deposit. Rules as other jobs: queue `media`, `ShouldBeUnique` on the media file uuid, tries 2, backoff [60,300], timeout 600 (below `retry_after`). Derivatives are encrypted with the file DEK and a stored random nonce (never a deterministic nonce) and written with the existing variants mechanism. No plaintext derivative on the vault disk.
Update `UploadLifecycleTest` expected chain order to include the job. Do NOT weaken it: keep `Bus::fake()` and the `chainJobs()` order assertion, and keep the assertion that `complete()` dispatches the chain and does no heavy work.

B-3. **Viewers** (Vue, in `pages/admin` / `components/consult`): PDF page-image scroller; image viewer; `<video>` / `<audio>` with `controlsList="nodownload noplaybackrate"`, `disablePictureInPicture`, `oncontextmenu` blocked on viewers; metadata card for unsupported/failed/pending with a clear reason. Locales ar/fr/en, bidi-isolated filenames and Latin runs (reuse the upload UX conventions). Say nothing in the UI that implies the content cannot be copied.

B-4. **Serving.** One controller streams a derivative asset through the vault decrypt-Range reader (native fopen/fseek; never `Storage::get`, never `readfile` on a vault file, never `storage:link`). Headers: `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox; default-src 'none'`, `Cache-Control: private, no-store`, correct `Content-Type` from a fixed map (never from the file). Access by signed URL per A4. Honour HTTP Range for audio/video.

B-5. **Audit per session** (A-3 extended): each signed-URL issuance logs once per file per window.

B-6. **Backfill** `php artisan vault:generate-previews {--uuid=} {--all} {--only-missing}` for files deposited before this feature; queued on `media`; idempotent; never touches originals.

B-7. **Doctor.** `vault:doctor` gains WARNs (not FAILs) for missing pdftoppm/imagick, ffmpeg, and for `media_consultations` rows stuck in `pending` older than the job timeout.

### Phase B tests
- Fixture PDF/image/MP4 (small, deleted in `afterEach`; skip with stated reason when a binary is absent) → derivative `ready`, asset stored encrypted, decrypts to a valid image/MP4.
- Derivative failure (forced) → `failed`, chain still reaches `ready`, deposit unaffected.
- Nonce stored and random: regenerating a derivative produces a different nonce and different ciphertext.
- Signed URL: another admin → denied; expired → denied; asset of another oeuvre → 404; status not `ready` → denied; non-admin → denied.
- Range request returns 206 with correct bytes.
- Response headers exactly as B-4.
- SHA-256 of every admin-obtainable response body ≠ SHA-256 of the original (extend the Phase A test to the new endpoints).
- Backfill idempotent.

## Owned files — touch nothing else
```
routes/admin.php                              ← consult routes only
app/Http/Controllers/Admin/**                 ← consultation controllers only
app/Policies/** (media/oeuvre access)         ← only to close admin access to originals
app/Jobs/GenerateConsultationDerivative.php   (Phase B)
app/Actions/Consultation/**                   (new)
app/Infrastructure/Render/**                  (new, Phase B)
app/Models/MediaConsultation.php, ConsultationAsset.php, MediaFile.php (relations only)
app/Console/Commands/VaultGeneratePreviewsCommand.php, VaultDoctorCommand.php (Phase B)
config/vault.php                              ← consultation keys only
database/migrations/**                        ← the two new tables only
resources/js/pages/admin/**, resources/js/components/consult/**, resources/js/locales/{ar,fr,en}.json
tests/Feature/Consultation/*, tests/Feature/Pipeline/UploadLifecycleTest.php (order update only, Phase B)
```
FROZEN — if a change is needed, STOP and report:
```
app/Domain/Vault/**   config/filesystems.php   app/Actions/Upload/**   author routes/controllers/pages
```

## Acceptance gate (per phase)
1. Part 0 report (Phase A) including the A1 chain proof with code references.
2. `php artisan test --compact` — 0 failures; state before/after counts and list every skip with its reason.
3. `composer ci:check` — passes end to end.
4. `npm run types:check`, `npm run lint:check`, `npm run format:check` — all 0.
5. The SHA-256 "never equals the original" test shown passing.
6. A manual check script, one screen: log in as admin, open an oeuvre, confirm no download path to an original exists.
7. An honest paragraph on D3: what this protects and what it does not.
8. `git status --short`

Then STOP.

## Git policy — applies to this entire task
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
