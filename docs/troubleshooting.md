# Troubleshooting

Symptom first. Every entry below is either something that actually happened in
this project or is directly reachable from the code.

**Start here.** `vault:doctor` diagnoses roughly half of these on its own:

```powershell
php artisan vault:doctor
php artisan vault:doctor --fpm
```

---

## Quick index

| Symptom | Cause | Fix |
|---|---|---|
| Upload hangs at the first chunk, no error | `npm run dev` not running; stale `public/build` | `npm run build`, or start the dev server |
| 413 on chunks, CLI limits look fine | The web SAPI has different effective limits | `vault:doctor --fpm`; edit the ini it names |
| Site 500s, CLI works | Herd serving a different PHP version | `herd isolate 8.4`, `herd restart` |
| Files stuck at `scanning` | No queue worker, or the worker is not on `media` | `php artisan queue:work --queue=media,default` |
| Random 403s on some chunks | ModSecurity | Add the `/uploads/` exception |
| Slow uploads, no errors | LVE I/O limits, or Defender scanning storage | Raise the limit; add the exclusion |
| Uploads serialise, one chunk at a time | `php artisan serve` is single-process on Windows | Use Herd |
| Tests fail after renaming pages | Stale `public/build` | `npm run build` |
| Type-check fails on `.form()` everywhere | `wayfinder:generate` without `--with-form` | Regenerate with the flag |
| Disk filling, no visible files | Abandoned sessions holding pre-allocated placeholders | See [below](#disk-filling-with-no-visible-files) |
| `complete()` takes minutes on a large file | Disks on different partitions | Move them onto one |
| Decryption fails after a restore | `APP_KEY` or master key mismatch | Restore the right keys |
| Two workers on one file | `retry_after` below 3600 | Restore 3600 |

---

## Upload hangs at the first chunk, no error

**Cause.** `npm run dev` is not running, so Laravel falls back to whatever is in
`public/build`. If that bundle predates the current source, the browser runs old
upload code — and old code that no longer matches the server fails in ways that
produce no visible error.

This is the single most expensive trap in this project. It cost one full
misdiagnosis session, and separately produced twelve phantom test failures that
looked like real regressions.

**Fix.**

```powershell
npm run build
# or, for development
npm run dev
```

**Confirm.**

```powershell
php artisan vault:doctor | Select-String "Frontend asset source"
```

`VaultDoctor` compares the newest mtime under `resources/js` with
`public/build/manifest.json` and WARNs when the build is behind. It only does
this when `APP_ENV=local` — serving a build in production is expected.

---

## 413 on chunk POSTs, but the CLI limits look correct

**Cause.** The command line and the web server do not necessarily share
effective PHP limits. Checking `php -i` from a terminal tells you nothing about
what PHP-FPM enforces.

On the reference machine both SAPIs load the *same* ini file and
`max_execution_time` still differs (`0` on CLI, `300` on FPM), because Herd
applies pool-level overrides on top of it. So "they're the same file" is not
reassurance.

**Fix.**

```powershell
php artisan vault:doctor --fpm
```

Rows marked `[sapi]` depend on the SAPI; values that differ are flagged `*`.
Read the FPM column, find the `Loaded php.ini` it names, edit that, then:

```powershell
herd restart
```

Set `post_max_size=32M`. Below 8 MiB every chunk is rejected.

---

## Site returns 500, every CLI command works

**Cause.** Herd is serving the site on a different PHP version than your CLI
runs. This happened in this project and looked like a routing bug for two
sessions: PHP 8.2 in the browser, 8.4 on the command line.

**Fix.**

```powershell
herd isolate 8.4
herd restart
```

**Confirm.**

```powershell
php artisan vault:doctor --fpm | Select-String "PHP version"
```

Both columns must match, and both must be 8.4 or later.

---

## Files complete but stay at `scanning` forever

**Cause.** Nothing is running the pipeline. Either no queue worker is up, or
one is up but is not listening to the `media` queue.

Heavy work is dispatched to `media` so it cannot block notifications on
`default`. A worker started as plain `php artisan queue:work` drains only
`default` and will never pick up a pipeline job.

**Fix.**

```powershell
php artisan queue:work --queue=media,default
```

**Confirm.**

```powershell
php artisan queue:failed
php artisan vault:doctor | Select-String "Queue worker|Pending jobs"
```

> The worker-detection check uses `wmic`, which is deprecated and missing on
> some Windows 11 builds — it can report `unknown` while a worker is running
> perfectly well. Look at your terminal rather than trusting that row.

> **`composer run dev` does not fix this.** Its queue process is
> `queue:listen --tries=1 --timeout=0` with no `--queue` argument, so `media`
> is never drained.

---

## Uploads serialise — one chunk at a time, very slow

**Cause.** `php artisan serve` is single-process on Windows. The uploader
issues up to three concurrent chunk requests per file; against `serve` they
queue behind one another. Throughput collapses and the chunked architecture
appears broken when it is working exactly as designed.

**Fix.** Use Herd, which runs PHP-FPM with multiple workers. Start `npm run dev`
and the queue worker in their own terminals rather than using
`composer run dev`.

---

## Random 403s on a subset of chunks

**Cause.** ModSecurity. The tell is that the failing chunks differ on each
attempt and **nothing appears in the Laravel log** — the request never reached
PHP.

**Fix.** Raise `SecRequestBodyNoFilesLimit` to at least 32M and add an
exception for the uploads path. Chunk bodies are raw `application/octet-stream`,
not file uploads, so the default no-files limit (around 128 KB) rejects every
one of them.

See [deployment-cpanel.md](deployment-cpanel.md#modsecurity) for the rule.
Production only.

---

## Slow uploads with no errors anywhere

Two common causes.

**On production: CloudLinux LVE limits.** Every chunk is a seek-and-write; I/O
and IOPS throttling shows up as slowness with clean logs. Raise I/O and IOPS to
the highest available and set EP/nproc to at least 30 — a low entry-process
limit queues the three concurrent chunk requests.

**On Windows: Defender scanning the storage directory.** Real-time protection
inspects every chunk write.

```powershell
# PowerShell as Administrator
Add-MpPreference -ExclusionPath "C:\onda-storage"
(Get-MpPreference).ExclusionPath
```

---

## Tests fail after renaming or adding a page

**Cause.** Inertia page components are resolved through the Vite manifest. A
new or renamed `.vue` file that is not in `public/build/manifest.json` raises:

```
Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest:
resources/js/pages/....vue
```

Every test touching that page returns 500 and the failures look like a
controller bug.

**Fix.**

```powershell
npm run build
```

> Do not run `npm run build` while `php artisan test` is running. The manifest
> is rewritten mid-suite and tests fail with
> `Unable to locate font CSS file from manifest` or
> `Not a valid Inertia response`. This has produced phantom failures in this
> project more than once. Let the build finish first.

---

## `npm run types:check` fails on every `.form()` call

**Cause.** `php artisan wayfinder:generate` was run without `--with-form`. The
vite plugin is configured with `formVariants: true` (`vite.config.ts`), so the
generated helpers normally carry `.form()` variants. Running the artisan command
bare regenerates them without, and every call site breaks at once:

```
error TS2339: Property 'form' does not exist on type '{ (options?: ...
```

**Fix.**

```powershell
php artisan wayfinder:generate --with-form
npm run types:check
```

`resources/js/routes` and `resources/js/actions` are gitignored, so this
produces no diff — which is also why it is easy to do by accident and not
notice.

---

## Disk filling with no visible files

**Cause.** `POST /uploads` pre-allocates the **full declared size** immediately
with `ftruncate()`. A 5 GB upload occupies 5 GB on the `incoming` disk from the
first chunk, before any content arrives. An abandoned session — a closed tab, a
lost connection — leaves that placeholder behind.

`upload_sessions` rows are hard-deleted on completion and are swept after
expiry, but **the pre-allocated file on `incoming` is a separate concern**.

**Current state — a real gap.** The hourly `vault:purge-temp` scheduled task
sweeps the **`work`** disk only (`routes/console.php`). Nothing sweeps
`incoming`. The 30-day purge job for soft-deleted vault files is P7 and does
not exist yet either.

**Manual cleanup**, after confirming no upload is genuinely in progress:

```powershell
# Inspect first
Get-ChildItem C:\onda-storage\incoming |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddHours(-4) } |
    Select-Object Name, Length, LastWriteTime

# Then remove
Get-ChildItem C:\onda-storage\incoming |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddHours(-4) } |
    Remove-Item
```

```bash
find /home/<acct>/onda-storage/incoming -type f -mmin +240 -ls
find /home/<acct>/onda-storage/incoming -type f -mmin +240 -delete
```

Four hours is comfortably beyond `VAULT_TEMP_TTL_MINUTES` (120). Do not go
below that value — you will delete an upload that is still legitimately running.

Cross-check against live sessions before deleting anything:

```sql
SELECT uuid, filename, size_bytes, received_chunks, total_chunks, expires_at
FROM upload_sessions
WHERE expires_at > NOW();
```

---

## Completing an upload takes minutes on a large file

**Cause.** The four disks are not on the same partition. `complete()` does a
`rename()` from `incoming` to `vault`, which is instant within a partition and
a full byte copy across one — minutes, and double the space while it runs.

**Confirm.**

```powershell
php artisan vault:doctor | Select-String "share one partition"
```

```bash
stat -c '%d %n' /home/<acct>/onda-storage/*
```

**Fix.** Move all four roots onto one partition and update `.env`. Existing
files must be moved with them.

---

## Decryption fails after a restore

**Cause.** A key mismatch. `media_files.dek_wrapped` is encrypted by **both**
the vault master key and `APP_KEY`. Restoring the database and the files while
supplying a different value for either produces files that cannot be decrypted.

**Fix.** Restore the original `VAULT_MASTER_KEY_PATH` file and the original
`APP_KEY`. There is no recovery path if either is genuinely lost — that is the
design.

**Confirm.**

```powershell
php artisan vault:doctor | Select-String "Master key"
```

Expect PASS on exists, readable, ≥ 32 bytes, outside repo.

---

## MAC verification failed on read

**Cause.** The `.bin` and its `.mac` sidecar are out of sync, or the ciphertext
was modified. Most often: a backup or restore copied `.bin` files without their
`.mac` companions.

AES-CTR is unauthenticated by design — it has to be, to stay seekable — so the
HMAC sidecar is the only integrity signal. A mismatch means the read is
refused.

**Fix.** Restore both files together from the same backup point. They must
always travel as a pair.

---

## Two workers processing the same file

**Cause.** `retry_after` below 3600. The queue assumes a job is dead after that
many seconds and re-dispatches it. Hashing a 5 GB file or running ffmpeg takes
longer than Laravel's 90-second default, so the job is re-dispatched while it
is still running.

**Confirm.**

```powershell
php artisan vault:doctor | Select-String "retry_after"
```

`VaultDoctor` FAILs anything below 3600.

**Fix.** Restore `'retry_after' => 3600` in `config/queue.php`, or unset
`DB_QUEUE_RETRY_AFTER` so the default applies. To make a job give up sooner,
set that job's own `$timeout` — that is the correct mechanism.

---

## `vault:doctor` FAILs on a storage disk

| FAIL | Meaning | Fix |
|---|---|---|
| `Disk '<x>' root exists` | Directory missing | Create it |
| `Disk '<x>' writable` | Wrong owner or permissions | `chmod 700`, correct the owner |
| `Disk '<x>' outside repo` | Root is inside the project | Move it out |
| `Disk '<x>' within open_basedir` | PHP will refuse the path | Add every vault root to `open_basedir` |
| `Disk '<x>' offset write/read proof` | Offset writes are unreliable here | Usually a network or synced filesystem — move to local disk |
| `All vault disks share one partition` | Disks are split | Move them together |

The offset proof truncates a 64 MiB file, seeks to 32 MiB, writes a random
megabyte, reads it back and compares. It is the exact mechanism every chunk
upload uses. A failure here means uploads cannot work on this filesystem,
whatever else passes.

---

## WARNs that are expected locally

These are correct on a development machine. Do not "fix" them.

| WARN | Why it is fine |
|---|---|
| `Binary: clamdscan — not found` | ClamAV is impractical on Windows; `SCAN_DRIVER=null` is the documented local setting |
| `Redis — unavailable` | Optional by design; the doctor says so in its own rationale |
| `Disk '<x>' system drive check` | Only a preference for a dedicated data drive |
| `Queue worker running — unknown` | `wmic` is absent on newer Windows 11 builds |
| `Failed jobs — N` | Informational; review with `php artisan queue:failed` |

**Zero FAILs is the bar. WARNs are not failures.**

---

## Still stuck

```powershell
php artisan vault:doctor --fpm      # the whole environment, both SAPIs
php artisan queue:failed            # what the pipeline choked on
Get-Content storage\logs\laravel.log -Tail 100 -Wait
php artisan about                   # versions, drivers, cache state
```

Check the browser devtools Network tab for the failing chunk's actual status
code — 413, 403 and 500 point at three completely different layers, and
guessing between them is where the hours go.
