---
name: "source-command-parallel-uploads"
description: "Migrated source command `parallel-uploads`"
---

# source-command-parallel-uploads

Use this skill when the user asks to run the migrated source command `parallel-uploads`.

## Command Template

Task: parallel uploads. Every file uploads at the same time; the one that finishes first is done first; a large file keeps uploading while small ones complete. Read AGENTS.md first.

Baseline to hold: zero across eslint, prettier, vue-tsc, Pint, phpstan and the full Pest suite; `composer ci:check` passes end to end.

## The regression

Before: on the author's oeuvre page I could pick a video, a PDF and a docx; all three showed progress together, and the small ones reached "uploaded" while the video was still going.

Now: the video starts, and every other file stays "waiting" until the video has finished. For a 2–5 GB video that means a 100 KB PDF waits for hours. This is a regression, not a missing feature.

## Rules that do not change

- The upload protocol stays exactly as it is: init → chunk (8 MiB, written at byte offset) → complete. Same chunk size, same CRC check, same endpoints, same server-side tracker.
- Uploads start the moment a file is chosen. No submit button.
- Per-slot rules stay as they are (which slot accepts which formats, how many files).
- `complete()` does no heavy work; the processing chain is untouched.

## Part 1 — Find the cause (report it, then continue)

Find the exact cause and report it with file and line references before changing code. Use `git log -p` / `git show` on the upload client and store to find the commit that introduced the serialisation, and quote the change.

Check every one of these; each alone can produce exactly this symptom:

1. **Client queue.** A store-level queue with concurrency 1, a singular "active upload" pointer, or an `await` over files in a `for` loop in `resources/js/stores/uploads.ts` / `lib/uploadClient.ts`.
2. **Shared Web Worker FIFO.** A single worker that slices/CRCs chunks in arrival order: the video floods its message queue and the other files' chunks wait behind it.
3. **Init serialised.** Init calls for later files only fire after the previous file's upload resolves.
4. **Browser connection limit.** If the site is served over HTTP/1.1, the browser allows about 6 connections per origin. If the video holds 6 in-flight chunk requests, everything else starves, including Inertia navigation and status polling. Report the protocol shown in the network panel (http/1.1, h2 or h3) locally on Herd.
5. **Server-side lock.** A `Cache::lock` or DB lock scoped to the user, the oeuvre or the quota, held across chunk requests instead of per upload session. A blocking session (`->block()` on routes) on chunk routes.
6. **PHP worker capacity.** Report the PHP-FPM `pm.max_children` (or equivalent) on Herd, and note that on cPanel the per-account process limit is usually small. Long chunk requests from one file can occupy every PHP worker.

State which of these is the cause, which are contributing, and which you ruled out and how.

Then continue to Part 2 without waiting, unless the cause is server-side in a frozen file; in that case STOP and report with the evidence.

## Part 2 — The fix: one fair scheduler

Build one upload scheduler in the client, owned by the uploads store:

- **Global pool.** At most `maxInFlight` chunk requests across all files at once. Default 4, which stays under the HTTP/1.1 limit and leaves room for navigation and polling.
- **Per-file cap.** At most `perFileInFlight` requests for any one file. Default 2.
- **Round-robin fairness.** When a slot in the pool frees, the next chunk comes from the next active file in rotation, not from the file that owns the most chunks. Small files therefore finish first by themselves, and the large file keeps progressing the whole time. No priority by size is needed.
- **Init in parallel.** Each file's init fires as soon as the file is chosen, with at most 3 inits at once. A file that is initialised joins the rotation immediately, even mid-upload of another file.
- **Workers.** No single shared FIFO worker. Use a small pool (`min(navigator.hardwareConcurrency - 1, 3)`, minimum 1), with tasks tagged by session uuid, dispatched in the same round-robin order. Slice with `File.slice()` per chunk; never read a whole file into memory. Release each chunk buffer after its request settles. Memory bound: `maxInFlight × 8 MiB`.
- **Isolation.**
  - A failing chunk retries with its own backoff and never pauses other files.
  - A file that fails permanently leaves the rotation; the others continue.
  - Pause, resume and cancel act per file.
  - A 413 or 422 on one file affects that file only.
