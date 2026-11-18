---
name: "source-command-admin-console"
description: "Migrated source command `admin-console`"
---

# source-command-admin-console

Use this skill when the user asks to run the migrated source command `admin-console`.

## Command Template

Task — Admin review console: authors and works. Read AGENTS.md first.

Uploads work. Authors deposit files in their own space and preview them. What is missing is
the other side of the counter: an ONDA officer's surface for reviewing who has registered
and what they have deposited.

This task builds the browsing and inspection layer. The approve/reject decision workflow is
deliberately **not** in scope — it needs a status machine and a `work_reviews` table, and
mixing it in here would make the diff unreviewable. Build the console that lets an officer
*see* everything first.

Baseline: `php artisan test --compact` at 0 failures, `composer lint:check` and
`composer types:check` at 0. Hold all three.

## Part 0 — Verify three prerequisites before writing anything

Report the answers first. Any of them can change the shape of this work.

**1. Can an admin read a media file at all?** The media routes currently carry
`role:author`. An admin hitting `/media/{uuid}/stream` gets 403 today. Confirm this, then
widen the middleware to admit `role:admin` alongside `role:author`, leaving
`MediaFilePolicy` to do the real ownership check — an admin may view any file, an author
only their own. Without this, every preview on every screen below is a 403.

**2. Does a variants endpoint exist?** `routes/web.php` registers `media.link` and
`media.stream`. Check whether anything serves the poster, preview clip and waveform that
`GenerateVariants` produces. If not, that endpoint is part of this task — it is the
mechanism that makes reviewing forty deposits a day possible without decrypting hundreds of
gigabytes.

