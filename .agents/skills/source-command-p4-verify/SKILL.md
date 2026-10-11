---
name: "source-command-p4-verify"
description: "Migrated source command `p4-verify`"
---

# source-command-p4-verify

Use this skill when the user asks to run the migrated source command `p4-verify`.

## Command Template

Verification task — not a new phase. Read AGENTS.md first.

## Context

Phase 4 was run BEFORE Phase 5, but its prompt stated "Phase 5 is merged. Files now reach
`ready` through the queue." That was false at the time: no processing pipeline existed. The
Phase 4 agent detected this itself and built against reality rather than against the prompt.

Phase 4 also never went through a gate review, and its browser automation failed, so five
of its acceptance criteria were never demonstrated.

Two of those criteria were untestable when it ran:
- a file transitioning `scanning` → `ready` in the UI
- a file whose processing fails displaying a `failed` state

Both are testable now. This task verifies Phase 4 against the pipeline that actually
exists, and fixes only what is genuinely broken.

Baseline is now zero across eslint, prettier, vue-tsc, Pint, phpstan and the full Pest
suite, and `composer ci:check` passes end to end. Hold all of it.

## Owned files

```
resources/js/**
resources/js/locales/**
routes/web.php                            ← page routes + status endpoint only
app/Http/Controllers/WorkController.php
tests/Feature/Pages/*
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/**  app/Actions/**  app/Jobs/**  app/Models/**  database/**  config/**
```

## Part 1 — Audit against reality (report before changing anything)

Read what Phase 4 actually built and answer each question, quoting the relevant code:

1. **Status reporting.** Phase 4 chose `router.reload({ only: ['mediaFiles'] })` through the
   existing page route rather than a bespoke JSON endpoint. Does the data it reloads expose
   the real `media_files.status` enum — including `processing`, `failed` and `quarantined` —
   or only a subset? Does it now surface duration, dimensions and variant availability,
   which Phase 5 populates?

2. **Polling.** Does it back off (2s → 5s → 15s) and stop at a terminal state, or poll
   indefinitely? An upload page left open for an hour must not issue thousands of requests.

3. **Terminal states.** Are `failed` and `quarantined` handled as distinct, explained
   states, or collapsed into a generic error? A quarantined deposit is not the author's
   fault and the copy should say so.

4. **Placeholders.** Because no pipeline existed when Phase 4 ran, it may have stubbed
   status transitions or hardcoded an optimistic "ready" path. Find any such placeholder and
   name it.

5. **Store scope.** Confirm the upload store is module-scoped state registered above the
   page components, and that an Inertia navigation cannot tear it down. Quote where it is
   registered. This is the single constraint the phase was built around.

Report findings first. Then fix only what is actually wrong — do not rewrite working code.

## Browser automation note

Phase 4's browser automation failed with "Cannot access a chrome-extension:// URL of
different extension" across every tab and tab group. Try once; if it fails the same way, do
NOT spend time debugging it.

Fall back to feature tests asserting rendered state (Inertia assertions on page props and
the rendered component), and say explicitly which criteria were verified by test versus by
manual observation. A criterion verified by an honest test is worth more than one claimed
from code reading.

The one thing a test cannot cover is RTL visual correctness. If the browser is unavailable,
state that plainly and leave it for manual human verification rather than asserting it from
code.

## Part 2 — Demonstrate what could not be tested before

Run a queue worker (`php artisan queue:work --queue=media,default`) and report what you
observed, not what you expect.

**(a) The success path.** Upload a real file through the UI. Confirm it shows `scanning`,
then `processing`, then `ready`, driven by real polling against real status transitions —
not by a timer or an optimistic client-side assumption. State the elapsed time and how many
poll requests were issued.

**(b) The failure path.** Force a pipeline failure and confirm the UI reaches `failed` with
an actionable message. Ways to force it, in order of preference: point `FFPROBE_BINARY` at a
nonexistent path; upload a file whose bytes are not valid media for its extension; or run
`vault:reprocess` on a deliberately corrupted vault object. State which you used.

**(c) Quarantine.** `ScanForMalware` uses a faked Scanner because ClamAV is absent locally.
Verify the UI renders `quarantined` distinctly from `failed`. A feature test asserting the
rendered state is acceptable here if a manual path is not practical — say which you did.

**(d) Variants.** Phase 5 now generates poster and preview images, encrypted with the file's
DEK. Does the UI display them, and can it? Phase 6 builds the decrypting read path — if
variants cannot be shown until then, say so plainly rather than leaving a broken image
element on the page.

## Part 3 — Re-confirm the three criteria that were testable but never reviewed

- A 500 MB upload completes. Check `disk_free_space` on C: first and report it; if free space
  is under ~5 GB, use a smaller file and say so.
- Killing the network mid-upload and restoring it resumes without data loss. This is the
  least-verified path in the whole frontend — the backoff and retry logic has never executed
  once — so exercise it for real rather than reading the code.
- Navigating to another Inertia page mid-upload does not interrupt the upload.
- The RTL layout is correct in Arabic, including numbers and file sizes.

## Acceptance gate

1. Part 1 findings, quoted, with a verdict on each of the five questions.
2. Part 2 (a) through (d) — what you observed, including poll counts and timings.
3. Part 3 — all four, with the free-space figure, and for each state whether it was verified
   by test, by manual observation, or not at all.
4. `npm run types:check`, `npm run lint:check`, `npm run format:check` — all still 0.
5. `php artisan test --compact` — still 0 failures.
6. `composer types:check` — still 0.
7. `composer ci:check` — still passes end to end.
8. `php artisan vault:doctor` — still 0 FAIL.
9. `git status --short`

Then STOP.

## Git policy — applies to this entire task

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed — use them freely.
- If you believe a commit is necessary, STOP and ask. Do not commit and then report it.
- End by printing `git status --short`.
