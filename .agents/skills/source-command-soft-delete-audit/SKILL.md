---
name: "source-command-soft-delete-audit"
description: "Migrated source command `soft-delete-audit`"
---

# source-command-soft-delete-audit

Use this skill when the user asks to run the migrated source command `soft-delete-audit`.

## Command Template

Task — Soft-delete audit. Read AGENTS.md first.

The goal is stated as "add soft deletes to every migration and model". Executed literally
that would break the application, so this task is framed as an **audit**: enumerate every
table, classify each one, apply soft deletes wherever they are genuinely missing and
appropriate, and report every exclusion with its reason.

Most of the tables that should have soft deletes already do. The value of this task is the
complete, written classification — so nobody asks the question again, and so the next agent
does not add `deleted_at` to a table where it is actively harmful.

Baseline: `php artisan test --compact` at 0 failures, `composer lint:check` and
`composer types:check` at 0. Hold all three.

## This drift has already happened once

In Phase 1, `softDeletes()` was added to `upload_sessions`, `media_variants`,
`file_access_logs` and `storage_quotas` — in several cases directly beneath a comment
reading "No softDeletes: ...". It caused two test failures and was reverted in a corrective
session.

Read `database/migrations/*_revert_soft_deletes_drift.php` and AGENTS.md's schema-conventions
section before doing anything. Re-adding what that migration removed would undo deliberate
work.

## Part 1 — Enumerate and classify (report before changing anything)

List **every** table in the schema, including framework and package tables. For each, state:
the table, whether it has `deleted_at` today, whether it should, and one line of reasoning.

Use these categories:

**Keep or add soft deletes** — domain entities a user can delete and might need back, where
the row's absence would orphan something else.

Expected members: `users`, `works`, `media_files`, `countries`, `wilayas`, `communes`.
Verify each actually has both the column and the `SoftDeletes` trait — a migration without
the trait, or a trait without the column, is a half-applied state that behaves confusingly.

**Never** — and for each, the concrete failure that follows from adding it:

- `upload_sessions` — ephemeral by design, swept after expiry. A soft-deleted session keeps
  its uuid alive; any lookup that does not exclude trashed rows would resolve a dead session
  and write chunks into a finalised upload.
- `file_access_logs` — an append-only legal audit trail. A soft-deletable audit row lets an
  actor hide their own access, which defeats the entire purpose of the table.
- `media_variants` — regenerable derivatives. Soft-deleting them leaks disk with no
  compensating benefit; they must be hard-deleted so the bytes go with them.
- `storage_quotas` — `user_id` is unique. A soft-deleted quota row permanently blocks
  creating a new quota for that user, and `firstOrCreate` would fail with a constraint
  violation rather than a clear error. This one fails silently until it doesn't.
- Spatie permission tables (`roles`, `permissions`, `model_has_roles`, and the rest) — the
  package's own queries do not account for a `deleted_at`, so a soft-deleted role would
  still be matched by some queries and not others.
- Framework tables (`sessions`, `cache`, `jobs`, `failed_jobs`, `password_reset_tokens`,
  `migrations`) — infrastructure. Laravel deletes from these directly, bypassing Eloquent
  entirely.
- Any append-only decision or audit table added later, `work_reviews` included.

**Decide and justify** — anything not in either list above. `download_tickets`, if Phase 6
created it, is one: it is short-lived like `upload_sessions`, which argues against. Make the
call and say why.

If the audit finds that every appropriate table already has soft deletes, **say so plainly
and stop there.** "Nothing to add" is a valid and useful outcome; inventing work to justify
the task is not.

## Part 2 — Apply only what is genuinely missing

For each gap found:

- A new migration adding the column. Do not edit existing migrations — an environment that
  has already migrated must converge to the same schema as a fresh install, and both paths
  must be verified.
- The `SoftDeletes` trait on the model.
- The `deleted_at` cast if the model declares casts explicitly.
- A factory check: a `NOT NULL` column added to a table breaks its factory, which surfaces
  as failures in unrelated tests. This has bitten this project before.

## Part 3 — Verify nothing about uploads changed

This is the requirement the brief emphasises, and it is where a soft-delete change does its
damage. Soft deletes alter the **default query scope** of every model that uses them, so a
query that used to find a row may silently stop finding it — or start finding one it should
not.

Check each of these explicitly and report what you found:

**Quota accounting.** Quota counts `withTrashed()->whereNull('purged_at')`, because a
soft-deleted file still occupies disk until it is purged. Confirm this still holds. If any
new soft delete changes what that sum returns, an author's quota silently drifts from
reality.

**Deduplication.** `DeduplicateFile` matches on `sha256_plain`. Confirm it considers trashed
rows — a soft-deleted file's bytes are still on disk with a live `ref_count`, and missing it
would write a duplicate copy. Equally, confirm nothing deletes shared bytes while another
row still references them.

**Upload session lookup.** Every route model binding and every query that resolves an
`UploadSession` by uuid. If soft deletes were added there, a completed or aborted session
could still resolve.

**Relationships.** `$work->mediaFiles` excludes trashed rows by default. Confirm the author's
file list and the pipeline both see what they expect. Soft-deleting a parent does **not**
cascade to children — say what currently happens to a work's files when the work is
soft-deleted, and whether that is the intended behaviour.

**Route model binding.** A soft-deleted model returns 404 by default. Confirm that is the
desired behaviour everywhere it now applies.

Then run a real end-to-end upload through the browser — init, chunks, complete, and the
pipeline through to `ready` — and confirm it still works. The test suite alone is not
sufficient evidence here; the suite was green through every previous upload defect in this
project.

## Part 4 — Document the classification in AGENTS.md

Replace the schema-conventions soft-delete rule with the full table given above:
table, decision, reason. One row per table, including framework and package tables.

This is the durable output of the task. The rule as written today says "works, media_files,
users ONLY", which is why a later session read it as excluding `wilayas` and `communes` and
had to reason it out again. A complete table removes the ambiguity permanently.

## What soft delete does not do — state this in the documentation

`$mediaFile->delete()` sets `deleted_at`. **The 5 GB on disk stays exactly where it is.**

That is why `purged_at` exists and why a separate job frees bytes after the retention
window. Anyone who reads "soft delete" as "the file is gone" will miscount storage. Make
that explicit where someone will read it.

## Owned files

```
database/migrations/*                 ← new migrations only, never edit existing ones
app/Models/**                         ← trait and cast only
database/factories/**                 ← only if a new column breaks one
AGENTS.md
tests/Feature/SchemaTest.php
```

FROZEN — if you believe a change is needed here, STOP and report:

```
app/Domain/**
app/Jobs/**
app/Actions/**
resources/js/**
config/**
```

A soft-delete change that requires editing an action or a job means it has altered
behaviour, which is exactly what this task must not do.

## Acceptance gate

1. The full classification table from Part 1 — every table, decision, reason.
2. What you actually changed, or an explicit "nothing needed adding".
3. Part 3 findings, each of the five checks answered individually.
4. A real browser upload completing end to end, through to `ready`.
5. `migrate:fresh` and an incremental migrate converge to an identical schema — confirm.
6. `php artisan test --compact` — 0 failures.
7. `composer lint:check`, `composer types:check` — 0.
8. `php artisan vault:doctor` — still 0 FAIL.
9. The AGENTS.md diff.
10. `git status --short`

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
