---
name: "source-command-upload-ux"
description: "Migrated source command `upload-ux`"
---

# source-command-upload-ux

Use this skill when the user asks to run the migrated source command `upload-ux`.

## Command Template

Task — redesign the deposit upload experience. Read AGENTS.md first.

This is a UI/UX task, not a new phase. The upload machinery works; what it communicates
does not. Baseline is zero across eslint, prettier, vue-tsc, Pint, phpstan and the full
Pest suite, and `composer ci:check` passes end to end. Hold all of it.

## The problem, stated plainly

An author opens a work, sees a dropzone and an empty list that says "no files uploaded
yet", and that is the entire interface. From that screen they cannot tell:

- that picking a file starts the upload immediately, with no button to press
- whether anything is happening after they pick one
- how long a 5 GB deposit will take, or that they may keep using the site meanwhile
- that the file is encrypted before it touches the disk, and that a hash is recorded as
  proof of deposit
- what "scanning" or "processing" means, or that a file is not yet safely deposited
- what to do when something fails

The last two matter most. This is a copyright office. The person on the other end is
depositing unpublished work they may have spent years on, over an Algerian connection, and
a 5 GB file will take hours. Silence during those hours is not a cosmetic problem — it is
the reason they will close the tab and phone the support line printed in the sidebar.

## Owned files

```
resources/js/pages/works/**
resources/js/components/upload/**
resources/js/components/media/**
resources/js/locales/{ar,fr,en}.json
resources/js/lib/format.ts
resources/js/stores/uploads.ts        ← only if state the UI needs is genuinely missing
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/**  database/**  config/**  routes/web.php
```

Do not change the upload protocol, the chunking, or the polling logic. This task changes
what the interface says and shows, not how it works.

## Design direction

Read `/mnt/skills/public/frontend-design/SKILL.md` if it is available in this environment,
and work in two passes: a short written plan first, then the build.

The subject sets the tone. This is a national copyright registry, not a file-sharing app.
The visual language should read as official and careful — closer to a registry receipt than
to a consumer cloud drive. It must sit inside the existing dark theme, the existing
`components/ui` primitives, Tailwind v4, and the IBM Plex families already loaded. Do not
introduce a new palette or typeface; work within what the dashboard already establishes.

Restraint matters more than decoration here. Spend the visual boldness on one thing: the
per-file deposit state. Everything else stays quiet.

## 1. Make the file's journey visible

This is the core of the task. A deposit file passes through states the backend already
tracks, and the UI currently surfaces none of them:

```
selected → uploading (0–100%) → finalising → scanning → processing → ready
                                                      ↘ failed
                                                      ↘ quarantined
```

Each file in the list needs a compact, honest representation of where it is. Decide the
form yourself — a stepped indicator, a state line, something else — but it must satisfy:

- The current state is legible at a glance, without hovering or clicking.
- During upload: percentage, bytes done of total, live speed, and a realistic ETA.
- After upload: which stage of processing, in the author's language, not ours. "Checking
  the file" reads better than "scanning"; "Preparing previews" better than
  "GenerateVariants". Never show a job class name.
- `ready` is visibly different in kind from the intermediate states — it is the moment the
  deposit is safe, and it should feel like it.
- `failed` and `quarantined` are distinct. A quarantined deposit is not the author's fault
  and the copy must not imply it is. Say what happened and who will look at it.

Do not fabricate progress within the processing stages. If the backend reports `scanning`,
show that honestly rather than animating a fake percentage. An indeterminate indicator that
tells the truth beats a progress bar that lies.

## 2. Answer "is there a submit button?" in the interface

There is no submit button and there should not be: chunks begin streaming the moment a file
is chosen, which is what makes a 5 GB upload resumable. But the interface never says so, so
the absence reads as a missing feature.

Make the automatic start obvious — through the dropzone copy, through immediate visible
motion the instant a file is accepted, or both. The person should never wonder whether they
have forgotten to press something.

If a file is chosen and nothing appears within a moment, that is a bug the UI must surface,
not hide.

## 3. Set expectations about time and scale

A 5 GB deposit over a typical Algerian connection is measured in hours. The interface should
say so before it starts, not after.

- Show an estimate as soon as the file is selected and the first chunks have established a
  rate.
