---
name: "source-command-submission-review"
description: "Migrated source command `submission-review`"
---

# source-command-submission-review

Use this skill when the user asks to run the migrated source command `submission-review`.

## Command Template

# Submission, review and notifications — two tasks

The deposit flow today ends at step 2: files upload, reach `ready`, and the oeuvre stays a
`draft` forever. Nothing moves it forward, no officer ever sees it, and the author never
hears back.

This series closes the loop:

```
draft ──submit──► submitted ──open──► under_review ──approve──► registered
  ▲                                         │
  │                                         └──reject + reason──► rejected
  └────────────────────── resubmit ─────────────────────────────────┘
```

Task 1 is the backend contract. Task 2 is both interfaces, built against it. Run them in
order.

"Pending" in the brief is the existing `submitted` status. Do **not** add a new enum value;
label it "En attente" / "قيد الانتظار" in the UI.

---

# TASK 1 — The status machine, actions and notifications

Read AGENTS.md first. Backend only — no Vue in this task.

Baseline: Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at
their current values. Hold all of it.

## Part 0 — Verify before building, and report

1. **Can an admin read media yet?** An earlier task was meant to widen the media routes from
   `role:author` to admit `role:admin`. Check `routes/web.php`. If an admin still gets 403 on
   `/media/{uuid}/stream`, this entire review flow is blind — widen it here, leaving
   `MediaFilePolicy` to do the ownership check.

2. **What notification infrastructure exists?** The header shows a bell with a count badge,
   and `resources/js/components/dashboard/NotificationPopover.vue` exists. Find out whether it
   reads real database notifications or placeholder data, whether the `notifications` table
   exists, and whether any mail transport is configured. Report what is real and what is a
   mockup.

3. **Is `resources/js/pages/author/oeuvres/Index.vue` committed?** It held roughly 1200 lines
   of in-progress work. Task 2 builds the author works table there. Report its state.

## The status machine — one place, not scattered updates

Build it as a single guarded class that knows which transitions are legal. Never
`$oeuvre->update(['status' => ...])` from a controller. Every transition records who, when,
from what, to what, and why.

| From | To | Who | Condition |
|---|---|---|---|
| `draft` | `submitted` | owning author | submission gate passes (below) |
| `rejected` | `submitted` | owning author | submission gate passes |
| `submitted` | `under_review` | admin | — |
| `under_review` | `registered` | admin | — |
| `under_review` | `rejected` | admin | a non-empty reason |
| `registered` | *anything* | nobody | **terminal** |

**`registered` is terminal and immutable.** No transition leaves it. This is the property that
makes a deposit evidence — a registered oeuvre that can still change is worth nothing in a
dispute.

## The submission gate — and the conditions problem

Submission requires:

- At least one file on the oeuvre.
- **No file in `uploading`, `scanning` or `processing`.** Submitting mid-pipeline would send
  an officer a deposit with files still being checked.
- **No file `failed` or `quarantined`.** The author must resolve or remove it first, and must
  be told which file by name.
- Every required slot satisfied — **with one deliberate exception.**

The exception: `conditions` on `college_oeuvre_files` is stored but not evaluated. MUSIQUE
marks `autorisation_sample` required, but ONDA only asks for it when a sample was actually
used. If the gate demanded every `is_required` slot, an author who used no sample could
**never submit**.

So the gate is:

- Required slots with **no** `conditions` → must hold at least one `ready` file. Blocking.
- Required slots **with** `conditions` → advisory. They do not block submission; the officer
  sees at review time which ones are empty and judges whether they apply.

State this rule in a comment where the gate lives, and note that it tightens automatically
once conditions are evaluated.

A refused submission returns every reason at once, not the first one found. An author told
"one more problem" five times in a row stops trusting the button.

## Freezing — enforced on the server, not only in the UI

Once an oeuvre leaves `draft`, the author can no longer change it.

- `InitUpload` refuses to start an upload into an oeuvre that is not `draft` or `rejected`.
  This is the one change permitted in `app/Actions/Upload/` — a status guard at the top of
  `InitUpload`, nothing else. Do not touch chunking, encryption, CRC, retry or finalize.
