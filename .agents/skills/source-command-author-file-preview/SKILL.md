---
name: "source-command-author-file-preview"
description: "Migrated source command `author-file-preview`"
---

# source-command-author-file-preview

Use this skill when the user asks to run the migrated source command `author-file-preview`.

## Command Template

Task: author-side file preview. Same experience as the admin: side Viewer plus "Open in new tab". Read AGENTS.md first.

The admin consultation is done and works with no system tools (browser-native PDF, DOCX, video and audio; pure-PHP derivatives for the rest). This task gives the **author** the same preview for **their own files only**, by reusing that module. No new rendering, no new delivery map, no second viewer.

Baseline to hold: `composer test` (Pint, PHPStan, Pest) green; Vitest, `npm run types:check`, `npm run format:check` all 0. `npm run lint:check` follows the separate ESLint fix: if that has not landed yet, lint your own files with the repo config once it works, and report.

## Rules
- **Reuse:** `ConsultViewer`, the viewer registry, `ConsultationDescriptor`, `PreviewFamilyResolver::deliveryMode()`, `ConsultOriginalController`, `ConsultationAssetController`, `ConsultRange`, `EnsureConsultationAccess`. Generalise; never copy.
- The author sees ONLY files of oeuvres they own. Another author's file returns 404 (IDOR), never 403. That way a probe does not even confirm the file exists.
- The admin behaviour and every admin test stay exactly as they are.
- No new Composer or npm packages. No system binaries.

## Step 0 — Report (no code)
1. Where the author sees their files today: pages, components (`DepositCard`, `MediaPreviewDialog`, …), and which endpoint each preview uses (media.link / media.stream?).
2. How `EnsureConsultationAccess`, `IssueAssetUrl` and `IssueOriginalUrl` bind a signed URL to the "issuing admin", and what must change to bind it to the "issuing user" instead.
3. Whether owner access to media.stream is audited today, and how. Owner previews follow that SAME convention; do not invent a new one.
4. Whether opening a file with no consultation row dispatches the job on demand (Gate 2), or not.

Continue unless something contradicts this brief; then STOP and report.

## Part 1 — Access: from "issuing admin" to "issuing user"
- Signed URLs carry the issuing user's id (rename the parameter if needed and keep compatibility within the same deploy). The middleware checks, in order:
  - authenticated;
  - signature valid and not expired;
  - the parameter equals the auth id;
  - the auth user is an admin (existing rule, unchanged), OR the auth user is the **owner** of the oeuvre the file belongs to;
  - the file belongs to that oeuvre (scoped binding);
  - consultation status is `ready`;
  - the delivery mode allows the requested kind.
- An author on someone else's file gets 404. A URL issued to author A and replayed by author B is denied.
- The owner may receive the inline original for the four `original_inline` families. They own the file, and can already download it through media.link / media.stream. Headers stay exactly as for the admin: `inline`, never `attachment`, plus `no-store`, `nosniff` and the CSP sandbox.
- `MediaFilePolicy::streamOriginal` stays owner-only and untouched (frozen).

## Part 2 — Author routes and pages
- In `routes/author.php` (with role:author and scoped bindings):
  - `GET author/oeuvres/{oeuvre}/files/{mediaFile}/preview` → full preview page (new tab);
  - plus the descriptor / asset-refresh endpoint the viewer needs (mirror the admin ones, through the same actions).
- Controller: `Author/OeuvreFilePreviewController`, with an owner policy check.
- **No duplicated page code.** Extract the body of `admin/oeuvres/FileReview.vue` into a shared `components/consult/FileReviewLayout.vue` (header, prev/next with ← →, viewer, tabs). `admin/oeuvres/FileReview.vue` and the new `author/oeuvres/FilePreview.vue` become thin wrappers that pass routes and which tabs to show.
- Tabs for the author: **Preview** and **Details** only: filename, slot, format, size, deposit date, full SHA-256 (copyable, this is their deposit receipt), and preview status. **No Activity tab**: the audit log is admin-only.
- Watermark: admin only by default (`vault.consult.watermark_for_owner`, default false). The author is viewing their own work.
- The author's oeuvre page, on each deposited file:
  - **"Aperçu"** opens the side Viewer (the same `ConsultViewer` in compact mode, with the selected file highlighted and always in sync);
  - **"Ouvrir dans un nouvel onglet"**: a real `<a target="_blank" rel="noopener noreferrer">` built with Wayfinder.
