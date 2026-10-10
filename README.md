# ONDA Storage

The digital portal for ONDA (Office National des Droits d'Auteur et des Droits
Voisins, Algeria): authors register copyright in their works and deposit the
files that prove it.

Laravel 13 + Inertia.js v3 + Vue 3 (TypeScript), with a deposit vault built on
top. Authors upload video, audio, PDF and PPTX files up to **5 GB each**, in
8 MiB chunks, encrypted before a single byte reaches disk. The files are legal
deposits, so integrity and the audit trail matter more than convenience.

Interface languages: French, Arabic (RTL) and English.

---

## Documentation

| Document | What it covers |
|---|---|
| [docs/installation.md](docs/installation.md) | Windows 11 + Herd, step by step, with verification after each step |
| [docs/configuration.md](docs/configuration.md) | Every `.env` key: what it does, local vs production, what breaks if it is wrong |
| [docs/deployment-cpanel.md](docs/deployment-cpanel.md) | Production on cPanel/LiteSpeed/CloudLinux |
| [docs/storage.md](docs/storage.md) | Where the bytes live, encryption, permissions, backup, growth planning |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Symptom → cause → fix |

`CLAUDE.md` in the repository root explains **why** the system is built the way
it is — the architecture decisions, the cryptography, the soft-delete
classification. These documents explain **how to run it**. Where you need a
rationale rather than a procedure, go there.

---

## Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| PHP | **8.4+**, 64-bit | `composer.json` declares `^8.3`, but `VaultDoctor` fails anything below 8.4 — see the gap note in [docs/installation.md](docs/installation.md#prerequisites). 32-bit PHP cannot seek past 2 GB and will corrupt large files. |
| Node.js | 20+ (developed on 24) | No `engines` constraint is declared in `package.json`. |
| Composer | 2.x | |
| MySQL | 8.0+ | Not bundled with Herd's free tier. |
| ffmpeg / ffprobe | any recent build | Optional; without it set `MEDIA_PROBE_DRIVER=null`. |
| Redis | — | **Optional by design.** Everything works without it. |

---

## Quick start

Full instructions, including the parts that are easy to get wrong, are in
[docs/installation.md](docs/installation.md). The short version:

```powershell
git clone <repository-url> onda-storage
cd onda-storage

composer install
npm install

Copy-Item .env.example .env
php artisan key:generate
```