**3. Are variants actually being generated and stored?** Query `media_variants` and check
the `variants` disk. If the pipeline has not produced any for existing files, say so — the
UI must degrade honestly (show the file's metadata and offer a stream) rather than render a
broken image.

## Structure

```
app/Http/Controllers/Admin/
├── AuthorController.php        index, show
└── WorkController.php          index, show

resources/js/pages/admin/
├── authors/{Index,Show}.vue
└── works/{Index,Show}.vue

resources/js/components/admin/   shared table, filter, stat pieces

routes/admin.php                 all under prefix /admin, name admin., role:admin
```

Routes:

```
GET /admin/authors            admin.authors.index
GET /admin/authors/{user}     admin.authors.show
GET /admin/works              admin.works.index
GET /admin/works/{work}       admin.works.show
```

Bind by uuid — `getRouteKeyName()` already returns it. A sequential id must never appear in
a URL.

## Screen 1 — `/admin/authors`

Every registered author, one row each.

Columns: name, email, wilaya, number of works, number of deposited files, storage used
against quota, and date of last activity.

**Sort order is a design decision, not a default.** Alphabetical is useless to an officer.
Pick something that surfaces what needs attention — most recent activity, or authors nearest
their quota ceiling — and say why you chose it. Let the officer re-sort.

Search by name and email. Filter by wilaya.

**Query performance is a real constraint here, not a theoretical one.** Works count, file
count and storage used are three aggregates per row. Written naively that is 3N queries for
N authors, and a registry will have thousands. Use `withCount` and `withSum` so the whole
page is a small fixed number of queries regardless of row count. State how many queries the
page issues and how you measured it.

Paginate. Do not load every author.

## Screen 2 — `/admin/authors/{user}`

One author, everything about them.

- Identity and contact: name in Arabic and Latin, email, phone, wilaya and commune,
  registration date, email-verified status.
- Storage: used against quota, as a figure and a bar.
- **All of their works**, with title, status, file count, total size, deposit date, each
  linking to the work screen. This is the screen's purpose — "show me everything author X
  has deposited".
- A short activity summary: last deposit, number of works by status.

Filter their works by status. Paginate if the list is long.

## Screen 3 — `/admin/works`

Every work across every author.

Columns: title, author (linking to their page), status, file count, total size, submission
date, and whether all files have reached `ready`.

**Drafts are never shown to an admin.** A draft is an author's private working state, not a
submission. Filter them out in the query, not in the view.

**A work with a `failed` or `quarantined` file must be visible as such**, because that is an
operational problem an officer needs to act on, distinct from a deposit awaiting judgement.

Filter by status, by author, by date range. Search by title. Paginate.

## Screen 4 — `/admin/works/{work}`

The inspection screen. An officer must be able to form a judgement here without downloading
anything.

**The work**: title, description, author with a link to their page, status, all relevant
dates.

**Each file**:
- Name, type, size, and duration or dimensions where the pipeline extracted them.
- The **sha256 fingerprint in full, copyable**. This is the legal artifact — the proof that
  this exact file was deposited on this date. Render it as evidence, in a monospace face,
  not as a truncated debug value.
- Integrity: hash recorded, MAC verified, scan result, and when each happened.
- Deposit date.

**Preview, from variants first**:
- Video → poster image, then the preview clip on click.
- Audio → waveform, then the audio player.
- PDF → first-page thumbnail.
- Anything without a variant → metadata and an explicit option to stream, never a broken
  image.

The full original streams only on an explicit request. Never automatically, and never on
page load — a work page that decrypts a 5 GB original because someone opened it is a
denial-of-service against your own server.

**Watermark every preview** with the officer's name and a timestamp. It does not prevent a
leak; it makes one attributable, which is the realistic deterrent in an office where staff
see unpublished work daily.

**Log every view.** Each time an admin streams or previews another user's file, write a
`file_access_logs` row naming the admin. Confirm this fires for the admin case specifically
and add a test — it probably already works through the existing logger, but "probably" is
not good enough for an audit trail.

## Access control

- Every route behind `role:admin`. Every one.
- An admin viewing an author's unpublished work is a privileged act: logged, watermarked,
  and deliberately not frictionless. A console that makes browsing other people's
  unpublished work feel casual is the wrong console for this domain.
- Never expose a vault path, a wrapped DEK, a nonce, or a sequential id in any response.
  Check the Inertia props you send, not just what the template renders — Inertia serialises
  whatever you pass it, and a model passed whole will leak its columns into the page source.

## Design

Match the existing dashboard: dark theme, `components/ui` primitives, Tailwind v4, IBM Plex.
The author-facing deposit work established a registry-receipt vocabulary — this console
should read as the same product seen from the other side.

Density matters more here than on the author side. An officer scanning forty rows needs
information per screen, not generous whitespace. Tables, not cards.

Full ar/fr/en with correct RTL. Isolate Latin runs with `<bdi>` and `<i18n-t>` — hashes,
sizes, dates, emails and filenames are all mixed-script, and the author side has already
established the pattern to reuse.

Empty states everywhere: no authors, an author with no works, no works matching a filter.

## Tests

- An author cannot reach any `/admin` route (403 on each of the four).
- A guest is redirected to login.
- The authors index shows every author and no admins.
- Counts and storage figures are correct against seeded data.
- The works index never includes a draft, even one belonging to the author being viewed.
- An author's show page lists only that author's works.
- An admin can stream another author's file; a second author cannot.
- An admin previewing a file writes a `file_access_logs` row naming the admin.
- No response contains a vault path, a wrapped DEK, or a nonce — assert on the JSON.

## Owned files

```
app/Http/Controllers/Admin/**
app/Http/Requests/Admin/**
app/Policies/**                       ← admin abilities only
resources/js/pages/admin/**
resources/js/components/admin/**
resources/js/locales/{ar,fr,en}.json
routes/admin.php
routes/web.php                        ← the media middleware widening only
tests/Feature/Admin/*
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/**
app/Jobs/**
app/Actions/Upload/**
app/Models/**            (except a scope or accessor, disclosed)
database/migrations/**
config/vault.php
config/filesystems.php
resources/js/pages/author/**
resources/js/stores/**
```

Do not touch `resources/js/pages/author/works/Index.vue` — it is in-progress work and must
not be reformatted.

## Deliver a plan first

The four screens with an ASCII wireframe each, the columns on every table, your sort-order
choices with reasons, and the query strategy for the aggregates. Wait for approval.

Then build in two stages, reporting after each:

1. Backend: routes, controllers, queries, the media widening, tests.
2. Frontend: the four pages, components, i18n.

## Acceptance gate

1. Part 0 findings — the three prerequisites, answered.
2. The plan, approved, then the staged build.
3. All tests green; `php artisan test --compact` at 0 failures.
4. `composer lint:check`, `composer types:check`, `npm run types:check`,
   `npm run lint:check` — all 0. Report `ci:check` as blocked by the in-progress
   `author/works/Index.vue` if it still is; do not touch that file.
5. Query counts for the authors index and the works index, with how you measured them.
6. Screenshots or descriptions of all four screens in Arabic and French, including the empty
   states and a work containing a quarantined file.
7. Confirm no admin preview triggers a full decrypt — say how you verified it.
8. `git status --short`

Then STOP. The approve/reject workflow is the next task, not this one.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
