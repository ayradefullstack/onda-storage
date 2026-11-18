---
name: "source-command-college-documents"
description: "Migrated source command `college-documents`"
---

# source-command-college-documents

Use this skill when the user asks to run the migrated source command `college-documents`.

## Command Template

Task — `college_oeuvre_files`: the required-document definitions per collège. Read AGENTS.md first.

Backend only. Migration, model, seeder, tests. **No UI, no controllers, no routes.**

Baseline: Pest, Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at
their current values. Hold all of it.

`COLLEGE_DOCUMENTS_MATRIX.md` is the specification. §5.2 is the master matrix: **69 rows**
across 22 colleges. That count is your assertion — if your seeder produces a different number,
it is wrong.

## Part 0 — Four schema questions, answered before you write

The proposed table is
`(id, ulid, register_type_college_id, title, extensions, created, updated, deleted)`.
It drops information the matrix carries that the upload page will need. Report on each, then
implement what you recommend.

**1. `document_key` is missing, and it matters most.**

The matrix's `document_key` (`paroles`, `enregistrement_oeuvre`, `autorisation_auteur`) is the
stable identifier. Without it, rows can only be matched by title — which is French text that
will be translated, edited and re-worded. Every later feature that asks "did they upload the
`justificatif_exploitation`?" needs this key.

Note that the same key recurs across colleges: `autorisation_auteur` appears under colleges 2,
4 and 5. So it is unique **per college**, not globally. Add it with a composite unique index
on `(register_type_college_id, document_key)`.

**2. Arabic titles.** The matrix carries `titre (ar)` for every row and this application is
trilingual. Add `title_ar` and `title_en` nullable, with the same fallback accessor the
membership models use.

Flag the two rows where the matrix says the Arabic file holds French text — college 14's
`contrat` and `jaquette`. Seed them as they are and note it; do not invent translations.

**3. `ordre` and `required`.** The matrix has both. Display order is not alphabetical and not
by id, and "required" drives the red asterisk and the server rule. Both are needed.

**4. `ulid` versus `uuid`.** The brief says `ulid`, but this project uses UUIDv7 through
`ondaKeys()` and `HasUuidColumn`, with `getRouteKeyName()` returning uuid. Follow the project
convention. Say so in your report so the difference is deliberate rather than silent.

**On the table name.** `college_oeuvre_files` reads like "files uploaded for an oeuvre", but
this table holds **requirement definitions** — a template, not uploads. The actual uploaded
files are `media_files`. Consider `college_required_documents`. I am not overruling the name;
say which you chose and why, because the next person to read the schema will have the same
reaction.

## The extensions column — the real decision

The matrix gives two values per row, and §11 documents that they frequently disagree:

- **`ext (client)`** — the literal `extension` key from ONDA's schema array. It only ever
  builds the HTML `accept` attribute and **never becomes a validation rule**. Often absent or
  empty, falling back to a permissive default the matrix marks `REPLI`.
- **`ext (serveur)`** — the rule actually enforced on submission. Often stricter, sometimes
  wider, and in many rows marked **`AUCUNE RÈGLE TROUVÉE`**: the field shows a red asterisk
  and is not checked at all.

**Seed the server rule.** It is the real constraint, and onda_storage has no legacy UI to stay
compatible with. The client `accept` attribute is derived from it, not stored separately —
which fixes ONDA's inconsistency rather than porting it.

Where the server column says `AUCUNE RÈGLE TROUVÉE`, do not seed an empty list. Pick a
sensible default from the document's nature and the client hint, and **mark those rows** so
an officer can review them later — a nullable `needs_review` boolean, or a note column. They
are a known gap in the source system, not settled requirements.

Normalise to a simple lowercase extension list (`["pdf","jpg","jpeg","png"]`), stored as JSON.
Do not store Laravel rule objects or MIME strings; the application builds those from the list.

## Conditional visibility — out of scope, but record it

Many rows carry `show_when` / `hide_when` conditions: `paroles` appears only when
`presence_parole = avec_paroles`; `autorisation_compositeur` is removed when the author's
qualité is Compositeur; the artiste-interprète colleges show documents based on which
`types_justificatifs_*` boxes were ticked.

Those conditions depend on declaration fields that do not exist in onda_storage yet. **Do not
build the condition engine.** Add a nullable `conditions` JSON column, seed the raw condition
from the matrix into it verbatim, and leave it unread for now.

The value is that the information survives. If it is dropped now, someone re-derives it from
ONDA's source in six months.

## Schema