- **State per file.** Store state is keyed by upload session uuid. Remove any singular "current upload" concept.
- **Progress.**
  - Each file shows its own progress, bytes, speed and ETA, computed from that file's own throughput, which changes as other files finish. That is correct; do not smooth it into a lie.
  - An aggregate line shows the total in progress.
  - Copy, in ar/fr/en: the files share one connection, so starting several at once does not make the connection faster; it makes small files finish without waiting for large ones.
- **Configuration.** `maxInFlight` and `perFileInFlight` come from the server as a shared Inertia prop, from `config('vault.upload.client_max_in_flight')` and `config('vault.upload.client_per_file_in_flight')`. Production can be tuned to the cPanel process limit without a rebuild. Add those two keys only.
- **Tab guard.** The `beforeunload` guard fires while any file is uploading. It continues to work across Inertia page navigation, since the store is application-scoped.

## Part 3 — Server-side correctness under concurrency

Verify, and fix only if broken. Any fix in `app/Actions/Upload/**` is limited to lock scope and must come with tests:

- Several upload sessions for the same user and the same oeuvre can receive chunks interleaved, and all of them complete.
- Locks are scoped to the upload session uuid, never to the user or the oeuvre, during chunk writes.
- **Quota race.** Two inits fired at the same time must not together exceed the remaining quota. Reservation happens atomically at init, under a short lock or a conditional update, not during chunks.
- Two concurrent uploads to the same single-file slot get a defined result. State which, and keep the existing slot rule; do not invent a new one.
- Chunk routes do not hold a blocking session lock.

## Tests

Vitest, using a fake transport with controllable latency:
- One large file (many chunks) and two small files. The small files complete while the large file is still in progress, and the large file's progress increases during that time.
- In-flight requests never exceed `maxInFlight` globally or `perFileInFlight` per file.
- Round-robin: with three active files, chunk dispatch order rotates across them.
- A file added mid-upload starts within one scheduling turn.
- A failing file (exhausted retries) does not stall the others; pause and cancel of one file do not affect the others.
- Inits run in parallel, capped at 3.
- No singular "current upload" state remains.

Pest:
- Two sessions, same user and same oeuvre, with interleaved chunks: both complete and both hashes are correct after the chain.
- Concurrent inits that together exceed the quota: exactly one is accepted.
- The defined same-slot behaviour.

Existing upload and lifecycle tests stay green and are not weakened.

## Owned files — touch nothing else
```
resources/js/stores/uploads.ts
resources/js/lib/uploadClient.ts, resources/js/lib/upload/** (new scheduler module)
resources/js/workers/**                     ← upload slicing/CRC workers only
resources/js/components/upload/**
resources/js/pages/author/oeuvres/**        ← only where the upload list is rendered
resources/js/locales/{ar,fr,en}.json
app/Http/Middleware/HandleInertiaRequests.php  ← the two shared props only
config/vault.php                            ← upload.client_* keys only
app/Actions/Upload/**                       ← lock scope / quota reservation only, if Part 3 finds a defect
tests/** for the above
```
FROZEN — STOP and report instead of editing: `app/Domain/Vault/**`, `config/filesystems.php`, the chunk protocol and chunk size, the processing chain, admin pages.

## Acceptance gate
1. Part 1: the cause, with the commit that introduced it, the protocol (h1/h2) and PHP worker count.
2. Part 3 findings: locks, quota race, same-slot behaviour.
3. `php artisan test --compact`: 0 failures, with before/after counts.
4. `npm run test` (Vitest), `npm run types:check`, `npm run lint:check`, `npm run format:check`: all 0.
5. `composer ci:check` passes end to end.
6. `npm run build`, so `public/build` is not stale. A stale build is what produced phantom failures before.
7. Manual check script, one screen: pick a large video (≥ 1 GB), a small PDF and a docx at once. Confirm all three show progress immediately, the PDF and docx reach "uploaded" while the video continues, and the network panel never shows more than `maxInFlight` chunk requests at once.
8. `git status --short`

Then STOP.

## Git policy — applies to this entire task
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
