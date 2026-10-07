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

Three processes must be up at the same time. Each needs its own terminal.

```powershell
# 1. The web server — Herd serves the site automatically once the site is linked.
#    Confirm with: herd links

# 2. The frontend dev server.
npm run dev

# 3. The queue worker. Herd does NOT manage this.
php artisan queue:work --queue=media,default
```

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

## Common commands

**Backend**

```powershell
php artisan test --compact          # the full Pest suite
composer lint                       # Pint, fix
composer types:check                # PHPStan (Larastan, level 7)
composer test                       # config:clear + lint:check + types:check + tests
php artisan vault:doctor            # environment audit (CLI)
php artisan vault:doctor --fpm      # ...and the web SAPI, side by side
```

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

Each run really uploads the files, so it leaves `e2e-*` deposits (about
300 MB) in the demo oeuvre and charges the demo author's quota. Use a throwaway
database (`php artisan migrate:fresh --seed`) if that matters.

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
