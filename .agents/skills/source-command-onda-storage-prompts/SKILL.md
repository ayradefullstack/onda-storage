---
name: "source-command-onda-storage-prompts"
description: "Migrated source command `onda-storage-prompts`"
---

# source-command-onda-storage-prompts

Use this skill when the user asks to run the migrated source command `onda-storage-prompts`.

## Command Template

# ONDA Storage — Codex Execution Plan

> Phase-gated prompts for `Codex` CLI. Local-first (Herd / Windows 11), production (cPanel) deferred to P8.
> Every phase ends with a **hard STOP gate**. Never chain two phases in one session.

---

## How to use this document

| Step | Action |
|---|---|
| 1 | Run **P-BOOT** once. It writes `AGENTS.md` + `.Codex/commands/`. |
| 2 | Run phases in order: `P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8`. |
| 3 | After each phase: run the gate command yourself, review the diff, then say `approved` or send corrections. |
| 4 | Parallel phases (P4 ‖ P5) use the orchestration block in §Parallel. |
| 5 | Start each phase in a **fresh session** (`/clear`) to avoid context drift. |

**Rules that apply to every prompt below** — they are baked into `AGENTS.md` by P-BOOT so you don't repeat them:

- Never touch files outside the phase's ownership list.
- Never run `php artisan storage:link` on any vault disk.
- Never use `Storage::put/get/append` for vault bytes.
- Never `git commit` unless the prompt says so.
- Stop at the gate. Do not start the next phase.

---

# P-BOOT — Repository memory

Run this first, in the project root, before any code.

````
You are setting up the project memory for a Laravel 13 + Inertia v3 + Vue 3 (TypeScript)
application called "ONDA Storage".

Read the existing codebase first (composer.json, package.json, routes/, app/Providers/,
resources/js/app.ts) and confirm what is actually installed before writing anything.

## Task

Create two things:

### 1. `AGENTS.md` at repo root

Document the following as canonical, non-negotiable project decisions. Write it in the
style of a working engineer's reference — dense, no marketing language, no restating the
obvious. Include a "Commands" section derived from the ACTUAL composer.json/package.json
scripts you found, not from assumption.

**Domain**
ONDA Storage is the deposit vault for ONDA (Algerian copyright office). Authors upload
original works — video, audio, PDF, PPTX — up to 5 GB per file, multiple files per work.
Files are legal deposits: integrity and audit trail matter more than convenience.

**Architecture — Ports & Adapters, applied to exactly 4 seams**
Local dev and cPanel production differ materially, so these four are interfaces with
swappable implementations bound in a service provider from config:
| Contract | local (Herd) | production (cPanel) |
|---|---|---|
| `Delivery` | `StreamDelivery` | `LiteSpeedDelivery` |
| `Scanner` | `NullScanner` | `ClamavScanner` |
| `ChunkTracker` | `DatabaseChunkTracker` | `RedisChunkTracker` |
| `MediaProbe` | `FfmpegProbe` | `FfmpegProbe` or `NullProbe` |
Do NOT create interfaces for anything else. No Repository pattern — Eloquent is the data
layer; use single-purpose Query Objects only where a query is genuinely complex.

**Directory layout**
```
app/Domain/Vault/{Contracts,Crypto,Value}   ← storage + encryption core
app/Domain/Deposit/                          ← Work, MediaFile lifecycle
app/Domain/Access/                           ← policies, signed links, audit
app/Domain/Quota/
app/Actions/                                 ← one class, one public handle()
app/Infrastructure/                          ← contract implementations
app/Jobs/
```
Dependencies point downward only. Domain must not import from Http or Infrastructure.

**The two rules everything derives from**
1. No large file ever traverses a single HTTP request. Browser splits into 8 MiB chunks;
   server writes each chunk at its byte offset into a pre-allocated file.
2. No plaintext byte is ever written to the vault. Encryption happens in-memory as each
   chunk arrives.

**Upload mechanics**
- `POST /uploads` pre-allocates with `ftruncate($fh, $totalSize)`, generates a per-file DEK.
- `POST /uploads/{uuid}/chunk/{index}` — body is `application/octet-stream`, raw bytes.
  NOT multipart. Read via `php://input`.
- Chunk size 8 MiB (8388608). Divisible by 16 so every chunk starts on an AES block boundary.
- Up to 3 concurrent chunks per file. Non-overlapping offsets, safe on ext4/NTFS.
- `POST /uploads/{uuid}/complete` verifies the received-mask, then `rename()` into the vault.
  `rename()` is instant ONLY if all disks share a partition — this is a hard requirement.

**Cryptography**
- AES-256-CTR. Chosen because it is length-preserving (offset writes stay valid) and
  seekable (HTTP Range works). Do not substitute GCM or CBC.
- Envelope: random 32-byte DEK per file, wrapped by a master KEK read from
  `VAULT_MASTER_KEY_PATH` (a file OUTSIDE the project directory and outside backups).
- IV construction: `nonce8 . pack('J', intdiv($byteOffset, 16))` → 16 bytes.
- Keys are derived, never used raw:
  `hash_hkdf('sha256', $dek, 32, 'onda-enc')` / `'onda-mac'`.
- CTR is unauthenticated, so encrypt-then-MAC per 1 MiB segment:
  `HMAC-SHA256(macKey, fileUuid . segmentIndex . ciphertextSegment)`, stored in a
  sidecar `{uuid}.mac` file.

**Storage**
- Vault bytes use NATIVE PHP I/O (`fopen`/`fseek`/`fwrite`/`ftruncate`). Flysystem has no
  seek-on-write, so `Storage::put()` on a chunk would truncate the whole file.
- `Storage` disks are used for path resolution, permissions and config only. Reach the
  absolute path with `->path()`, and ONLY inside `EncryptedLocalVault`.
- `Storage` IS appropriate for the `variants` and `public` disks (small, write-once).
- Disks: `vault`, `incoming`, `work`, `variants`. All rooted OUTSIDE the project, all on
  one partition, all `0700` dirs / `0600` files, all with `'throw' => true`.