- State clearly that the upload continues while they browse other pages, since it does —
  the store is application-scoped. This is a real feature and it is currently invisible.
- State just as clearly that closing the tab pauses it, and that they can resume by
  re-selecting the same file. The `beforeunload` guard already fires; the copy around it
  should explain rather than merely warn.
- Show the remaining quota against what this file will consume, before it consumes it.

## 4. Build trust, because the content deserves it

An author is handing over unpublished work. Three facts already true of the system are worth
stating in the interface, briefly and without ceremony:

- The file is encrypted before it is written to disk.
- A cryptographic fingerprint is recorded as proof that this exact file was deposited on
  this date.
- Once `ready`, the deposit is recorded in an audit trail.

Show the fingerprint when it exists — abbreviated, copyable in full. For a copyright deposit
this is not a technical detail; it is the receipt. Treat it as one.

Keep this to a few short lines near the file's ready state. It is reassurance, not a
security whitepaper.

## 5. Fix the empty and error states

The current empty state ("no files uploaded yet") is a dead end. It should instead say what
this space is for and what the author should do next — accepted formats, the size ceiling,
what happens after upload. An empty screen is an invitation to act.

Errors currently say what broke, at best. Each one needs a next action:

| Situation | What the author needs |
|---|---|
| chunk failed after retries | which part failed, and a retry that resumes rather than restarts |
| quota exceeded (413) | how much room is left, and how much this file needs |
| session expired (410) | that they can start again, and that nothing was lost |
| wrong file type | which formats are accepted, stated positively |
| processing failed | whether to re-upload or contact support, and the reference to quote |

## 6. Fix the Arabic layout defects

Two are visible on the current screen and must be fixed; look for others.

**Bidi text breaking apart.** The line reading "حتى 5 GB — mp4, mov, avi..." currently
renders with the "5" and "حتى" separated, because Latin format names and digits inside an
RTL paragraph reorder under the bidi algorithm. Isolate the Latin runs properly — `<bdi>`,
`unicode-bidi: isolate`, or explicit direction on those spans. Check every mixed-script
string in this feature, not only the one visible in the screenshot: file sizes, percentages,
speeds, timestamps and filenames are all mixed-script and all affected.

**Filenames.** A filename in an RTL paragraph will reorder and can display a misleading
extension. Isolate it, and truncate from the middle rather than the end so the extension
stays visible.

Verify progress bars, speed readouts and ETAs read correctly in Arabic — a progress bar
should fill from the correct side, and a value like "12.4 MB/s" must not fragment.

Check French as well; it shares the Latin-in-mixed-content paths.

## 7. Copy

Every string in all three locales, written for an author rather than a developer. Plain
verbs, sentence case, no filler. An action keeps its name throughout: if the button says
"Deposit", the confirmation says "Deposited".

Avoid system vocabulary in user-facing text: no "chunk", no "job", no "queue", no
"quarantine" as a bare word without explanation, no status enum values shown raw.

## What not to do

- Do not add a submit button. It would break resumability and misrepresent how uploads work.
- Do not animate fake progress through the processing stages.
- Do not add a new palette, typeface, icon set, or npm package.
- Do not touch the upload protocol, chunk size, retry logic, or polling intervals.
- Do not display variants. The decrypting read path is Phase 6; a variant count in text is
  the correct treatment until then.

## Deliver a plan first

Before writing code, produce a short plan: the per-file state representation with an ASCII
wireframe, the copy for each of the seven states in all three languages, and the empty-state
treatment. Wait for approval.

Review that plan against this brief before building. If any part of it is what you would
produce for any generic file-uploader — a row of cards with a spinner and a percentage —
revise it, and say what you changed and why. This screen belongs to a copyright registry
and should not be mistakable for a consumer cloud drive.

## Acceptance gate

1. The plan, approved, then the build.
2. `npm run types:check`, `npm run lint:check`, `npm run format:check` — all 0.
3. `php artisan test --compact` — 0 failures.
4. `composer ci:check` — still passes end to end.
5. Screenshots or a description of every state: uploading, finalising, scanning, processing,
   ready, failed, quarantined, and empty — in Arabic and in French.
6. Confirm the bidi fixes with the specific strings that were broken, before and after.
7. `git status --short`

Then STOP.

## Git policy — applies to this entire task

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
