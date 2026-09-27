# Deployment — cPanel / LiteSpeed / CloudLinux

> **Verification status.** The author of this document has no cPanel account
> for this project. Every setting below is derived from the repository — from
> `VaultDoctor`'s checks, `config/vault.php`, `config/filesystems.php` and
> `CLAUDE.md` — and from the documented behaviour of LiteSpeed, ModSecurity and
> CloudLinux. **Control-panel paths and the cron syntax are unverified against
> a live account.** Treat this as a checklist to work through with the hosting
> provider, not as a transcript of a completed deployment. Items that could not
> be verified at all are marked **[unverified]**.

---

## What is different about production

Four seams swap implementation between local and production. Nothing else
changes.

| Contract | Local (Herd) | Production (cPanel) |
|---|---|---|
| `Delivery` | `StreamDelivery` | `LiteSpeedDelivery` |
| `Scanner` | `NullScanner` | `ClamavScanner` |
| `ChunkTracker` | `DatabaseChunkTracker` | `RedisChunkTracker` |
| `MediaProbe` | `FfmpegProbe` | `FfmpegProbe` or `NullProbe` |

```dotenv
APP_ENV=production
APP_DEBUG=false

DELIVERY_DRIVER=litespeed
SCAN_DRIVER=clamav
CHUNK_TRACKER_DRIVER=redis
MEDIA_PROBE_DRIVER=ffmpeg
```

`CHUNK_TRACKER_DRIVER=redis` requires Redis to be reachable. If it is not,
leave it at `database` — Redis is optional by design and a missing Redis must
never take the deposit flow down with it.

---

## PHP settings

**MultiPHP INI Editor** (cPanel), or `.user.ini` in the document root.

| Setting | Value | Consequence if wrong |
|---|---|---|
| `post_max_size` | `32M` | Below 8 MiB: every chunk POST returns 413. Far above: a single-request upload works in production and hides an architecture violation. |
| `upload_max_filesize` | `16M` | Backstop. Chunk bodies are raw `application/octet-stream`, not multipart. |
| `memory_limit` | `256M` | The pipeline streams. If 256M is insufficient, something is buffering a whole file and that is the bug. |
| `max_execution_time` | `120` | No HTTP request does heavy work; the queue does. |
| `session.gc_maxlifetime` | `≥ 14400` | A 5 GB upload outliving its session fails every remaining chunk. |

**Verify after applying** — from SSH, in the application directory:

```bash
php artisan vault:doctor --fpm
```

Only the FPM column governs uploads. Rows marked `[sapi]` depend on it and
differing values are flagged with `*`.

### PHP version

8.4 or later. `VaultDoctor` FAILs below it regardless of what `composer.json`
allows. Set it in **MultiPHP Manager**, per domain.

### `disable_functions`

`exec`, `shell_exec` and `proc_open` must not all be disabled, or neither
ffmpeg nor ClamAV can be invoked. The doctor FAILs when all three are off.
Shared hosts frequently disable them by default.

```bash
php -i | grep disable_functions
```

### `open_basedir`

If set, it **must include every vault root**. PHP refuses any path outside it,
so an incomplete list means every write fails immediately after deploy, with a
permission error that looks like a filesystem problem.

```
/home/<acct>/onda-storage/vault:/home/<acct>/onda-storage/incoming:/home/<acct>/onda-storage/work:/home/<acct>/onda-storage/variants:/home/<acct>/public_html:/tmp
```

`VaultDoctor` checks each disk root against `open_basedir` and FAILs any that
falls outside — but only when `open_basedir` is set at all.

---

## LiteSpeed

| Setting | Value | Where | Consequence |
|---|---|---|---|
| Max Request Body Size | `64M` | WHM → LiteSpeed Web Server → Tuning **[unverified]** | Below 8 MiB, LiteSpeed rejects chunks before PHP is reached — a 413 that no PHP setting explains. |
| `X-LiteSpeed-Location` support | enabled (default) | — | Required by `LiteSpeedDelivery`. Without it downloads fall back to streaming through PHP and pin a worker for the whole transfer. |

---

## ModSecurity

ModSecurity intermittently blocks chunk POSTs. The symptom is distinctive and
easy to misread: **random 403s on a subset of chunks**, different chunks each
attempt, with nothing in the Laravel log — because the request never reached
PHP.

