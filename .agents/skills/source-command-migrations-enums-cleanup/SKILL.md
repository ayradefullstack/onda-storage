---
name: "source-command-migrations-enums-cleanup"
description: "Migrated source command `migrations-enums-cleanup`"
---

# source-command-migrations-enums-cleanup

Use this skill when the user asks to run the migrated source command `migrations-enums-cleanup`.

## Command Template

Task: consolidate and clean all database migrations (one migration per table), and move every enumerable column to a PHP backed-enum class stored as an unsigned tiny integer. Read AGENTS.md first.

Context: the project is still in development, so the migration history can be rewritten and the database rebuilt with `migrate:fresh`. The goal is a migration folder that reads like a schema document: one file per table, ordered by dependency, easy to review.

The work has two phases with a hard gate between them:
- **Phase 1** is a pure refactor: the resulting schema must be identical to today's, except for the explicitly listed renames.
- **Phase 2** is the intentional change: enums and tiny-integer columns.

Do not mix them.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Safety rules (read first)
- Before any `migrate:fresh`, take a dump of the local dev database: `mysqldump --no-data` and a full dump into `storage/backups/` (gitignored). Report the paths.
- Run `php artisan optimize:clear` first. Never run migrations with a cached config.
- `migrate:fresh` / `migrate:reset` are allowed **only** on:
  - the local dev database;
  - two scratch databases you create for the comparison (`<db>_schema_before`, `<db>_schema_after`).
  Never on any remote database. Tests always run through `composer test` (TestEnvironmentGuard).
- After the local rebuild, encrypted vault files stored locally will no longer match any database row. **Do not delete them.** Report the vault storage path so I clean it myself, and never touch the vault master key.
- Package-published migrations (Spatie permission, Sanctum, notifications, Telescope…) stay as separate files. Do not merge package tables into app tables.

## Phase 0 — Inventory and proposal (no code; STOP and wait for my approval)
1. **Migration map:** for each table, every migration that touches it (create, add/change/drop columns, indexes, FKs, rename), in run order.
   - Flag the migrations that contain data changes (inserts/updates), raw SQL (triggers, checks, generated columns), or `Schema::rename`.
2. **The `works` → `oeuvres` rename:**
   - every trace of `work` in the schema: table, `work_id` columns, indexes, FK names;
   - every trace in the code: models, relations, factories, seeders, morph map / `*_type` values, notification `data`, audit actions, translations, routes, TS types, tests.
3. **Enumerable columns:** every column holding a fixed set of values. This covers string status/type columns, MySQL native `enum()` columns, and magic integers. For each one, give:
   - the table and column;
   - the current type and values;
   - where the values are used (PHP, Vue/TS, tests, seeders);
   - whether a status machine or another critical piece reads it (e.g. `OeuvreStatusMachine`, the audit log, the upload/file statuses, the deposit hash chain).
4. **Proposed enum list:** class, namespace, cases with their integer values, default value, whether the column is nullable, whether it is indexed (and why).
5. **Proposed migration list:** final file names, in order, with the tables in each.
6. **Hash chain check:** does the deposit hash-chain payload include any of these columns? If so, how will its representation stay stable?
7. **Other environments:** the deployed server(s) and teammates' machines have the old migration names in their `migrations` table. Write the exact reset procedure (see "Other environments" below) adapted to what you found.

STOP after Phase 0.

## Phase 1 — Consolidate migrations (schema-equivalent)

### Rules
- **One migration per table:** `create_<table>_table`. It contains every column, index and foreign key the table has today, written as if it had been designed this way from the start.
- **Keep the framework defaults:**
  - `0001_01_01_000000_create_users_table` (users, password_reset_tokens, sessions), `…_create_cache_table`, `…_create_jobs_table`;
  - merge the later user additions (first/last name, etc.) into the users migration.
- **Naming and order:**
  - timestamps in a clean sequence, grouped by domain, with gaps left for later inserts, e.g. `2026_01_01_000100_create_colleges_table`, `…_000200_…`;
  - every parent table comes before its children; pivot tables come after both parents.
- **Column order inside each table:**
  1. `id`;
  2. foreign keys;
  3. business columns;
  4. enum/status columns;
  5. booleans/flags;
  6. JSON;
  7. dates;
  8. `timestamps()`;
  9. `softDeletes()`;
  10. composite indexes and unique constraints at the end, each with a short comment saying which query it serves.