- Vault paths are sharded: `{uuid[0:2]}/{uuid[2:4]}/{uuid}.bin`.

**Schema conventions**
- Every domain table: `id` (bigint PK, used for FKs) + `uuid` (UUIDv7, unique, the route key).
- `getRouteKeyName()` returns `'uuid'`. Never expose sequential ids in URLs.
- Soft deletes on `works`, `media_files`, `users` ONLY.
- NO soft deletes on: `upload_sessions` (ephemeral), `file_access_logs` (append-only audit —
  giving it `deleted_at` would allow hiding evidence), `media_variants` (regenerable).
- Soft delete does NOT free disk. Separate `purged_at` column + a job that purges bytes
  after a 30-day retention window. Quota counts `withTrashed()->whereNull('purged_at')`.
- `sha256_plain` is a plain index, NOT unique — a soft-deleted row must not block re-upload.

**Queue**
- Driver `database`. Redis is optional and must never be a hard dependency.
- `retry_after` MUST be 3600 in `config/queue.php`. The 90-second default causes a 5 GB
  hash or an ffmpeg run to be re-dispatched while still executing.
- Long jobs declare `public int $timeout` and implement `ShouldBeUnique` keyed on file uuid.
- Heavy work goes on the `media` queue so it cannot block notifications on `default`.
- Post-upload pipeline is an explicit `Bus::chain()`, not events. Events are for side
  effects (notifications, audit, quota) only.

**Read paths are two architecturally distinct flows**
- Preview/stream (~95% of use): PHP decrypts on the fly with Range support. Occupies a
  worker for seconds.
- Full download (~5%): a queued job decrypts to `work/`, then hands off to `Delivery`.
  The temp file is deleted after 30 minutes. This exists because a 5 GB download over a
  slow link would otherwise pin a PHP-FPM worker for an hour.

**Access control layers (all four required)**
File outside webroot → signed URL (15 min) → Policy on ownership → bound to the issuing
session (`?u=` must equal `auth()->id()`) → every access written to `file_access_logs`.

**Local environment (Windows 11 + Herd free)**
- Herd free has no bundled MySQL/Redis. MySQL runs in WSL2, reached at `127.0.0.1:3306`.
- Queue worker is NOT managed by Herd — run `php artisan queue:work` in its own terminal.
- Keep `post_max_size=32M` and `upload_max_filesize=16M` locally too. Do not raise them.
  Low limits surface any architecture violation immediately instead of after deploy.
- `.env` paths use forward slashes even on Windows.
- Storage root lives on a separate drive, excluded from Windows Defender, never inside
  OneDrive.
- `SCAN_DRIVER=null` locally — ClamAV is not practical on Windows.

**Hard prohibitions**
- Never `php artisan storage:link` on a vault disk. One command undoes every protection.
- Never `Storage::get()` a vault file — it loads 5 GB into memory.
- Never `readfile()` / `response()->download()` for vault files.
- Never place the master key inside the repo, inside `storage/`, or inside any backup set.
- Never make `sha256_plain` unique.
- Never use multipart form encoding for chunk bodies.

**Testing**
Pest. Feature tests for HTTP flows, unit tests for `Domain/Vault/Crypto`.
Encryption tests must include: offset round-trip at a non-zero chunk index, Range read
across a segment boundary, and MAC failure detection on a flipped bit.

### 2. `.Codex/commands/`

Create these slash commands, each a short markdown file with a focused instruction:
- `vault-check.md` — run `composer test` + `npm run types:check`, report failures only,
  no summary of passing tests.
- `crypto-verify.md` — run only the `Domain/Vault/Crypto` unit tests and report the
  round-trip / Range / MAC-failure assertions explicitly.
- `phase-gate.md` — run the current phase's acceptance commands, print a PASS/FAIL table,
  and refuse to proceed to the next phase.
- `no-drift.md` — diff working tree against HEAD and flag any file touched that is outside
  the currently declared ownership list.

## Constraints

- Write files only. Do not install packages, do not modify existing application code.
- If any statement above contradicts what you found in the codebase, STOP and report the
  contradiction instead of silently reconciling it.

## Output

`AGENTS.md` + the four command files. Then STOP and print a one-screen summary of
anything in the codebase that contradicted the spec.
````

---

# P0 — Environment audit

**Nothing else gets built until this passes.** It converts assumptions about the environment into facts.

````
Phase 0 — Environment doctor. Read AGENTS.md first.

## Goal

An artisan command `php artisan vault:doctor` that audits whether this machine can
actually run the vault architecture. It must run identically on Windows/Herd and on
cPanel, so the SAME command later tells us the delta between environments.

## Checks (each: PASS / WARN / FAIL + the measured value + why it matters in one line)

**PHP core**
- `PHP_INT_SIZE === 8` — FAIL if not. 32-bit PHP cannot seek past 2 GB.
- PHP version ≥ 8.4, SAPI name, `open_basedir` value.
- Extensions: openssl, fileinfo, pcntl (informational on Windows), sodium.
- `openssl_get_cipher_methods()` contains `aes-256-ctr` — FAIL if absent.
- Benchmark: encrypt 64 MiB with aes-256-ctr, report MiB/s. WARN below 200 MiB/s
  (suggests no AES-NI; throughput will bottleneck the pipeline).

**Upload limits** — report `post_max_size`, `upload_max_filesize`, `memory_limit`,
`max_execution_time`, `max_input_time`. WARN if `post_max_size` < 32M (chunks will fail)
OR > 256M (limits are too loose; the architecture is being bypassed).

**disable_functions** — parse the ini list. Report whether `exec`, `shell_exec`, `proc_open`
are callable. FAIL if all are disabled (no ffmpeg, no ClamAV possible).

**External binaries** — resolve and version-check `ffmpeg`, `ffprobe`, `clamdscan`.
Report absent ones as WARN with the config key that disables them (`SCAN_DRIVER=null`).

