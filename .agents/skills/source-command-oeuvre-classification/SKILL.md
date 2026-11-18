---
name: "source-command-oeuvre-classification"
description: "Migrated source command `oeuvre-classification`"
---

# source-command-oeuvre-classification

Use this skill when the user asks to run the migrated source command `oeuvre-classification`.

## Command Template

# Oeuvre classification — task series

Porting ONDA's four-level classification into onda_storage, so that an author selects a
branch before depositing and the required documents follow from that branch.

Four tasks. **Run them in order** — each depends on the one before. Section 0 is decisions
you make before any of them start.

---

## 0 — Decisions to settle first

### 0.1 Where does the classification live: the author, or the oeuvre?

In ONDA the wizard runs at **registration**. An author registers once as
"Auteur / Gestion collective / oeuvres musicales / Compositeur", and that is a property of
their membership.

The brief asks for it **per oeuvre**, before each deposit. That is a different model, and it
is defensible — an author may deposit a musical work and a literary one — but it is not what
the source system does, so decide it consciously rather than inheriting it by accident.

Recommended: store the classification on `oeuvres`, since the required documents are a
property of the deposit. If the author also has a fixed adhesion type, that belongs on the
user and can pre-fill the wizard.

### 0.2 Classification must be frozen at deposit time

`oeuvres` should carry the foreign keys **and a snapshot of `code_college`** as a plain
string.

The reason is legal, not technical. A registered deposit records the classification it was
filed under. If a college is renamed or re-coded three years later, the deposit's record
must not silently change with it. The FK gives you joins; the snapshot gives you the truth
as of the filing date.

### 0.3 Read-only access to `C:\Herd\ONDA`

Grant it with `Codex --add-dir C:\Herd\ONDA`.

**That project is READ-ONLY.** Never write, never run its migrations, never run its seeders,
never start its server. It is a live reference system. Any agent that modifies a file under
that path has failed the task regardless of what else it achieved.

### 0.4 The in-progress file

`resources/js/pages/author/works/Index.vue` has roughly 1200 lines of uncommitted work.
Task A renames that directory. **Commit it before starting**, or Task A will collide with it
and the diff becomes unreadable.

---

## TASK A — Rename `works` to `oeuvres`

Read AGENTS.md first. Mechanical refactor, no behaviour change.

Baseline: `php artisan test --compact` at 0 failures, `composer lint:check` and
`composer types:check` at 0. Hold all three.

### Scope

```
table     works       → oeuvres
model     Work        → Oeuvre
FK        work_id     → oeuvre_id      (media_files, upload_sessions, anything else)
routes    works.*     → oeuvres.*      (URI /author/oeuvres/*)
pages     author/works/  → author/oeuvres/
controller  Author/WorkController → Author/OeuvreController
```

Find every reference first — `work_id`, `works`, `Work::`, `$work`, `route('works.`,
`works.` in locale keys — and produce the complete list before changing anything. A rename
that misses one call site fails at runtime, not at build time.

### Migrations

Use `Schema::rename()` and `renameColumn()` in a new migration. Do not edit the original
create migrations: an already-migrated environment and a fresh install must converge to the
same schema, and both paths must be verified.

`renameColumn` on a column carrying a foreign key needs care on MySQL — the constraint may
need dropping and recreating. Test `migrate:fresh` **and** an incremental migrate from the
current state.

### Watch for

- **Route names.** `works.index` → `oeuvres.index`. Every `route()` call, every Wayfinder
  import, every test.
- **Wayfinder.** Regenerate with `php artisan wayfinder:generate --with-form`. Without the
  flag, every `.form()` call site fails type-checking — this has already bitten the project.
- **Locale keys.** `works.*` in `ar.json`, `fr.json`, `en.json`. Rename the keys and every
  `t('works.…')`.
- **The `work` disk.** `config/filesystems.php` defines a disk called `work` for pipeline
  plaintext. It has nothing to do with `works` the table. **Do not rename it.** Confirm
  explicitly that you left it alone.
- **Pipeline jobs.** They reference `MediaFile`, not `Work`, but check for `work_id` in
  `DecryptToTemp`, `RecordDeposit` and the rest.

### Gate

1. The complete reference list you produced before starting.
2. `migrate:fresh` and incremental migrate converge — confirm.
3. `php artisan test --compact` — 0 failures.
4. `composer lint:check`, `composer types:check`, `npm run types:check`,
   `npm run lint:check` — all 0.
