---
name: "source-command-migrations-enums-phase2"
description: "Migrated source command `migrations-enums-phase2`"
---

# source-command-migrations-enums-phase2

Use this skill when the user asks to run the migrated source command `migrations-enums-phase2`.

## Command Template

Task: Phase 2a: convert every enumerable column to an int-backed PHP enum stored as `unsignedTinyInteger`, and apply the enums in all PHP code. Read AGENTS.md first.

Context: Phase 1 is done. The migrations are consolidated, with one `create_<table>_table` per table. The project is still in development, so the enum changes are made **directly inside the consolidated create migrations**, with no alter migrations, and the database is rebuilt with `migrate:fresh --seed`.

Baseline to hold: `composer test` green; Vitest, `npm run types:check`, `npm run lint:check`, `npm run format:check` all 0; `npm run build`.

## Scope rule
In every app migration (package migrations untouched):
1. Every `->enum(...)` column becomes `unsignedTinyInteger`, with an int enum class.
2. Every string column holding a fixed set of values (status, type, kind, action, direction…) becomes `unsignedTinyInteger`, with an int enum class.
3. Every existing `unsignedTinyInteger` / `tinyInteger` column that holds coded values gets an int enum class, if it does not have one already.

Exclude, and list in the report with the reason:
- `boolean()` columns (MySQL tinyint(1)): they stay booleans, cast to `bool`;
- tinyints that are real numbers: counts, sort order, priority, level, percentage, attempts;
- the two approved string exceptions:
  - `media_consultations.family`, which keeps the string-backed `PreviewFamily`;
  - `consultation_assets.kind`, a plain string with no cast, because of the `old:<kind>` trick (it stays on the follow-up list).

After this task, `grep -rn "->enum(" database/migrations` (app migrations) must return nothing.

## Enum class convention
- **Path = namespace (PSR-4):** `app/Enums/<Model>/<Attribute>Enum.php`, namespace `App\Enums\<Model>`.
  - `<Model>` is the StudlyCase singular name of the table's model (`oeuvres` → `Oeuvre`, `media_files` → `MediaFile`, `file_access_logs` → `FileAccessLog`).
  - `<Attribute>` is the StudlyCase column name (`status` → `StatusEnum`, `type_gestion` → `TypeGestionEnum`, `action` → `ActionEnum`).
- **Approved exceptions:**
  - `App\Enums\Referentiel\StatusEnum` is shared by the five reference tables (D2);
  - `oeuvre_reviews.from_status` / `to_status` use `App\Enums\Oeuvre\StatusEnum`.
- When two enums with the same short name meet in one file, import them with explicit aliases (`use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;`).
- **Shape (follow this exactly):**
```php
<?php

namespace App\Enums\Oeuvre;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

enum StatusEnum: int implements HasPresentation
{
    use EnumHelpers;

    case DRAFT        = 1;
    case SUBMITTED    = 2;
    case UNDER_REVIEW = 3;
    case REGISTERED   = 4;
    case REJECTED     = 5;

    public function label(): string
    {
        return __('enums.oeuvre.status.' . $this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT        => 'neutral',
            self::SUBMITTED    => 'info',
            self::UNDER_REVIEW => 'warning',
            self::REGISTERED   => 'success',
            self::REJECTED     => 'destructive',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT        => 'file-pen',
            self::SUBMITTED    => 'send',
            self::UNDER_REVIEW => 'search-check',
            self::REGISTERED   => 'badge-check',
            self::REJECTED     => 'circle-x',
        };
    }
}
```
- `App\Enums\Contracts\HasPresentation`: `label()`, `color()`, `icon()`.
- `App\Enums\Concerns\EnumHelpers`:
  - `key()`: the snake_case case name;
  - `fromKey(?string): ?self`: matches the key, never the translated label;
  - `values()`;
  - `keys()`;
  - `options()`: `[['value', 'key', 'label']]`;
  - `dbComment()`: `"App\Enums\Oeuvre\StatusEnum: 1=draft, 2=submitted, …"`.
- **Values:** start at 1, explicit on every case, **permanent** (never renumber, never reuse). The proposal approved in Phase 0 fixes the order (e.g. `file_access_logs.action` in the order the actions were added; `Referentiel` ACTIVE=1, INACTIVE=2).
- `color()` uses only the existing StatusTone names (success/warning/destructive/info/neutral/primary); `icon()` uses lucide names.
- Labels: `lang/{ar,fr,en}/enums.php` with keys `enums.<model>.<attribute>.<key>`, complete for every case in all 3 languages.

## Column pattern (in the consolidated create migration)
```php
$table->unsignedTinyInteger('status')
    ->default(StatusEnum::DRAFT->value)   // only when a default makes sense
    ->comment(StatusEnum::dbComment());
```
- Nullable only where "no value" is a real state.
- Indexes, as approved:
  - add `(author_id, status)` on `oeuvres`;
  - keep `(status, submitted_at)`, `(oeuvre_id, status)` and `(user_id, status)`;
  - the reference tables get `(parent_fk, status)` instead of a lone index, except `register_types`, which keeps its lone index;
  - drop the lone `colleges.type_gestion` index.
  Each index has a one-line comment naming the query it serves.