Then, before anything else works, you must create the storage directories and
the vault master key — see
[installation §8](docs/installation.md#8-storage-directories) and
[§9](docs/installation.md#9-the-vault-master-key). Afterwards:

```powershell
php artisan migrate --seed
npm run build
php artisan vault:doctor
```

`vault:doctor` is the gate. **Zero FAILs** is the bar; WARNs are normal on a
local machine.

---

## Running it

Four processes must be up at the same time. Each needs its own terminal.

```powershell
# 1. The web server — Herd serves the site automatically once the site is linked.
#    Confirm with: herd links

# 2. The frontend dev server.
npm run dev

# 3. The queue worker. Herd does NOT manage this.
php artisan queue:work --queue=media,default

# 4. The previews worker (page images, web video for the admin review).
#    NOTE the connection name `previews` before --queue: see "Queue workers".
php artisan queue:work previews --queue=previews --timeout=6600
```

Without **(4)**, deposits still reach *ready* (they never wait for previews)
but the admin review shows "preparing" until the previews worker runs.

Without **(2)**, Laravel silently serves the last `public/build` — your source
changes are invisible in the browser. Without **(3)**, uploads complete but stay
at `scanning` forever, because nothing runs the post-upload pipeline.

> **Do not use `composer run dev` for upload work.** It starts
> `php artisan serve`, which is single-process on Windows: the three concurrent
> chunk requests the uploader issues queue behind one another and the
> architecture appears broken when it is not. It also starts a queue listener
> without `--queue=media,default`, so the `media` queue is never drained. See
> [troubleshooting](docs/troubleshooting.md).

---

## Queue workers

Two queues, on two queue *connections* backed by the same `jobs` table:

| Work | Connection | Queue | `retry_after` | Worker |
|---|---|---|---|---|
| The upload pipeline (decrypt, hash, dedup, scan, variants, record, cleanup) and notifications | `database` | `media`, `default` | 3600 s | `php artisan queue:work --queue=media,default` |
| Consultation previews (page images, sheet JSON, 480p video) | `previews` | `previews` | 7200 s (`VAULT_CONSULT_RETRY_AFTER`) | `php artisan queue:work previews --queue=previews --timeout=6600` |

A deposit reaches *ready* without waiting for previews: the last step of the
upload chain (`CleanupTemp`) queues `GenerateConsultationDerivative` on
`previews`, and the preview job decrypts the original into its own scratch
directory, renders, encrypts the derivatives and deletes the directory.

**Why a separate connection, and why the connection name is in the command.**
`retry_after` belongs to the *connection* the worker is started on. A
full-length ffmpeg encode took about 16 minutes on the long videos here and can
take far longer on a weak host; with the 3600 s of the pipeline connection a
slow encode would be handed to a second worker while still running. The preview
job's timeout (`VAULT_CONSULT_JOB_TIMEOUT`, 6600 s) is always below the previews
`retry_after` (a test asserts it), and the ffmpeg limit
(`VAULT_CONSULT_RENDER_TIMEOUT`, 5400 s) is below the job timeout. A worker
started as `queue:work --queue=previews` WITHOUT the `previews` connection name
reads the same table but uses the `database` connection's 3600 s, which defeats
the point — always pass `previews` first.

Encode speed: `VAULT_CONSULT_VIDEO_PRESET` (libx264 `-preset`, default
`veryfast`) and `VAULT_CONSULT_VIDEO_CRF` (default `28`).

**cPanel (cron instead of a daemon).** There is no long-running worker, so cron
starts short-lived ones every minute:

```cron
* * * * * cd /home/USER/app && php artisan queue:work --queue=media,default --stop-when-empty --max-time=55 --timeout=3000 >> /dev/null 2>&1
* * * * * cd /home/USER/app && flock -n /tmp/onda-previews.lock php artisan queue:work previews --queue=previews --stop-when-empty --max-time=3300 --timeout=6600 >> /dev/null 2>&1
```

- `--stop-when-empty` makes the process exit as soon as the queue is drained, so
  idle ticks cost nothing and do not pile up.
- `--max-time` is a *between jobs* limit: after that many seconds the worker
  takes no NEW job and exits, but a job already running is allowed to finish
  (up to `--timeout`). It is therefore safe to set well below the job length;
  it never kills an encode.
- The previews line runs under `flock -n`: if the previous tick's worker is
  still busy with a long encode, the new tick exits at once instead of starting
  a second CPU-heavy worker beside it. The pipeline line has no `flock`, so
  several of its workers can drain `media` in parallel.
- Neither line can shorten a running job: only `--timeout` (and the host's own
  process limits, which you must check — some shared hosts kill processes after
  a fixed time) can do that.

---

## Common commands

**Backend**

```powershell
php artisan test --compact          # the full Pest suite (temporary storage roots, see below)
composer lint                       # Pint, fix
composer types:check                # PHPStan (Larastan, level 7)
composer test                       # config:clear + lint:check + types:check + tests
php artisan vault:doctor            # environment audit (CLI)
php artisan vault:doctor --fpm      # ...and the web SAPI, side by side
```


**Tests never touch the real vault.** `Tests\TestCase` points the `vault`,
`incoming`, `work` and `variants` disks at a temporary directory created for
each test and removed afterwards, whatever `VAULT_DISK_ROOT` etc. say in `.env`;
`tests/Feature/Isolation/StorageIsolationTest.php` fails if that is bypassed.
Before this, test runs left thousands of orphan files in the dev vault
(`php artisan vault:doctor` lists them; `vault:quarantine-orphans` moves them
aside, never deleting).

**Vault housekeeping commands** (all dry-run unless `--apply`; none deletes
bytes): `vault:mark-missing-bytes`, `vault:recount-refs`,
`vault:quarantine-orphans --older-than=7`.

**Frontend**

```powershell
npm run dev
npm run build
npm run types:check                 # vue-tsc
npm run lint:check                  # ESLint
npx vitest run                      # frontend unit tests
```

**After changing routes or controllers**

```powershell
php artisan wayfinder:generate --with-form
```

The `--with-form` flag is not optional. Without it the generated helpers lose
their `.form()` variants and `npm run types:check` fails at every call site that
uses one.

---

## End-to-end tests (Playwright)

`tests/e2e/concurrent-uploads.spec.ts` drives a real Chromium against the
**local Herd site** and checks that a second upload is not rejected while a
large one is running: a 300 MB file is started in the multi-file video slot,
and once it is well under way a small PDF in another slot and a small PDF in
the same slot must both reach *Deposited* while the large file is still
uploading. It then waits for the large file to complete and asserts that no
upload request failed with anything other than a 429/503 that recovered.

These tests are **not** part of `composer ci:check` (nor of `php artisan test`
or Vitest). They need:

- a **local seeded database** — the demo author (`author1@onda.dz`, from
  `RoleAndUserSeeder`; the password is read from that seeder) and a draft
  oeuvre with its required-document slots;
- the site served by Herd, a running **queue worker** (`php artisan queue:work
  --queue=media,default`) and a **current `npm run build`**;
- Chromium for Playwright, once: `npx playwright install chromium`.

```powershell
npx playwright test                                  # or: tests/e2e/concurrent-uploads.spec.ts
```

Everything is configurable by environment variable: `E2E_BASE_URL` (default
`http://onda-storage.test`), `E2E_EMAIL`, `E2E_PASSWORD`, `E2E_OEUVRE_UUID`,
`E2E_LARGE_MB` (300), `E2E_UPLOAD_MBPS` (emulated upload bandwidth, default 3)
and `E2E_START_PCT` (how far the large file gets before the small ones start,
default 30).

**The e2e suite cleans up after itself.** It runs against the Herd site and
really uploads into the dev vault, so Playwright's `globalTeardown`
(`tests/e2e/cleanup.ts`) runs `php artisan e2e:cleanup --apply` when the run
ends. That command purges only the suite's own deposits (`original_name`
starting `e2e-`, uploaded by the demo author), frees their bytes through
`VaultBytesReleaser` (bytes still referenced by any other row are kept),
removes their derivatives, and returns the quota charge. This is the app's
deletion path, not a dedicated storage root: a file removed in the UI is only
soft-deleted and keeps its bytes until purge, so the purge marker
(`purged_at`) is set explicitly. `E2E_SKIP_CLEANUP=1` keeps the files for
inspection; `php artisan e2e:cleanup` alone is a dry run.

A dedicated vault root for e2e is not used on purpose: it would need a second
web server with its own `.env`, and `php artisan serve` is single-process on
Windows, which defeats a test whose point is concurrent chunk requests.

---

## Three things that cost real time if you get them wrong

> **The vault master key.** Lose the file at `VAULT_MASTER_KEY_PATH` and every
> stored file is permanently unrecoverable — there is no recovery path, by
> design. Equally: a backup containing both the key and the encrypted data is
> the same as storing the data unencrypted. Keep them apart.
> See [docs/storage.md](docs/storage.md#backup).

> **Never run `php artisan storage:link` against a vault disk.** One command
> publishes the entire deposit archive under `public/`, undoing every access
> control the system has. See [docs/storage.md](docs/storage.md#nothing-in-public).

> **A stale `public/build` is invisible.** The app loads, behaves normally, and
> acts as though your committed fixes do not exist. This has cost this project
> one full misdiagnosis session and, separately, twelve phantom test failures.
> `vault:doctor` warns about it; believe the warning.
