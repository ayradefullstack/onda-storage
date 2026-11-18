---
name: "source-command-create-page-cascade"
description: "Migrated source command `create-page-cascade`"
---

# source-command-create-page-cascade

Use this skill when the user asks to run the migrated source command `create-page-cascade`.

## Command Template

Task — `/author/oeuvres/create`: the classification cascade. Read AGENTS.md first.

The membership tables are seeded. `register_type_colleges` now carries `type_gestion_id` as a
foreign key to `type_gestions` — that is the column the cascade filters on.

This task replaces the title and description inputs on the create page with the selects.

Baseline: Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check` and Vitest all at
their current values. Hold all of it.

## Part 0 — Read the schema as it actually is, and report

The migration and seeder were edited by hand since the last task. Do not assume; read them
and answer:

1. Does `register_type_colleges` still carry the old `type_gestion` tinyint **alongside** the
   new `type_gestion_id`? If both exist, which one is populated, and which is authoritative?
   Two columns meaning the same thing will drift — say what you found and recommend dropping
   one, but do not drop it without approval.

2. `type_gestions` is seeded for `register_type_id = 1` only. So what is
   `type_gestion_id` on the colleges of types 2, 3 and 4 — null, or pointing at an
   Auteur-scoped row? Either can work, since the cascade does not filter on it for those
   types, but the answer determines whether the column should be nullable and whether a
   foreign key constraint holds.

3. Confirm the seeded counts: how many types, type_gestions, colleges, members and roles are
   in the database right now. Paste the query output. The cascade is built against this data,
   so it needs to be a known quantity.

Report all three before writing code.

## The cascade

**Type = Auteur (`register_type_id = 1`) — four selects**

```
1  Type       all register_types
2  Gestion    type_gestions where register_type_id = 1
3  Collège    register_type_colleges
              where register_type_id = 1
                AND type_gestion_id = the chosen gestion
4  Qualité    register_type_members
              where register_type_college_id = the chosen college
