---
name: "source-command-task-a-b"
description: "Migrated source command `task-a-b`"
---

# source-command-task-a-b

Use this skill when the user asks to run the migrated source command `task-a-b`.

## Command Template

# Two tasks — run them in this order

The upload bug comes first. The URL restructure touches routing and Wayfinder, and doing it
while an upload defect is open would make the next diagnosis harder.

---

# TASK A — Diagnose the silent upload failure

Diagnostic task. Read AGENTS.md first. **Do not fix anything until Part 1 is reported.**

## What was observed

A screen recording of a real manual test, 21 seconds:

- The author opens a work's page. The reworked UI is live: trust strip, quota line
  ("0 B of 50 GB used"), and the new empty-state copy all render correctly.
- They click Browse and pick `IMG_0967.MOV` — a MOV file, 2,535,267 KB (about 2.42 GB),
  from the Downloads folder.
- Fifteen seconds later the page is **completely unchanged**. No card. No progress. The
  quota still reads 0 B. The empty state still says "no deposits yet".
- **No error message of any kind appears.**

The silence is the most informative part. A rejected file should produce a reason. Nothing
at all points to either the file never reaching the store, or reaching it and not being
rendered.

## Part 1 — Find where it stops, and report before changing anything

Instrument, do not guess. Answer these in order; each one halves the search space.

**1. Did `POST /uploads` fire at all?**

Check `storage/logs/laravel.log` and the `upload_sessions` table for a row with filename
`IMG_0967.MOV` around the time of the test. This single fact splits the problem:

- A session row exists → the file reached the server; the defect is in rendering.
- No row → the file never left the browser; the defect is in selection, validation, or the
  store.

**2. If nothing left the browser**, work through the client path with temporary logging at
each hand-off. Report exactly which step swallowed it:

- the file input's change handler firing at all
- `uploadValidation.ts` accepting or rejecting the file
- the store's `addFiles`/`startFile` receiving it
- the worker constructing successfully
- `initUpload` being called

Two specific suspects to check explicitly, and say which applies:

**Uppercase extension.** The file is `.MOV`, not `.mov`. If the whitelist comparison is
case-sensitive anywhere — client validation, the `accept` attribute, or `InitUpload`'s
server-side check — this file is rejected. Windows and iOS both produce uppercase
extensions routinely, so this would break a large share of real deposits. Test both cases
on every layer that checks an extension.

**A rejection with nowhere to render.** If validation rejected the file, where does that
message go? If the reject path writes to a state nothing displays, or fires a toast that is
not mounted on this page, the user sees exactly what the recording shows. Trace the
rejection message from where it is set to where it is rendered, and confirm the two connect.

**3. If a session row does exist**, the upload is running invisibly and the defect is in the
merged list. Check:

- Does `Show.vue`'s empty-state condition still test only `mediaFiles.length === 0`? An
  in-flight upload produces no `MediaFile` row until `complete()` succeeds — hours later for
  a 2.4 GB file — so an empty state gated on that array alone will always show during an
  upload. This is the exact structural gap identified in the UX plan; confirm whether the
  condition was actually updated or only the copy was.
- The `workId` filter on in-flight uploads: what type and value is stored in the store
  versus what `Show.vue` compares against? A `work.id` number compared to a `work.uuid`
  string matches nothing and fails silently. Log both sides and compare.
- Does the store actually hold the file? Inspect it directly in the browser console.

## Part 2 — Fix, then prove it

Fix only the root cause you identified. If you find more than one defect, fix each and
report them separately.

Then prove it with a test that would have caught this:

- A feature or component test asserting that an in-flight upload renders in the list while
  `mediaFiles` is still empty. This is the regression that matters most — it is the one the
  recording exposes.
- If the cause was case-sensitivity: a test uploading `.MOV`, `.MP4` and `.PDF` uppercase
  through every validation layer.
- If the cause was an unrendered rejection: a test asserting the message appears in the DOM.

## Part 3 — Give me a manual retest script

One screen. Exact steps, exact expected result at each step, using a small file (about
50 MB) rather than 2.4 GB so the test takes a minute. Include what I should see within the
first two seconds — that is the moment that failed here.

## A note on the earlier incident

