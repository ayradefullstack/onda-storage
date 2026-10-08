---
name: "source-command-step-one-schema"
description: "Migrated source command `step-one-schema`"
---

# source-command-step-one-schema

Use this skill when the user asks to run the migrated source command `step-one-schema`.

## Command Template

Task — Step 1: classification schema, models, relationships and seeder. Read AGENTS.md first.

Backend only. **No UI, no controllers, no routes.** The create page comes after this.

Baseline: Pest 225 passed / 2 skipped, Pint 0, PHPStan 0, `npm run types:check` 0,
`npm run lint:check` 0, Vitest 24/24. Hold all of it.

## Source material — and which one wins

C:\Users\MOHAMEDADDABENKOSSEI\Downloads\onda_db.sql

| File | Role |
|---|---|
| `step-one-select.md` | **The specification.** The seeder logic and data to implement. |
| `onda_db.sql` | **The verification anchor.** A dump of the live ONDA tables. |
| `STEP_TWO_ANALYSIS.md` | Background: the cascade rules, §3.2's tree, §5's column definitions. |

I have already verified that the spec reproduces the dump exactly:

| Table | Dump | Spec produces |
|---|---|---|
| `register_types` | 4 | 4 |
| `register_type_colleges` | 21 | **22** — see below |
| `register_type_members` | 100 | 100 |
| `register_role_auteurs` | 87 | 87 |

Use these as hard assertions. If your seeder produces different numbers, it is wrong —
do not adjust the expected numbers to match your output.

**The one intended difference:** `step-one-select.md` adds `OEUVRE_FILM` ("Œuvres film",
type 1, gestion 1) which is **not** in the dump. It is marked `is_disabled = true` in the
spec's `match`. So the target is 22 colleges: the dump's 21, plus this one, disabled.
Confirm that reading before you rely on it.

## Three defects in the spec — resolve each and report

**1. `$typeGestion` is an array, used as a scalar.**

```php
$typeGestion = [1 => ['Collective management'], ...];
'MUSIQUE' => [' oeuvres musicales', $typeGestion[1]],   // passes ['Collective management']
// later:
'type_gestion' => $collegeData[1],                       // expects 1
```

The dump settles it: `register_type_colleges.type_gestion` is `tinyint unsigned` holding
1, 2 or 3. Use the integer. Say what you did.

**2. `PHOTOGRAPHIE` and `SUSCEPTIBLE_GESTION_COLLECTIVE`** appear in `$membersByCode` and
`$rolesByCode` but no college carries either code. They are dead entries that seed nothing.
Leave them out and note it, or keep them inert and note that — either is fine, but say which.

**3. `RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION` and `CODE_OEUVRE_FILM`** are model
constants the spec references. Define them on the model.

## The new `type_gestions` table — one real decision

The brief asks for a table where ONDA has a hardcoded enum. The spec seeds it **only for
`register_type_id = 1` (Auteur)**, three rows — which matches the UI, where the gestion
select appears for Auteur alone.

But every college of types 2, 3 and 4 also carries `type_gestion = 1`. So a foreign key from
`register_type_colleges.type_gestion` to a table scoped to Auteur would be semantically
wrong: a Producteur college pointing at a row that belongs to Auteur.

Two coherent resolutions:

- **(a)** `type_gestions` is a label lookup for the Auteur dropdown. `register_type_colleges.type_gestion`
  stays a plain `tinyint` with no FK, exactly as the dump has it. Minimal, matches the data.
- **(b)** Seed `type_gestions` for all four register types — twelve rows — and add a real FK
  `type_gestion_id`. Normalised, but it changes the column and invents rows the source system
  does not have.

**Recommended: (a).** Pick one, implement it, and state the reasoning in a comment on the
migration. Do not leave it implicit.

## Migrations

Five new migrations. Follow **this project's** conventions, not ONDA's:

- `ondaKeys()` for `id` + `uuid`. **Not** ONDA's `ulid` — the dump has `ulid char(26)`;
  this project uses UUIDv7 as the route key.
- `HasUuidColumn` on every model, `getRouteKeyName()` returning uuid.
- Soft deletes on all five: reference rows an admin may retire, which qualifies under the
  project's classification.
- **No** `CascadeSoftDeletes`, `Searchable`, `HasSlug`, or activity-log traits. Do not pull
  in ONDA's package dependencies.

```
register_types
  name, slug, name_ar (null), name_en (null),
  status tinyint default 1, is_disabled bool default false

type_gestions
  register_type_id FK → register_types (cascade),
  name, name_ar (null), name_en (null)

register_type_colleges
  register_type_id FK → register_types (cascade),
  code_college (unique, indexed, nullable),
  name, name_ar (null), name_en (null),
  status tinyint default 1,
  type_gestion tinyint default 1 (indexed),
  code_dv (nullable), adhesion bool default true,
  is_disabled bool default false

register_type_members
  register_type_college_id FK → register_type_colleges (cascade),
  name, code_qlt (nullable, indexed),
  status tinyint default 1, is_disabled bool default false,
  available_in_registration bool default true (indexed)

register_role_auteurs
  register_type_college_id FK → register_type_colleges (cascade),
  name, name_ar (null), name_en (null),
  status tinyint default 1, is_disabled bool default false
```