## Apply the enums in all PHP code
- **Models:** every enum column is in `casts()`. Add the trait `App\Models\Concerns\SerializesEnumsAsKeys` to every model that has enum casts: `toArray()`, JSON and Inertia props emit `$enum->key()` ('submitted', 'active', 'rtl'…). The frontend therefore keeps receiving the same strings.
- **Replace every literal with the enum case,** everywhere in `app/`, `database/factories`, `database/seeders`, `routes/`, `config/` and `tests/`: `'submitted'`, `'ready'`, `'consult_original'`, `'rtl'`, `=== 1` / `STATUS_ACTIVE`… This covers:
  - queries: `where('status', StatusEnum::SUBMITTED)`, `whereIn(…, [cases])`;
  - policies, actions, jobs, controllers, requests, notifications, commands, services;
  - `OeuvreStatusMachine`: the transition table written with the cases, with **no behaviour change**;
  - `SubmissionGate`, `AccessLogger`, `RecordDeposit`, `TransitionMediaFileStatus`, `InitUpload`, `StoreChunk`, `AbortUpload`, `VaultBytesReleaser`, `GenerateVariants`, and the renderers.
- **Delete the old constant classes and lists** once nothing uses them (`OeuvreStatus`, `MediaFileStatus`, `Language::DIRECTIONS`, `STATUS_ACTIVE`, `RegisterTypeCollege::IN_FLIGHT`…). Keep a helper list only if it is now built from the enum (e.g. `StatusEnum::inFlight(): array`).
- **Validation:** `Rule::enum(XEnum::class)` for int inputs. For URL/filter inputs that carry the key, use a small rule or `fromKey()`; an unknown key is ignored (filters) or rejected with 422 (forms).
- **Routes:** the `{kind}` segment of `MediaVariantController` stays the key string; resolve it with `fromKey()`; an unknown key → 404.
- **Hash chains (must stay stable):**
  - `file_access_logs`: the payload `action` is serialised as `$action->key()`, which gives exactly today's strings;
  - `reference_data_changes`: enum values are hashed as their permanent int. Document both in AGENTS.md.
  - The chain-verification tests (FailureAndAuditTest, OriginalInlineTest) stay green.
- **Languages:** the ar/fr/en rows come from an idempotent seeder. Every test and code path that needs them seeds them, or falls back safely (report which).
- **Frontend:** untouched in this phase, except the `=== 1` / `=== 0` status comparisons in `Colleges.vue` and `Gestions.vue`, which become key comparisons (`=== 'active'`), because props now carry keys.

## Tests
- **Unit, per enum:**
  - a snapshot of value ↔ key, so a renumbering fails the test;
  - `label()` exists in ar/fr/en;
  - `fromKey()` round-trips and returns null for unknown keys;
  - `dbComment()` lists every case.
- **Arch (Pest):**
  - every class under `App\Enums` (except the approved `PreviewFamily` exception, which lives outside) is int-backed, implements `HasPresentation` and uses `EnumHelpers`;
  - no `->enum(` in app migrations;
  - no status/action/kind string literals in `app/` (a targeted grep-based test on the known values).
- **Schema test:** for every column whose comment starts with `App\Enums\`, the type is `tinyint unsigned`, the class exists, and the owning model casts that column to that class.
- **Serialization:** model `toArray()` and Inertia props emit keys, not ints.
- **Hash chains:** the payloads are unchanged (`action` key strings); the chain verification passes.
- **Behaviour:** the existing status machine, upload, consultation, review and dashboard tests pass with no behaviour change.

## Verification
1. `php artisan optimize:clear`, a dump of the dev DB first, then `php artisan migrate:fresh --seed` (local dev DB only).
2. A schema diff between the Phase 1 result and now: only the enum columns (type, default, comment) and the approved indexes may differ. Paste it.
3. Literal grep before and after (app, factories, seeders, tests), with counts, and the list of what remains with a reason (client-only states, the approved string exceptions).
4. `composer test` green with before/after counts; Vitest, types, lint, format 0; `npm run build`.
5. Smoke test in the browser (ar and fr): login, create an oeuvre, upload, submit, admin review (accept/reject), list filters (`?status=submitted`), author dashboard counts, reference pages (Colleges/Gestions active toggle).
6. Do not delete local vault files; report the path so I clean it.

## Report
- The table of every enum: class, cases with values, table.column, default, nullable, index.
- The excluded columns with the reason (booleans, numeric tinyints, approved strings).
- The deleted constant classes.
- Steps 2–5 above.
- `git status --short`.

Then STOP. Phase 2b (generated TS enums plus replacing the frontend literals) is a separate task.

## Owned files
```
database/migrations/** (app migrations only), database/seeders/**, database/factories/**
app/Enums/** (new), app/Models/** (casts, SerializesEnumsAsKeys)
app/** (literal → enum replacement only, no behaviour change)
routes/** (only the {kind} binding if needed)
lang/{ar,fr,en}/enums.php
resources/js/pages/**/Colleges.vue, Gestions.vue (status comparisons only)
AGENTS.md (enum conventions, hash-chain representation)
tests/**
```
FROZEN: the vault encryption, the upload protocol, the behaviour of the status machine and the review actions, the consultation viewers, colour and font tokens, package migrations.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `git mv`, `--cached`, `update-index`).
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
