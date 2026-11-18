---
name: "source-command-readme-update"
description: "Migrated source command `readme-update`"
---

# source-command-readme-update

Use this skill when the user asks to run the migrated source command `readme-update`.

## Command Template

Task: update the existing README.md in place. Read AGENTS.md first.

Goal: README.md must describe the project **as it is now**:
- remove what was removed;
- add what was added, in the code and in operations;
- document the problems we actually hit locally and on cPanel, with their fixes.

**The code is the source of truth.** Every command, env variable, config key, route name, job name and audit action you write must exist in the code. If something in this brief does not match the code, follow the code and list the mismatch in your report.

Scope: `README.md` only. No code changes. If `.env.example` is missing a variable the README documents, list it in the report; do not edit `.env.example`.

## Method
1. Read the current README.md fully. Keep every section that is still accurate (seeded accounts, domain, classification, …). Rewrite what is outdated; delete what describes removed code.
2. Verify against the code:
   - `config/vault.php`, `config/queue.php`, `config/filesystems.php`, `.env.example`;
   - `php artisan list` and `--help` for every vault/e2e command;
   - `ProcessMediaFile::chainJobs()`;
   - `config('vault.consult.delivery')`;
   - the `file_access_logs.action` enum;
   - `routes/admin.php`, `routes/author.php`;
   - `composer.json` and `package.json` scripts.
3. Write the README in English, concise, with tables where they help.

## Required structure (adapt titles, keep the order)
1. Overview: what ONDA Storage is, the roles (author, admin), the 5 GB per file limit, and that production needs **no system binaries**.
2. Stack.
3. Core rules (3):
   - no large file in one request (8 MiB chunks written at offset);
   - no plaintext byte in the vault;
   - vault bytes are deleted only when provably unreferenced (`VaultBytesReleaser`).
4. Architecture:
   - **Encryption:** AES-256-CTR (seekable, so Range works on encrypted files), per-file DEK wrapped by the KEK file + `APP_KEY`, HKDF keys, `.mac` sidecar per 1 MiB, random stored nonce for previews. A warning box: losing `vault-master.key` or `APP_KEY` = all deposits unreadable; keep offline backups.
   - **Upload protocol:** init → chunks → complete (no heavy work). The fair parallel scheduler (global and per-file caps from config, worker pool, round-robin). Quota reserved at init under a short per-user lock. Retry resumes the same session.
   - **Pipeline:** the exact chain order from the code. RecordDeposit verifies bytes before the hash-chained ledger row. Dedup (`FOR UPDATE`, lowest verified id, release after commit). Scanner and probe optional. Preview generation dispatched by the last step on the separate `previews` connection, so a deposit is `ready` before its preview exists.
   - **Consultation / delivery map:** a table generated from the real config, one row per family: mode (`original_inline` / `derivative` / `unsupported`), the rendering (PDF.js with search and no download/print UI, docx-preview in a sandboxed iframe, native video/audio with Range, GD images, PhpSpreadsheet JSON, pptx text outline, escaped text), and what the server needs. Note that the original_inline stream is a 206 capped per response and decrypted in pieces. Note that the ffmpeg/pdftoppm/soffice renderers remain but run only if a family is set to `derivative`. Re-run `vault:generate-previews --all --force` after changing the map.
   - **Access:**
     - admin vs author route/controller/page split;
     - signed URLs bound to the issuing user, with the middleware check order;
     - another author's file returns 404;
     - `media.link` / `media.stream` are owner-only;
     - the audit actions (from the enum), including `consult_original`;
     - author preview (side viewer + new tab, Preview/Details tabs, no Activity tab, no watermark for the owner by default).
   - **Domain:** the oeuvre status machine, the classification reference data, `college_oeuvre_files`, the format registry and `VerifyContentType`.
