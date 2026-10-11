---
name: "source-command-admin-review"
description: "Migrated source command `admin-review`"
---

# source-command-admin-review

Use this skill when the user asks to run the migrated source command `admin-review`.

## Command Template

# Admin review — two tasks, in this order

## Before either: two blockers

**Uploads do not currently work.** Chunk zero never reaches the server for any file, of any
size. Until that is fixed there is nothing real to review, and an admin console built
against seeded fixtures will miss whatever the real data looks like.

**Preview is impossible today.** Every stored byte is AES-256-CTR encrypted, and no
decrypting read path exists — that is Task 1 below. An admin console without it can list
files and show metadata, but cannot open a single one.

So: fix chunk zero, then Task 1, then Task 2. Task 2 alone would produce a console whose
central function does not work.

---

# TASK 1 — The decrypting read path (Phase 6)

Read AGENTS.md first. Baseline is zero across eslint, prettier, vue-tsc, Pint, phpstan and
the full Pest suite, and `composer ci:check` passes end to end. Hold all of it.

## Two architecturally separate flows — do not merge them into one controller

**Flow A — stream and preview (~95% of use).** PHP decrypts on the fly with Range support.
Occupies a worker for seconds, because a reviewer watches thirty seconds, not five hours.

**Flow B — full download (~5%).** A queued job decrypts to `work/`, then hands the bytes to
the `Delivery` driver. Never stream 5 GB through PHP: on a slow link that pins a PHP-FPM
worker for an hour, and ten concurrent downloads would take the server down.

## Owned files

```
app/Http/Controllers/Media/{StreamController,DownloadController,VariantController}.php
app/Actions/Media/{IssueStreamUrl,RequestDownload,PrepareDownload}.php
app/Infrastructure/Delivery/{StreamDelivery,LiteSpeedDelivery,XSendFileDelivery}.php
app/Jobs/PrepareDownloadJob.php
app/Domain/Access/{SignedMediaUrl,AccessLogger}.php
database/migrations/*_create_download_tickets_table.php
app/Models/DownloadTicket.php
resources/js/components/media/{VideoPlayer,AudioPlayer,PdfViewer,DownloadButton}.vue
routes/web.php                        ← the media group only
tests/Feature/Media/*
```

FROZEN — if you need a change here, STOP and report:
`app/Domain/Vault/**`, `app/Actions/Upload/**`, existing migrations.

## Flow A — streaming

`GET /media/{mediaFile:uuid}/stream`, middleware `['auth','signed','throttle:media']`.

Four checks, in this order, all required:

1. `hasValidSignature()`
2. `authorize('view', $mediaFile)` — the author owns the work, or the user is an admin
3. `(int) $request->query('u') === auth()->id()` — binds the link to the session that issued
   it, so a leaked URL is useless to anyone else
4. `AccessLogger::record(...)` — before the bytes are sent, not after

Then parse `Range`, align the start down to a 16-byte AES block boundary, call
`VaultContract::readRange`, and return 206 with correct `Content-Range`, `Accept-Ranges`,
`Content-Length` and the stored mime type. No Range header → 200 with the full stream.
Unsatisfiable Range → 416.

`readRange` already verifies only the MAC segments covering the requested bytes, so seeking
in a 5 GB video must not read 5 GB. Confirm that holds end to end and say how you verified it.

Signed URLs live 15 minutes. A player must handle expiry mid-playback by requesting a fresh
URL — build that into the player component rather than extending the TTL.

## Flow B — full download

Three steps, never one:

1. `POST /media/{uuid}/download` → authorize, create a `DownloadTicket`
   (uuid, media_file_id, user_id, status, path, expires_at), dispatch `PrepareDownloadJob`,
   return the ticket uuid.
2. The job decrypts to `work/{random}.tmp` with 0600 permissions, marks the ticket ready,
   notifies the user.