- The policy denies edits to a `submitted`, `under_review` or `registered` oeuvre.
- A `rejected` oeuvre reopens for the author: new files may be added, and it may be
  resubmitted.

A frozen oeuvre enforced only by hiding the upload button is not frozen.

## Schema

**`oeuvre_reviews`** — append-only decision history:

```
oeuvre_id, actor_id, from_status, to_status, reason (nullable), created_at
```

A table rather than columns on `oeuvres`, because an oeuvre can be rejected, resubmitted and
reviewed again — the history is the useful artifact, and it matches how `file_access_logs`
already works. **No `updated_at`, no soft deletes**: a decision record that can be edited or
removed defeats its purpose. Add it to AGENTS.md's soft-delete classification as "never".

Also on `oeuvres`, if not present: `submitted_at`, `reviewed_at`, `reviewed_by`,
`registered_at`. The admin queue orders by `submitted_at`.

## Concurrency

Two officers opening the same deposit is normal in a small office. The failure to prevent:
two opposite decisions on the same oeuvre within a minute, the second silently overwriting the
first.

Moving to `under_review` records the officer who holds it. A decision by anyone else is
refused unless they explicitly take it over. Alternatively, optimistic locking that refuses a
decision if the status changed since the page loaded. Choose one, implement it, and say why.

## Notifications

Database channel, required. Mail only if Part 0 found a working transport — do not configure
one in this task.

| Event | To | Content |
|---|---|---|
| submitted / resubmitted | every admin | oeuvre label, author, collège, file count |
| registered | the author | oeuvre label, registration date |
| rejected | the author | oeuvre label, **the officer's reason verbatim** |

The rejection notification carries the reason. A rejection the author has to go and hunt for
is a support call.

Resubmission is visibly distinct from first submission in the admin notification — an officer
looking at a resubmitted deposit needs to know it was rejected before.

Localise notification text in ar/fr/en, rendered in the **recipient's** locale, not the
actor's.

## Endpoints

```
POST  /author/oeuvres/{oeuvre}/submit            author.oeuvres.submit
POST  /admin/oeuvres/{oeuvre}/review             admin.oeuvres.review   (→ under_review)
POST  /admin/oeuvres/{oeuvre}/approve            admin.oeuvres.approve
POST  /admin/oeuvres/{oeuvre}/reject             admin.oeuvres.reject
GET   /notifications                             (read, mark-as-read)
```

Thin controllers: each calls the status machine and returns.

## Tests

- Submit with a file still `scanning` is refused, naming it.
- Submit with a `quarantined` file is refused, naming it.
- Submit with an empty **unconditional** required slot is refused.
- Submit with an empty **conditional** required slot succeeds.
- A refused submit returns all reasons, not only the first.
- Submitting someone else's oeuvre is 403.
- `InitUpload` refuses an upload into a `submitted` oeuvre.
- `InitUpload` accepts an upload into a `rejected` oeuvre.
- An author cannot edit an `under_review` oeuvre.
- Reject without a reason is refused.
- Approve writes an `oeuvre_reviews` row and sets `registered_at`.
- No transition out of `registered` succeeds, for any actor.
- A rejected oeuvre can be resubmitted, producing a second review row.
- The concurrency guard prevents the double-decision case.
- Submission notifies every admin; approval and rejection notify only the author.
- The rejection notification contains the reason.

## Owned files

```
app/Domain/Deposit/**                   the status machine
app/Actions/Oeuvre/**   app/Actions/Review/**
app/Actions/Upload/InitUpload.php       the status guard ONLY
app/Models/{Oeuvre,OeuvreReview}.php
app/Policies/OeuvrePolicy.php
app/Notifications/**
app/Http/Controllers/{Author,Admin}/**
database/migrations/*                   new only
routes/{author,admin,web}.php           new endpoints; media widening if Part 0 needs it
tests/**
AGENTS.md                               the soft-delete classification row
```

FROZEN — STOP and report if you need these:

```
app/Domain/Vault/**   app/Jobs/**
app/Actions/Upload/{StoreChunk,CompleteUpload,AbortUpload}.php
database/seeders/**   config/vault.php   config/filesystems.php
resources/js/**
```

## Gate

1. Part 0 findings.
2. The transition table as implemented, and where it is enforced.
3. Your concurrency choice and why.
4. All tests green; Pest, Pint, PHPStan at baseline or better.
5. `git status --short`

Then STOP. No UI.

---

# TASK 2 — The author and admin interfaces

Read AGENTS.md first. Task 1 is merged; the contract is fixed.

## Deliver a plan first

Wireframes for the author works table, the author oeuvre page's submit area, and the admin
review decision area. Wait for approval.

## Author — `/author/oeuvres` as a table

The works list opens as a table by default.

**Read the existing `Index.vue` first.** It holds substantial in-progress work by the project
owner. Build on it rather than replacing it, and report what you kept and what you changed.
Do not reformat parts you do not otherwise touch.

| Column | Content |
|---|---|
| Œuvre | the display label, linking to the oeuvre page |
| Collège | localised collège name |
| Documents | required slots satisfied, e.g. "4 / 6" |
| Statut | badge: Brouillon · En attente · En examen · Enregistrée · Rejetée |
| Dates | created; submitted when applicable |
| Actions | depend on status (below) |

Actions by status:

- `draft` → Continue · **Submit** when the gate passes, greyed with the reason when it does not
- `submitted` / `under_review` → View
- `rejected` → View · the reason in a line under the row · Fix and resubmit
- `registered` → View · certificate, if one exists

Filter by status, search by label, paginate. A rejected oeuvre is visually impossible to miss
— it is the only row that asks the author to act.

Use `withCount` / eager loading for the documents column. It is an aggregate per row; written
naively it is N queries. State the query count for the page.

## Author — the oeuvre page

A submit area at the bottom of step 2:

- When the gate passes: the button, and a confirmation explaining exactly what the author gives
  up — no more files, no edits, until a decision comes back.
- When it does not: every blocking reason listed, each naming the file or slot. Conditional
  slots that are empty appear as advisory, not as blockers.
- Once submitted: the current status, and roughly what happens next.
- When rejected: the officer's reason, prominently, and the path back.

## Admin — the review decision

On the existing admin oeuvre screen:

- "Start review" moves the oeuvre to `under_review` and records the officer.
- If another officer holds it, say who, and offer an explicit take-over.
- Approve, or reject with a **mandatory** reason. The reason reaches the author verbatim, so the
  field should invite a sentence an author can act on — not a code, not a checkbox.
- Empty conditional slots are listed for the officer: these are the ones the author was told
  might not apply, and it is the officer's call.
- The `oeuvre_reviews` history, if the oeuvre has been round before. An officer looking at a
  resubmission needs to know why it was rejected last time.

## Notifications — the bell

Wire the header bell to real database notifications: unread count, a list, mark as read,
clicking one opens the oeuvre. If Part 0 found `NotificationPopover.vue` showing placeholder
data, replace the data source and keep its design.

Poll with a backing-off interval; do not open a websocket in this task.

## Design and i18n

Match the existing dashboard and the deposit vocabulary already established. `components/ui`
primitives, Tailwind v4, no new packages. Full ar/fr/en with `<bdi>` around Latin runs.

## Gate

1. The plan, approved, then the build.
2. In a real browser, the full loop:
   - author completes step 2 and submits; the admin's bell shows it
   - admin starts review, rejects with a reason; the author's bell shows it, with the reason
   - author adds a file and resubmits; the admin sees it marked as a resubmission
   - admin approves; the author sees `registered`
   - author tries to upload into the registered oeuvre and is refused
3. The works table: query count, and screens in Arabic and French with one oeuvre in each status.
4. Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — at baseline or better.
5. **Run `npm run build` before the browser check.** A stale `public/build` has already cost this
   project a full misdiagnosis session and 12 phantom test failures.
6. `git status --short`

Then STOP.

---

## Git policy — both tasks

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End each task by printing `git status --short`.