5. A real browser upload end to end, through the pipeline to `ready`.
6. Confirm the `work` disk is untouched.
7. `git status --short`

Then STOP.

---

## TASK B — Reference tables and seeders

Read AGENTS.md first. Task A is merged.

### Use subagents for extraction — this is where they pay off

The source data is large: 4 types, 21 colleges, 100 members, 87 author roles, spread across
a 500-line seeder. **Retyping it by hand will introduce errors**, and errors in reference
data are invisible until a user picks the wrong branch.

Dispatch parallel read-only subagents over `C:\Herd\ONDA`, each returning structured data:

| Subagent | Reads | Returns |
|---|---|---|
| 1 | `database/seeders/RegisterTypesSeeder.php` | Every type, college, member and role as structured data: names, `code_college`, `code_qlt`, `type_gestion`, `available_in_registration`, `is_disabled` |
| 2 | The four `create_register_*` migrations plus their alters | Final column set per table, with types, nullability, indexes and FKs |
| 3 | `app/Enums/Auteurs/TypeGestionEnum.php` and the models | The enum cases with labels, plus the scopes and accessors that matter |
| 4 | `resources/views/livewire/auth/steps/two.blade.php` and `Register.php` hooks | The cascade rules: what resets what, which levels hide for which type, the filters applied at each level |

Parallel **reading** is safe and genuinely faster. Writing stays sequential and in one
agent — do not have subagents write to this repo.

Verify what comes back against `STEP_TWO_ANALYSIS.md` §3.2, which already contains the full
tree. **The row counts are stated in §7.1: 4 / 21 / 100 / 87.** If your extraction produces
different numbers, something was missed — reconcile before writing a single seeder line.

### Schema

Four tables, following **this project's** conventions, not ONDA's:

- `uuid` via `ondaKeys()`, not ONDA's `ulid`
- `HasUuidColumn`, `getRouteKeyName()` returning uuid
- Soft deletes per this project's classification — these are reference tables an admin may
  retire, so they qualify
- No `CascadeSoftDeletes`, no `Searchable`, no activity-log trait. Do not import ONDA's
  package dependencies.

```
register_types              name, slug, name_ar, name_en, status, is_disabled
register_type_colleges      register_type_id, code_college (unique), name, name_ar,
                            name_en, type_gestion, status, adhesion, is_disabled
register_type_members       register_type_college_id, name, code_qlt,
                            available_in_registration, status, is_disabled
register_role_auteurs       register_type_college_id, name, name_ar, name_en, status
```

`code_college` is the load-bearing column — Task C's document schema keys off it. Index it
and keep it unique.

`type_gestion` becomes a PHP enum: 1 collective, 2 individual, 3 simple protection. Port
the labels in all three locales.

### Classification columns on `oeuvres`

```
register_type_id             FK, nullable until the wizard is wired
type_gestion                 tinyint, nullable
register_type_college_id     FK, nullable
register_type_member_id      FK, nullable
code_college_snapshot        string, nullable   ← frozen at deposit, see §0.2
```

Nullable, because existing rows predate the wizard. A later task can make them required for
new deposits.

### Seeder

`RegisterTypesSeeder`, idempotent, matching ONDA's approach: `firstOrCreate` on types,
`updateOrCreate` on colleges keyed by `(register_type_id, name, type_gestion)`, and member
matching by `code_qlt` first. It must never delete.

Register it in `DatabaseSeeder` **after** the geography seeders.

Note two things from the source, and preserve them:

- Several college names carry **leading spaces** (`" oeuvres musicales"`). They are in the
  live data. Decide whether to trim, and say which you chose — if you trim, `updateOrCreate`
  keyed on name will not match existing rows.
- `REFERENTIEL_HORS_ADHESION` is seeded but hidden from registration, and its members all
  carry `available_in_registration = false`. Port it with its flags; the filtering is Task D's
  concern, not the seeder's.

### Tests

- Seeder produces exactly 4 / 21 / 100 / 87 rows.
- Running it twice changes nothing.
- Every college has a unique non-null `code_college`.
- Every college's `type_gestion` is a valid enum case.
- FK chain: deleting a type cascades correctly.
- Spot-check three branches against `STEP_TWO_ANALYSIS.md` §3.2 — MUSIQUE, LOGICIEL and
  PRODUCTION_PHONOGRAMME — asserting member names and `code_qlt` values.

### Gate