```
college_oeuvre_files                       (or college_required_documents — your call)
  ondaKeys()                               id + uuid
  register_type_college_id                 FK → register_type_colleges, cascade
  document_key                             string, indexed
  title, title_ar (null), title_en (null)
  extensions                               json, the server-rule list
  is_required                              bool, default true
  display_order                            unsigned small int
  max_size_kb                              unsigned int, nullable
  allows_multiple                          bool, default true
  conditions                               json, nullable
  needs_review                             bool, default false
  timestamps + softDeletes

  unique (register_type_college_id, document_key)
```

Soft deletes: these are reference rows an officer may retire, so they qualify under the
project's classification. No `CascadeSoftDeletes`, no `Searchable`, no activity-log traits.

## Model

`belongsTo registerTypeCollege`, `HasUuidColumn`, the localised-title accessor with fallback
to `title`, `scopeRequired()`, `scopeOrdered()`. Cast `extensions` and `conditions` to array.

Add `hasMany` on `RegisterTypeCollege` — **that is the only change permitted** to the
membership models. If you find you need more, STOP and report.

No business logic: no filesystem access, no validation-rule construction in the model.

## Seeder

`CollegeOeuvreFileSeeder`, idempotent, `updateOrCreate` keyed on
`(register_type_college_id, document_key)`. **Never deletes.**

Resolve colleges by `code_college`, not by the matrix's numeric `college_id` — those ids are
ONDA's and need not match this database. If a code is not found, fail loudly with the code
named; a silently skipped college is 4 to 6 missing requirements nobody notices.

Register it in `DatabaseSeeder` **after** the membership seeder.

Two colleges are unreachable in this application: `REFERENTIEL_HORS_ADHESION` (7) and
`OEUVRE_FILM` (8), both `is_disabled`. The matrix gives each a single `numerique` row. Seed
them for completeness and note that they are unreachable, so the row counts reconcile.

## Verification

The matrix is a hand-generated document. Optionally dispatch **read-only** subagents over
`C:\Herd\ONDA` to check it against the source — the `get*($type)` methods in
`HandlesDroitsAuteurStep4` and `HandlesDroitsVoisinsStep4`, and
`OeuvreFactory::getValidationDataByCollegeCode()`. Split by college group so they run in
parallel.

Reading is parallel and safe; writing stays sequential in one agent. **Never write anything
under `C:\Herd\ONDA`.**

Where the source and the matrix disagree, **the source wins** — but report every discrepancy,
because it means the analysis has drifted.

If you skip the verification pass, say so plainly rather than implying the matrix was checked.

## Tests

- The seeder produces exactly **69 rows**.
- Running it twice changes nothing.
- Every row's `extensions` is a non-empty array of lowercase extensions.
- `(register_type_college_id, document_key)` is unique; `autorisation_auteur` exists under
  three different colleges without collision.
- Spot-check three colleges against §5.2 in full — MUSIQUE (6 rows), EDITEUR_MUSICAL (4 rows),
  PRESTATION_AUDIOVISUELLE (6 rows) — asserting keys, titles, order and extensions.
- College 6's `oeuvres_plastiques_graphiques` carries `max_size_kb = 102400`.
- Rows marked `needs_review` are exactly those the matrix shows as
  `AUCUNE RÈGLE TROUVÉE` — paste the count.
- Every college in the database has at least one document row.
- The localised title falls back to `title` when `title_ar` is null.

## Owned files

```
database/migrations/*                                    new only
database/seeders/CollegeOeuvreFileSeeder.php
database/seeders/DatabaseSeeder.php                      registration line only
app/Models/CollegeOeuvreFile.php
app/Models/RegisterTypeCollege.php                       the hasMany relation only
database/factories/CollegeOeuvreFileFactory.php
tests/Feature/Membership/*
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/**   app/Jobs/**   app/Actions/**   app/Http/**
resources/js/**   routes/**
app/Models/{RegisterType,TypeGestion,RegisterTypeMember,RegisterRoleAuteur,Oeuvre,MediaFile}.php
database/seeders/MembershipTypeSeeder.php
config/vault.php   config/filesystems.php
```

## Acceptance gate

1. Your Part 0 answers and the table-name decision.
2. Whether you ran the source verification pass, and every discrepancy found.
3. `migrate:fresh --seed` clean.
4. `SELECT COUNT(*)` → 69. Paste it, plus a per-college breakdown.
5. The `needs_review` count.
6. Seeder run twice — counts unchanged.
7. Tests green; `php artisan test --compact` at 0 failures.
8. Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at baseline.
9. Confirm nothing under `C:\Herd\ONDA` was modified.
10. `git status --short`

Then STOP. Wiring these requirements into the upload page is the next task.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
