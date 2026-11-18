---
name: "source-command-documentation-task"
description: "Migrated source command `documentation-task`"
---

# source-command-documentation-task

Use this skill when the user asks to run the migrated source command `documentation-task`.

## Command Template

Task — Write the project's setup and deployment documentation. Read AGENTS.md first.

Documentation only. **Do not change a single line of application code, configuration, or
tests.** If you find something wrong while documenting it, write it down in the report and
leave the code alone.

## Why this is not a formality

Every environment finding in this project's history was paid for in lost hours, and none of it
is written down anywhere a new developer would look:

- `php artisan serve` on Windows is single-process, so parallel chunk uploads queue behind each
  other and the architecture looks broken when it is not.
- Herd keeps a **separate `php.ini` per SAPI**. A CLI-only check reports green while FPM still
  rejects chunks with 413.
- Herd served the site on PHP 8.2 while the CLI ran 8.4, producing 500s that looked like a
  routing bug for two sessions.
- `npm run dev` not running makes Laravel silently serve a stale `public/build`. That cost a
  full misdiagnosis session and later 12 phantom test failures.
- A project placed under `/mnt/c/` in WSL2 has roughly ten times the I/O cost.
- Windows Defender scanning the storage directory throttles every chunk write.
- `wayfinder:generate` without `--with-form` breaks type-checking across every `.form()` call
  site in the app.

Someone setting this up next week will hit these in the same order unless the document stops
them. Write it so they do not.

## What to produce

```
README.md                    the entry point: what this is, how to run it
docs/installation.md         Windows 11 + Herd, step by step
docs/configuration.md        every setting, with its value and its reason
docs/deployment-cpanel.md    production, including where files live
docs/storage.md              the vault layout, paths, permissions, backup
docs/troubleshooting.md      symptom → cause → fix
```

Split them if one grows past a comfortable read. Keep `README.md` short — what the project is,
the prerequisites, and links onward.

## Method — derive, do not recall

Read the repository and report what you find. Do not write a generic Laravel setup guide.

- `composer.json` and `package.json` — the real scripts, the real PHP and Node constraints.
- `.env.example` — every key. Any key present in code but missing there is a finding.
- `config/vault.php`, `config/filesystems.php`, `config/queue.php` — the values that matter and
  why each is set as it is.
- `app/Domain/Vault/Doctor/VaultDoctor.php` — it already encodes most of the environment
  requirements as executable checks. The documentation should mirror it, not contradict it.
- `AGENTS.md` — the architecture decisions. The docs explain *how to run it*; AGENTS.md
  explains *why it is built that way*. Link, do not duplicate.
- The migrations, for what `migrate --seed` actually produces.

Where the code and your expectation disagree, the code wins. Report the surprise.

## docs/installation.md — Windows 11 + Herd

Ordered steps, each with the command and how to verify it worked.

Cover, at minimum:

**Prerequisites.** PHP version from `composer.json`, **64-bit** (`PHP_INT_SIZE === 8`; 32-bit
cannot seek past 2 GB), Node version, Composer, Git, MySQL, ffmpeg.

**MySQL.** Herd's free tier bundles no database. Document running it in WSL2 and reaching it at
`127.0.0.1:3306` from Windows, or whichever approach this project actually uses — check
`.env.example`.

**Redis.** Optional by design and must never become a hard dependency. Document installing it
in WSL2, how to verify it from the Windows side, and — importantly — that everything works
without it. State which driver each of `QUEUE_CONNECTION`, `CACHE_STORE` and `SESSION_DRIVER`
should use locally.

**php.ini, and the SAPI trap.** Herd keeps a separate ini per SAPI. Show how to find the loaded
file for each (`php --ini`, and `phpinfo()` or `vault:doctor --fpm` for the web side), and state
plainly that editing one does not affect the other.

Give the values and the reason for each:

| Setting | Value | Why |
|---|---|---|
| `post_max_size` | 32M | A chunk is 8 MiB. Deliberately low — high limits hide architecture violations until deploy. |
| `upload_max_filesize` | 16M | Same. |
| `memory_limit` | 256M | The pipeline streams; it never loads a file whole. |
| `max_execution_time` | 120 | No request does heavy work. |
| `session.gc_maxlifetime` | ≥ 14400 | A 5 GB upload can outlive a short session. |

Check these against `VaultDoctor` and `.env.example` before writing them — report any
disagreement rather than quietly picking one.

**ffmpeg.** Install, and then the part people miss: a binary on the user's PATH is not on
PHP-FPM's PATH. Document the absolute-path settings in `.env` and why they are the durable
answer on both Windows and cPanel.

**Storage directories.** Where they go, why outside the project, why on a non-system drive if
one exists, why excluded from Windows Defender, and why never inside OneDrive. Give the
PowerShell commands.

**The master key.** Generating it, where it goes, permissions, and — in a box nobody can miss —
that losing it means every stored file is permanently unrecoverable, and that a backup
containing both the key and the data is equivalent to no encryption at all. Two keys must be
kept: the vault master key and `APP_KEY`, since `dek_wrapped` is encrypted by both.

**Running it.** Which processes must be up simultaneously: the web server, `npm run dev`, and a
queue worker for `media,default`. State that Herd does not manage the worker, that the pipeline
silently does nothing without it, and that with `npm run dev` stopped Laravel serves the last
build.

**Verify.** `php artisan vault:doctor` and `--fpm`. Show expected output, explain that WARNs
are normal locally, and that **zero FAILs** is the bar.

## docs/configuration.md

