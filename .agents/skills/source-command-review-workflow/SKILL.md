---
name: "source-command-review-workflow"
description: "Migrated source command `review-workflow`"
---

# source-command-review-workflow

Use this skill when the user asks to run the migrated source command `review-workflow`.

## Command Template

# The deposit review workflow — two tasks

## Prerequisites

**Phase 6 must exist.** Every stored byte is AES-256-CTR encrypted and there is no
decrypting read path yet. Without it an admin can list files and read metadata but cannot
open one, which is the whole point of a review console. If Phase 6 is not merged, run that
first.

**Admins currently cannot read any media at all.** The media routes carry `role:author`.
The role-split task deliberately left this alone as out of scope; it is in scope here, and
it is the first wall this feature hits.

**Chunk zero.** If uploads are still failing, fix that first — a review console tested only
against seeded fixtures will miss what real deposits look like.

---

# TASK A — The submission and review contract

Read AGENTS.md first. Backend only. No UI in this task: both interfaces in Task B are built
against the contract this one establishes.

Baseline is zero across every check and `composer ci:check` passes end to end. Hold it.

## The gap nobody has noticed yet

`works.status` has the values `draft|submitted|under_review|registered|rejected`, but
**nothing in the application ever moves a work out of `draft`.** `WorkController@store`
creates it as a draft and there it stays. There is no submit action.

So today an author can upload files forever and no admin will ever see them. The review
console has nothing to review. That missing transition is the first thing to build.

## The status machine

```
draft ──submit──► submitted ──open──► under_review ──approve──► registered
  ▲                                        │
  │                                        └──reject──► rejected
  └────────────────resubmit────────────────────────────────┘
```

Build this as **one guarded place** that knows which transitions are legal — not
`$work->update(['status' => ...])` scattered across controllers. Every transition records
who, when, and why.

Rules, each of which needs a test:

- **Submit requires every file at `ready`.** A work with a `failed`, `quarantined` or
  still-processing file is not submittable. The author must be told which file is holding
  it up, by name.
- **Submit requires at least one file.** An empty deposit is not a deposit.
- **`registered` is terminal and immutable.** No transition leaves it. This is the property
  that makes the deposit evidence — a registered work that can still change is worth nothing
  in a dispute.
- **A submitted work is frozen for the author.** No adding files, no removing files, no
  editing the title while it is `submitted` or `under_review`. Enforce this in the policy,
  not only in the UI.
- **`rejected` returns control to the author**, who fixes and resubmits. The cycle can repeat.
- Only an admin may move to `under_review`, `registered` or `rejected`.
- Only the owning author may submit or resubmit.

## Schema

**`work_reviews`** — append-only decision history:
`work_id, reviewer_id, from_status, to_status, reason (nullable), created_at`.

A table rather than columns on `works`, because a work can be rejected, resubmitted and
reviewed again — the history is the useful artifact, and it matches how `file_access_logs`
already works. No `updated_at`, no soft deletes: a decision record that can be edited or
removed defeats its purpose.

`reason` is required for `rejected` and optional otherwise. Enforce that in the action, not
only as a form rule.

Add `submitted_at` to `works` if it is not already derivable — the review queue orders by
how long a deposit has been waiting, and that number needs a source.

## Admin media access

Widen the media read routes to admit `role:admin` alongside `role:author`, with
`MediaFilePolicy` doing the real ownership check as it already does. An admin may view any
file; an author only their own.

Two requirements that come with that widening:

- **Every admin view of another user's file is logged** in `file_access_logs`, naming the
  admin. This already happens if the access logger is in the path — confirm it fires for the
  admin case specifically, and add a test.
- **Admin previews are watermarked** with the viewer's name and a timestamp. It does not
  prevent a leak; it makes one attributable, which is the realistic deterrent where staff
  see unpublished work daily.

## Concurrency

Two officers opening the same work is normal in a small office. Decide and implement one of:
a claim on entering `under_review` recording who holds it, or optimistic locking that
refuses a decision if the status changed since the page loaded.

State which you chose and why. The failure to avoid is two officers reaching opposite
decisions on the same deposit within the same minute, with the second silently overwriting
the first.

## Notifications

The author is notified on `registered` and on `rejected`, and the rejection notification
carries the reason. A deposit decided in silence is a support call.

Use the existing notification setup; do not add a channel or a package.

## Certificate

The codebase already has `CertificateModal.vue` and an "Official certificates" sidebar item.
Read them and report what exists. If registration is meant to produce a certificate, say how
the existing component expects its data, so Task B can wire it rather than reinvent it.

Do not build certificate generation in this task — just report the shape.

## Owned files

```
app/Domain/Deposit/WorkStatus.php              or wherever the status machine belongs
app/Actions/Review/**
app/Actions/Work/SubmitWork.php
app/Models/WorkReview.php
app/Policies/WorkPolicy.php
app/Notifications/**
database/migrations/*_create_work_reviews_table.php
database/migrations/*_add_submitted_at_to_works.php
routes/web.php                                  ← media middleware only
routes/author.php, routes/admin.php             ← new endpoints
app/Http/Controllers/{Admin,Author}/**
tests/Feature/Review/*
```

FROZEN: `app/Domain/Vault/**`, `app/Jobs/**`, `app/Actions/Upload/**`, the pipeline.

## Tests

