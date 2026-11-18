---
name: "source-command-env-cleanup"
description: "Migrated source command `env-cleanup`"
---

# source-command-env-cleanup

Use this skill when the user asks to run the migrated source command `env-cleanup`.

## Command Template

Task: clean `.env` and `.env.example`: remove the variables the project does not use, group the rest, and document each variable with a short comment. Read AGENTS.md first.

This is a configuration refactor with **zero behaviour change**: the resolved Laravel config must be identical before and after, apart from keys that nothing reads.

## Safety rules (read first)
- **Never print a secret value** in your output, report or logs: `APP_KEY`, passwords, tokens, keys, DSNs and vault paths. When you need to compare values, compare hashes or show `***`.
- First, back up `.env` to `.env.backup-<YYYYMMDD-HHMM>`. Check that `.gitignore` covers `.env`, `.env.backup-*` and `.env.testing`. Report the backup name.
- In `.env`, **never change a value**. You may only delete unused keys, reorder keys and add comments. This applies above all to `APP_KEY`, the vault master key path, `DB_*`, and anything vault- or encryption-related.
- `.env.example` holds **no real secrets**: placeholders or safe dev defaults only. `APP_KEY=` stays empty.
- Do not touch the production `.env` on the server. You will give me the list of keys to remove there.
- `php artisan optimize:clear` before every check. Never leave a config cache behind.

## Step 0 — Inventory (no edits; STOP and wait for my approval)
1. **Every key** in `.env` and in `.env.example`, by name only, never values. For each, mark: in `.env` only, in `.env.example` only, or in both.
2. **For each key, where it is read.** Search all of these, because many keys are read outside `config/`:
   - `config/*.php` (`env('KEY'`);
   - **unpublished framework config:** `vendor/laravel/framework/config/*.php` (Laravel 11+ reads many keys from there when the app config file is not published);
   - **package configs merged with `mergeConfigFrom`:** Fortify, passkeys, Spatie permission, Telescope/Pulse if present…;
   - frontend: `import.meta.env.VITE_*` in `resources/js/**`, plus `vite.config.*`;
   - `phpunit.xml`, `.env.testing`, `TestEnvironmentGuard`, `composer.json` scripts, deploy scripts, the README, CI files;
   - direct `env()` calls outside `config/`, in `app/`, `routes/` or `database/`. These are bugs: they return null once the config is cached. List them.
3. **Classify each key:**
   - **USED:** read by app config, framework/package config, Vite, tests or scripts → keep.
   - **UNUSED:** nothing reads it (starter-kit leftovers: e.g. `AWS_*`, `REDIS_*`, `PUSHER_*`, `MEMCACHED_*`, `VITE_PUSHER_*`, `POSTMARK_*`… only if really unused) → remove.
   - **REDUNDANT:** set to exactly the config default and not environment-specific → propose keeping it in `.env.example` only as commented documentation, or removing it. Recommend one option per key.
   - **MISSING:** read by config with no default (or with a default that is wrong for this project), but absent from `.env.example` → add it.
   - **MISPLACED:** an `env()` call outside `config/` → propose moving it into a config key (list the files; no fix in this task unless trivial).
4. **The proposed section layout and order** (see below).

STOP after Step 0.

## Target format (both files, same keys, same order)
- **Sections**, each with a header block:
```dotenv
# ─────────────────────────────────────────────
# Database
# ─────────────────────────────────────────────
```
- Suggested order (adjust to what exists):
  1. Application
  2. Locale
  3. Logging
  4. Database
  5. Session
  6. Cache
  7. Queue (connections `media` and `previews`, retry/timeouts)
  8. Mail
  9. Filesystems
  10. **Vault** (master key path, storage roots, chunk size, quotas)
  11. Upload
  12. Consultation / preview
  13. Security (Fortify, passkeys, rate limits)
  14. Frontend (`VITE_*`)
  15. Local tooling
- **One comment line (two at most) above each key.** Never put a comment inline after a value. The comment says:
  - what the key controls;
  - the allowed values or units (bytes, seconds, MiB);
  - the production recommendation when it differs from dev.
- Tags at the start of a comment where they apply:
  - `[required]`: the app fails without it;
  - `[secret]`: never commit, never share;
  - `[prod]`: must be changed for production;
  - `[critical]`: losing or changing it loses data (`APP_KEY`, the vault master key path).
  For example:
```dotenv
# [required][secret][critical] App encryption key; also wraps vault DEKs. Changing it makes every deposit unreadable. Generate once: php artisan key:generate
APP_KEY=

# [prod] false in production; true only for local debugging.
APP_DEBUG=true

# [required][critical] Absolute path to the 32-byte vault master key, outside the web root. Use forward slashes, even on Windows (D:/onda-secrets/vault-master.key).
VAULT_MASTER_KEY_PATH=
```
- Write the comments in English, short and factual, from what the code does (read the config file before writing a comment). Do not guess.
- Quote values that contain spaces or `#`. Paths use `/`, which avoids the earlier "unexpected escape sequence" error on Windows.
- `.env` and `.env.example` have **exactly the same keys, in the same order, with the same comments**. Only the values differ.

## Verification (mandatory)
1. **Resolved config: before = after.**
   - Before editing, export the full resolved config: `php artisan tinker --execute` that dumps `config()->all()` as JSON with every leaf value replaced by its `sha256`. Do the same after editing.
   - Diff the two. The diff must be **empty**, apart from keys that were only fed by removed UNUSED variables and have no effect.
   - Paste the diff with the key names only.
2. **Same for the test environment:** export the same under `APP_ENV=testing` via `composer test`'s environment, and check that `TestEnvironmentGuard` still passes.
3. **Key parity:** a script compares the keys of `.env` and `.env.example`; the result must be identical lists, in the same order.
4. **A regression test** `tests/Unit/EnvExampleTest.php`:
   - every key in `.env.example` is read somewhere (config, framework/package config, Vite, phpunit), which prevents dead keys from coming back;
   - every `env()` key without a default in `config/` exists in `.env.example`;
   - `.env.example` contains no non-empty value for keys tagged `[secret]`.
5. `composer test` green; `npm run build` (the `VITE_*` keys still resolve); the app loads in the browser (login, an oeuvre page, an upload starts, a consultation opens).

## Report
- The removed keys, each with the reason. **This list is also what I will remove from the server's `.env`.**
- The added keys (MISSING), with the default you documented.
- The REDUNDANT decisions taken.
- The MISPLACED `env()` calls found.
- The config diff (empty), the key-parity result, the test results.
- The backup file name.
- `git status --short`. `.env` must not appear; only `.env.example` and the test file should.

Then STOP.

## Owned files
```
.env                    ← local only, gitignored: delete unused keys, reorder, comment; never change values
.env.example
.gitignore              ← only to add .env.backup-* if missing
tests/Unit/EnvExampleTest.php (new)
README.md               ← only the environment section, if it lists variables that changed
```
FROZEN: `config/*.php` (read only; report misplaced `env()` calls instead of fixing them), all application code, the server `.env`.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `git mv`, `--cached`, `update-index`).
- Never stage or print `.env` or its backup.
- Leave every change unstaged. Stop only processes you started. Never kill all php.exe or node.exe.
- End by printing `git status --short`.