**Storage** — for each of vault/incoming/work/variants:
- root exists, is writable
- within `open_basedir` when set
- resolved absolute path
- `disk_free_space` in GiB
- **partition identity**: on POSIX compare `stat()['dev']`; on Windows compare the drive
  letter. FAIL if the four disks are not all on one device — `rename()` would degrade to a
  full copy.
- Live proof: create a 1 MiB file, `ftruncate` to 64 MiB, `fseek` to offset 32 MiB, write
  and read back a known pattern, verify bytes match, delete. FAIL on mismatch. This is the
  single most important check in the command — it proves the core write mechanism works
  on this filesystem.

**Master key** — `VAULT_MASTER_KEY_PATH` is set, file exists, is readable, is ≥ 32 bytes,
and is NOT inside `base_path()`. FAIL if inside the project. WARN if permissions are
looser than 0600 on POSIX.

**Database & queue** — connection works, driver name, `queue.connections.database.retry_after`
(FAIL if < 3600), pending jobs count, whether a worker appears to be running.

**Redis** — probe only. Report available/unavailable. Never FAIL: Redis is optional.

## Implementation constraints

- One command class + one small `Check` value object + one check-registry array.
  Do NOT build a plugin architecture for this.
- Every check returns a status, a label, a measured value, and a one-line rationale.
- Exit code 1 if any FAIL, 0 otherwise, so it can gate a deploy script.
- Support `--json` for machine-readable output.
- No new composer packages.
- Must not crash when a binary or path is missing — that is a reportable result, not an
  exception.

## Acceptance gate

Run `php artisan vault:doctor` on this machine and paste the full output.
Then STOP. Do not create migrations, models, or any other file.
````

---

# P1 — Schema, disks, config

````
Phase 1 — Persistence and configuration foundation. Read AGENTS.md first.
`vault:doctor` passes on this machine.

## Owned files (touch nothing else)

```
config/filesystems.php          (edit)
config/queue.php                (edit — retry_after only)
config/vault.php                (new)
database/migrations/*           (new)
app/Models/{Work,MediaFile,UploadSession,MediaVariant,FileAccessLog,StorageQuota}.php
app/Concerns/HasUuidColumn.php
app/Providers/AppServiceProvider.php   (Blueprint macro only)
database/factories/*
tests/Feature/SchemaTest.php
tests/Unit/HasUuidColumnTest.php
.env.example                    (edit)
```

## Tasks

**1. `Blueprint::ondaKeys()` macro** in AppServiceProvider — emits `id()` + unique `uuid`.

**2. `HasUuidColumn` trait** — boots a `creating` hook assigning UUIDv7
(`Str::uuid7()`, or `Str::orderedUuid()` if unavailable in this Laravel version — check
first and note which you used). Overrides `getRouteKeyName()` to `'uuid'`.

**3. Migrations.** Apply the soft-delete rules from AGENTS.md exactly — including the
tables that must NOT have `deleted_at`.

- `works` — author_id FK, title, description, status enum
  (draft|submitted|under_review|registered|rejected), registered_at, softDeletes
- `media_files` — work_id, uploaded_by, original_name, extension, mime, size_bytes,
  disk, path, sha256_plain (plain index), dek_wrapped (text), nonce (char 16),
  mac_path, status enum (uploading|assembling|scanning|processing|ready|failed|quarantined),
  duration_sec, width, height, ref_count (default 1), scanned_at, verified_at,
  purged_at (nullable), softDeletes
- `upload_sessions` — user_id, work_id, uuid, filename, size_bytes, chunk_size,
  total_chunks, received_chunks (unsignedInteger), received_bytes (unsignedBigInteger),
  chunk_mask (binary, nullable), dek_wrapped, nonce, temp_path, status, expires_at.
  NO softDeletes.
- `media_variants` — media_file_id, kind enum (poster|preview|waveform|thumbnail), path,
  size_bytes. NO softDeletes.
- `file_access_logs` — media_file_id, user_id (nullable), action enum
  (stream|download|preview|delete|purge), ip, user_agent, bytes_sent, range_header,
  prev_hash (char 64, nullable), row_hash (char 64). NO softDeletes, NO updated_at.
- `storage_quotas` — user_id unique, limit_bytes, used_bytes.

Index deliberately: `media_files(work_id, status)`, `media_files(sha256_plain)`,
`upload_sessions(user_id, status)`, `upload_sessions(expires_at)`,
`file_access_logs(media_file_id, created_at)`.

**4. Models.** Relations, casts (`size_bytes` → integer, `dek_wrapped` → encrypted,
timestamps → datetime), `HasUuidColumn` on all, `SoftDeletes` only where the rules allow.
`MediaFile` gets a `humanSize()` accessor and a `scopeStored()` (ready + not purged).
No business logic in models — no encryption, no filesystem access.

**5. `config/vault.php`** — chunk_size, max_file_size, mac_segment_size, retention_days,
temp_ttl_minutes, and the four driver keys (delivery/scanner/tracker/probe) reading from
env with the local-safe defaults.

**6. `config/filesystems.php`** — the four disks per AGENTS.md: local driver, env-based
root, `'throw' => true`, `'serve' => false`, 0600/0700 permissions.

**7. `.env.example`** — every new key with the Windows-style forward-slash example paths
and an inline comment on the master key warning (loss = total data loss; must be outside
backups).

## Tests

- Every model creates and persists via factory.
- `uuid` auto-populates and is a valid v7 (check version nibble).
- `getRouteKeyName()` returns `'uuid'`.
- `upload_sessions` and `file_access_logs` do NOT respond to `SoftDeletes` (assert the
  trait is absent and the column does not exist).
- Two `media_files` rows can share the same `sha256_plain` (proves it is not unique).
- Soft-deleting a `MediaFile` keeps it in the quota sum until `purged_at` is set.

## Acceptance gate

`php artisan migrate:fresh --seed` succeeds, `php artisan test --compact` green,
`composer types:check` green. Print the schema of `media_files` and `upload_sessions`
via `php artisan db:table`. Then STOP.
````

---

# P2 — Crypto core