**Do not port `ahdesion`.** The dump carries both `ahdesion` and `adhesion` — the first is a
misspelling that was superseded by an alter migration and left behind. Keep only `adhesion`,
and note the omission.

`code_college` is load-bearing: the later documents task keys off it. Unique and indexed.

## Models and relationships

```
RegisterType        hasMany typeGestions
                    hasMany registerTypeColleges
                    scopeActive()

TypeGestion         belongsTo registerType

RegisterTypeCollege belongsTo registerType
                    hasMany registerTypeMembers
                    hasMany registerRoleAuteurs
                    scopeActive()
                    scopeAvailableForRegistration()   ← excludes REFERENTIEL_HORS_ADHESION
                    scopeForGestion(int $g)

RegisterTypeMember  belongsTo registerTypeCollege
                    scopeAvailableInRegistration()

RegisterRoleAuteur  belongsTo registerTypeCollege
```

**Localised name accessor.** `name_ar` and `name_en` are nullable on most rows — the dump
shows them NULL nearly everywhere. Add an accessor that returns the localised name when
present and falls back to `name`, mirroring ONDA's `name_global`. Without the fallback, an
Arabic UI renders empty selects.

No business logic in models: no filesystem access, no encryption, no HTTP.

## Seeder — `MembershipTypeSeeder`

Implement `step-one-select.md`'s logic. Preserve these behaviours exactly:

- **Null every `code_college` first**, before reassigning. The spec does this so a partial
  earlier run cannot collide on the unique index.
- `firstOrCreate` on types by name.
- `updateOrCreate` on colleges keyed by `(register_type_id, name, type_gestion)`, setting
  `code_college`, `code_dv`, `is_disabled`.
- `code_dv`: null for types 1 and 2 (droits d'auteur), the college code for types 3 and 4
  (droits voisins).
- `is_disabled = true` for `REFERENTIEL_HORS_ADHESION`, `OEUVRE_FILM` and `PROTECTION_SAMPLE`.
- `firstOrCreate` on roles by `(college_id, name)`.
- `syncMember`: match by `code_qlt`, then `legacy_names`, then `name`; update or create.
  **Never delete.** Keep the `member()` helper's four-argument shape.
- Deferred colleges (the seven simple-protection ones) run in a second pass, each getting a
  default `Auteur` role, and a fallback member named after the college only when no member
  list exists for that code.

**Leading spaces in college names are real data** — `" oeuvres musicales"` with the space.
The dump confirms it. `updateOrCreate` is keyed on name, so trimming would fail to match
anything seeded earlier. Keep them verbatim and add a comment saying why, or someone will
"fix" them later.

Register it in `DatabaseSeeder` after the geography seeders.

## Tests

- Seeder produces exactly 4 types, 22 colleges, 100 members, 87 roles.
- Running it twice changes nothing — assert the same counts and no duplicate `code_college`.
- Every college has a unique, non-null `code_college`.
- `type_gestion` is always 1, 2 or 3.
- `code_dv` is null for types 1 and 2, non-null for types 3 and 4.
- `REFERENTIEL_HORS_ADHESION` and `OEUVRE_FILM` are `is_disabled = true`; everything else
  is false.
- All six `REFERENTIEL_HORS_ADHESION` members have `available_in_registration = false`.
- Auteur with `type_gestion = 1` yields exactly MUSIQUE, DRAMATIQUE, LITTERAIRE_EMISSION,
  REFERENTIEL_HORS_ADHESION and OEUVRE_FILM.
- Auteur with `type_gestion = 3` yields exactly the seven deferred colleges.
- Spot-check three branches against the dump: MUSIQUE (5 members), PRESTATION_AUDIOVISUELLE
  (21 members), PRODUCTION_PHONOGRAMME (3 members), asserting names and `code_qlt` values.
- The localised name accessor falls back to `name` when `name_ar` is null.

## Not in this task

No classification columns on `oeuvres` yet, no controllers, no routes, no Vue, no
required-documents mapping. Those follow once this data is correct.

## Owned files

```
database/migrations/*                             new only
database/seeders/MembershipTypeSeeder.php
database/seeders/DatabaseSeeder.php               registration line only
app/Models/{RegisterType,TypeGestion,RegisterTypeCollege,RegisterTypeMember,RegisterRoleAuteur}.php
database/factories/**                             for the five models
tests/Feature/Membership/*
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/**   app/Jobs/**   app/Actions/**   app/Http/**
resources/js/**   routes/**   config/vault.php   config/filesystems.php
app/Models/Oeuvre.php   app/Models/MediaFile.php
```

Never write anything under `C:\Herd\ONDA`.

## Acceptance gate

1. Your resolution of the three spec defects, and your `type_gestions` decision with reasoning.
2. `migrate:fresh --seed` clean.
3. The four counts verified in the database: 4 / 22 / 100 / 87. Paste the query output.
4. Seeder run twice — counts unchanged.
5. Tests green; `php artisan test --compact` at 0 failures.
6. Pint, PHPStan, `npm run types:check`, `npm run lint:check`, Vitest — all at baseline.
7. A real browser upload still works end to end through to `ready` — this task adds tables
   near nothing the pipeline touches, but confirm rather than assume.
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