- **Foreign keys:**
  - `foreignId('x_id')->constrained()` with an **explicit** `cascadeOnDelete()` / `restrictOnDelete()` / `nullOnDelete()`;
  - keep exactly today's delete behaviour. Never introduce a cascade on deposits, oeuvre reviews, the audit log or vault file rows: they are append-only or legally relevant.
- **`works` → `oeuvres`:** the table is created directly as `oeuvres`, with `oeuvre_id` columns everywhere. Delete the rename migration and the old `works` create migration. Fix every code trace from Phase 0 (morph map, models, factories, TS, translations, tests).
- **No data changes in migrations:** reference data moves to idempotent seeders (`updateOrCreate`), called from `DatabaseSeeder`. Raw SQL that is part of the schema (triggers, checks) stays, in the migration of its table.
- Every `down()` is `Schema::dropIfExists('<table>')`.
- Delete the old migration files with the filesystem (not git). Git history keeps them.

### Verification (mandatory)
1. Run the **old** migrations on `<db>_schema_before` and the **new** ones on `<db>_schema_after`.
2. Export both schemas in a normalised form: tables, columns (type, length, nullable, default, unsigned, comment), indexes, unique keys, foreign keys with their on-delete/on-update rules, triggers. Use information_schema to JSON, sorted, or `SHOW CREATE TABLE` with the auto-increment counters stripped.
3. **Diff them.** The only allowed differences are the listed `works` → `oeuvres` renames, and column order where it was reorganised. Paste the diff.
4. Run `php artisan migrate:fresh --seed` on the local dev DB, then `composer test`. Both must be green.

**STOP after Phase 1** with the diff, the new file list and the test results. I review and commit Phase 1 before Phase 2.

## Phase 2 — Enum classes and tiny-integer columns

### Enum convention
- **Path = namespace (PSR-4):** `app/Enums/<Model>/<Attribute>Enum.php`, namespace `App\Enums\<Model>`. Example: `App\Enums\User\StatusEnum`. The model folder is singular, and the folder and the namespace must match exactly.
- When two enums with the same short name are used in one file, import them with explicit aliases (`use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;`).
- **Int-backed:** `enum StatusEnum: int`, values starting at 1, explicit on every case.
  - A value is **permanent**: never renumber, never reuse a removed value.
  - New cases take the next free number.
- **A shared contract and trait** to avoid repeating boilerplate:
  - `App\Enums\Contracts\HasPresentation` declares `label(): string`, `color(): string`, `icon(): string`.
  - `App\Enums\Concerns\EnumHelpers` provides:
    - `key(): string` (snake_case of the case name);
    - `fromKey(string $key): ?self` (matches on the case name/key, never on translated text);
    - `values(): array`;
    - `options(): array` (`[['value', 'key', 'label']]` for selects);
    - `dbComment(): string` (`"App\Enums\User\StatusEnum: 1=active, 2=inactive, …"`).
- Each enum implements the contract:
  - `label()` uses the translation key convention `enums.<model>.<attribute>.<key>` (lang files ar/fr/en);
  - `color()` returns a semantic name that matches an existing badge variant in the UI (`success`, `warning`, `destructive`, `info`, `neutral`…), with no new colours;
  - `icon()` returns an icon name from the icon set the frontend actually uses (e.g. lucide), not `bx` classes.
- No `DELETED` case where the model already uses `SoftDeletes`: deletion is `deleted_at`. Report any such overlap.

### Column pattern (every enum column)
```php
$table->unsignedTinyInteger('status')
    ->default(StatusEnum::ACTIVE->value)   // only when a default makes sense
    ->comment(StatusEnum::dbComment())
    ->index();                             // only if queried alone; prefer composite indexes
```
- Index rules:
  - an enum column used in list filters together with an owner column gets a **composite** index (e.g. `['user_id', 'status']`), not a lone index;
  - a lone index only where queries filter on that column alone.
  - Justify each index in Phase 0.
- Nullable only where "no value" is a real state.
- MySQL native `enum()` columns are all replaced by this pattern, so adding a case no longer needs a migration.
- These changes are made **directly in the consolidated create migrations** from Phase 1 (development only: no alter migrations).
- Note in AGENTS.md: an enum class referenced by a migration must never be renamed or deleted without updating the migration.