- Files still uploading or processing: show the existing status, no preview button. Pending consultation: the existing poll.
- Previews work in every oeuvre status (draft, submitted, under_review, registered, rejected). A preview is read-only and does not touch the submitted freeze.
- `MediaPreviewDialog`: if the new side Viewer covers everything it does, replace it and delete it, together with its now-dead locale keys and tests. If it does something the Viewer does not, report it and keep it. One preview system, not two.
- Locales ar/fr/en. References, dates and filenames in `<bdi dir="ltr">`, using the existing `formatDate` that strips RLM marks.

## Part 3 — Tests
- Owner: preview page 200; descriptor issued; inline original served for pdf, docx, mp4 and mp3; derivative assets served for jpg, xlsx and pptx; Range returns 206; headers exact.
- Another author: the preview page, the descriptor, every asset and the original all return 404. URLs issued to the owner and replayed by another author are denied. Expired URL: denied. Consultation not ready: denied.
- An admin opening the author route: denied by role:author (state the status code). Admins keep using the admin route.
- Every existing admin test unchanged and green, including NoOriginalForAdminTest and the media.link / media.stream 403 tests for admins.
- No Activity data is ever present in the author page props (assert it).
- Vitest: the shared layout renders admin tabs vs author tabs; the watermark is hidden for the owner by default; the side Viewer selection stays in sync on the author page.

## Owned files — touch nothing else
```
routes/author.php                                  ← preview routes only
app/Http/Controllers/Author/OeuvreFilePreviewController.php (new)
app/Http/Middleware/EnsureConsultationAccess.php
app/Actions/Consultation/{IssueAssetUrl,IssueOriginalUrl,ConsultationDescriptor,AuditConsultation}.php
app/Policies/OeuvrePolicy.php (or the existing owner policy) ← preview ability only, if one is needed
config/vault.php                                   ← consult.watermark_for_owner only
resources/js/components/consult/**                 ← FileReviewLayout extraction
resources/js/pages/admin/oeuvres/FileReview.vue    ← becomes a thin wrapper, no behaviour change
resources/js/pages/author/oeuvres/**               ← FilePreview.vue and the entry points
resources/js/components/media/**, resources/js/components/upload/DepositCard.vue ← MediaPreviewDialog replacement only
resources/js/locales/{ar,fr,en}.json
tests/** for the above
```
FROZEN — STOP and report instead of editing: `app/Domain/Vault/**`, `app/Actions/Upload/**`, `app/Policies/MediaFilePolicy.php`, the media.link / media.stream behaviour, the delivery map, the processing chain, the admin routes.

## Acceptance gate
1. The Step 0 report.
2. `composer test`: green, with before/after counts and every skip listed with its reason.
3. Vitest, `npm run types:check`, `npm run format:check`: 0. Lint as described above.
4. `npm run build`.
5. A manual script, run with ffmpeg, pdftoppm and soffice blanked in `.env` as before:
   - author A opens their oeuvre → Aperçu on a PDF (search works) → "Ouvrir dans un nouvel onglet" → steps through a DOCX, an MP4, an MP3, a JPG, an XLSX and a PPTX with ← →;
   - then author B pastes one of A's preview URLs and an asset URL: 404 / denied.
6. `git status --short`

Then STOP.

## Git policy — applies to this entire task
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- Stop only processes you started (record their PIDs). Never kill all php.exe.
- End by printing `git status --short`.