Every `.env` key: what it does, its local value, its production value, and what breaks if it is
wrong. Group them — app, database, queue, vault, media, mail.

Include the non-obvious ones with their reasons:

- `retry_after = 3600`. The 90-second default re-dispatches a still-running 5 GB hash or ffmpeg
  job, producing two workers on one file.
- `FILE_DELIVERY` — `stream` locally, `litespeed` on cPanel, and what each does.
- `SCAN_DRIVER` — `null` locally; why ClamAV is not practical on Windows.
- Chunk size, MAC segment size, retention days, temp TTL.

Explain the four swappable drivers (delivery, scanner, tracker, probe) and why they exist:
local and production differ materially, and the alternative is conditional code everywhere.

## docs/deployment-cpanel.md

Every setting with its location in the control panel and its consequence:

| Setting | Value | Where |
|---|---|---|
| `post_max_size` | 32M | MultiPHP INI Editor |
| `upload_max_filesize` | 16M | same |
| `memory_limit` | 256M | same |
| `max_execution_time` | 120 | same |
| Max Request Body Size | 64M | WHM → LiteSpeed → Tuning |
| `SecRequestBodyNoFilesLimit` | ≥ 32M | ModSecurity |
| EP / nproc | ≥ 30 | CloudLinux LVE |
| I/O and IOPS | highest available | LVE |

Each with its consequence spelled out:

- `open_basedir` must include every vault root, or every write fails after deploy.
- Exclude the storage directory from JetBackup, or backups grow by terabytes.
- Cloudflare's free plan caps request bodies at 100 MB — 8 MiB chunks pass, but large downloads
  need a DNS-only subdomain.
- ModSecurity intermittently blocks chunk POSTs; the symptom is random 403s on a subset of
  chunks. Include the exception rule for the uploads path.
- Verify `exec()` is not in `disable_functions` before enabling ClamAV or ffmpeg.
- Confirm all four disks are on one partition — `rename()` silently degrades to a full copy
  otherwise, which for a 5 GB file means minutes and double the space.

**Cron.** The queue worker and the scheduler, with the exact lines, and the note that
`--max-time` stops a worker picking up *new* jobs without killing a running one, so a long
ffmpeg run completes safely.

**The deploy sequence.** Including `npm run build` and `wayfinder:generate --with-form`, and
running `vault:doctor` as a gate that aborts the deploy on any FAIL.

**First-deploy checklist**, ordered, with the master key generated and backed up to two offline
locations **before the first upload** — at the top of the list, because after the first upload
it is too late.

## docs/storage.md

Where the bytes actually live.

- The four disks, their roles, the sharded layout, the permissions.
- The `.mac` sidecars and the variants.
- **Nothing in `public/`.** The `storage:link` prohibition and what one command would undo.
- Encryption at a level an operator needs: what is encrypted, where the keys live, and where
  the one plaintext window is — the `work/` directory during pipeline processing and download
  preparation, how long it lasts, and what removes it.
- Backup: the database, the files, and the key — **kept separately**, with the reason.
- Growth planning: per-author quota times author count, with a worked example and a disk-space
  alert threshold.

## docs/troubleshooting.md

A symptom-first table. Every entry must come from something that actually happened in this
project or is provably reachable from the code.

| Symptom | Cause | Fix |
|---|---|---|
| Upload hangs at the first chunk, no error | `npm run dev` not running; stale `public/build` | `npm run build`, or start the dev server |
| 413 on chunks, CLI limits look fine | FPM has a different `php.ini` | `vault:doctor --fpm`; edit the ini it names |
| Site 500s, CLI works | Herd serving a different PHP version | `herd isolate 8.4`, restart |
| Files stuck at `scanning` | No queue worker | Start `queue:work --queue=media,default` |
| Random 403s on some chunks | ModSecurity | Add the exception |
| Slow uploads, no errors | LVE I/O limits, or Defender scanning storage | Raise the limit; add the exclusion |
| Tests fail after renaming pages | Stale `public/build` | `npm run build` |
| Type-check fails on `.form()` everywhere | `wayfinder:generate` run without `--with-form` | Regenerate with the flag |
| Disk filling, no visible files | Abandoned upload sessions holding pre-allocated placeholders | Document the current manual cleanup and note the gap |

Add whatever else the code tells you is reachable.

## Style

Written for a developer who has never seen this project.

Commands in fenced blocks, copy-pasteable, PowerShell where the platform is Windows. Every step
says how to verify it worked. Tables for settings. No marketing language, no restating the
obvious, no "simply" or "just".

Warnings for the three things that destroy data or waste days: the master key, `storage:link`
on a vault disk, and the stale-build trap.

English. The audience is developers, and the codebase and AGENTS.md are already in English.

## Report the gaps you find

Documenting a system exposes what is undocumented or wrong. List separately, and change
nothing:

- `.env` keys used in code but absent from `.env.example`.
- Settings in `VaultDoctor` that no document or config explains.
- Anything in AGENTS.md that no longer matches the code.
- Steps you could not verify because they need a real cPanel account — mark them clearly as
  unverified rather than presenting them as tested.

## Acceptance gate

1. The files listed above, written.
2. The gap list.
3. **Walk your own installation guide on this machine**, step by step, and report any step that
   was wrong, missing or out of order. A setup guide nobody has followed is a draft.
4. Confirm no application code, configuration or test was modified — `git status --short` should
   show only documentation files.

Then STOP.

## Git policy

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed.
- If you believe a commit is necessary, STOP and ask.
- End by printing `git status --short`.