The riskiest phase. Run it alone, with tests written before implementation.

````
Phase 2 — Vault crypto core. Read AGENTS.md first.

This is the highest-risk component in the project. Write tests FIRST, then implement
until they pass. Do not add features beyond this spec.

## Owned files

```
app/Domain/Vault/Contracts/{VaultContract,ChunkTracker,Delivery,Scanner,MediaProbe}.php
app/Domain/Vault/Crypto/{KeyManager,CtrCipher,SegmentMac,HkdfKeys}.php
app/Domain/Vault/Value/{ByteRange,ChunkRef,ContentHash,UploadIntent,StoredObject,TempFile}.php
app/Domain/Vault/Exceptions/*.php
app/Infrastructure/Vault/EncryptedLocalVault.php
app/Infrastructure/Tracker/{DatabaseChunkTracker,RedisChunkTracker}.php
app/Providers/VaultServiceProvider.php
tests/Unit/Vault/*
```

No HTTP layer, no routes, no controllers, no jobs in this phase.

## Value objects

`ByteRange` — readonly start/end, validates `end >= start >= 0`, `length()`,
`alignedDown(16)` returning a new instance aligned to the AES block boundary,
`fromHeader(?string $rangeHeader, int $fileSize)` returning null on absent/invalid.

`TempFile` — wraps an absolute path, deletes the file in `__destruct()`. This guarantees
the only plaintext on disk disappears even when a job throws.

## `HkdfKeys`

Derives `enc` and `mac` keys from a DEK with `hash_hkdf('sha256', $dek, 32, 'onda-enc'|'onda-mac')`.
The DEK itself is never used directly for either operation.

## `KeyManager`

- Reads the KEK from `config('vault.master_key_path')`. Fails loudly with a clear message
  if missing, unreadable, shorter than 32 bytes, or located inside `base_path()`.
- Caches the KEK in a private property for the request — never in the Laravel cache,
  never in a static, never logged.
- `generateDek(): string` — `random_bytes(32)`.
- `wrap(string $dek): string` / `unwrap(string $wrapped): string`.
- `generateNonce(): string` — 8 raw bytes.

## `CtrCipher`

```php
ivFor(string $nonce8, int $byteOffset): string   // nonce8 . pack('J', intdiv($offset,16))
transformAt(string $data, string $encKey, string $nonce, int $offset): string
```
CTR is symmetric — one method both encrypts and decrypts. Assert in `transformAt` that
`$offset % 16 === 0` and throw `UnalignedOffset` otherwise.

Also `streamTransform(resource $in, resource $out, string $encKey, string $nonce, int $startOffset, ?int $length)`
reading in 64 KiB blocks (a multiple of 16) so a 5 GB file never enters memory.

## `SegmentMac`

- Segment size from config (1 MiB default).
- `tagFor(string $macKey, string $fileUuid, int $segIndex, string $ciphertext): string` —
  raw 32-byte HMAC-SHA256 over `fileUuid . pack('J',$segIndex) . $ciphertext`.
- Sidecar file `{uuid}.mac` = tags concatenated, tag N at offset N*32. Seekable by design.
- `verifyRange()` reads only the tags covering a byte range — a Range request must never
  force reading 5 GB.
- `hash_equals()` for every comparison. Never `===`.

## `EncryptedLocalVault implements VaultContract`

```php
beginUpload(UploadIntent): UploadSession
writeChunk(UploadSession, int $index, string $bytes): void
finalize(UploadSession): StoredObject
readRange(StoredObject, ?ByteRange): StreamInterface
decryptToTemp(StoredObject): TempFile
destroy(StoredObject): void
```

- `beginUpload`: quota check, `disk_free_space >= size * 2.1` (the ×2 covers the later
  plaintext temp file), create the `.part` via Storage so the directory gets correct
  permissions, then `ftruncate` it to full size with native I/O.
- `writeChunk`: derive keys, encrypt at offset, `fopen('r+b')` → `fseek` → `fwrite` →
  `fflush` → `fclose`. Compute and store the MAC tags for the segments this chunk covers.
  Assert the written byte count equals the input length; throw on short write.
- `finalize`: verify the tracker mask is complete, `mkdir` the shard path 0700, `rename()`
  from incoming to vault. Never copy.
- `readRange`: align the requested range down to a 16-byte boundary, verify the covering
  MAC tags, return a PSR-7 stream that decrypts lazily in 64 KiB blocks and discards the
  alignment prefix.
- `decryptToTemp`: full streaming decrypt into the `work` disk with a random filename,
  returns a `TempFile`.

Reach absolute paths with `Storage::disk(...)->path()` — and note in a comment that this
is local-driver-only and deliberately confined to this class.

## `ChunkTracker`

```php
markReceived(string $uuid, int $index, int $bytes): void
receivedMask(string $uuid): array
isComplete(string $uuid, int $total): bool
forget(string $uuid): void
```
`RedisChunkTracker` uses `SETBIT`/`INCRBY` (atomic, no row locks — a 5 GB file is 640
concurrent updates to one row otherwise). `DatabaseChunkTracker` uses a binary mask column
inside a short transaction. Both must pass the identical test suite.

## `VaultServiceProvider`

Binds all five contracts from `config('vault.*')` via `match`. Register it.

## Required tests

1. Round-trip at offset 0.
2. Round-trip at chunk index 5 (offset 41943040) — proves offset independence.
3. Three chunks written out of order (2, 0, 1) produce a correct plaintext file.
4. Range read spanning a MAC segment boundary returns exactly the right bytes.
5. Range read with an unaligned start (e.g. byte 7) returns correct bytes.
6. Flipping one bit in the ciphertext makes MAC verification fail.
7. Truncating the `.mac` sidecar fails verification rather than passing silently.
8. `transformAt` with an unaligned offset throws.
9. `KeyManager` refuses a key file inside `base_path()`.
10. `TempFile::__destruct` removes the file, including when the scope exits via exception.
11. Both trackers produce identical results for the same sequence, including duplicates.
12. A 200 MiB synthetic file round-trips with peak memory under 64 MiB — assert with
    `memory_get_peak_usage(true)`. This is the proof that nothing loads the file whole.

