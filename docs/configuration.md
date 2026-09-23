# Configuration

Every `.env` key that matters, its local value, its production value, and what
breaks when it is wrong.

Keys not listed here are Laravel framework defaults that this project does not
depart from.

---

## Application

| Key | Local | Production | Notes |
|---|---|---|---|
| `APP_NAME` | `cloud-upload-onda` | as branded | Also feeds `VITE_APP_NAME`. |
| `APP_ENV` | `local` | `production` | `VaultDoctor`'s stale-build check only runs when this is `local` — outside local, serving a build is expected and never warned about. |
| `APP_KEY` | generated | generated, **and backed up** | See [the two keys](#the-two-keys). |
| `APP_DEBUG` | `true` | **`false`** | `true` in production exposes stack traces containing vault paths and configuration. |
| `APP_URL` | `http://onda-storage.test` | the real host | `.env.example` ships `http://localhost:8000`, which matches `php artisan serve`, not Herd. Signed URLs are generated from this — a wrong value produces links that fail signature validation. |
| `APP_LOCALE` | `en` | `ar` or `fr` | The backend's fallback locale is `ar`, set in `SetLocale::FALLBACK_LOCALE`, not here. |

### The two keys

`media_files.dek_wrapped` holds each file's data encryption key. It is wrapped
twice: by the **vault master key** (`VAULT_MASTER_KEY_PATH`) and by Laravel's
`encrypted` cast, which uses **`APP_KEY`**.

Losing either one makes every deposit unreadable. Both must be backed up, both
must be kept away from the encrypted data, and `APP_KEY` must not be rotated
without first re-encrypting every `dek_wrapped` value. Laravel's
`APP_PREVIOUS_KEYS` exists for rotation but is **not** present in
`.env.example`; if you ever rotate, read the framework documentation before
touching a populated database.

---

## Database

| Key | Local | Production | Notes |
|---|---|---|---|
| `DB_CONNECTION` | `mysql` | `mysql` | |
| `DB_HOST` | `127.0.0.1` | cPanel host, usually `localhost` | With MySQL in WSL2, `127.0.0.1` reaches it from Windows. |
| `DB_PORT` | `3306` | `3306` | |
| `DB_DATABASE` | `onda_storage` | cPanel-prefixed, e.g. `acct_onda` | |
| `DB_USERNAME` / `DB_PASSWORD` | yours | cPanel user | `.env.example` ships `admin123` — a committed placeholder. Change it. |

---

## Queue

| Key | Local | Production | Notes |
|---|---|---|---|
| `QUEUE_CONNECTION` | `database` | `database` | Redis is optional and must never become required. |
| `DB_QUEUE_RETRY_AFTER` | `3600` (default) | `3600` | **Not in `.env.example`** — the value comes from the default in `config/queue.php`. See below. |

### `retry_after = 3600`

```php
// config/queue.php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 3600),
```

Laravel's default is 90 seconds: after 90 seconds a job is assumed dead and
re-dispatched. Hashing a 5 GB file or running ffmpeg over it takes far longer
than that, so the default produces **two workers processing the same file
simultaneously** — duplicated work, interleaved status writes, and variant
files written twice.

`VaultDoctor` FAILs anything below 3600. Do not lower it. If you need a job to
give up sooner, set that job's own `$timeout`, which is the correct mechanism.

### Queues

Heavy work goes to the `media` queue so it cannot block notifications on
`default`. A worker must listen to both:

```bash
php artisan queue:work --queue=media,default
```

A worker started without `--queue` listens only to `default` and will never run
a single pipeline job. Uploads will complete and then sit at `scanning`
forever.

---

## Cache and session

| Key | Local | Production | Notes |
|---|---|---|---|
| `CACHE_STORE` | `database` | `database` or `redis` | Also backs `Cache::lock()`, which `DeduplicateFile` uses to serialise identical concurrent uploads. |
| `SESSION_DRIVER` | `database` | `database` | |
| `SESSION_LIFETIME` | `120` | `120` | Minutes. Separate from PHP's `session.gc_maxlifetime`, which must be ≥ 14400 so a long upload does not outlive its session. |

---

## Vault — storage roots

| Key | Local | Production | Notes |
|---|---|---|---|
| `VAULT_DISK_ROOT` | `C:/onda-storage/vault` | `/home/<acct>/onda-storage/vault` | Final encrypted deposits. |
| `INCOMING_DISK_ROOT` | `C:/onda-storage/incoming` | `/home/<acct>/onda-storage/incoming` | Pre-allocated files being written chunk by chunk. |
| `WORK_DISK_ROOT` | `C:/onda-storage/work` | `/home/<acct>/onda-storage/work` | **The only place plaintext ever exists.** See [storage.md](storage.md#the-plaintext-window). |
| `VARIANTS_DISK_ROOT` | `C:/onda-storage/variants` | `/home/<acct>/onda-storage/variants` | Posters, previews, waveforms. |

All four **must be on one partition**. Completing an upload is a `rename()`
from `incoming` into `vault`; across partitions that silently becomes a full
copy — minutes and double the disk for a 5 GB file. `VaultDoctor` compares the
device identity of all four and FAILs a split.

All four must also be **outside** the project directory. The doctor FAILs a
root inside `base_path()`.

Forward slashes, even on Windows.

---

## Vault — the master key

| Key | Local | Production |
|---|---|---|
| `VAULT_MASTER_KEY_PATH` | `C:/onda-secrets/vault-master.key` | `/home/<acct>/onda-secrets/vault-master.key` |

At least 32 bytes. Outside the repository, outside `storage/`, outside every
backup set that also contains the data. `0600` on Linux.

Read through `config('vault.master_key_path')` rather than `env()` directly, so
it survives `config:cache`.

> Losing this file makes every stored deposit permanently unrecoverable. A
> backup containing both this key and the vault data is equivalent to storing
> the data unencrypted.

---

## Vault — sizes and lifetimes

| Key | Value | Why this value |
|---|---|---|
| `VAULT_CHUNK_SIZE` | `8388608` (8 MiB) | Divisible by 16, so every chunk begins on an AES block boundary. Changing it breaks resumption of in-flight uploads and must match what the browser client uses. |
| `VAULT_MAX_FILE_SIZE` | `5368709120` (5 GiB) | The per-file ceiling from `CLAUDE.md`. |
| `VAULT_MAC_SEGMENT_SIZE` | `1048576` (1 MiB) | AES-CTR is unauthenticated, so ciphertext is HMAC'd per segment into a `{uuid}.mac` sidecar. Smaller segments mean more digests; larger ones mean a Range read must verify more data than it returns. |
| `VAULT_RETENTION_DAYS` | `30` | Days a soft-deleted file's bytes survive before the purge job frees them. Until then they still count against quota. |
| `VAULT_TEMP_TTL_MINUTES` | `120` | How long an in-progress upload session and its `incoming` temp file may live. Also the cutoff used by the hourly `vault:purge-temp` scheduled sweep of `work/`. |

---

## The four swappable drivers

Local (Herd on Windows) and production (cPanel on Linux) differ materially:
LiteSpeed can hand a file off to the kernel, Windows cannot; ClamAV runs as a
daemon on cPanel and is impractical on Windows. The alternative to these four
seams would be `if (production)` branches scattered through the codebase.

Exactly four seams are interfaces. Nothing else is.

| Key | Local | Production | What it selects |
|---|---|---|---|
| `DELIVERY_DRIVER` | `stream` | `litespeed` | How bytes reach the client. `stream` reads and writes through PHP, occupying a worker for the duration. `litespeed` writes an `X-LiteSpeed-Location` header and lets the web server serve the file, freeing the worker immediately. |
| `SCAN_DRIVER` | `null` | `clamav` | Malware scanning. `null` passes everything. |
| `CHUNK_TRACKER_DRIVER` | `database` | `redis` | Which chunks of an upload have arrived. `database` writes a row per chunk; `redis` uses a bitmap, which matters when 640 chunks arrive in quick succession. |
| `MEDIA_PROBE_DRIVER` | `ffmpeg` | `ffmpeg` or `null` | Duration and dimension extraction. `null` leaves those columns empty. |

> The environment key is **`DELIVERY_DRIVER`**, read in `config/vault.php` as
> `'delivery_driver' => env('DELIVERY_DRIVER', 'stream')`. If you have seen
> `FILE_DELIVERY` referenced anywhere, it is wrong and setting it does nothing.

### Why `SCAN_DRIVER=null` locally

ClamAV on Windows means either a manual daemon install or WSL2 with the vault
path mounted across the boundary. Both are fragile and neither reflects
production. `VaultDoctor` WARNs that `clamdscan` is missing and names the config
key to set — the WARN is the expected local state, not a defect.

Before enabling `clamav` in production, confirm `exec` is not in
`disable_functions`. The doctor FAILs when `exec`, `shell_exec` and `proc_open`
are *all* disabled, and neither ffmpeg nor ClamAV can run in that case.

---

## Media pipeline

| Key | Default | Notes |
|---|---|---|
| `FFMPEG_BINARY` | `ffmpeg` | **Set an absolute path.** The PATH the queue worker inherits is not your shell's. |
| `FFPROBE_BINARY` | `ffprobe` | Same. |
| `CLAMDSCAN_BINARY` | `clamdscan` | Same, on cPanel usually `/usr/local/cpanel/3rdparty/bin/clamdscan`. |
| `VAULT_PROBE_TIMEOUT` | `30` | Seconds for an ffprobe call. |
| `VAULT_SCAN_TIMEOUT` | `120` | Seconds for a ClamAV scan. |
| `VAULT_VARIANT_TIMEOUT` | `300` | Seconds for variant generation. All three must stay below `retry_after`. |
| `VAULT_DEDUP_LOCK_SECONDS` | `10` | How long `DeduplicateFile` holds `Cache::lock($sha256)`. Long enough for the lookup and row updates; short enough that a crashed worker's lock clears quickly. |
| `VAULT_POSTER_FRAME_PERCENT` | `0.10` | Where in the video the poster frame is taken from. |
| `VAULT_PREVIEW_MAX_SECONDS` | `30` | Preview clip length. |
| `VAULT_PREVIEW_MAX_HEIGHT` | `480` | Preview resolution ceiling. |

`config/vault.php` explains the absolute-path point directly: a tool installed
via winget may not be on the PATH the worker's process inherits even though it
is on the interactive shell's. The same holds on cPanel for a different reason —
cron and PHP-FPM run with a minimal PATH.

---

## Mail

| Key | Local | Production |
|---|---|---|
| `MAIL_MAILER` | `log` | `smtp` |
| `MAIL_HOST` / `MAIL_PORT` | `127.0.0.1` / `2525` | your SMTP provider |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | a real, deliverable address |

`MAIL_MAILER=log` writes mail to `storage/logs/laravel.log` instead of sending
it — the right local default.

> `MAIL_FROM_ADDRESS` ships as `hello@example.com`. A real SMTP provider will
> reject or silently rewrite that. Deposit-lifecycle notifications currently use
> the database channel only, so this affects password resets and email
> verification.

---

## Keys used by the framework but absent from `.env.example`

These are read somewhere in `config/` but never appear in the example file. All
have working defaults; they are listed so their absence is a known quantity
rather than a surprise.

| Key | Where | Why it matters here |
|---|---|---|
| `DB_QUEUE_RETRY_AFTER` | `config/queue.php` | Controls the 3600 value `VaultDoctor` FAILs below. Defaulted correctly, but undocumented and settable. |
| `PASSKEYS_USER_HANDLE_SECRET` | `config/fortify.php` | Falls back to `config('app.key')`. If set explicitly, it becomes a **third** secret to back up — passkey logins break without it. |
| `APP_PREVIOUS_KEYS` | `config/app.php` | The only supported route for `APP_KEY` rotation. Relevant because `dek_wrapped` depends on `APP_KEY`. |

The remaining ~84 undeclared keys are stock Laravel driver options (Memcached,
DynamoDB, SQS, Beanstalkd, Papertrail, Slack, Postmark, Resend) that this
project does not use.