An automated session previously created two abandoned `IMG_0967.MOV` sessions with
pre-allocated 2.4 GB placeholders. Check `C:/onda-storage/incoming` for any placeholder from
today's manual test, and report whether one exists. If the upload silently initialised and
was abandoned, it left 2.4 GB of zeros with nothing to reclaim it — the Phase 7 gap already
recorded. Report the finding; do not build the cleanup here.

## Owned files

```
resources/js/**
tests/Feature/Pages/*
app/Actions/Upload/InitUpload.php     ← only if the extension check is genuinely the cause
```

If the fix requires touching anything else, STOP and report.

## Acceptance gate

1. Part 1 findings, with the evidence for each answer.
2. The fix, with the root cause stated in one sentence.
3. The new tests, passing.
4. `npm run types:check`, `npm run lint:check`, `npm run format:check` — all 0.
5. `php artisan test --compact` — 0 failures.
6. `composer ci:check` — still passes end to end.
7. The manual retest script.
8. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.

---

# TASK B — Move author routes under /author

Run only after Task A is merged. Read AGENTS.md first.

## Goal

Every author-facing authenticated route moves under an `/author` prefix:

```
/dashboard      →  /author/dashboard
/works          →  /author/works
/works/{work}   →  /author/works/{work}
/uploads/*      →  see the decision below
```

## Decide these three before writing code, and state your reasoning

**1. Do the upload endpoints move?**

They are author-only and sit behind `role:author`, so `/author/uploads/*` is consistent. But
they are also a stable client-server contract that the frontend, the P3 curl script, and
several tests all reference.

Moving them is defensible; leaving them is defensible. Pick one, explain why in a sentence,
and be consistent. If you move them, every caller must move with them — including the curl
script in `scratch/`, if it is still there.

**2. Is there an admin side that should mirror this?**

`resources/js/pages/admin/Dashboard.vue` exists. If admin routes are also unprefixed, an
`/author` prefix without a matching `/admin` prefix leaves the app half-converted. Report
what admin routes exist today and recommend whether to do both now, both later, or only
author. Do not convert admin without approval.

**3. Do route names change?**

Keep them. `dashboard`, `works.index`, `works.show` should keep their names and change only
their URIs. Nothing that calls `route('works.index')` — tests, Wayfinder, redirects — then
needs to change, and the blast radius stays small. If you disagree, argue it first.

## What to watch for

**Locale handling.** Public routes are locale-prefixed (`/{locale}/...`); authenticated
routes are not, and take their locale from a cookie. `/author/*` is authenticated, so it
must NOT gain a locale prefix. Confirm `SetLocale` still resolves correctly under the new
prefix — a middleware that reads the first path segment could now see `author` where it
previously saw `dashboard`.

**Fortify redirects.** Login, registration and email verification redirect somewhere after
success. Find every hardcoded `/dashboard` — in `config/fortify.php`, in `app/Http/Responses/*`,
in `RouteServiceProvider::HOME` if it exists — and update it. A stale redirect sends a
newly-logged-in author to a 404.

**Wayfinder.** Run `php artisan wayfinder:generate` after the routes change and confirm the
generated helpers reflect the new URIs. The frontend calls through those helpers, so stale
generated files would break silently at runtime rather than at build time.

**Tests.** Several tests assert redirect targets. Some assert a literal `/` — those are
correct and must not change (Fortify's logout and verification responses genuinely redirect
to root, established in an earlier session). Only update assertions that reference
`/dashboard` or `/works`.

**Sidebar and navigation.** Every link in `AppSidebar.vue` and elsewhere must go through
Wayfinder helpers, not hardcoded strings. If you find hardcoded paths, that is worth fixing
as part of this — say which you found.

## Owned files

```
routes/web.php
routes/settings.php                   (if it holds author routes)
config/fortify.php
app/Http/Responses/**
app/Providers/**                      (redirect constants only)
resources/js/**                       (navigation and generated helpers)
tests/**                              (assertions referencing the moved URIs)
```

Do not touch `app/Domain/**`, `app/Actions/**`, `app/Jobs/**`, migrations, or the vault
config.

## Acceptance gate

1. Your three decisions, with reasoning.
2. `php artisan route:list` — paste the author routes showing the new URIs and unchanged
   names.
3. `php artisan test --compact` — 0 failures.
4. `composer ci:check` — still passes end to end.
5. Manually confirm and report: logging in lands on `/author/dashboard`; the sidebar links
   all resolve; a full upload still works end to end; logging out still goes to `/`.
6. Confirm no author route acquired a locale prefix.
7. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
