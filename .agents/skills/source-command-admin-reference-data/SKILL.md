---
name: "source-command-admin-reference-data"
description: "Migrated source command `admin-reference-data`"
---

# source-command-admin-reference-data

Use this skill when the user asks to run the migrated source command `admin-reference-data`.

## Command Template

Task — Admin reference-data management. Read AGENTS.md first.

One admin surface, tabbed, for the four seeded reference tables: register types, type
gestions, colleges, members, and college document requirements. Table view per tab, with
view, edit, status change and visibility toggles.

Baseline: Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at
their current values. Hold all of it.

## Part 0 — Two things to settle before building

### 0.1 The seeder will fight the UI

`MembershipTypeSeeder` uses `updateOrCreate` keyed on `(register_type_id, name, type_gestion)`
for colleges, and matches members by `code_qlt` then `legacy_names` then `name`.
`CollegeOeuvreFileSeeder` keys on `(register_type_college_id, document_key)`.

So if an admin renames a college through this UI, the next seeder run **does not match it** and
creates a duplicate. If an admin edits a document's extensions, the next run silently reverts
them.

Decide and state which:

- **(a)** `name` is not editable through the UI; only the localised names, flags and operational
  fields are. The seeder stays authoritative for identity, the UI owns presentation and
  behaviour. **Recommended** — it is the only option where both can coexist without a merge
  strategy.
- **(b)** `name` is editable, and the seeder becomes a first-install-only tool that is never run
  again against a populated database. Say how you would prevent someone running it anyway.

Whatever you choose, put it in a comment at the top of both seeders so the next person does not
have to work it out.

### 0.2 Three overlapping flags

Colleges carry `status` and `is_disabled`. Members carry `status`, `is_disabled` **and**
`available_in_registration`. The meanings overlap and an admin UI will expose that confusion
immediately.

Read the code and report what each one actually does today in the cascade and the seeders, then
give each a distinct label and a one-line explanation in the UI. Do not merge or drop any of
them — the seeded data depends on all three.

## The page

Route per tab, sharing a layout — not client-side tabs over one payload. Deep links must work,
a refresh must stay on the same tab, and each tab needs its own pagination, search and filters.

```
/admin/referentiel/types            admin.referentiel.types
/admin/referentiel/gestions         admin.referentiel.gestions
/admin/referentiel/colleges         admin.referentiel.colleges
/admin/referentiel/membres          admin.referentiel.membres
/admin/referentiel/documents        admin.referentiel.documents
```

All behind `role:admin`. Bind by uuid; never expose a sequential id.

The tab bar shows a row count per tab. An admin should see at a glance that colleges holds 22
and documents holds 69.

## The tables

**Types** — name, localised names, colleges count, status, disabled.

**Gestions** — name, localised names, parent type, colleges count.

**Colleges** — `code_college`, name, localised names, parent type, gestion, members count,
documents count, **oeuvres count**, status, disabled, adhesion.
Filter by type and by gestion. Search by name and code.

**Members** — name, `code_qlt`, parent college, status, disabled, available in registration.
Filter by college. Search by name and code.

**Documents** — `document_key`, title, localised titles, parent college, extensions, required,
display order, max size, allows multiple, **needs review**, conditions present.
Filter by college and by `needs_review`. Search by key and title.

The counts are aggregates per row. Use `withCount` so each page is a fixed small number of
queries regardless of row count — 100 members with a naive relation load is 100 queries. State
the number for each tab and how you measured it.

## Edit — what is editable, and what is not

This is the core of the task. Getting it wrong corrupts deposits that are already filed.

### Never editable

| Field | Why |
|---|---|
| `code_college` | The join key `CollegeOeuvreFileSeeder` resolves against, and the value frozen into `oeuvres.code_college_snapshot`. Changing it orphans documents and makes every existing snapshot a lie. |
| `document_key` | Frozen into `media_files.document_key_snapshot` for the same reason. |
| `register_type_id` on a college | Moves a college between types, invalidating every oeuvre already classified under it. |
| `register_type_college_id` on a member or document | Same. |

Render these read-only with a short explanation, not hidden. An admin should see the key and
understand why it is fixed.

### Editable, safe

- `name_ar`, `name_en`, `title_ar`, `title_en` — pure display.
- `status`, `is_disabled`, `available_in_registration` — behaviour, and the point of this task.
- `display_order` on documents.
- `adhesion` on colleges.

### Editable, with consequences — warn before saving

- **`is_required` on a document.** Turning it on makes existing draft oeuvres un-submittable
  until the author uploads that file. Show how many drafts are affected before saving.
