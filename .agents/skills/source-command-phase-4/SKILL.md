---
name: "source-command-phase-4"
description: "Migrated source command `phase-4`"
---

# source-command-phase-4

Use this skill when the user asks to run the migrated source command `phase-4`.

## Command Template

Phase 4 — Frontend upload manager. Read AGENTS.md first.

Phase 5 is merged. Files now reach `ready` through the queue, with `sha256_plain`,
duration, dimensions and variants populated. Test against real status transitions produced
by `php artisan queue:work --queue=media` — not against mocked states.

The P3 upload endpoints are frozen and verified end-to-end with a real 200 MB upload
through Herd.

Baseline to hold: **6 test failures, 28 `types:check` errors.** Do not lower the 28 by
fixing pre-existing generics — that work is deferred deliberately.

## Owned files — touch nothing else

```
resources/js/**
lang/**                                  (or the project's existing i18n location)
routes/web.php   ← Inertia page routes + the status polling endpoint ONLY
app/Http/Controllers/WorkController.php  (thin, for the page routes)
tests/Feature/Pages/*
```

FROZEN — if you believe a change is needed here, STOP and report rather than editing:

```
app/Domain/**
app/Actions/**
app/Jobs/**
app/Models/**
database/**
config/**
```

## The one constraint that defines this phase

The upload store lives at application level, above the page components. An Inertia
navigation must NOT interrupt an in-flight upload. If uploads die when the user opens
another page, this phase has failed regardless of how the UI looks. Design for this first;
it cannot be retrofitted cleanly.

## Deliverables

```
resources/js/stores/uploads.ts              Pinia store — app-level, NOT page-level
resources/js/workers/chunker.worker.ts      slicing + CRC32
resources/js/lib/uploadClient.ts            fetch wrapper, retry, abort
resources/js/composables/useUploadQueue.ts
resources/js/pages/works/{Index,Show,Create}.vue
resources/js/components/upload/{Dropzone,QueueItem,ProgressRing,ResumeBanner}.vue
resources/js/components/media/StatusBadge.vue
resources/js/types/upload.ts
```

## Worker

Slicing and hashing run off the main thread — computing CRC32 over 8 MiB on the UI thread
visibly freezes the browser. The worker posts `{index, buffer, crc32}` back to the store,
transferring the `ArrayBuffer` rather than copying it.

The P3 backend validates `X-Chunk-CRC32` only. Do not compute a client-side SHA-256 unless
you have a concrete use for it, and never invent a header the backend does not read.

Never attempt to hash the whole file client-side — `crypto.subtle.digest` cannot stream and
would require the entire 5 GB in memory.

## Upload client

- `POST` chunks with `Content-Type: application/octet-stream` and the raw `ArrayBuffer`.
  Never `FormData` — the backend returns 415 for multipart, by design.
- Header `X-Chunk-CRC32` using crc32b, matching the backend's algorithm.
- Concurrency 3 per file; 1 file at a time by default, configurable.
- Retry with exponential backoff (1s, 2s, 4s, 8s) on network error or 5xx; max 4 attempts,
  then mark the chunk failed and surface a manual retry.
- On 422 (CRC mismatch) retry that chunk once immediately, without backoff.
- On 410 (session expired) surface a re-init prompt rather than failing silently.
- On 413 (quota exceeded) show the remaining bytes the backend returns — do not compute it
  client-side.
- Honour `AbortController` so pause is instant.
- CSRF: these are session routes. Include the token exactly as the rest of the app does —
  check the existing Inertia setup rather than inventing a mechanism.

## Resume

On mount, `GET /uploads/{uuid}` returns the received mask; send only what is missing.
Persist session uuid, filename and size in `sessionStorage` so a refresh can offer resume.

Be explicit in the UI: after a page refresh the user must re-select the same file — the
browser cannot retain a File handle across reloads (the File System Access API is
Chrome-only and out of scope). Validate the re-selected file's size against the stored value
before resuming, and refuse with a clear message on mismatch.

## Status polling

After `complete`, the file sits at `scanning` and the Phase 5 chain advances it through
`processing` to `ready`. Build a thin `GET /works/{uuid}` endpoint returning the current
`media_files.status` plus, once available, duration, dimensions and variant availability.

Show the state honestly — "processing", not "done". Handle `failed` and `quarantined` as
distinct, explained states, not as a generic error. A quarantined deposit is not the
author's fault and the message should say so.

Poll with a backing-off interval (2s, then 5s, then 15s) and stop once the file reaches a
terminal state. Do not poll indefinitely.

## UI

- Dropzone with extension and size validation before any request is sent.
- Per-file: progress %, uploaded/total, live speed, ETA, pause/resume/cancel/retry.
- Aggregate progress in a persistent bar visible across page navigations.
- `beforeunload` guard while any upload is active.
- Errors must be actionable: "Chunk 42 failed — retry", not "Upload failed".
- Full ar/fr/en i18n with correct RTL. Format numbers, dates and file sizes per locale.

## Constraints

- TypeScript strict. No `any`.
- Existing `resources/js/components/ui/` primitives + Tailwind v4 only. No new packages.
- Call the backend through generated Wayfinder helpers, not hardcoded URLs. Run
  `php artisan wayfinder:generate` if the upload routes are missing from the generated files.
- Follow the project's name-based layout convention in `resources/js/app.ts` — do not import
  a layout manually.
- Locale handling for authenticated routes comes from the `locale` cookie, not a path
  prefix. Read the notes in `app.ts` and `i18n.ts` before touching anything locale-related.

## Acceptance gate

1. `npm run types:check`, `npm run lint:check`, `npm run format:check` — all green.
2. `php artisan test --compact` — baseline still 6.
3. Manual demonstration, reported with what you actually observed:
   (a) a 500 MB upload completes, and the file transitions `scanning` → `ready` in the UI
       while a queue worker runs
   (b) killing the network mid-upload and restoring it resumes without data loss
   (c) navigating to another Inertia page mid-upload does not interrupt it
   (d) the RTL layout is correct in Arabic
   (e) a file whose processing fails displays a `failed` state, not a silent success
4. `git status --short`

Then STOP.

## Git policy — applies to this entire phase

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed — use them freely.
- If you believe a commit is necessary, STOP and ask. Do not commit and then report it.
- End the phase by printing `git status --short` so I can check the touched files against
  the ownership list above.
