# Storage — where the bytes actually live

What is on disk, what state it is in, who can read it, and how to back it up
without destroying the protection.

`CLAUDE.md` explains *why* the cryptography is designed this way. This document
is what an operator needs to run it.

---

## The four disks

All four are `local` Flysystem disks declared in `config/filesystems.php` with
`'visibility' => 'private'`, `0600` files, `0700` directories, `'serve' => false`
and `'throw' => true`.

| Disk | Env key | Holds | Lifetime |
|---|---|---|---|
| `vault` | `VAULT_DISK_ROOT` | Final encrypted deposits and their `.mac` sidecars | Permanent, until the purge job runs after `VAULT_RETENTION_DAYS` |
| `incoming` | `INCOMING_DISK_ROOT` | Pre-allocated files being written chunk by chunk | Until the upload completes or expires (`VAULT_TEMP_TTL_MINUTES`) |
| `work` | `WORK_DISK_ROOT` | **Plaintext**, during pipeline processing and download preparation | Minutes to 30 minutes — see [the plaintext window](#the-plaintext-window) |
| `variants` | `VARIANTS_DISK_ROOT` | Posters, preview clips, waveforms | With the file |

Three hard rules:

1. **All four on one partition.** Completing an upload is a `rename()` from
   `incoming` to `vault`. That is instant only within a partition; across one it
   silently becomes a full byte copy — minutes and double the space for a 5 GB
   file. `VaultDoctor` compares device identity and FAILs a split.
2. **All four outside the project directory.** The doctor FAILs a root inside
   `base_path()`.
3. **`serve => false` on every one.** Nothing here is web-reachable.

> The `work` **disk** and the `oeuvres` **table** are unrelated despite the
> similar naming in older code — `CLAUDE.md` notes that the disk deliberately
> kept its name when `works` was renamed to `oeuvres`.

---

## Layout

Vault paths are sharded two levels deep by the file's UUID, so no directory
accumulates millions of entries:

```
{uuid[0:2]}/{uuid[2:4]}/{uuid}.bin
```

For `01a0c989-dedd-71ee-9fd4-c6dc2d086b63`:

```
C:\onda-storage\vault\
└── 01\
    └── a0\
        ├── 01a0c989-dedd-71ee-9fd4-c6dc2d086b63.bin    ← ciphertext
        └── 01a0c989-dedd-71ee-9fd4-c6dc2d086b63.mac    ← HMAC sidecar
```

`incoming` and `work` are flat — their contents are short-lived.

### The `.mac` sidecars

AES-256-CTR is length-preserving and seekable, which is exactly why it was
chosen: offset writes stay valid and HTTP Range reads work. It is also
**unauthenticated** — ciphertext can be modified undetectably.

So the vault does encrypt-then-MAC, per 1 MiB segment:

```
HMAC-SHA256(macKey, fileUuid . segmentIndex . ciphertextSegment)
```

The digests live in `{uuid}.mac` next to `{uuid}.bin`. A read verifies the
segments it touches. **A `.bin` without its `.mac` cannot be verified** — back
them up together, always.

### Variants

Regenerable derivatives: poster frame, preview clip, waveform. They are
encrypted too, each with its own nonce (`media_variants.nonce`). They are
classified *never* soft-deleted, so deleting one frees its bytes immediately —
they can be regenerated from the original.

---

## Nothing in `public/`

> ### Never run `php artisan storage:link` against a vault disk
>
> One command creates a symlink under `public/` and publishes the entire
> deposit archive to anyone who can guess a URL. It undoes, in a single
> keystroke, every layer below.

Access to a vault file requires **all four** of:

1. The file is outside the web root — no URL reaches it directly.
2. A signed URL, valid 15 minutes (`media.link` issues it).
3. `MediaFilePolicy` — an author gets their own files; an admin gets any file.
4. The URL is bound to the issuing session: `?u=` must equal `auth()->id()`.

Every access is written to `file_access_logs`, which is append-only and
hash-chained.

Two more prohibitions from `CLAUDE.md`, both about memory:

- **Never `Storage::get()` a vault file** — it loads 5 GB into memory.
- **Never `readfile()` or `response()->download()`** for one.

Verify nothing is published:

```powershell
Test-Path public\storage        # must be False
```

```bash
ls -la /home/<acct>/public_html/storage   # must not exist
```

---

## Encryption, for an operator

### What is encrypted

Everything in `vault` and `variants`, before it reaches disk. No plaintext byte
is ever written to the vault — encryption happens in memory as each chunk
arrives.

### How the keys nest

```
VAULT_MASTER_KEY_PATH  (the KEK, a 32-byte file on disk, outside the repo)
        │  wraps
        ▼
   per-file DEK  (random 32 bytes, one per file)
        │  stored in media_files.dek_wrapped,
        │  itself encrypted again by Laravel's `encrypted` cast (APP_KEY)
        ▼
   derived via hash_hkdf('sha256', $dek, 32, 'onda-enc' | 'onda-mac')
        ├── encryption key → AES-256-CTR
        └── MAC key        → HMAC-SHA256
```

The DEK is never used raw; both working keys are HKDF-derived from it with
distinct info strings.

The IV is `nonce8 . pack('J', intdiv($byteOffset, 16))` — an 8-byte per-file
nonce plus the block counter. That construction is what lets a chunk be
encrypted at its byte offset without knowing anything about the other chunks.

### The two keys

`dek_wrapped` is encrypted **twice**: by the vault master key and by `APP_KEY`.

**Both must be backed up. Losing either loses every file.**

### The plaintext window

There is exactly one, and it is on the `work` disk:

| When | What | How long | Removed by |
|---|---|---|---|
| Post-upload pipeline | `DecryptToTemp` writes `work/{uuid}.tmp` so hashing, scanning and probing can read the file | The duration of the chain — seconds to minutes | `CleanupTemp`, the last job in the chain |
| Variant generation | `work/{uuid}-{poster\|preview\|waveform}.{ext}` staging files | The ffmpeg run | `GenerateVariants`' own `finally` block |
| Full download | A queued job decrypts to `work/` and hands off to `Delivery` | **30 minutes** | The download cleanup job |

If a worker is killed mid-chain, the normal cleanup never runs. The hourly
scheduled task `vault:purge-temp` (`routes/console.php`) is the backstop: it
deletes matching files in `work/` older than `VAULT_TEMP_TTL_MINUTES`. It is
`onOneServer()`, so multiple hosts sweeping a shared `work/` disk is wasteful
but safe.

**Operational consequence:** `work/` is the one directory where an attacker
with filesystem access finds readable deposits. It deserves the same `0700` as
the others, it must never be web-served, and it must never be backed up.

---

## Deletion and disk space

> Soft delete does **not** free disk.

`$mediaFile->delete()` sets `deleted_at`. The ciphertext and its `.mac` sidecar
stay exactly where they are, and deduplicated rows may still point at them.

Bytes are freed separately: `purged_at` is set by a purge job after
`VAULT_RETENTION_DAYS` (30). **That job is P7 and does not exist yet** — today
nothing frees a soft-deleted file's bytes.

So disk usage is:

```php
MediaFile::withTrashed()->whereNull('purged_at')->sum('size_bytes')
```

and the runtime `storage_quotas.used_bytes` counter must reconcile to it.
Anyone who reads "soft deleted" as "gone" will miscount storage.
`DeduplicateFile::findOriginal()` uses `withTrashed()` for exactly this reason.

Two consequences worth telling users about, because both surprise people:

- Deleting a 5 GB draft does **not** free 5 GB today.
- A quarantined file removed by its author stays in quarantine and stays
  visible to admins via `withTrashed()`. Removal takes it off the deposit; it
  does not erase the evidence.

### Deduplication

Identical content is stored once. `media_files.ref_count` tracks how many rows
point at one set of bytes, and `sha256_plain` is a **plain index, never
unique** — a soft-deleted row must not block re-uploading the same content.

Any future purge must respect `ref_count`. Deleting bytes still referenced by
another row empties that row's file.

---

## Backup

Three things to back up, and **they must not travel together**.

| What | Where | Frequency | Notes |
|---|---|---|---|
| Database | Standard cPanel/JetBackup or `mysqldump` | Daily | Holds `dek_wrapped`, quotas, the audit trail. Small. |
| Vault files | Its own job, excluded from JetBackup | Weekly, or continuous replication | The bulk. `.bin` and `.mac` together. |
| Keys (vault master + `APP_KEY`) | **Offline, two locations** | On creation, then on change | Never in an automated backup that also holds the data. |

```bash
# Database
mysqldump -u <user> -p <db> | gzip > onda-$(date +%F).sql.gz

# Vault files — mirror, never delete on the destination
rsync -av --delete-excluded \
  /home/<acct>/onda-storage/vault/ \
  /backup/onda-vault/
```

Never back up `work/` (plaintext) or `incoming/` (partial files).

### Why the keys are kept separately

A backup containing both the encrypted data and the key that decrypts it
provides **no protection at all**. Whoever holds that archive holds the
plaintext. The encryption exists to make a stolen disk or a leaked backup
useless; putting the key in the same archive defeats it entirely.

The corollary is the risk running the other way: lose the key and no amount of
intact ciphertext helps. Two offline copies, in different physical locations,
created before the first upload.

### Restore order

1. Restore the keys first — nothing else is usable without them.
2. Restore the database.
3. Restore the vault files.
4. `php artisan vault:doctor` — zero FAILs.
5. Verify one known file decrypts and its MAC validates.

A restore that has not been tested is not a backup. Test it on a copy.

---

## Growth planning

Storage is bounded by per-author quota, held in `storage_quotas.limit_bytes`.

```
worst case = author count × per-author quota
```

### Worked example

500 authors at a 50 GB quota:

| | |
|---|---|
| Theoretical maximum | 500 × 50 GB = **25 TB** |
| Realistic at 20% average use | **5 TB** |
| Plus soft-deleted, not yet purged (30 days) | +10–15% → **~5.7 TB** |
| Plus variants (~2% of originals) | +100 GB |
| Plus `work/` transient peak | +50 GB |
| **Provision** | **~6 TB**, with headroom |

Deduplication reduces this in practice — identical files are stored once — but
do not plan on it. Plan for the worst case and be pleasantly surprised.

### Alert threshold

Alert at **80% of provisioned capacity**, which on the example above is
4.8 TB. The reason for 80% rather than 90%: an upload in flight has already
pre-allocated its full size in `incoming` via `ftruncate()`, so a nominally
5 GB file occupies 5 GB from the first chunk. At 90% a handful of concurrent
large uploads can exhaust the disk before anyone reads the alert.

```bash
df -h /home/<acct>/onda-storage
du -sh /home/<acct>/onda-storage/*
```

`vault:doctor` reports free space per disk on every run — informational, not a
threshold, but it is in the output you already read.

---

## Quick reference

| Question | Answer |
|---|---|
| Where are deposits? | `VAULT_DISK_ROOT`, sharded `{uuid[0:2]}/{uuid[2:4]}/{uuid}.bin` |
| Is there plaintext on disk? | Only in `work/`, briefly — see above |
| Where is the key? | `VAULT_MASTER_KEY_PATH`, outside the repo, `0600` |
| How many keys must I keep? | Two: the vault master key and `APP_KEY` |
| Does deleting free space? | No. `purged_at` does, and the purge job does not exist yet |
| Can I `storage:link`? | **No** |
| Can I back up everything in one archive? | No — keys separate from data |
| What must share a partition? | All four disks |