- Submitting with a `failed` file is refused, naming the file.
- Submitting with zero files is refused.
- Submitting a work owned by someone else is 403.
- An author cannot add a file to a submitted work.
- An author cannot edit a registered work.
- No transition out of `registered` succeeds.
- Rejecting without a reason is refused.
- Approving writes a `work_reviews` row and sets `registered_at`.
- A rejected work can be resubmitted, producing a second `work_reviews` row.
- An admin can stream another author's file; a second author cannot.
- An admin viewing a file writes a `file_access_logs` row naming the admin.
- The concurrency guard you chose actually prevents the double-decision case.

## Acceptance gate

1. The status machine, with the transition table and where it is enforced.
2. Your concurrency decision and its reasoning.
3. What you found in `CertificateModal.vue` and the certificates route.
4. All tests green; `php artisan test --compact` at 0 failures.
5. `composer ci:check` — still passes end to end.
6. `git status --short`

Then STOP. No UI.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.

---

# TASK B — Both sides of the review

Read AGENTS.md first. Task A is merged; the contract is fixed.

## Deliver a plan first

The screens, the data each needs, and a wireframe of the admin review screen. Wait for
approval.

## Author side — `/author/works`

**Submit.** A work in `draft` with at least one `ready` file gets a clear submit action.
Before it is available, the author needs to see what is missing: "3 of 4 files are still
processing", or the name of the file that failed.

Submitting is consequential — it freezes the deposit. Confirm it, and say plainly what the
author is giving up: no more files, no edits, until a decision comes back.

**After submission.** The author sees where their deposit stands and roughly how long
reviews take. `rejected` shows the officer's reason prominently, with the path back: fix,
then resubmit. A rejection the author has to hunt for is a support call.

`registered` is the moment the deposit is legally recorded. It should look like it — and if
a certificate exists, this is where it is reachable.

## Admin side — `/admin/works`

**The queue.** Works awaiting a decision, **oldest first** — a deposit waiting three weeks
is the thing an officer needs to see, not the newest arrival. Show: title, author, file
count, total size, waiting time, and whether every file is `ready`.

Filter by status. **Drafts are never visible to an admin** — a draft is private working
state, not a submission.

**The review screen.** An officer must be able to decide without downloading anything.

- The work's metadata and the author's details.
- Each file with: name, type, size, duration or dimensions, deposit date, and the sha256
  fingerprint in full and copyable. **The fingerprint is the legal artifact** — treat it as
  evidence, not a debug field.
- Integrity per file: MAC verified, hash recorded, scan clean. A file that failed
  verification must be visible before any decision is made.
- Preview inline **from variants** — poster and preview clip for video, waveform for audio,
  first-page thumbnail for PDF. Watermarked.
- Stream the full file only on an explicit request. Full download behind a second,
  deliberate action, since it is the one operation that puts plaintext on disk.
- The decision: approve, or reject with a mandatory reason. The reason goes to the author
  verbatim, so the field should invite a sentence an author can act on rather than a code.
- The review history from `work_reviews`, if the work has been round before. An officer
  looking at a resubmission needs to know why it was rejected last time.

**Why variants matter here.** An officer reviews perhaps forty deposits a day. Opening
originals would mean decrypting hundreds of gigabytes. Phase 5 generates small previews for
exactly this reason — make them the default path and the original the exception.

**Audit** — `/admin/works/{work}/audit`. Every stream, download, preview and decision, with
who and when, and whether the hash chain verifies. This is what makes the deposit defensible
if it is ever disputed.

## Design

Match the existing dashboard: dark theme, `components/ui` primitives, Tailwind v4, IBM Plex.
The author-facing deposit work established a registry-receipt vocabulary — the admin console
should read as the same product from the other side of the counter.

Density matters more here than on the author side. An officer scanning forty rows needs
information per screen, not generous whitespace.

Full ar/fr/en with correct RTL. Isolate Latin runs with `<bdi>` and `<i18n-t>` — hashes,
sizes, dates and filenames are all mixed-script, and the author-side work has already
established the pattern to follow.

## Access control

Everything behind `role:admin`. An admin viewing an author's unpublished work is a
privileged act: logged, watermarked, and deliberately not frictionless. A UI that makes
browsing other people's unpublished work feel casual is the wrong UI for this domain.

Never expose a vault path or a sequential id anywhere.

## Owned files

```
app/Http/Controllers/{Admin,Author}/**
resources/js/pages/{admin,author}/**
resources/js/components/{admin,review}/**
resources/js/locales/{ar,fr,en}.json
routes/{admin,author}.php
tests/Feature/{Admin,Pages}/*
```

FROZEN: everything Task A established, plus `app/Domain/**`, `app/Jobs/**`, migrations.

## Tests

- An author cannot reach any `/admin` route.
- An admin never sees a draft in the queue.
- The queue orders by waiting time, not by id.
- Submitting from the UI is blocked while a file is not `ready`, with the file named.
- Approving updates the status and is visible to the author.
- Rejecting without a reason is refused by the form and by the action.
- An admin previewing a file writes a `file_access_logs` row.
- The audit view detects a tampered hash chain.

## Acceptance gate

1. The plan, approved, then the build.
2. All tests green; `php artisan test --compact` at 0 failures.
3. `composer ci:check` — still passes end to end.
4. Screenshots or descriptions of each screen in Arabic and French, including: an empty
   queue, a work with a quarantined file, a rejected work as the author sees it, and a
   registered work.
5. Confirm an admin preview never triggers a full decrypt — say how you verified it.
6. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