1. The extracted tree, with row counts, reconciled against §7.1's 4 / 21 / 100 / 87.
2. `migrate:fresh --seed` clean, and the counts verified in the database.
3. Tests green; `php artisan test --compact` at 0 failures.
4. All four lint and type checks at 0.
5. Confirm nothing under `C:\Herd\ONDA` was modified — `git status` in that repo, if it is
   one, or file timestamps.
6. `git status --short`

Then STOP. No UI.

---

## TASK C — Required documents per branch

Read AGENTS.md first. Task B is merged.

### What this builds

Given a `code_college`, return the list of documents an author must upload for that oeuvre:
label, whether required, help text, accepted extensions, and any conditional visibility.

`STEP_TWO_ANALYSIS.md` Appendix A maps all 17 college codes to their schema method in ONDA's
`HandlesDroitsAuteurStep4` and `HandlesDroitsVoisinsStep4`. Appendix A.4 spells out the
cards for each.

### Extraction

Subagents again, one per group of college codes, reading the `DA4` and `DV4` methods listed
in Appendix A.2. Each returns the card list as structured data.

Cross-check against Appendix A.4, which already documents them. Where the code and the
document disagree, **the code wins** — but report the discrepancy, since it means the
analysis has drifted from the source.

### Design

Store the schema as **configuration or a database table, not a `match` statement**. ONDA
uses a 2700-line component with a `match` over college codes; that is what you are porting
away from, not toward.

A `required_documents` table keyed by `code_college` lets an ONDA officer adjust the
requirements without a deploy — which is what will actually be asked for once this is live.
Argue for or against, then decide.

Handle the fallback ONDA has: an unknown or empty college code yields a single generic
card ("Oeuvre format numérique").

### Integration

An endpoint, or Inertia props, returning the document list for a chosen branch. Task D
consumes it. Do not build UI here.

### Gate

1. All 17 college codes covered, with the card list for each.
2. Any discrepancies between the code and Appendix A.4, listed.
3. Your storage decision and its reasoning.
4. Tests: every code returns a non-empty list; an unknown code returns the fallback.
5. `php artisan test --compact` — 0 failures; all lint and type checks at 0.
6. `git status --short`

Then STOP.

---

## TASK D — The selection wizard

Read AGENTS.md first. Tasks A, B and C are merged.

Deliver a plan first — the screens, the cascade behaviour, and the wireframe. Wait for
approval.

### The cascade

Four levels, from `STEP_TWO_ANALYSIS.md` §3:

```
1  Type              Auteur · Editeur · Artiste-interprète · Producteur
2  Type de gestion   visible for Auteur ONLY; hidden and forced for the other three
3  Collège           filtered by type, and by gestion when type is Auteur
4  Qualité           filtered by available_in_registration
```

Rules that must be preserved exactly — each is a real behaviour in the source system:

- Level 2 is **hidden** for Editeur, Artiste-interprète and Producteur. Their colleges carry
  `type_gestion = 1` implicitly.
- `REFERENTIEL_HORS_ADHESION` never appears in the college list.
- Level 4 shows only members with `available_in_registration = true`.
- Changing a level **resets every level below it**. §3.6 of the analysis has the exact reset
  table — follow it rather than improvising, because a stale lower selection produces an
  invalid branch that validates cleanly.
- A disabled option renders but is not selectable, with an "unavailable" suffix.

### After selection

Once the branch is chosen, show the documents Task C returns, then the existing upload flow.

The deposit's classification is written to `oeuvres` at creation, including the
`code_college` snapshot.

### Design

Match the existing dashboard. The author-side deposit work established the vocabulary —
reuse it. Full ar/fr/en with correct RTL, `<bdi>` around Latin runs as established.

Do not import ONDA's Livewire markup or its Blade structure. This is Vue 3 and Inertia; port
the **behaviour**, not the implementation.

### Gate

1. The plan, approved, then the build.
2. Every branch in §3.2 reachable — walk at least six, including one per type.
3. Reset behaviour matches §3.6 — demonstrate with two cases.
4. Documents change correctly when the college changes.
5. A full deposit end to end: wizard, upload, pipeline to `ready`, with the classification
   stored including the snapshot.
6. Screens in Arabic and French.
7. All tests green; all lint and type checks at 0.
8. `git status --short`

Then STOP.

---

## Git policy — applies to every task above

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End each task by printing `git status --short`.
- **Never write anything under `C:\Herd\ONDA`.** It is a read-only reference.
