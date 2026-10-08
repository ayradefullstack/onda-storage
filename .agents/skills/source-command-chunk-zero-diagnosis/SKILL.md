---
name: "source-command-chunk-zero-diagnosis"
description: "Migrated source command `chunk-zero-diagnosis`"
---

# source-command-chunk-zero-diagnosis

Use this skill when the user asks to run the migrated source command `chunk-zero-diagnosis`.

## Command Template

Diagnostic task — chunks never reach the server. Read AGENTS.md first.

**Do not write a fix until Part 1 is reported.** A wrong guess here costs another manual
test cycle.

## Evidence

Four real manual uploads, all against work_id 70, user_id 132. From `upload_sessions`:

| filename | size_bytes | chunk_size | total_chunks | received_chunks | received_bytes |
|---|---|---|---|---|---|
| IMG_0967.MOV | 2,596,112,423 | 8,388,608 | 310 | 0 | 0 |
| Estimation_E-Certif-TF.pdf | **69,008** | 8,388,608 | **1** | 0 | 0 |
| IMG_0967.MOV | 2,596,112,423 | 8,388,608 | 310 | 0 | 0 |
| BAC 2026.pdf | **110,849** | 8,388,608 | **1** | 0 | 0 |

The UI shows, for every one of them: "Chunk 0 failed for an unknown reason — retry", with a
retry button. `dek_wrapped` and `nonce` are populated on all four rows.

## What this evidence rules out

A **69 KB PDF, one single chunk**, fails identically to a 2.4 GB MOV. That eliminates file
size, chunk count, `post_max_size`, `upload_max_filesize`, nginx `client_max_body_size`,
execution timeouts, and the uppercase `.MOV` extension theory — none of them can stop a
69 KB request.

`init` succeeds every time, which proves authentication, CSRF for JSON requests, routing,
`role:author`, the quota check, and `EncryptedLocalVault::beginUpload` all work. A DEK was
generated and wrapped, a nonce stored, and the placeholder allocated.

The defect is confined to one narrow path: **between a successful init and the arrival of
chunk 0**, and it is identical for every file.

## Ranked hypotheses

**1. The worker is still broken.** The previous session fixed the worker construction and
verified that "init now correctly reaches the server" — but init is a metadata POST that
does not touch the worker at all. The worker is only used for slicing and CRC32. That
verification never exercised the code that was fixed.

The error code supports this: `'unknown'` comes from the catch-all added in that same
session, described at the time as existing to catch "sliceAndHash's `new Worker(...)`
throwing". Before the catch, uploads hung silently forever; now they fail loudly. **Same
underlying failure, newly visible.**

A related and very likely variant: the site is served over **https** at
`https://onda-storage.test` while the Vite dev server runs over **http** on port 5173.
Mixed content is blocked, and a worker is among the first things to fail. Check the page
protocol and Vite's protocol, and check whether `npm run dev` is even running — if it is
not, Laravel serves the built assets from `public/build` instead, which is a different code
path with different worker behaviour.

**2. An unmapped HTTP status.** The client maps 410, 413 and 422. A 419 (CSRF token
mismatch or expiry), 405, 404 or 500 falls through to the generic handler and reads as
"unknown". Init succeeding does not prove the chunk request carries the same headers — the
chunk POST sends a raw `application/octet-stream` body and may go through a different code
path than the JSON init call.

**3. A server-side 500 reading `php://input`.** Less likely, since nothing appears in the
session row, but a thrown exception in `StoreChunk` before the tracker update would look
exactly like this. `storage/logs/laravel.log` settles it immediately.

## Part 1 — Find where it stops

Start with the single decisive question:

**Does `POST /uploads/{uuid}/chunk/0` appear in the browser's network activity at all?**

- **No** → the failure is client-side, before the request is ever made. Hypothesis 1.
- **Yes** → read the status code and the response body. Hypothesis 2 or 3.

Then, in order:

1. Read `storage/logs/laravel.log` for the window covering these four attempts. Any
   exception at all? If `StoreChunk` threw, it is there.
2. Check the browser console output. The real error is almost certainly printed there and
   then swallowed into `errorCode: 'unknown'`. Capture the actual message and stack.
3. Report the page protocol (http or https), whether `npm run dev` is running, Vite's
   configured protocol and port, and whether the app is currently serving dev assets or
   built assets from `public/build`.
4. Trace the chunk request construction in `lib/uploadClient.ts` and compare it header by
   header against the init request. Specifically: is the CSRF header present on both? Init
   works, so any difference between them is a suspect.
5. Add temporary logging at each hand-off in the chunk path — worker construction, worker
   message received, CRC computed, fetch issued, response received — and report exactly
   which step is the last one reached.

Report all of this before changing any code.

## Part 2 — Fix the root cause, and fix the diagnostics

Fix what you found. Then fix the thing that made this take two manual test cycles:

**`errorCode: 'unknown'` must never be the final word.** Whatever the underlying exception
was, it should reach the developer. Log the real error and stack to the console with the
session uuid and chunk index. Show the user something actionable, but do not discard the
detail — that discard is why the first manual test produced no information.

If the cause is an unmapped HTTP status, map the ones that matter (419 in particular
deserves its own message: the session expired, log in again) and make the fallback report
the status code rather than "unknown".

## Part 3 — A test that would have caught this

The gap is structural: every prior verification of the upload path went through curl or Pest,
both of which bypass the browser, the worker, and the client's fetch layer entirely. That is
why a total failure of chunk transmission survived a green test suite.

Add a test that exercises the client's chunk path — the worker slicing, the CRC
computation, the request construction — without a browser if that is what is achievable
here. State plainly what your test does and does not cover; a test that only proves the
server accepts a chunk adds nothing, since we already know it does.

## Part 4 — Clean up and report the disk state

The four abandoned sessions left pre-allocated placeholders: two at 2.42 GB, two small.
About 5 GB of zeros in `C:/onda-storage/incoming` with nothing to reclaim them.

- Report the actual file sizes on disk and the free space on C:.
- Delete the four sessions and their placeholders.
- Confirm `storage_quotas.used_bytes` is still 0 for user 132 — it should be, since nothing
  reached `complete()`.

This is the Phase 7 gap already on record. It has now occurred four times in one afternoon
of ordinary testing. Report it again with the real numbers; do not build the cleanup job.

## One more thing to check

The retry button appears to have created a **new** session rather than resuming the existing
one — rows 28 and 30 are both IMG_0967.MOV with identical size. Confirm whether retry
resumes or restarts. Restarting discards every received chunk and allocates a second
placeholder, which for a 5 GB file means 10 GB consumed for one deposit. If retry restarts,
say so; the fix may belong to this task or the next, but the behaviour needs to be known.

## Owned files

```
resources/js/**
tests/**
app/Actions/Upload/StoreChunk.php    ← only if the cause is genuinely server-side
```

If the fix requires anything else, STOP and report.

## Acceptance gate

1. Part 1 findings, with the network evidence, the console error, and the log excerpt.
2. The root cause in one sentence, plus the fix.
3. The improved error reporting, with a demonstration of what the developer now sees.
4. The new test, and an honest statement of its coverage.
5. Part 4 disk numbers and cleanup confirmation.
6. The retry resume-or-restart finding.
7. `npm run types:check`, `npm run lint:check`, `npm run format:check` — all 0.
8. `php artisan test --compact` — 0 failures.
9. `composer ci:check` — still passes end to end.
10. A manual retest script, one screen, using a small PDF — under a minute to run.
11. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