| Setting | Value | Consequence |
|---|---|---|
| `SecRequestBodyNoFilesLimit` | `≥ 32M` | The default (around 128 KB) rejects every 8 MiB chunk, since the body is raw octet-stream rather than a file upload. |

Exception rule for the uploads path **[unverified — confirm the rule ID range
with the host]**:

```apache
<LocationMatch "^/uploads/">
    SecRuleRemoveById 200002 200003
    SecRequestBodyNoFilesLimit 33554432
</LocationMatch>
```

Chunk endpoints live at `POST /uploads/{uuid}/chunk/{index}` (see
`routes/author.php`), deliberately not locale-prefixed, so a single path prefix
covers them.

---

## CloudLinux LVE

| Setting | Value | Consequence |
|---|---|---|
| EP / nproc | `≥ 30` | The uploader issues up to 3 concurrent chunk requests per file. A low entry-process limit queues them and throughput collapses — it looks like a slow server, not a limit. |
| I/O | highest available | Every chunk is a seek-and-write. |
| IOPS | highest available | Same. |
| Memory | ≥ 1 GB | Streaming, not buffering, but ffmpeg needs headroom. |

Slow uploads with no errors anywhere are usually LVE I/O throttling. Check
`lveinfo` or the LVE Manager graphs before looking anywhere else.

---

## Storage

Four directories outside the document root, **all on one partition**:

```bash
mkdir -p /home/<acct>/onda-storage/{vault,incoming,work,variants}
chmod 700 /home/<acct>/onda-storage/{vault,incoming,work,variants}
```

```dotenv
VAULT_DISK_ROOT=/home/<acct>/onda-storage/vault
INCOMING_DISK_ROOT=/home/<acct>/onda-storage/incoming
WORK_DISK_ROOT=/home/<acct>/onda-storage/work
VARIANTS_DISK_ROOT=/home/<acct>/onda-storage/variants
```

**Confirm one partition.** Completing an upload is a `rename()` from `incoming`
to `vault`. Across partitions `rename()` silently degrades to a full byte copy:
for a 5 GB file that is minutes instead of milliseconds, and double the space
while it runs.

```bash
stat -c '%d %n' /home/<acct>/onda-storage/*
```

All four device numbers must match. `vault:doctor` performs the same comparison
and FAILs a split.

### Exclude storage from JetBackup

