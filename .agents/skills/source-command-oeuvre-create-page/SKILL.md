---
name: "source-command-oeuvre-create-page"
description: "Migrated source command `oeuvre-create-page`"
---

# source-command-oeuvre-create-page

Use this skill when the user asks to run the migrated source command `oeuvre-create-page`.

## Command Template

Task — Reference tables and the oeuvre create page. Read AGENTS.md first.

The `works` → `oeuvres` rename is merged and a real upload has been verified end to end.

This task builds the classification data and the create page that uses it. The
required-documents engine is a separate, later task — it belongs on the upload page, after
a classification exists.

Baseline: `php artisan test --compact` at 0 failures; Pint, PHPStan, `npm run types:check`
and `npm run lint:check` all at 0. Hold all of it.

## Reference material

`STEP_TWO_ANALYSIS.md` — a verified reverse-engineering of ONDA's Step Two, with the full
option tree in §3.2, the table definitions in §5, the models in §6, the seeder counts in
§7.1, and the reset rules in §3.6. Read it before touching the source project.

`C:\Herd\ONDA` — grant read access with `Codex --add-dir C:\Herd\ONDA`.

**That project is READ-ONLY.** Never write a file, never run its migrations or seeders,
never start its server. It is a live reference system. Modifying anything under that path
fails the task regardless of what else is achieved.

## Part 1 — Extract the option tree, using subagents

The data is large: 4 types, 21 colleges, 100 members, 87 author roles, spread across a
500-line seeder. **Retyping it by hand will introduce errors**, and errors in reference data
stay invisible until a user selects a wrong branch.

Dispatch parallel read-only subagents, each returning structured data:

| Subagent | Reads | Returns |
|---|---|---|
| 1 | `database/seeders/RegisterTypesSeeder.php` | Every type, college, member and role: names, `code_college`, `code_qlt`, `type_gestion`, `available_in_registration`, `is_disabled` |
| 2 | The four `create_register_*` migrations and their alters | Final column set per table: types, nullability, indexes, foreign keys |
| 3 | `app/Enums/Auteurs/TypeGestionEnum.php` and the four models | Enum cases with labels; the scopes and accessors that matter |
| 4 | `resources/views/livewire/auth/steps/two.blade.php`, `app/Livewire/Auth/Register.php` hooks, `BaseComponet.php` | The cascade rules: what resets what, which levels hide for which type, the filter applied at each level |

Parallel **reading** is safe and genuinely faster. All writing stays sequential, in one
agent — subagents must not write to this repository.

**Verification is built in.** §7.1 states the row counts: **4 types, 21 colleges, 100
members, 87 roles.** If your extraction produces different numbers, something was missed.
Reconcile before writing a single seeder line.

## Part 2 — Schema

Four reference tables, following **this project's** conventions, not ONDA's:

- `uuid` via `ondaKeys()`, not ONDA's `ulid`
- `HasUuidColumn`, `getRouteKeyName()` returning uuid
- Soft deletes — these are reference rows an admin may retire, so they qualify under the
  project's classification
- No `CascadeSoftDeletes`, no `Searchable`, no activity-log trait. Do not pull in ONDA's
  package dependencies.

```
register_types            name, slug, name_ar, name_en, status, is_disabled
register_type_colleges    register_type_id, code_college (unique, indexed), name,
                          name_ar, name_en, type_gestion, status, adhesion, is_disabled
register_type_members     register_type_college_id, name, code_qlt,
                          available_in_registration, status, is_disabled
register_role_auteurs     register_type_college_id, name, name_ar, name_en, status
```

`code_college` is load-bearing — the later documents engine keys off it. Unique and indexed.

`type_gestion` becomes a PHP enum: 1 collective, 2 individual, 3 simple protection. Port the
labels in all three locales.

### Classification columns on `oeuvres`

```
register_type_id             FK, nullable
type_gestion                 tinyint, nullable
register_type_college_id     FK, nullable
register_type_member_id      FK, nullable
code_college_snapshot        string, nullable
```

Nullable because existing rows predate the wizard.

**`code_college_snapshot` is not redundant with the FK.** A registered deposit records the
classification it was filed under. If a college is renamed or re-coded in three years, the
FK follows the change and the deposit's record silently changes with it. The snapshot keeps
the truth as of the filing date. Comment it so nobody removes it as duplication.

## Part 3 — Seeder

`RegisterTypesSeeder`, idempotent, matching the source's approach: `firstOrCreate` on types,
`updateOrCreate` on colleges keyed by `(register_type_id, name, type_gestion)`, members
matched by `code_qlt` first. It must never delete.

Register it in `DatabaseSeeder` after the geography seeders.

Two details from the source, both real:

- Several college names carry **leading spaces** (`" oeuvres musicales"`). Decide whether to
  trim and say which you chose — if you trim, an `updateOrCreate` keyed on name will stop
  matching rows seeded earlier.
- `REFERENTIEL_HORS_ADHESION` is seeded but hidden from registration, and all its members
  carry `available_in_registration = false`. Seed it with its flags intact; the filtering
  belongs to the query layer, not the seeder.

## Part 4 — The create page