```

**Type = 2, 3 or 4 — three selects**

```
1  Type       all register_types
2  Collège    register_type_colleges where register_type_id = the chosen type
3  Qualité    register_type_members where register_type_college_id = the chosen college
```

The gestion select is **not rendered at all** for types 2, 3 and 4 — not disabled, not hidden
with CSS. It is absent, and its value is never accepted from the client for those types.

## Filters

- **`REFERENTIEL_HORS_ADHESION` never appears** in any collège list. It is a reference list of
  non-membership qualities, not a depositable category.
- **Members**: only `available_in_registration = true`.
- **Disabled colleges** (`is_disabled = true`, which includes `OEUVRE_FILM`) appear in the
  list but are not selectable, with a suffix marking them unavailable. The option exists so
  the author can see the category is coming; it cannot be picked.
- Anything with `status = 0` is excluded entirely.

## Resets

Changing any level **clears every level below it**.

Type changed → gestion, collège and qualité cleared.
Gestion changed → collège and qualité cleared.
Collège changed → qualité cleared.

A stale lower selection that survives a change above it produces a branch that is internally
incoherent yet passes a naive required-fields check. This is the single most likely bug in
this feature — test it directly.

## Title and description

Both become nullable. The inputs are removed from this page; the columns stay. A later step
in this flow collects them.

That leaves every new oeuvre unnamed, and the index, the admin console and the breadcrumbs
all display a title today. Propose a display label in your plan — something derived from the
classification and the creation date reads acceptably where a blank cell does not. Say what
you chose.

## Data delivery

The whole tree is roughly 4 + 3 + 22 + 100 rows. Ship it as Inertia props from the controller
in one eager-loaded query and run the cascade client-side. No endpoint per level, no round
trip per select.

Send only the fields the UI needs: ids, localised names, `type_gestion_id`, `is_disabled`,
`available_in_registration`, `code_college`. Do not pass models whole — Inertia serialises
every column you hand it into the page source, and these tables are fine but the habit is
what leaks `dek_wrapped` elsewhere.

## Server-side validation

Required-fields validation is not enough. Reject **incoherent** combinations even when every
field is present:

- The collège must belong to the chosen type.
- The member must belong to the chosen collège.
- For Auteur, the collège's `type_gestion_id` must equal the chosen gestion.
- For types 2, 3 and 4, a gestion sent by the client is rejected outright — it is derived,
  not accepted.
- A disabled collège is rejected.
- `REFERENTIEL_HORS_ADHESION` is rejected.
- A member with `available_in_registration = false` is rejected.

Each of these is a case a correct UI never produces and a crafted request will. Each needs
its own test.

## Persistence

Write to the oeuvre: `register_type_id`, `type_gestion_id`, `register_type_college_id`,
`register_type_member_id`, and `code_college_snapshot`.

Add the columns in a migration if they are not there yet — nullable, since existing rows
predate this.

**Why the snapshot**, in a comment on the migration: a registered deposit records the
classification it was filed under. If a collège is renamed or re-coded in three years, the
deposit's record must not silently change with it. The foreign key gives you joins; the
snapshot gives you the truth as of the filing date.

The oeuvre is created as `draft`, and the author continues to the upload page as before.

## UI

One page. Sections reveal themselves as the level above is answered — not a multi-step
wizard, since an author classifies every deposit and a wizard per deposit is punishing.

- A select is disabled until its parent is chosen, with placeholder text saying what to pick
  first rather than sitting inert and unexplained.
- Once the branch is complete, show a plain summary of the chosen path above the submit
  button so the author can check it at a glance.
- Existing `components/ui` primitives, Tailwind v4, the existing dark theme. No new packages.

## Localisation

`name_ar` and `name_en` are null on most rows. Use the localised-name accessor from the
seeder task, which falls back to `name`. Without it an Arabic UI renders empty selects.

Full ar/fr/en for labels, placeholders and validation messages. Collège and member names are
French inside an Arabic layout — wrap them in `<bdi>` as the author-side deposit work already
established.

## Also check

The dashboard has a quick-create form that posts a title. With the input gone it will either
break or create a nameless oeuvre. Find it, fix it, and replace its hardcoded URL with a
Wayfinder helper — a previous task flagged that and left it.

List every existing test that creates an oeuvre with a title; they will need updating.

## Tests

- Auteur renders the gestion select; types 2, 3 and 4 do not.
- Auteur + gestion 1 yields exactly MUSIQUE, DRAMATIQUE, LITTERAIRE_EMISSION and OEUVRE_FILM
  (disabled), with REFERENTIEL_HORS_ADHESION absent.
- Auteur + gestion 3 yields exactly the seven simple-protection colleges.
- Type 3 yields exactly the four prestation colleges.
- Changing the type clears the three levels below it.
- Changing the gestion clears collège and qualité.
- A collège from the wrong type is rejected.
- A member from the wrong collège is rejected.
- A gestion that does not match the collège is rejected for Auteur.
- A gestion posted for type 3 is rejected.
- A disabled collège is rejected.
- `REFERENTIEL_HORS_ADHESION` is rejected even when posted directly.
- A member with `available_in_registration = false` is rejected.
- A created oeuvre stores all five classification fields including the snapshot.
- The localised name falls back to `name` when `name_ar` is null.

## Owned files

```
app/Http/Controllers/Author/OeuvreController.php
app/Http/Requests/Author/**
app/Models/Oeuvre.php                       relations, casts, fillable only
database/migrations/*                       classification columns on oeuvres, new only
resources/js/pages/author/oeuvres/{Create,Index,Show}.vue
resources/js/components/oeuvre/**
resources/js/components/dashboard/*         the quick-create form only
resources/js/locales/{ar,fr,en}.json
tests/**
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/**   app/Jobs/**   app/Actions/Upload/**
app/Models/{RegisterType,TypeGestion,RegisterTypeCollege,RegisterTypeMember,RegisterRoleAuteur}.php
database/seeders/**   resources/js/stores/**
config/vault.php   config/filesystems.php
```

The membership models and the seeder are settled. If the cascade needs something they do not
expose, STOP and report rather than editing them.

## Deliver a plan first

Your Part 0 findings, the page structure with a wireframe, the display-label proposal, and the
list of tests that need updating. Wait for approval.

## Acceptance gate

1. Part 0 answers, with the query output.
2. The plan, approved, then the build.
3. Six branches walked in the browser, at least one per type — report what you saw.
4. Reset behaviour demonstrated in two cases.
5. A full deposit end to end: classify, create, upload, pipeline through to `ready`, with the
   classification and snapshot stored. Paste the oeuvre row.
6. Screens in Arabic and French.
7. Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at baseline
   or better.
8. **Run `npm run build` before the browser check.** A stale `public/build` has already cost
   this project a full misdiagnosis session and 12 phantom test failures. Confirm you rebuilt.
9. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