The vault holds the largest data in the account and is already backed up
separately (see [storage.md](storage.md#backup)). Leaving it in JetBackup grows
backups by terabytes and slows every run.

**JetBackup → Settings → Exclusions** **[unverified]**:

```
/home/<acct>/onda-storage/*
```

Back the vault up on its own schedule. Never in the same set as the master key.

---

## Cloudflare

Cloudflare's free plan caps request bodies at **100 MB**. 8 MiB chunks pass
comfortably, so uploads work.

Large **downloads** are the problem: a 5 GB response through the proxy will be
cut. Serve downloads from a **DNS-only** (grey-cloud) subdomain — for example
`files.example.dz` — so the transfer bypasses the proxy entirely.

---

## The master key

> Generate it and back it up to **two offline locations before the first
> upload**. After the first upload it is too late: losing the key makes every
> deposit permanently unrecoverable.
>
> Never place the key in the same backup set as the vault data. A backup
> containing both is equivalent to storing the data unencrypted.

```bash
mkdir -p /home/<acct>/onda-secrets
head -c 32 /dev/urandom > /home/<acct>/onda-secrets/vault-master.key
chmod 600 /home/<acct>/onda-secrets/vault-master.key
```

```dotenv
VAULT_MASTER_KEY_PATH=/home/<acct>/onda-secrets/vault-master.key
```

Outside the repository, outside `storage/`, outside `public_html`, and excluded
from JetBackup. `VaultDoctor` FAILs a key inside `base_path()` and WARNs on
permissions looser than `0600`.

Back up **`APP_KEY` as well** — `dek_wrapped` is encrypted by both.

---

## Cron

### Queue worker

```cron
* * * * * cd /home/<acct>/app && /usr/local/bin/php artisan queue:work --queue=media,default --sleep=3 --tries=3 --max-time=3600 --stop-when-empty >> /dev/null 2>&1
```

`--max-time=3600` stops the worker accepting **new** jobs after an hour; it does
not kill a job already running. A two-hour ffmpeg run started at minute 59
finishes normally. This is what makes a cron-driven worker safe on a host with
no supervisor.

`--queue=media,default` is required. Without it the worker only drains
`default` and no pipeline job ever runs.

> A cron-restarted worker is a fallback. If the host offers a real supervisor
> (CloudLinux's process manager, or Supervisor itself), prefer it.

### Scheduler

```cron
* * * * * cd /home/<acct>/app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler currently runs one task, `vault:purge-temp`, hourly: it sweeps
`work/` for plaintext temp files and variant staging files older than
`VAULT_TEMP_TTL_MINUTES`. `CleanupTemp` is the normal path; this catches jobs
that crashed before reaching it. It is `onOneServer()`, so it is safe to run
the scheduler on more than one host.

Use absolute paths. Cron's PATH is minimal — this is the same reason
`FFMPEG_BINARY` should be an absolute path.

---

## Deploy sequence

```bash
cd /home/<acct>/app

php artisan down

git pull origin main

composer install --no-dev --optimize-autoloader
npm ci

# Generated route/action helpers. --with-form is required: without it every
# .form() call site fails type-checking.
php artisan wayfinder:generate --with-form

npm run build

php artisan migrate --force

# Re-derive college_oeuvre_files.mime_types if the format registry changed.
php artisan referentiel:sync-mime-types

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Gate: abort on any FAIL.
php artisan vault:doctor || { echo "vault:doctor FAILED — aborting"; php artisan up; exit 1; }

php artisan queue:restart
php artisan up
```

Two things worth stating:

- **`npm run build` is not optional.** Without it the server keeps serving the
  previous bundle and the deploy silently does nothing visible.
- **`vault:doctor` is the gate.** Any FAIL means the environment cannot host
  the vault safely. Do not deploy past it.

`php artisan queue:restart` signals running workers to exit cleanly after their
current job; cron starts a fresh one with the new code.

---

## First-deploy checklist

Ordered. The key comes first, because after the first upload it is too late.

- [ ] **Generate the vault master key.**
- [ ] **Back up the master key to two offline locations.**
- [ ] **Back up `APP_KEY` to the same two locations.**
- [ ] Confirm neither key is in any automated backup that also holds vault data.
- [ ] Create the four storage directories, `chmod 700`.
- [ ] Verify all four report the same device number.
- [ ] Add every vault root to `open_basedir`, if it is set.
- [ ] Exclude `/home/<acct>/onda-storage/*` from JetBackup.
- [ ] Set PHP 8.4+ in MultiPHP Manager.
- [ ] Apply the five PHP settings; verify with `vault:doctor --fpm`.
- [ ] Set LiteSpeed Max Request Body Size to 64M.
- [ ] Set `SecRequestBodyNoFilesLimit` and add the `/uploads/` exception.
- [ ] Raise LVE EP/nproc to ≥ 30 and I/O to the highest available.
- [ ] Confirm `exec` is callable before enabling ClamAV or ffmpeg.
- [ ] Set `FFMPEG_BINARY` / `FFPROBE_BINARY` / `CLAMDSCAN_BINARY` to absolute paths.
- [ ] Install both cron entries.
- [ ] If downloads go through Cloudflare, create the DNS-only subdomain.
- [ ] **Re-measure the MIME types on this host.** `App\Support\FileFormats` records values measured on libmagic 545 (Windows); cPanel ships a different build and can return a different type for the same file — notably `application/zip` instead of the full OpenXML type. Run `finfo_file()` over a sample of each accepted format here and add any new value to the registry's `mimes` list.
- [ ] **Run `php artisan referentiel:sync-mime-types`** after any change to the format registry, so `college_oeuvre_files.mime_types` is re-derived. `--dry-run` first to see what would change.
- [ ] Run `php artisan vault:doctor` — **zero FAILs**.
- [ ] Upload one small test file end to end; confirm it reaches `ready`.
- [ ] Confirm nothing appeared under `public_html`.

---

## Post-deploy verification

```bash
php artisan vault:doctor                      # zero FAILs
php artisan queue:failed                      # empty
ls -la /home/<acct>/public_html/storage       # must NOT exist
tail -f storage/logs/laravel.log
```

The `public_html/storage` check matters: a symlink there means someone ran
`php artisan storage:link` against a vault disk, which publishes the entire
deposit archive. See [storage.md](storage.md#nothing-in-public).