### Code
- **Models:** `casts()` maps every enum column to its class. No raw integers or status strings anywhere in app code.
  - Replace every `'submitted'`, `'ready'`, `1`… with the enum cases in queries, policies, actions, jobs, status machines, notifications, factories, seeders and tests.
  - Report the grep before and after.
- **Validation:** `Rule::enum(StatusEnum::class)`. Filters accept the **key** in the URL (`?status=submitted`), converted with `fromKey()`. URLs stay readable, and unknown keys are ignored safely.
- **Status machine:** `OeuvreStatusMachine` and every transition table use the enum cases. Behaviour is unchanged.
- **Hash chain:** if the deposit payload includes an enum column, serialise it with a stable, documented representation, and add a test.
- **Frontend:**
  - Inertia props send enum fields as `{ value, key }` (through a resource/`toArray`, consistently).
  - Generate `resources/js/enums/<model>/<attribute>.ts` from the PHP enums with an artisan command (e.g. `php artisan enums:typescript`): a `const` object of keys to values, plus a type.
  - Vue code compares with these constants, never with literals.
  - Labels come from the frontend locales with the same key convention, `enums.<model>.<attribute>.<key>`.
  - A Pest test fails if the generated TS files are out of date with the PHP enums.
- Delete code made dead by the migration (old string constants, helper maps).

### Tests
- **Unit, for each enum:**
  - values are unique and stable (snapshot of value ↔ key);
  - `label()` has a translation in ar/fr/en;
  - `fromKey()` round-trips;
  - `dbComment()` lists every case.
- **Arch (Pest):**
  - every class in `App\Enums` is int-backed and implements `HasPresentation`;
  - every enum column in the schema has a cast on its model (compare information_schema comments with the model casts).
- **The status machine and the existing feature tests pass unchanged in behaviour.**
- **Filters:** a key in the URL filters correctly; an unknown key does not crash.
- **The TS-generation freshness test.**
- **Schema check:** every enum column is `tinyint unsigned` with a comment naming its enum class.

### Phase 2 verification
- `php artisan migrate:fresh --seed`, then `composer test` green. Vitest, types, lint and format all 0. `npm run build`.
- A schema diff between the end of Phase 1 and the end of Phase 2: only the enum columns (type, default, comment, indexes) may differ. Paste it.
- Manual smoke test in the browser (ar and fr): login, create an oeuvre, upload, submit, admin review (accept/reject), filters on the lists, the author dashboard counts. Describe each.

## Other environments (write it, do not run it)
Add a short "Migration reset" section to the README, used once per environment after this change:
1. Back up the database.
2. Stop the queue workers.
3. Run `php artisan migrate:fresh --seed --force`. The old data is development data only.
4. Empty the vault storage directory, keeping the master key and `APP_KEY` untouched.
5. Run `php artisan optimize:clear && php artisan config:cache && php artisan route:cache`.
6. Run `php artisan queue:restart` and restart the workers.

State clearly that, from now on, any schema change is a **new** migration. History is rewritten only this once, before production.

## Owned files
```
database/migrations/**            ← rewrite/delete (app migrations only; package migrations untouched)
database/seeders/**, database/factories/**
app/Enums/** (new), app/Models/**  ← casts, relations, work→oeuvre
app/** code using status/type literals ← replace with enum cases only, no behaviour change
app/Console/Commands/EnumsTypescript.php (new)
resources/js/enums/** (generated), resources/js/** usages of the literals and of work→oeuvre
lang/{ar,fr,en}/enums.php, resources/js/locales/{ar,fr,en}.json
README.md (Migration reset section), AGENTS.md (enum + migration conventions)
tests/**
```
FROZEN: the vault encryption and the upload protocol (beyond the enum/rename replacements), the behaviour of the status machine and the review actions, colour and font tokens.

## Acceptance gate
- **Phase 0:** the inventory and proposal, approved.
- **Phase 1:** the schema diff (empty except the listed renames), the new migration list, green tests, then STOP.
- **Phase 2:** the schema diff (enum columns only), the literal grep before/after, all checks green, the smoke test.
- `git status --short` at the end of each phase.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `git mv`, `--cached`, `update-index`).
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
