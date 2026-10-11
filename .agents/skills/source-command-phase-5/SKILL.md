---
name: "source-command-phase-5"
description: "Migrated source command `phase-5`"
---

# source-command-phase-5

Use this skill when the user asks to run the migrated source command `phase-5`.

## Command Template

Phase 5 — Post-upload processing pipeline. Read AGENTS.md first.

P0 through P3-FIX are merged and committed. A file arrives at status `scanning` with
`sha256_plain = null` — P3-FIX deliberately moved hashing out of the HTTP request. Your
chain owns everything from there to `ready`.

Baseline to hold: **6 test failures, 28 `types:check` errors.** Do NOT lower the 28 by
fixing pre-existing generics in Commune/Country/User/Wilaya — that work is deliberately
deferred so a moving baseline cannot mask a regression introduced by this phase.

## Owned files — touch nothing else

```
app/Jobs/**
app/Infrastructure/{Scanner,Probe}/**
app/Domain/Deposit/**
app/Actions/Media/**
app/Events/MediaFileStatusChanged.php
app/Console/Commands/VaultReprocessCommand.php
config/vault.php                      ← pipeline keys only
database/seeders/VaultDemoSeeder.php  ← the stale comment only
tests/Feature/Pipeline/*
tests/fixtures/*                       ← small media fixtures
```

FROZEN — if you believe a change is needed here, STOP and report rather than editing:

```
app/Domain/Vault/**
app/Models/**
database/migrations/**
config/filesystems.php
app/Actions/Upload/**
routes/web.php
resources/js/**
```

Do not build the status polling endpoint — that belongs to the next phase.

## Housekeeping first

`DatabaseSeeder`'s `use WithoutModelEvents;` is now commented out, which makes
`VaultDemoSeeder`'s docblock ("DatabaseSeeder uses WithoutModelEvents... uuid is therefore
assigned explicitly here") stale and misleading. Fix that comment. A comment describing a
condition that no longer holds is exactly what misled an earlier session in this project.

## The chain

`Bus::chain()` — explicit ordering, not events:

```
DecryptToTemp → ComputeContentHash → DeduplicateFile → ScanForMalware
              → ExtractMetadata → GenerateVariants → RecordDeposit → CleanupTemp
```

Ordering rationale — do not rearrange: ffprobe and clamdscan need a real, seekable
plaintext file. `ffprobe` reading from `pipe:0` fails on MP4 because the moov atom may sit
at the end of the file.

## Job rules — all of them

- `public int $timeout`: DecryptToTemp 1800, GenerateVariants 3600, everything else 300.
- All implement `ShouldBeUnique` with `uniqueId()` returning the media file uuid.
- All dispatched to the `media` queue. Nothing heavy on `default`.
- `$tries = 2`, `backoff()` returning `[60, 300]`.
- `failed()` sets `media_files.status = 'failed'`, records the reason, deletes the temp
  file, and notifies the uploader.
- The plaintext temp path passes through the chain via a shared `TempFile`. If any job
  throws, the destructor still removes it — verify this holds across job boundaries, since
  the object is serialized between jobs.
- `retry_after` is already 3600 (set in P1). Do not change it; verify every timeout above
  stays below it.

## Individual jobs

**DecryptToTemp** — `VaultContract::decryptToTemp`. Check free space first; this is the
moment disk usage peaks, since the plaintext temporarily doubles the file's footprint.

**ComputeContentHash** — streaming `hash_init` / `hash_update_stream`. Store
`sha256_plain`. Also assert the decrypted size equals `size_bytes`; a mismatch is a
failure, not a warning — it means the stored bytes are not what was uploaded.

**DeduplicateFile** — look for another stored file with the same `sha256_plain`, inside a
`Cache::lock($sha, 10)`. On a match: increment the original's `ref_count`, point this row
at the same path AND the same wrapped DEK, then delete the duplicate bytes. **Bytes are
deleted only when `ref_count` reaches 0.** Get this right — it is the easiest place in the
entire project to destroy someone's legal deposit.

**ScanForMalware** — the `Scanner` contract. `ClamavScanner` shells out to
`clamdscan --fdpass`; `NullScanner` returns clean and logs at debug level. Infected →
status `quarantined`, move the bytes to a quarantine path, notify admins. **Never
auto-delete** — a false positive on a legitimate deposit must be recoverable.