## Acceptance gate

`php artisan test --compact --filter=Vault` green, `composer types:check` green.
Print the peak-memory number from test 12. Then STOP — no HTTP layer yet.
````

---

# P3 — Upload endpoints

````
Phase 3 — Upload HTTP layer. Read AGENTS.md first. P2 is merged and green.

## Owned files

```
app/Actions/Upload/{InitUpload,StoreChunk,CompleteUpload,AbortUpload}.php
app/Http/Controllers/UploadController.php
app/Http/Requests/{InitUploadRequest,CompleteUploadRequest}.php
app/Policies/{WorkPolicy,MediaFilePolicy,UploadSessionPolicy}.php
app/Domain/Quota/QuotaPolicy.php
routes/web.php                  (append the upload group only)
tests/Feature/Upload/*
```

Do NOT touch `app/Domain/Vault/**` — it is frozen. If you believe the contract is wrong,
STOP and report rather than editing it.

## Routes

Inside `Route::middleware(['auth','verified','role:author'])`, prefix `uploads`:

```
POST   /                        → init      (throttle:10,1)
GET    /{session:uuid}          → status
POST   /{session:uuid}/chunk/{index}  → chunk  (throttle:1200,1)
POST   /{session:uuid}/complete → complete
DELETE /{session:uuid}          → abort
```

These are JSON routes inside the Inertia session — not an API. No Sanctum, no tokens.
Chunk throttle must accommodate 640 requests for one 5 GB file plus concurrency.

## Actions

**InitUpload** — validate filename/size/mime against the extension whitelist; enforce
`QuotaPolicy`; verify the work belongs to the user; delegate to `VaultContract::beginUpload`.
Returns `{uuid, chunk_size, total_chunks, received: [], expires_at}`.

**StoreChunk** — the hot path, called 640× per file. Keep it lean:
- Read raw bytes from `php://input`. Reject multipart with a clear 415.
- Reject if session status is not `uploading` or if it has expired.
- Validate `index` is within `0..total_chunks-1`.
- Verify the `X-Chunk-CRC32` header against `crc32b` of the body → 422 on mismatch so the
  client retries one chunk instead of the file.
- Validate the body length: `chunk_size`, except the final index which may be shorter.
- Idempotent: a chunk already marked received returns 200 without rewriting. Network
  retries must not corrupt the file.
- `writeChunk` then `markReceived`.
- Response `{index, received, total, bytes}` — small, this runs 640 times.

**CompleteUpload** — `isComplete` check (409 listing the missing indices if not),
`finalize`, create the `MediaFile` row with status `scanning`, dispatch the P5 chain
(guard with `class_exists` until P5 lands), delete the session, return the file uuid.

**AbortUpload** — delete the `.part`, forget the tracker mask, mark the session aborted.

## Controller

Thin dispatch only. No logic. `StoreChunkRequest` must not be a FormRequest that reads
the body — raw binary bodies and FormRequest validation do not mix; validate headers and
route params in the action.

## Failure semantics

| Case | Status |
|---|---|
| quota exceeded | 413 + remaining bytes |
| disk full at init | 507 |
| CRC mismatch | 422 + the index to retry |
| session expired | 410 |
| complete with gaps | 409 + missing indices |
| multipart body sent | 415 |

## Tests (Pest, feature)

- Full 3-chunk lifecycle with a small synthetic file; assert decrypted content matches.
- Out-of-order chunk arrival.
- Duplicate chunk is a no-op (assert `received_bytes` does not double).
- CRC mismatch returns 422 and does not advance the mask.
- Complete with a missing chunk returns 409 listing it.
- Another author cannot read, write to, or abort someone else's session (403 on each).
- Quota exceeded blocks init.
- Expired session rejects a chunk with 410.
- Final chunk shorter than `chunk_size` is accepted; a non-final short chunk is rejected.

## Acceptance gate

Tests green. Then upload a real 200 MB file end-to-end with `curl` — write the shell
script, run it, and paste the output plus the sha256 comparison of original vs decrypted.
Then STOP.
````

---

# P4 ‖ P5 — Parallel

P4 and P5 have **disjoint file ownership** and no shared module. Run them as two agents (two terminals, or `git worktree`).

## Orchestration

````
You are the orchestrator for phases 4 and 5. They run in parallel.

Ownership — a violation is a hard failure, not a style issue:

AGENT-FE (P4) owns:
  resources/js/**
  routes/web.php   ← ONLY the Inertia page routes block
  tests/Feature/Pages/*

AGENT-BE (P5) owns:
  app/Jobs/**
  app/Infrastructure/{Scanner,Probe}/**
  app/Domain/Deposit/**
  app/Actions/Media/**
  config/vault.php   ← ONLY the pipeline keys
  tests/Feature/Pipeline/*

Shared and FROZEN for both — if either needs a change here, both stop and I decide:
  app/Domain/Vault/**
  app/Models/**
  database/migrations/**
  config/filesystems.php

The contract between them is the existing JSON response shape from P3 plus the
`media_files.status` enum. Neither agent may change either.

Before writing, each agent prints its intended file list for my approval.
Neither agent commits. I review both diffs and merge.
````

---

## P4 — Vue upload manager

````
Phase 4 — Frontend upload manager. Read AGENTS.md first. You are AGENT-FE.
You own resources/js/** only. The P3 endpoints exist and are frozen.

## Deliverables

```
resources/js/stores/uploads.ts            Pinia store — app-level, NOT page-level
resources/js/workers/chunker.worker.ts    slicing + SHA-256 + CRC32
resources/js/lib/uploadClient.ts          fetch wrapper, retry, abort
resources/js/composables/useUploadQueue.ts
resources/js/pages/works/{Index,Show,Create}.vue
resources/js/components/upload/{Dropzone,QueueItem,ProgressRing,ResumeBanner}.vue
resources/js/types/upload.ts
```

## The critical design constraint

The store lives at application level, above the page components. An Inertia navigation
must NOT interrupt an in-flight upload. If uploads die when the user opens another page,
the phase has failed regardless of how the UI looks.

## Worker

Slicing and hashing run off the main thread — hashing 8 MiB on the UI thread visibly
freezes the browser. `crypto.subtle.digest` cannot stream, but at 8 MiB per chunk it is
fine; never attempt to hash the whole file client-side.

Worker sends `{index, buffer, sha256, crc32}` back to the store.

## Upload client

- `POST` chunks with `Content-Type: application/octet-stream` and the raw `ArrayBuffer`.
  Never `FormData` — the backend rejects multipart with 415.
- Header `X-Chunk-CRC32`.
- Concurrency 3 per file, 1 file at a time by default (configurable).
- Retry with exponential backoff (1s, 2s, 4s, 8s) on network error or 5xx; max 4 attempts,
  then mark the chunk failed and let the user retry manually.
- On 422 (CRC) retry that chunk immediately, once, without backoff.
- On 410 (expired) surface a re-init prompt rather than silently failing.
- Honour `AbortController` so pause is instant.

## Resume

On mount, `GET /uploads/{uuid}` returns the received mask; send only what is missing.
Persist session uuid + filename + size in `sessionStorage` so a refresh can offer resume.

Be explicit in the UI: after a page refresh the user must re-select the same file — the
browser cannot retain a File handle across reloads (File System Access API is
Chrome-only and out of scope). Validate the re-selected file's size against the stored
value before resuming, and refuse with a clear message on mismatch.

## UI

- Dropzone with extension + size validation before any request is sent.
- Per-file: progress %, uploaded/total, live speed, ETA, pause/resume/cancel/retry.
- Aggregate progress in a persistent bar visible across page navigations.
- `beforeunload` guard while any upload is active.
- Errors are actionable: "Chunk 42 failed — retry" not "Upload failed".
- Full ar/fr/en i18n with correct RTL. Numbers and file sizes formatted per locale.

## Constraints

- TypeScript strict. No `any`.
- Existing `resources/js/components/ui/` primitives + Tailwind v4 only. No new packages.
- Backend calls go through generated Wayfinder route helpers, not hardcoded URLs.
- Follow the project's name-based layout convention — do not import a layout manually.

## Acceptance gate

`npm run types:check`, `npm run lint:check`, `npm run format:check` all green.
Then demonstrate manually and report: (a) 500 MB upload succeeds, (b) killing the network
mid-upload and restoring it resumes without data loss, (c) navigating to another Inertia
page mid-upload does not interrupt it. Then STOP.
````

---

## P5 — Processing pipeline

````
Phase 5 — Post-upload pipeline. Read AGENTS.md first. You are AGENT-BE.
You own app/Jobs, app/Infrastructure/{Scanner,Probe}, app/Domain/Deposit,
app/Actions/Media. Do NOT touch resources/js or app/Domain/Vault.

## The chain

`Bus::chain()` — explicit ordering, not events:

```
DecryptToTemp → ComputeContentHash → DeduplicateFile → ScanForMalware
              → ExtractMetadata → GenerateVariants → RecordDeposit → CleanupTemp
```

Ordering rationale, do not rearrange: ffprobe and clamdscan need a real seekable
plaintext file. `ffprobe` via `pipe:0` fails on MP4 because the moov atom may be at the
end of the file.

## Job rules — all of them

- Every job: `public int $timeout` (DecryptToTemp 1800, GenerateVariants 3600,
  the rest 300).
- All implement `ShouldBeUnique` with `uniqueId()` = media file uuid.
- All on the `media` queue. Nothing heavy on `default`.
- `$tries = 2`, `backoff()` returning `[60, 300]`.
- `failed()` sets `media_files.status = 'failed'`, records the reason, deletes the temp
  file, notifies the uploader.
- The temp plaintext path is passed through the chain via a shared `TempFile`. If a job
  throws, the destructor still removes it.

## Individual jobs

**DecryptToTemp** — `VaultContract::decryptToTemp`. Verify free space first.

**ComputeContentHash** — streaming `hash_init`/`hash_update_stream`. Store `sha256_plain`.
Also assert the decrypted size equals `size_bytes`; mismatch → failed, not a warning.

**DeduplicateFile** — look up another stored file with the same `sha256_plain` inside a
`Cache::lock($sha, 10)`. On match: increment the original's `ref_count`, point this row at
the same path AND the same wrapped DEK, delete the just-written duplicate bytes. Bytes are
only deleted when `ref_count` reaches 0 — this is essential, get it right.

**ScanForMalware** — `Scanner` contract. `ClamavScanner` shells out to `clamdscan --fdpass`;
`NullScanner` returns clean and logs at debug level. Infected → status `quarantined`,
move bytes to a quarantine path, notify admins. Never auto-delete: it may be a false
positive on a legitimate deposit.

**ExtractMetadata** — `MediaProbe` contract wrapping `ffprobe -v quiet -print_format json
-show_format -show_streams`. Extract duration, dimensions, codec, bitrate. Absent ffprobe
→ `NullProbe`, nulls, status still advances. Never fail a deposit over missing metadata.

**GenerateVariants** — poster at 10% duration, a 480p/30s preview clip, an audio waveform
PNG, a PDF first-page thumbnail. Each variant is encrypted with the SAME file DEK and
written to the `variants` disk with `Storage` (small, write-once — Flysystem is correct
here). Individual variant failure is logged and skipped, not fatal.

**RecordDeposit** — the legal record. Append to `file_access_logs` (or a dedicated
`deposit_records` table if you judge that cleaner — argue it in one line, then decide) a
row containing sha256_plain, size, author, timestamp, and `row_hash =
sha256(prev_hash . canonical_json_of_this_row)`. This hash chain makes any later
tampering with the audit trail detectable. Set status `ready`.

**CleanupTemp** — explicit unlink plus a `Schedule` entry purging `work/` files older
than the configured TTL, hourly.

## Also deliver

- `MediaFileStatusChanged` event + Inertia-friendly notification so the frontend can poll
  or receive progress.
- An `php artisan vault:reprocess {uuid}` command to re-run the chain on one file.

## Tests

- Full chain on a small real MP4 fixture reaches `ready`.
- Deduplication: two identical uploads → one set of bytes on disk, `ref_count` 2.
- Deleting one of a deduplicated pair does NOT delete the bytes.
- Scanner reporting infected → `quarantined`, bytes moved, not deleted.
- Missing ffprobe → chain still reaches `ready` with null metadata.
- A thrown exception mid-chain leaves no file in `work/`.
- The hash chain detects a tampered audit row.
- `ShouldBeUnique` prevents a second dispatch for the same uuid.

## Acceptance gate

`php artisan test --compact --filter=Pipeline` green. Then process a real 1 GB video
end-to-end and paste: elapsed time per job, peak memory, and the final row from
`media_files`. Then STOP.
````

---

# P6 — Streaming and download

````
Phase 6 — Read paths. Read AGENTS.md first. P2–P5 merged and green.

Two architecturally separate flows. Do not merge them into one controller.

## Owned files

```
app/Http/Controllers/Media/{StreamController,DownloadController,VariantController}.php
app/Actions/Media/{IssueStreamUrl,RequestDownload,PrepareDownload}.php
app/Infrastructure/Delivery/{StreamDelivery,LiteSpeedDelivery,XSendFileDelivery}.php
app/Jobs/PrepareDownloadJob.php
app/Domain/Access/{SignedMediaUrl,AccessLogger}.php
resources/js/components/media/{VideoPlayer,AudioPlayer,PdfViewer,DownloadButton}.vue
routes/web.php   (media group only)
tests/Feature/Media/*
```

## Flow A — stream (the default, ~95%)

`GET /media/{mediaFile:uuid}/stream`, middleware `['auth','signed','throttle:media']`.

Four checks in order, all required:
1. `hasValidSignature()`
2. `authorize('view', $mediaFile)` — author owns the work, or admin
3. `(int) $request->query('u') === auth()->id()` — binds the link to the issuing session,
   so a leaked URL is useless to anyone else
4. `AccessLogger::record(...)`

Then parse `Range`, align down to 16 bytes, `readRange`, return 206 with correct
`Content-Range`, `Accept-Ranges: bytes`, `Content-Length`, and `Content-Type` from the
stored mime. Absent Range → 200 with the full stream. Unsatisfiable Range → 416.

Signed URLs live 15 minutes. Players must handle expiry mid-playback by re-requesting a
fresh URL — implement that in the player component, do not just extend the TTL.

## Flow B — full download (~5%)

Never stream 5 GB through PHP. Three steps:

1. `POST /media/{uuid}/download` → authorize, create a `DownloadTicket`
   (uuid, media_file_id, user_id, status, expires_at, path), dispatch
   `PrepareDownloadJob`, return the ticket uuid.
2. Job decrypts to `work/{random}.tmp` (0600), marks the ticket ready, notifies the user.
3. `GET /downloads/{ticket:uuid}` → signature + ownership + session-bound + logged, then
   hand off to `Delivery`.

Ticket TTL 30 minutes. Scheduled cleanup deletes expired tickets and their temp files.
The plaintext window is deliberate, bounded, and audited — document it in a comment.

## Delivery implementations

- `StreamDelivery` (local) — `Symfony\StreamedResponse` reading in 64 KiB blocks. Slow,
  works everywhere.
- `LiteSpeedDelivery` — `X-LiteSpeed-Location` header pointing at an internal alias, so
  the web server sends the bytes and PHP is released immediately.
- `XSendFileDelivery` — `X-Sendfile` for Apache with mod_xsendfile.

Selected by `config('vault.delivery')`. Local dev uses `stream` and must not break.

## Variants

`GET /media/{uuid}/variant/{kind}` — same authorization, decrypts small files in memory
(that is fine at this size), `Cache-Control: private, max-age=900`.

## Watermark

For admin previews of another user's work, overlay the viewer's name + timestamp on the
poster/preview. It does not prevent leaks; it makes them attributable, which is the
realistic deterrent for a copyright office.

## Tests

- Range request returns 206 with exact bytes and correct headers.
- Unaligned Range start returns correct bytes (proves alignment handling).
- Range spanning a MAC segment boundary verifies and returns correctly.
- Unsatisfiable Range → 416.
- Expired signature → 403.
- Valid signature but wrong `u` param → 403 (the leaked-link case).
- Non-owner author → 403; admin → 200.
- Every successful and failed access writes a `file_access_logs` row.
- Download ticket cannot be redeemed twice after expiry.
- `LiteSpeedDelivery` emits the header and an empty body (assert without a real server).

## Acceptance gate

Tests green. Then in a browser: play a 1 GB video, seek to the middle, seek backwards,
confirm no errors and that the served bytes are correct. Report the number of PHP
processes occupied during a full download. Then STOP.
````

---

# P7 — Hardening

````
Phase 7 — Quotas, retention, observability. Read AGENTS.md first.

## Deliverables

**Quota** — `QuotaPolicy` enforced at BOTH init and complete (a concurrent upload can slip
past a single check). Counts `withTrashed()->whereNull('purged_at')` plus variants and
`.mac` sidecars, since those consume real disk. Admin UI to set per-author limits.
Warning notification at 80%, hard block at 100%.

**Retention** — `PurgeExpiredFilesJob`: soft-deleted for more than `retention_days`,
`ref_count` at 0 → delete bytes, delete `.mac`, delete variants, set `purged_at`, log it.
Never purge a row whose `ref_count` is above 0. Add `--dry-run` and log every purge to the
audit chain.

**Cleanup** — hourly: abandoned `upload_sessions` past `expires_at` plus their `.part`
files; `work/` files older than the TTL; expired download tickets. All idempotent.

**Orphan detection** — `php artisan vault:audit`:
- vault files with no `media_files` row (orphaned bytes)
- rows whose file is missing (broken references)
- `ref_count` mismatches against actual referencing rows
- `.mac` sidecars whose size does not match the file size
- disk usage per author vs the recorded quota
Report only by default; `--fix` for the safe subset, and refuse to delete anything that is
merely unreferenced without `--force` plus a typed confirmation.

**Monitoring** — `php artisan vault:status`: disk free/used with a projection, upload
success rate over 7 days, average processing time per job class, failed jobs, quarantine
count, active sessions. Alert to admins below 25% free space.

**Rate limiting** — named limiters: `uploads` (10 init/min), `chunks` (1200/min — a 5 GB
file is 640 requests plus concurrency), `media` (streaming, generous), `downloads` (5/hour,
because each one costs a full decrypt).

**Backup** — `php artisan vault:backup-manifest` writing a JSON manifest of every stored
file with uuid, sha256, size, path. Plus a documented `rsync` command in
`docs/backup.md`, and an explicit, prominent section stating that the master key must be
backed up separately from the data, that a backup containing both is equivalent to no
encryption at all, and that key loss is unrecoverable.

## Tests

- Concurrent init requests cannot jointly exceed the quota (simulate the race).
- Purge respects `ref_count`.
- `vault:audit` detects a deliberately created orphan, a deliberately deleted file, and a
  corrupted `.mac`.
- Cleanup jobs are idempotent when run twice.

## Acceptance gate

Tests green, `vault:audit` clean on a seeded dataset. Then run a load test: 10 concurrent
5 GB uploads. Report throughput, peak memory, error count, and where the bottleneck sat
(CPU, disk I/O, or PHP-FPM workers). Then STOP.
````

---

# P8 — Production readiness (cPanel)

Only after P0–P7 are green locally.

````
Phase 8 — cPanel deployment readiness. Read AGENTS.md first.

Do not deploy. Produce the artifacts and the checklist so deployment is mechanical.

## Deliverables

**`docs/deployment.md`** — every value, with the reason, in a table:

| Setting | Value | Where |
|---|---|---|
| post_max_size | 32M | MultiPHP INI Editor |
| upload_max_filesize | 16M | same |
| memory_limit | 256M | same |
| max_execution_time | 120 | same |
| Max Request Body Size | 64M | WHM → LiteSpeed → Tuning |
| SecRequestBodyNoFilesLimit | ≥ 32M | ModSecurity |
| EP / nproc | ≥ 30 | CloudLinux LVE |
| I/O + IOPS | highest available | LVE |

Plus, each with its consequence spelled out:
- `open_basedir` must include every vault root, or every write fails after deploy.
- Exclude the storage directory from JetBackup, or backups grow by terabytes.
- If Cloudflare is used: the free plan caps request bodies at 100 MB (8 MiB chunks pass),
  but 5 GB downloads must go through a DNS-only subdomain.
- ModSecurity intermittently blocks chunk POSTs — symptom is random 403s on a subset of
  chunks; include the exception rule for the uploads path.
- Verify `exec()` is not in `disable_functions` before enabling ClamAV or ffmpeg.

**Cron entries**
```
* * * * * cd /home/onda/app && php artisan queue:work --queue=media,default \
          --stop-when-empty --max-time=55 --tries=2 >> /dev/null 2>&1
* * * * * cd /home/onda/app && php artisan schedule:run >> /dev/null 2>&1
```
Note in the doc: `--max-time` stops the worker from *picking up new* jobs after 55s; it
does not kill a running job, so a long ffmpeg run completes safely.

**Internal alias** for `X-LiteSpeed-Location`, with the `.htaccess` that makes the vault
path internal-only and unreachable from outside.

**`.env.production.example`** — every key, with `FILE_DELIVERY=litespeed`,
`SCAN_DRIVER=clamav`, `QUEUE_CONNECTION=database`, and the key path outside the app root.

**`deploy.sh`** — maintenance mode → pull → `composer install --no-dev -o` →
`npm ci && npm run build` → `migrate --force` → config/route/view cache →
`vault:doctor` → **abort and roll back if doctor fails** → queue restart → up.

**`docs/runbook.md`** — diagnosis and fix for: uploads failing at a specific chunk index;
jobs stuck in `processing`; disk full; ClamAV daemon down; a file failing MAC verification;
suspected key compromise (rotation procedure); and restore-from-backup, including the
order in which the key must be restored.

**First-deploy checklist**, ordered, with the master key generated and backed up to two
offline locations BEFORE the first upload — placed at the top of the list, because after
the first upload it is too late.

## Acceptance gate

Run `vault:doctor` locally and paste the output annotated with the expected production
delta for each check. Then STOP.
````

---

# Gate summary

| Phase | Acceptance |
|---|---|
| P-BOOT | `AGENTS.md` + 4 commands exist; contradictions reported |
| P0 | `vault:doctor` runs, all critical checks PASS |
| P1 | `migrate:fresh` + tests green; soft-delete exceptions verified |
| P2 | Crypto tests green; 200 MiB round-trip under 64 MiB peak memory |
| P3 | 200 MB curl upload; sha256 matches |
| P4 | 500 MB upload; resume after network loss; survives page navigation |
| P5 | 1 GB video reaches `ready`; dedup verified |
| P6 | 1 GB video plays with seeking; PHP process count during download |
| P7 | 10 concurrent 5 GB uploads; bottleneck identified |
| P8 | Doctor output annotated with production deltas |

---

# Operating notes

**One phase per session.** `/clear` between phases. Context pollution is the main cause of
an agent editing frozen files.

**Reject scope creep immediately.** If a phase produces files outside its ownership list,
revert and re-run with the list restated. Do not accept "I also improved X".

**The gates are the point.** A phase that "looks done" but whose gate command was not run
is not done. Run the command yourself.

**When an agent wants to change a frozen contract**, that is a signal worth taking
seriously — but decide it yourself between phases, never mid-phase.

**Track decisions.** When you approve a deviation, add one line to `AGENTS.md`. That file
is the project's memory; if it drifts from reality, every subsequent phase inherits the drift.