- **`extensions` on a document.** Tightening it does not retroactively invalidate files already
  uploaded, and must not. Say so in the UI so nobody assumes otherwise.
- **`max_size_kb`.** Same.
- **`allows_multiple` from true to false** on a slot that already holds several files. The rule
  applies to new uploads only; existing files stay. Say so.

### Extensions editing

Store as a lowercase list. Validate on save: non-empty, lowercase, no dots, no MIME strings,
no rule objects. `needs_review` clears automatically when an admin saves extensions on a
flagged row — that is exactly the review the flag was asking for.

## Status and visibility changes — guarded

Disabling a college or a type is the action with the widest blast radius.

Before disabling a college, show its **oeuvres count**. If any oeuvre is currently `draft`,
`submitted` or `under_review` under it, warn explicitly: those authors are mid-deposit.

Disabling must **not** break anything already filed:

- Existing oeuvres keep their classification and remain viewable and reviewable.
- A disabled college stops appearing as selectable in step 1 — which is what the flag already
  does in the cascade.
- `registered` oeuvres are untouched under any circumstance.

Add a test for each of those three.

## No delete

Nothing in this UI deletes a reference row. A college with deposits filed under it must never
disappear, and soft-deleting one would leave those oeuvres pointing at a trashed parent.

Use status and disabled to retire something. If an admin asks for delete later, that is a
separate decision with a migration behind it.

## Audit

Changing reference data changes what the law requires of a deposit. Every change here is
recorded: who, when, which row, which field, old value, new value.

Check whether a suitable audit mechanism already exists — `file_access_logs` has a hash chain,
and an activity-log package may be installed. Report what you found and either reuse it or add
a small `reference_data_changes` table. Do not add a package.

## Design and i18n

Match the existing admin console: dark theme, `components/ui` primitives, Tailwind v4. Density
over whitespace — an officer scanning 100 members needs rows, not cards.

Full ar/fr/en. College and member names are French inside an Arabic layout: wrap them in
`<bdi>`, as the deposit UI already does. `name_ar` is null on most rows, so use the localised
accessor with its fallback.

## Owned files

```
app/Http/Controllers/Admin/Referentiel/**
app/Http/Requests/Admin/**
app/Policies/**                                 admin abilities only
app/Models/{RegisterType,TypeGestion,RegisterTypeCollege,RegisterTypeMember,CollegeOeuvreFile}.php
                                                scopes, casts, relations, counts only
database/migrations/*                           audit table only, if needed
database/seeders/{MembershipTypeSeeder,CollegeOeuvreFileSeeder}.php
                                                the Part 0.1 comment only
resources/js/pages/admin/referentiel/**
resources/js/components/admin/**
resources/js/locales/{ar,fr,en}.json
routes/admin.php
tests/Feature/Admin/*
```

FROZEN — STOP and report if you need these:

```
app/Domain/**   app/Jobs/**   app/Actions/Upload/**
app/Models/{Oeuvre,MediaFile,UploadSession}.php
resources/js/pages/author/**   resources/js/stores/**
config/vault.php   config/filesystems.php
```

## Tests

- An author cannot reach any `/admin/referentiel` route — 403 on each of the five.
- Each tab lists the right rows: 4 types, 22 colleges, 100 members, 69 documents.
- Query count per tab stays fixed as rows are added.
- `code_college` cannot be changed, even by a direct request.
- `document_key` cannot be changed, even by a direct request.
- A college's `register_type_id` cannot be changed.
- Editing `name_ar` succeeds and does not touch anything else.
- Toggling `is_disabled` on a college removes it from the step 1 cascade.
- Disabling a college does **not** change existing oeuvres classified under it.
- A `registered` oeuvre is unaffected by any reference-data change.
- Turning on `is_required` reports the number of affected drafts before saving.
- Saving valid extensions on a `needs_review` row clears the flag.
- Invalid extensions — uppercase, with dots, or MIME strings — are rejected.
- Every change writes an audit record naming the admin and the field.
- No route deletes a reference row.

## Acceptance gate

1. Your Part 0.1 decision and Part 0.2 findings.
2. Query count per tab, and how you measured it.
3. In a real browser: open each tab, edit a localised name, toggle a college's disabled flag,
   then confirm in step 1 that it disappeared from the cascade and that an existing oeuvre
   classified under it still opens correctly.
4. Edit a `needs_review` document's extensions and confirm the flag clears.
5. Screens in Arabic and French, including one tab with a filter applied.
6. Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — at baseline or
   better.
7. **Run `npm run build` before the browser check.** A stale `public/build` has already cost
   this project a full misdiagnosis session and 12 phantom test failures.
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