5. Requirements table, Local vs Production. PHP 8.4 and its extensions: verify whether `ext-sodium` is really required (`composer check-platform-reqs`, grep for `sodium_`). If it is, keep it and say which code needs it. `proc_open` must be enabled (exec/shell_exec may stay disabled). MySQL. Node for the build only. System binaries: optional locally, none in production. Redis optional.
6. Local setup (Windows 11 + Herd), step by step:
   - clone, `composer install`, `npm ci`, `.env`, `key:generate`;
   - storage roots outside the repo on one partition;
   - master key creation (`php -r "file_put_contents(..., random_bytes(32));"` + icacls);
   - **paths in `.env` with `/`, never `\`**;
   - `migrate --seed`, `npm run build` / `npm run dev`, `vault:doctor` expecting FAIL 0;
   - run commands from a normal PowerShell (not Administrator, not WSL).
7. Environment variables table (only variables that exist), including `VAULT_CLIENT_MAX_IN_FLIGHT` ("keep below the PHP worker count; Herd has 4 → 3"), the per-file cap, the optional `*_BINARY` paths (local only, absolute, with `/`), `SCAN_DRIVER`, and a `CACHE_STORE` that supports atomic locks.
8. Queue workers and scheduler:
   - a table of the two workers (`media,default` with retry_after 3600; the `previews` connection with its real retry_after and job timeout);
   - the local commands (three windows), stressing that the connection name `previews` must come before `--queue`;
   - the cPanel cron lines for `schedule:run`, the pipeline worker (`--stop-when-empty --max-time`) and the previews worker (`flock -n`), each with `php -d memory_limit=256M`;
   - why `--max-time` never kills a running job;
   - `queue:restart` after every deploy.
9. Production deployment (cPanel):
   - layout (app in `~/public_html`, storage in `~/onda-storage/...`, key in `~/onda-secrets` chmod 600);
   - MultiPHP INI values (16M / 32M / 256M / 120) and that the CLI uses `php -d`;
   - the deploy command list;
   - `config:cache` / `route:cache` in production only;
   - never run tests on the server;
   - offline key backups;
   - verify `.env`, `storage` and the vault are not reachable over HTTP.
10. Testing:
    - `composer test` (not bare `php artisan test`), Vitest, types, lint, format, `composer ci:check`;
    - sqlite `:memory:` + per-test storage roots;
    - **the TestEnvironmentGuard** and why it exists (a cached config once made RefreshDatabase wipe the dev MySQL database);
    - never `optimize` / `config:cache` locally, use `optimize:clear`;
    - real-binary tests skip with a reason, with tool and test time caps;
    - Playwright e2e requirements and `e2e:cleanup`;
    - stale `public/build` causes phantom failures.
11. Artisan commands table (only real commands and options): `vault:doctor` (+ `--fpm`, local only), `vault:generate-previews`, `vault:reprocess`, `vault:mark-missing-bytes`, `vault:quarantine-orphans`, `vault:recount-refs`, `e2e:cleanup`. Mark which are dry-run by default.
12. Troubleshooting, two tables (Symptom / Cause / Fix).

    **Local (Windows / Herd):**
    - `.env` "unexpected escape sequence": `\` in paths, use `/`.
    - doctor "Master key file exists" FAIL: the key is never in git; create it.
    - `Permission denied` on `laravel.log` / `bootstrap/cache` / `.tmp` despite Full Control: **Sophos Intercept X CryptoGuard** flags `php.exe` as ransomware. Check the Sophos Events tab; IT adds Sophos Central exclusions for Herd's `php.exe` / `php-cgi.exe` (every PHP version folder) and `ffmpeg.exe` / `pdftoppm.exe`.
    - Same error from one terminal only: a sandboxed or WSL shell; use a normal PowerShell.
    - Tests wiped the dev DB, or 419 / "missing session" failures: cached config; `optimize:clear`, `migrate:fresh --seed`, use `composer test`.
    - "Chunk 0 failed" / phantom UI bugs: stale `public/build` or Vite not running.
    - Uploads rejected while a big upload runs: PHP workers saturated; `VAULT_CLIENT_MAX_IN_FLIGHT=3`.
    - Web vs CLI differences: Herd site on PHP 8.5 vs CLI 8.4; unify the version.
    - Preview stuck "préparation en cours": the previews worker is not running; start it, then `vault:generate-previews --all --force`.
    - `.doc` / `.odt` / `.ppt` "aperçu indisponible": expected; ask for docx/pptx/PDF.
    - Video "format non lisible": HEVC/AVI codec; recommend MP4 H.264.
    - `npm ci` ERESOLVE: misaligned eslint packages; never `--legacy-peer-deps` or `.npmrc`.
    - Tools "not found" after `winget upgrade`: the version is in the path; update `.env`.

    **cPanel:**
    - `ext-sodium` missing (only if step 5 confirms it is needed): WHM → EasyApache 4 → enable `ea-php84-php-sodium`; check CLI `php -m` and a web probe.
    - doctor reports `exec` / `shell_exec` disabled: fine, `proc_open` is used.
    - `vault:doctor --fpm` fails: NAT hairpin / SSL, endpoint local-only; use a temporary probe file and delete it.
    - Upload limits unexpected: CLI vs web php.ini; MultiPHP INI Editor for web, `php -d` for CLI.
    - Self-requests to the domain fail: NAT loopback; ask the host for `/etc/hosts`.
    - Jobs not running: cron missing or old code; add the cron lines, `queue:restart`.
    - ffmpeg / LibreOffice mentioned by doctor: INFO only, not needed in production (the hosting team does not support them).
13. Security model:
    - **protected:** encryption at rest, integrity, access, deletion safety;
    - **accepted trade-off:** for PDF, DOCX, video and audio the decrypted original reaches the reviewer's browser (no converters on cPanel). Reviewers are authenticated ONDA officials, a leak is attributable (watermark, `consult_original` audit), devtools extraction is possible;
    - images, spreadsheets, text and pptx are derivative-only;
    - original download is owner-only.
14. What changed / removed:
    - **removed:** ffmpeg/poppler/LibreOffice as production requirements; `StreamOriginalDialog`; `MediaPreviewDialog`;
    - **moved:** previews off the deposit chain;
    - **added:** the parallel scheduler and quota reservation, the dedup race fix and `VaultBytesReleaser`, the doctor consistency checks, the housekeeping commands, the browser-native preview, the author preview, `consult_original`, the test guard.

## Report
- The sections you kept from the old README, the ones you rewrote, and the ones you deleted.
- Every place where this brief disagreed with the code, and what you wrote instead.
- The `ext-sodium` finding (required or not, by what).
- The env variables documented but missing from `.env.example`.
- `git diff --stat README.md`, then `git status --short`.

## Git policy
- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`, `git checkout`, `git restore`, `git stash` (any form), `git clean`, `git tag`, `git revert`, or any index operation (`git rm`, `--cached`, `update-index`).
- Leave the change unstaged. I review and commit myself.