Rewrite `resources/js/pages/author/oeuvres/Create.vue` at `/author/oeuvres/create`.

### Form

Title, description, then the four-level cascade:

```
1  Type de déclarant   Auteur · Editeur · Artiste-interprète · Producteur
2  Type de gestion     visible for Auteur ONLY; hidden and implicit for the other three
3  Collège             filtered by type, and by gestion when type is Auteur
4  Qualité             filtered by available_in_registration
```

One page with the cascade revealing progressively — not a multi-step wizard. The author is
already authenticated; this screen classifies a deposit, it does not enrol a member. The
reference screenshot shows all four selects on a single card, which is the right shape.

### Cascade rules — preserve each one exactly

From §3 of the analysis. These are real behaviours, not incidental:

- **Level 2 is hidden** for Editeur, Artiste-interprète and Producteur. Their colleges carry
  `type_gestion = 1` implicitly; do not show a control the source system does not show.
- **`REFERENTIEL_HORS_ADHESION` never appears** in the college list.
- **Level 4 shows only** members with `available_in_registration = true`.
- **Changing a level resets every level below it.** §3.6 has the exact reset table — follow
  it rather than improvising. A stale lower selection produces an invalid branch that
  passes validation cleanly, which is the worst kind of bug here.
- A **disabled** option renders but is not selectable, with an "unavailable" suffix.

### Server-side validation of the whole branch

The client can send any combination. Validate the **relationships**, not just that each id
exists:

- The college belongs to the chosen type.
- The member belongs to the chosen college.
- For Auteur, the college's `type_gestion` matches the chosen gestion.
- The college is not `REFERENTIEL_HORS_ADHESION`.
- The member has `available_in_registration = true`.

A rule per relationship, each with a test that posts a deliberately mismatched branch.

### After creation

The oeuvre is created as `draft` with its classification and the `code_college_snapshot`,
then the author is redirected to the show page to upload files.

### Locking the classification

The classification determines which documents are required. Changing it after files are
uploaded would invalidate them.

Lock it once the first file is uploaded. Before that, allow editing. State how you
implemented the lock and enforce it in the policy, not only in the UI.

### Data delivery

Send the full tree to the page as Inertia props in one payload and cascade client-side — the
whole tree is 4 types, 21 colleges and 100 members, small enough that per-level round trips
would add latency for nothing. Cache it server-side; it changes rarely.

Do not build a JSON endpoint per level.

### Design

Match the existing dashboard: dark theme, `components/ui` primitives, Tailwind v4, IBM Plex.
Full ar/fr/en with correct RTL, `<bdi>` around Latin runs as the deposit pages already do.

Names come from the reference tables in the user's locale, falling back to French.

## Tests

- The seeder produces exactly 4 / 21 / 100 / 87 rows.
- Running the seeder twice changes nothing.
- Every college has a unique, non-null `code_college`.
- Spot-check three branches against §3.2 — MUSIQUE, LOGICIEL and PRODUCTION_PHONOGRAMME —
  asserting member names and `code_qlt`.
- Each relationship validation rule, with a mismatched branch posted.
- A college from `REFERENTIEL_HORS_ADHESION` is refused.
- A member with `available_in_registration = false` is refused.
- Creating an oeuvre stores all five classification fields including the snapshot.
- The classification cannot be changed once a file exists.
- An author cannot create an oeuvre for another author.

## Owned files

```
database/migrations/*                    ← new migrations only
database/seeders/RegisterTypesSeeder.php
database/seeders/DatabaseSeeder.php      ← registration only
app/Models/{RegisterType,RegisterTypeCollege,RegisterTypeMember,RegisterRoleAuteur}.php
app/Models/Oeuvre.php                    ← relations and casts only
app/Enums/TypeGestion.php
app/Http/Controllers/Author/OeuvreController.php
app/Http/Requests/Author/**
app/Policies/OeuvrePolicy.php
app/Rules/**
resources/js/pages/author/oeuvres/Create.vue
resources/js/components/oeuvre/**
resources/js/locales/{ar,fr,en}.json
routes/author.php
tests/**
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/Vault/**
app/Jobs/**
app/Actions/Upload/**
config/vault.php
config/filesystems.php
resources/js/stores/**
```

## Acceptance gate

1. The extracted tree with row counts, reconciled against §7.1's 4 / 21 / 100 / 87.
2. Your decision on the leading spaces in college names.
3. `migrate:fresh --seed` clean, counts verified in the database.
4. All tests green; `php artisan test --compact` at 0 failures.
5. Pint, PHPStan, `npm run types:check`, `npm run lint:check` — all 0.
6. **Run `npm run build` before the browser check.** A stale `public/build` has now caused
   two separate false failures in this project — once as a total upload failure, once as 12
   test failures after the rename. Do not skip it.
7. In the browser: walk at least six branches, one per type, and confirm the cascade resets
   correctly when a higher level changes. Create one oeuvre and confirm the classification
   including the snapshot is stored.
8. Screenshots or descriptions in Arabic and French.
9. `git status --short`

Then STOP. The required-documents engine is the next task.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- **Never write anything under `C:\Herd\ONDA`.**
- End by printing `git status --short`.