**ExtractMetadata** — the `MediaProbe` contract wrapping
`ffprobe -v quiet -print_format json -show_format -show_streams`. Extract duration,
dimensions, codec, bitrate. Absent ffprobe → `NullProbe`, null values, and the status still
advances. Never fail a deposit over missing metadata.

**GenerateVariants** — poster frame at 10% of duration, a 480p/30s preview clip, an audio
waveform PNG, a PDF first-page thumbnail. Each variant is encrypted with the SAME file DEK
and written to the `variants` disk using `Storage` — small write-once files are exactly
where Flysystem is the right tool. An individual variant failing is logged and skipped, not
fatal.

**RecordDeposit** — the legal record. Append a row containing sha256_plain, size, author,
timestamp, and `row_hash = sha256(prev_hash . canonical_json_of_this_row)`. The hash chain
makes later tampering with the audit trail detectable. Use `file_access_logs` (it already
carries `prev_hash` / `row_hash`) unless you can argue in one line that a dedicated table is
cleaner — then decide and state which you chose and why. Set status `ready`.

**CleanupTemp** — explicit unlink, plus a `Schedule` entry purging `work/` files older than
`config('vault.temp_ttl_minutes')`, hourly.

## Also deliver

- A `MediaFileStatusChanged` event so a later phase can observe transitions.
- `php artisan vault:reprocess {uuid}` to re-run the chain on a single file.

## Local environment

`vault:doctor` currently reports 9 WARNs, including missing binaries. Install ffmpeg first
if you can (`winget install Gyan.FFmpeg`, then reopen the terminal) — without it, half of
this phase's tests only exercise the null path.

Your tests must nevertheless pass on a machine with both ffmpeg and ClamAV absent. Skip
explicitly with a stated reason where a real binary is required, and list every skip in the
gate report.

The queue worker is not managed by Herd. Run it in a separate terminal for the whole phase:
`php artisan queue:work --queue=media,default --tries=2`

This machine has only `C:`, shared with Windows. Keep fixtures small (under ~50 MB) and
delete them in `afterEach`. Do not generate multi-GB test files — filling the system drive
takes Windows down, not just the test run.

## Tests

- Full chain on a small real MP4 fixture reaches `ready`.
- Deduplication: two identical uploads → one set of bytes on disk, `ref_count` 2.
- Deleting one of a deduplicated pair does NOT delete the shared bytes.
- Scanner reporting infected → `quarantined`, bytes moved, not deleted.
- Missing ffprobe → the chain still reaches `ready` with null metadata.
- An exception mid-chain leaves no file in `work/`.
- The hash chain detects a tampered audit row.
- `ShouldBeUnique` prevents a second dispatch for the same uuid.
- `sha256_plain` is null before the chain and populated after.
- A job exceeding its timeout is not re-dispatched while still running (assert the timeout
  values are below `retry_after`).

## Acceptance gate

1. `php artisan test --compact --filter=Pipeline` — green. State which tests were skipped
   for missing binaries and why.
2. `php artisan test --compact` — baseline still 6. State before/after counts.
3. `composer types:check` — still 28.
4. Upload a real file through the P3 curl script, then process it end-to-end through
   `php artisan queue:work --queue=media`. Paste: elapsed time per job, peak memory, and
   the final `media_files` row showing status `ready` with a populated hash.
5. `php artisan vault:doctor` — still 0 FAIL.
6. `git status --short`

Then STOP. No frontend work.

## Git policy — applies to this entire phase

- Do NOT run `git commit`, `git push`, `git merge`, `git rebase`, `git reset`,
  `git checkout`, `git restore`, `git stash`, `git clean`, `git tag`, or `git revert`.
- Do NOT create branches, pull requests, or tags.
- Leave every change unstaged in the working tree. I review the raw diff and commit myself.
- `git status`, `git diff`, `git log`, `git show` are allowed — use them freely.
- If you believe a commit is necessary, STOP and ask. Do not commit and then report it.
- End the phase by printing `git status --short` so I can check the touched files against
  the ownership list above.