3. `GET /downloads/{ticket:uuid}` → signature, ownership, session binding, logging — then
   hand off to the `Delivery` driver.

Ticket TTL 30 minutes. Scheduled cleanup deletes expired tickets and their temp files.

This creates a bounded window where plaintext exists on disk. That is deliberate; document
it in a comment stating the window, the permissions, and what deletes it.

## Delivery drivers

- `StreamDelivery` (local) — `StreamedResponse` in 64 KiB blocks. Slow, works everywhere.
- `LiteSpeedDelivery` — `X-LiteSpeed-Location` pointing at an internal alias, so the web
  server sends the bytes and PHP is released immediately.
- `XSendFileDelivery` — `X-Sendfile`, for Apache with mod_xsendfile.

Selected by `config('vault.delivery')`. Local development uses `stream` and must keep working.

## Variants

`GET /media/{uuid}/variant/{kind}` — same four authorization checks. Variants are small, so
decrypting them in memory is fine. Each carries its own stored nonce.
`Cache-Control: private, max-age=900`.

**This endpoint matters more than the stream one.** An admin reviewing a queue of deposits
should be looking at posters and 30-second previews, not decrypting originals. Make it fast
and make it the default path the UI reaches for.

## Watermarking

When an admin views another user's work, overlay the viewer's name and a timestamp on the
poster and preview. It does not prevent a leak; it makes one attributable, which is the
realistic deterrent in a copyright office where staff see unpublished work daily.

## Tests

- Range request returns 206 with exact bytes and correct headers.
- An unaligned Range start returns correct bytes.
- A Range spanning a MAC segment boundary verifies and returns correctly.
- Unsatisfiable Range → 416.
- Expired signature → 403.
- Valid signature, wrong `u` parameter → 403 (the leaked-link case).
- A non-owner author → 403; an admin → 200.
- Every access, successful or refused, writes a `file_access_logs` row.
- A download ticket cannot be redeemed after expiry.
- `LiteSpeedDelivery` emits the header with an empty body (assert without a real server).
- Seeking into a large file does not read the whole file — assert on bytes read or peak
  memory, whichever you can measure cleanly.

## Acceptance gate

1. All tests green.
2. In a browser: play a large video, seek forward, seek backward. Report how many PHP
   processes were occupied during a full download versus during streaming.
3. `php artisan test --compact` — 0 failures.
4. `composer ci:check` — still passes end to end.
5. `php artisan vault:doctor` — still 0 FAIL.
6. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.

---

# TASK 2 — The admin review console

Read AGENTS.md first. Task 1 is merged; the decrypting read path exists.

## What this is

An ONDA officer's working surface. They arrive each morning to a queue of deposits awaiting
a decision, and they need to reach a defensible judgement on each one without downloading
gigabytes and without leaving the audit trail incomplete.

Design for the officer who reviews forty deposits in a day, not for a demo of two.

## Deliver a plan first

Before writing code: the screens, the data each one needs, the schema additions, and a
wireframe of the review screen. Wait for approval.

## Schema — the model cannot express a review today

`works.status` has the right values (`draft|submitted|under_review|registered|rejected`) but
nothing records **who decided, when, or why**. A rejection with no stated reason is not
defensible in a registry.

Propose a `work_reviews` table rather than columns on `works`: append-only rows of
(work_id, reviewer_id, from_status, to_status, reason, created_at). A work can be rejected,
resubmitted and reviewed again, so the history is the useful artifact — and it matches how
`file_access_logs` already works.

Argue for or against in one paragraph, then decide. This is the one place in this task where
a migration is expected.

## Screens

**1. Authors** — `/admin/authors`

Every author, with: name, email, wilaya, deposit count, storage used against quota, date of
last deposit, and how many of their works are awaiting review. Sortable and searchable.

The useful default sort is not alphabetical. Think about what an officer actually needs to
surface — authors with pending work, or approaching their quota ceiling. Choose one and say
why.

**2. Review queue** — `/admin/review`

Works awaiting a decision, oldest first, because a deposit waiting three weeks is the
problem the officer needs to see. Show: title, author, file count, total size, time since
submission, and whether every file has reached `ready`.

**A work whose files are not all `ready` is not reviewable yet.** Make that visible rather
than letting an officer open something and find nothing to look at. If a file is `failed` or
`quarantined`, that belongs on this screen — it is an operational problem, not a review
decision.

**3. Work detail and review** — `/admin/works/{work}`

The core screen. It must let an officer decide without downloading anything.

- The work's metadata and the author's details.
- Every file with: name, type, size, duration or dimensions, deposit date, and the sha256
  fingerprint shown in full and copyable. **The fingerprint is the legal artifact** — treat
  it as evidence, not as a debug field.
- Integrity status per file: MAC verified, hash recorded, scan clean. If a file failed
  verification the officer must see it before deciding anything.
- Preview inline from variants: poster and preview clip for video, waveform and audio player
  for sound, first-page thumbnail for PDF. Watermarked with the officer's name.
- Stream the full file only when they explicitly ask, never automatically.
- Full download behind a second, deliberate action — it costs a decrypt job and is the one
  operation that puts plaintext on disk. Consider requiring a stated reason; it is recorded
  in the audit log either way.
- The decision: approve, reject with a mandatory reason, or return for correction. Approval
  sets `registered_at` and writes a `work_reviews` row.

**4. Deposit audit** — `/admin/works/{work}/audit`

The `file_access_logs` chain for this work: every stream, download, preview and decision,
with who and when. Show whether the hash chain verifies. This is what makes the deposit
defensible if it is ever disputed.

## Access control

- Everything behind `role:admin`. Every route, every action.
- An admin viewing an author's unpublished work is a privileged act. Log it, watermark it,
  and do not make it frictionless — a UI that makes browsing other people's unpublished work
  feel casual is the wrong UI for this domain.
- Never expose a raw vault path or a sequential id anywhere in the interface.

## Design

Match the existing dashboard: dark theme, `components/ui` primitives, Tailwind v4, IBM Plex.
The author-facing deposit work established a registry-receipt vocabulary — the admin console
should read as the same product, from the other side of the counter.

Full ar/fr/en with correct RTL. Isolate Latin runs in Arabic with `<bdi>` and `<i18n-t>` —
hashes, sizes, dates and filenames are all mixed-script.

Density matters here in a way it did not on the author side. An officer scanning forty rows
needs information per screen, not generous whitespace.

## Owned files

```
app/Http/Controllers/Admin/**
app/Actions/Review/**
app/Policies/**                       ← admin abilities
app/Models/WorkReview.php
database/migrations/*_create_work_reviews_table.php
resources/js/pages/admin/**
resources/js/components/admin/**
resources/js/locales/{ar,fr,en}.json
routes/web.php                        ← the admin group only
tests/Feature/Admin/*
```

FROZEN: `app/Domain/Vault/**`, `app/Actions/Upload/**`, `app/Jobs/**`, the media read path
from Task 1, existing migrations.

## Tests

- An author cannot reach any `/admin` route (403 on each).
- An admin sees all authors; an author sees none.
- Approving sets `registered_at` and writes a `work_reviews` row.
- Rejecting without a reason is refused.
- Reviewing a work whose files are not all `ready` is refused, with a clear message.
- Every admin view of a file writes a `file_access_logs` row naming the admin.
- The audit view detects a tampered hash chain.
- The review queue orders by submission age, not by id.

## Acceptance gate

1. The plan, approved, then the build.
2. All tests green.
3. `php artisan test --compact` — 0 failures.
4. `composer ci:check` — still passes end to end.
5. Screenshots or descriptions of each screen in Arabic and French, including the empty
   state of the review queue and a work with a quarantined file.
6. Confirm an admin preview never triggers a full decrypt — say how you verified it.
7. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
