# ONDA Storage

The digital portal for ONDA (Office National des Droits d'Auteur et des Droits
Voisins, Algeria): authors register copyright in their works and deposit the
files that prove it.

## 1. Overview

- **Authors** create an *oeuvre* (a work), classify it, and deposit the files
  its classification requires. Files are up to **5 GB each**
  (`VAULT_MAX_FILE_SIZE`), uploaded in 8 MiB chunks and encrypted before a
  single byte reaches disk. An author can preview and download their own files.
- **Admins** (ONDA officers) review a submitted deposit, consult its files in
  the browser, and register or reject it. An admin never downloads an original.
- The files are legal deposits: integrity and the audit trail matter more than
  convenience.
- **Production needs no system binaries.** It runs on shared cPanel hosting
  with standard PHP extensions only. ffmpeg, poppler and LibreOffice are
  optional local extras.

Interface languages: Arabic (RTL), French and English, managed in the database
(`/admin/languages`).

## 2. Stack

| Layer | What |
|---|---|
| Backend | PHP 8.4, Laravel 13, Fortify (login, 2FA, passkeys), spatie/laravel-permission, PhpSpreadsheet |
| Frontend | Inertia v3, Vue 3 + TypeScript, Tailwind v4, reka-ui, vue-i18n, Wayfinder (generated routes) |
| Preview | `pdfjs-dist`, `docx-preview`, native `<video>` / `<audio>` |
| Data | MySQL 8; queue, cache and sessions on the `database` driver |
| Tests | Pest, PHPStan (Larastan level 7), Pint, Vitest, ESLint, Prettier, Playwright |

## 3. Core rules

1. **No large file ever travels in one HTTP request.** The browser splits a
   file into 8 MiB chunks (`8388608`, divisible by 16); the server writes each
   chunk at its byte offset into a pre-allocated file.
2. **No plaintext byte is ever written to the vault.** Each chunk is encrypted
   in memory as it arrives.
3. **Vault bytes are deleted only when provably unreferenced.**
   `App\Domain\Deposit\VaultBytesReleaser` is the one place that deletes vault
   bytes. It re-counts every row (trashed rows included) pointing at the path
   at the moment of deletion, and keeps the bytes when in doubt.

## 4. Architecture

### Encryption

- **AES-256-CTR.** It is length-preserving, so chunks can be written at any
  offset, and seekable, so HTTP Range works on encrypted files.
- **Envelope keys.** Each file has a random 32-byte DEK. The DEK is wrapped by
  the master KEK (the file at `VAULT_MASTER_KEY_PATH`), and the wrapped value
  is stored in a column with Laravel's `encrypted` cast, so it also depends on
  `APP_KEY`.
- **Derived keys.** Keys are never used raw:
  `hash_hkdf('sha256', $key, 32, 'onda-enc' | 'onda-mac')`.
- **Integrity.** CTR is unauthenticated, so the ciphertext is HMAC-SHA256'd per
  1 MiB segment into a `{uuid}.mac` sidecar. A read fails on a MAC mismatch.
- **Previews.** Every derivative is encrypted with the file's own DEK and a
  fresh random nonce stored with its row.

> **Warning.** Losing `vault-master.key` **or** `APP_KEY` makes every deposit
> permanently unreadable. There is no recovery path. Keep offline backups of
> both, stored apart from the encrypted data: a backup holding the keys and the
> data together is the same as storing the data unencrypted.

### Upload protocol

| Step | Route | What happens |
|---|---|---|
| init | `POST /uploads` (`uploads.init`) | Validates the slot and format, reserves quota, generates the DEK, pre-allocates the file |
| chunks | `POST /uploads/{session}/chunk/{index}` (`uploads.chunk`) | Raw `application/octet-stream` body, encrypted and written at its offset |
| complete | `POST /uploads/{session}/complete` (`uploads.complete`) | Checks the received-chunk mask, renames the file into the vault, queues the pipeline. No heavy work |

`GET /uploads/{session}` (`uploads.status`) and `DELETE /uploads/{session}`
(`uploads.abort`) complete the set.

- **Fair parallel scheduler** (`resources/js/lib/upload/scheduler.ts`). At most
  `VAULT_CLIENT_MAX_IN_FLIGHT` chunk requests run across all files, and at most
  `VAULT_CLIENT_PER_FILE_IN_FLIGHT` for one file. Free slots are handed out
  round-robin between files, so a small file is not stuck behind a 5 GB one. A
  small worker pool slices the chunks. Both limits reach the browser as an
  Inertia prop, so they can be tuned without a rebuild.
- **Quota is reserved at init**, under a short per-user cache lock
  (`upload-init:{user}`). Live upload sessions count against the quota, so
  parallel uploads cannot overshoot it.
- **Retry resumes the same session**: the client asks `uploads.status` which
  chunks arrived and sends only the missing ones. A 429 or 5xx retries by
  itself; a 410 (expired session) starts a fresh one.
- `php artisan uploads:release-stale` aborts live sessions with no recent chunk
  and releases their reserved quota.

### Pipeline

`ProcessMediaFile` runs this chain on the `media` queue
(`ProcessMediaFile::chainJobs()`):

| # | Job | Role |
|---|---|---|
| 1 | `DecryptToTemp` | Decrypts into the `work` disk |
| 2 | `VerifyContentType` | `finfo` on the real bytes against the format registry; a mismatch fails the file |
| 3 | `ComputeContentHash` | SHA-256 of the plaintext |
| 4 | `DeduplicateFile` | Links identical content to one stored copy |
| 5 | `ScanForMalware` | `Scanner` contract; `NullScanner` unless `SCAN_DRIVER=clamav` |
| 6 | `ExtractMetadata` | `MediaProbe` contract; never throws |
| 7 | `GenerateVariants` | Poster, clip, waveform through ffmpeg; skipped when the tool is absent |
| 8 | `RecordDeposit` | Verifies the bytes are on disk, appends the ledger row, sets `ready` |
| 9 | `CleanupTemp` | Deletes the plaintext temp file, then queues the preview |

- **RecordDeposit** checks that the vault bytes are intact *before* it writes
  anything. If they are missing, the file fails with `bytes_missing` and no
  deposit row exists. The ledger (`file_access_logs`) is hash-chained:
  `row_hash = sha256(prev_hash . canonical row)`.
- **Dedup** runs under a cache lock on the hash, selects candidates with
  `FOR UPDATE`, keeps the lowest id whose bytes are verified on disk, and
  releases the duplicate's bytes only after the transaction commits, through
  `VaultBytesReleaser`.
- **Scanner and probe are optional.** Steps 5 to 7 degrade to no-ops without
  their tools; they never fail a deposit.
- **Previews are off the deposit chain.** `CleanupTemp` dispatches
  `GenerateConsultationDerivative` on the separate `previews` connection, so a
  deposit is `ready` before its preview exists.

### Consultation and the delivery map

`config('vault.consult.delivery')` decides how each preview family reaches the
viewer. It is read only by `PreviewFamilyResolver::deliveryMode()`; a
`family:ext` key overrides the bare `family` key.

| Family | Mode | Rendering | Server needs |
|---|---|---|---|
| `pdf` | `original_inline` | PDF.js on canvas with a text layer and search; own toolbar, no download or print UI | nothing |
| `document:docx` | `original_inline` | `docx-preview` in an iframe with `sandbox` and no scripts (files up to `VAULT_CONSULT_DOCX_MAX_BYTES`) | nothing |
| `document` (doc, odt, rtf) | `unsupported` | "preview unavailable" card | nothing |
| `video` | `original_inline` | native `<video>` over Range | nothing |
| `audio` | `original_inline` | native `<audio>` over Range | nothing |
| `image` | `derivative` | resized, metadata-stripped image | `gd` |
| `spreadsheet` | `derivative` | PhpSpreadsheet to JSON pages | `zip`, `xml` |
| `csv` | `derivative` | JSON pages | nothing |
| `text` (txt, svg, json, xml, sql) | `derivative` | escaped text | nothing |
| `presentation:pptx` | `derivative` | text outline of the slides (ZipArchive) | `zip`, `xml` |
| `presentation` (ppt, odp) | `unsupported` | card | nothing |
| `archive`, `other` | `unsupported` | card | nothing |

- An `original_inline` response is always a **206**, capped at
  `VAULT_CONSULT_RANGE_MAX_BYTES` (8 MiB) per response and decrypted in 1 MiB
  pieces. It is `Content-Disposition: inline`, never `attachment`, with
  `no-store`, `nosniff` and a CSP sandbox.
- For `original_inline` families the preview job only marks the consultation
  `ready`: it creates no asset and decrypts nothing.
- The ffmpeg, pdftoppm and soffice renderers are still in
  `app/Infrastructure/Render`, but they run only for a family set to
  `derivative` in the map.
- After changing the map: `php artisan vault:generate-previews --all --force`.

### Access

| | Admin | Author |
|---|---|---|
| Routes | `routes/admin.php`, `role:admin`, names `admin.oeuvres.*` | `routes/author.php`, `role:author`, names `oeuvres.*` |
| Preview page | `admin.oeuvres.files.review` → `Admin\OeuvreFileReviewController` → `admin/oeuvres/FileReview.vue` | `oeuvres.files.preview` → `Author\OeuvreFilePreviewController` → `author/oeuvres/FilePreview.vue` |
| Descriptor refresh | `admin.oeuvres.files.review.assets` | `oeuvres.files.preview.assets` |
| Signed asset / original | `admin.oeuvres.files.consult.asset` / `.original` | `oeuvres.files.consult.asset` / `.original` |
| Signature parameter | `admin=<user id>` | `u=<user id>` |

Both sides share `ConsultationAssetController`, `ConsultOriginalController`,
`EnsureConsultationAccess` and the `FileReviewLayout.vue` / `ConsultViewer.vue`
components.

**Check order on a signed consultation URL:**

1. authenticated, verified, with the route's role (route group);
2. signature valid and not expired (`signed`; TTL `VAULT_CONSULT_URL_TTL`);
3. the `admin` / `u` parameter equals the session's user id (403 otherwise);
4. the file belongs to the oeuvre in the URL (scoped binding, 404 otherwise);
5. admin: `OeuvrePolicy::inspect`; author: `OeuvrePolicy::preview`, owner only;
6. on the original route only: the family's mode is `original_inline` (404);
7. the consultation is `ready` (403).

- **Another author's file returns 404**, never 403, so a probe cannot confirm
  the file exists.
- **`media.link` / `media.stream` are owner-only**
  (`MediaFilePolicy::streamOriginal`). They are the only download path, and
  `VAULT_OWNER_DOWNLOAD_ENABLED=false` turns it off. No flag lets an admin
  download an original.
- **Author preview.** On the oeuvre page each ready file has a *Preview* button
  (a side viewer, with the selected card highlighted) and an *open in new tab*
  link. The full page has **Preview** and **Details** tabs (name, slot, format,
  size, deposit date, SHA-256, preview status). There is no Activity tab, and
  no watermark for the owner unless `VAULT_CONSULT_WATERMARK_FOR_OWNER=true`.
  The admin sees a watermark with their email (`VAULT_CONSULT_WATERMARK`).

**Audit actions** (`file_access_logs.action`; append-only, hash-chained):

| Action | Written when |
|---|---|
| `deposit` | `RecordDeposit` records a processed upload |
| `stream` | The owner streams an original (`media.stream`) or opens a preview of their own file |
| `inspect` | An admin opens an oeuvre page (one row per file) |
| `admin_consult` | An admin consults a derivative |
| `consult_original` | An admin is issued the inline original (pdf, docx, video, audio) |
| `preview` | An admin loads a poster, clip or waveform variant (`admin.media.variant`) |
| `bytes_released` / `bytes_kept` | `VaultBytesReleaser` decides to delete or keep bytes |
| `bytes_missing` | `RecordDeposit` or `vault:mark-missing-bytes` finds the bytes gone |
| `download`, `delete`, `purge` | In the enum; nothing writes them today |

Consultation rows are written once per user, file and
`VAULT_CONSULT_AUDIT_WINDOW` (10 minutes), not per Range request.

### Domain

- **Oeuvre status machine** (`OeuvreStatusMachine`, the only writer of
  `oeuvres.status`; every move adds an `oeuvre_reviews` row):

  | From | To | Who |
  |---|---|---|
  | `draft`, `rejected` | `submitted` | the owning author, if the submission gate passes |
  | `submitted` | `under_review` | an admin, who then holds the deposit |
  | `under_review` | `registered` | the admin holding it (or one who takes it over) |
  | `under_review` | `rejected` | same, with a reason |
  | `registered` | none | terminal |

  The author can edit only in `draft` and `rejected`.
- **Classification reference data**: `register_types`, `type_gestions`,
  `register_type_colleges`, `register_type_members`, `register_role_auteurs`,
  seeded by `MembershipTypeSeeder`. Admins manage types, gestions, colleges,
  members and documents at `/admin/referentiel/*`. There is no delete; entries
  are retired.
- **`college_oeuvre_files`**: the documents each college requires (a template,
  not uploads). They become the upload slots of an oeuvre and feed the
  submission gate.
- **Format registry** (`App\Support\FileFormats`): the single list of accepted
  extensions with their measured MIME types. `VerifyContentType` rejects a file
  whose real content does not match its extension. After changing the registry
  run `php artisan referentiel:sync-mime-types`.

## 5. Requirements

| | Local (Windows 11 + Herd) | Production (cPanel) |
|---|---|---|
| PHP | 8.4, 64-bit | 8.4, 64-bit (`ea-php84`) |
| PHP extensions | `openssl`, `fileinfo`, `gd`, `zip`, `mbstring`, `xml`, `dom`, `simplexml`, `xmlreader`, `xmlwriter`, `curl`, `iconv`, `pdo_mysql`, `sodium` | same |
| `proc_open` | enabled | enabled; `exec` and `shell_exec` may stay disabled |
| Database | MySQL 8 | MySQL 8 |
| Node.js | 20+ (build and dev server) | not needed if `public/build` is built elsewhere |
| ffmpeg, pdftoppm, LibreOffice | optional | **none** |
| ClamAV | no (`SCAN_DRIVER=null`) | optional (`SCAN_DRIVER=clamav`) |
| Redis | optional | optional |

- `composer.json` declares `php ^8.3`, but `vault:doctor` fails below 8.4.
  32-bit PHP cannot seek past 2 GB.
- **`ext-sodium`**: no application code calls `sodium_*`, and Composer does not
  require it (`composer check-platform-reqs` does not list it). It is needed in
  practice for two reasons: `vault:doctor` reports a missing `sodium` as FAIL,
  and the WebAuthn libraries behind passkeys use it for Ed25519 keys.
- `proc_open` is what Symfony Process uses, for the scheduler's background
  worker and for any optional tool.

## 6. Local setup (Windows 11 + Herd)

Run every command from a **normal PowerShell**: not Administrator, not WSL.

```powershell
git clone <repository-url> onda-storage
cd onda-storage
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
```

1. **Storage roots**, outside the repository, all on one partition, not in
   OneDrive:

   ```powershell
   New-Item -ItemType Directory -Force C:\onda-storage\vault, C:\onda-storage\incoming, C:\onda-storage\work, C:\onda-storage\variants, C:\onda-secrets
   ```

2. **Master key** (never in git):

   ```powershell
   php -r "file_put_contents('C:/onda-secrets/vault-master.key', random_bytes(32));"
   icacls "C:\onda-secrets\vault-master.key" /inheritance:r /grant:r "$($env:USERNAME):(R)"
   ```

3. **`.env`**: set the database, `APP_URL` (the Herd site, for example
   `http://onda-storage.test`) and the five vault paths. **Write paths with
   `/`, never `\`**: `VAULT_DISK_ROOT=C:/onda-storage/vault`.

4. **Database and assets**:

   ```powershell
   php artisan migrate --seed
   npm run build      # or: npm run dev
   php artisan vault:doctor
   ```

   `vault:doctor` must end with **FAIL: 0**. WARN lines are normal locally
   (system drive, no Redis, no ClamAV).

**Seeded accounts** (`RoleAndUserSeeder`; the password is in that seeder):
`admin@gmail.com` (admin), `author1@onda.dz` and `author2@onda.dz` (authors).
Everyone signs in at `/login`.

## 7. Environment variables

Only the variables that matter for the vault. Defaults are in
`config/vault.php`.

| Variable | Default | Notes |
|---|---|---|
| `APP_KEY` | none | Also encrypts the wrapped DEKs. Never rotate it without a plan |
| `VAULT_MASTER_KEY_PATH` | none | The KEK file, outside the repo and outside backups |
| `VAULT_DISK_ROOT`, `INCOMING_DISK_ROOT`, `WORK_DISK_ROOT`, `VARIANTS_DISK_ROOT` | none | Outside the repo, one partition, `/` separators |
| `VAULT_CHUNK_SIZE` | `8388608` | Do not change |
| `VAULT_MAX_FILE_SIZE` | `5368709120` | 5 GiB per file |
| `VAULT_MAC_SEGMENT_SIZE` | `1048576` | Do not change |
| `VAULT_RETENTION_DAYS` | `30` | Days a soft-deleted file keeps its bytes |
| `VAULT_TEMP_TTL_MINUTES` | `120` | Lifetime of an upload session and of temp files |
| `VAULT_CLIENT_MAX_IN_FLIGHT` | `3` | Chunk requests across all files. **Keep below the PHP worker count** (Herd has 4, so 3) |
| `VAULT_CLIENT_PER_FILE_IN_FLIGHT` | `2` | Chunk requests for one file |
| `VAULT_DEDUP_LOCK_SECONDS` | `10` | Dedup and ledger lock TTL |
| `CACHE_STORE` | `database` | Must support atomic locks (`database`, `redis`, `file`) |
| `QUEUE_CONNECTION` | `database` | |
| `DB_QUEUE_RETRY_AFTER` | `3600` | Pipeline connection; the doctor fails below 3600 |
| `SCAN_DRIVER` | `null` | `null` or `clamav` |
| `MEDIA_PROBE_DRIVER` | `ffmpeg` | `ffmpeg` or `null`; set `null` where ffprobe is absent |
| `CHUNK_TRACKER_DRIVER` | `database` | `database` or `redis` |
| `DELIVERY_DRIVER` | `stream` | Full-download delivery is not implemented yet |
| `FFMPEG_BINARY`, `FFPROBE_BINARY`, `PDFTOPPM_BINARY`, `SOFFICE_BINARY`, `CLAMDSCAN_BINARY` | the bare name | Optional, local only. Absolute path with `/` |
| `VAULT_OWNER_DOWNLOAD_ENABLED` | `true` | The owner's `media.link` / `media.stream` |
| `VAULT_CONSULT_URL_TTL` | `30` | Minutes a signed consultation URL lives |
| `VAULT_CONSULT_AUDIT_WINDOW` | `10` | Minutes per audit row |
| `VAULT_CONSULT_WATERMARK` | `true` | Watermark for admins |
| `VAULT_CONSULT_WATERMARK_FOR_OWNER` | `false` | Watermark for the owner |
| `VAULT_CONSULT_RANGE_MAX_BYTES` | `8388608` | Cap of one inline-original response |
| `VAULT_CONSULT_DOCX_MAX_BYTES` | `31457280` | Above this a docx shows "too large for preview" |
| `VAULT_CONSULT_PPTX_MAX_SLIDES`, `VAULT_CONSULT_PPTX_MAX_TEXT_BYTES` | `300`, `2097152` | pptx outline caps |
| `VAULT_CONSULT_SHEET_MAX_ROWS`, `_MAX_COLS`, `_MAX_BYTES` | `5000`, `100`, `15728640` | Spreadsheet caps |
| `VAULT_CONSULT_TEXT_MAX_BYTES` | `2097152` | Text preview cap |
| `VAULT_CONSULT_IMAGE_MAX_EDGE` | `2000` | Image derivative size |
| `VAULT_CONSULT_RETRY_AFTER` | `7200` | `retry_after` of the `previews` connection |
| `VAULT_CONSULT_JOB_TIMEOUT` | `6600` | Preview job timeout; must stay below the line above |
| `VAULT_CONSULT_RENDER_TIMEOUT`, `VAULT_CONSULT_CONVERT_TIMEOUT` | `5400`, `180` | Tool limits, used only by `derivative` tool renderers |
| `VAULT_CONSULT_PENDING_WARN` | `900` | Seconds before the doctor flags a stuck preview |
| `VAULT_DOCTOR_WEB_ENABLED` | `false` | Exposes `/_vault-doctor` outside `local` (signed URL, runtime values only) |
| `VAULT_DOCTOR_RESOLVE_IP` | empty | Pins the domain to an IP for `vault:doctor --fpm` behind NAT |

Other `VAULT_CONSULT_*` keys (`PDF_MAX_PAGES`, `PAGE_MAX_EDGE`, `VIDEO_PRESET`,
`VIDEO_CRF`, `VIDEO_MAX_HEIGHT`, `AUDIO_BITRATE`) and `VAULT_PROBE_TIMEOUT`,
`VAULT_SCAN_TIMEOUT`, `VAULT_VARIANT_TIMEOUT`, `VAULT_POSTER_FRAME_PERCENT`,
`VAULT_PREVIEW_MAX_SECONDS`, `VAULT_PREVIEW_MAX_HEIGHT` only tune the optional
tools.

## 8. Queue workers and scheduler

Two workers, on two queue *connections* backed by the same `jobs` table:

| Work | Connection | Queues | `retry_after` | Job timeout |
|---|---|---|---|---|
| Upload pipeline and notifications | `database` | `media`, `default` | 3600 s | per job, up to 3600 s |
| Consultation previews | `previews` | `previews` | 7200 s (`VAULT_CONSULT_RETRY_AFTER`) | 6600 s (`VAULT_CONSULT_JOB_TIMEOUT`) |

**Local: three windows** (Herd serves the site itself and does not manage
workers).

```powershell
npm run dev
php artisan queue:work --queue=media,default
php artisan queue:work previews --queue=previews --timeout=6600
```

> The connection name **`previews` must come before `--queue`**. `retry_after`
> belongs to the connection the worker starts on. `queue:work --queue=previews`
> without it reads the same table with the pipeline's 3600 s.

- Without the first worker, uploads complete but stay at `scanning`.
- Without the second, deposits still reach `ready`, but previews stay
  "preparing".
- Do not use `composer run dev` for upload work: `php artisan serve` is
  single-process on Windows, and its queue listener does not drain `media`.

**cPanel: cron instead of daemons.**

```cron
* * * * * cd /home/USER/public_html && php -d memory_limit=256M artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/public_html && php -d memory_limit=256M artisan queue:work --queue=media,default --stop-when-empty --max-time=55 --timeout=3000 >> /dev/null 2>&1
* * * * * cd /home/USER/public_html && flock -n /tmp/onda-previews.lock php -d memory_limit=256M artisan queue:work previews --queue=previews --stop-when-empty --max-time=3300 --timeout=6600 >> /dev/null 2>&1
```

- `schedule:run` runs the hourly sweep of stale temp files in `work/`
  (`vault:purge-temp`) and a short worker for the `default` queue. It does
  **not** drain `media`; the second line does.
- `--stop-when-empty` exits as soon as the queue is drained, so idle ticks cost
  nothing.
- **`--max-time` never kills a running job.** It is checked between jobs: after
  that many seconds the worker takes no new job and exits, while a job already
  running continues up to its own timeout.
- `flock -n` on the previews line stops a second heavy worker from starting
  beside a busy one.
- Run **`php artisan queue:restart` after every deploy**, so no worker keeps
  running old code.

## 9. Production deployment (cPanel)

**Layout**

| What | Where |
|---|---|
| Application | `~/public_html`; the root `.htaccess` sends every request to `public/` and denies dotfiles and the framework directories |
| Storage | `~/onda-storage/{vault,incoming,work,variants}`, `chmod 700`, one partition |
| Master key | `~/onda-secrets/vault-master.key`, `chmod 600` |

**PHP settings** (MultiPHP INI Editor, web only):

| Key | Value |
|---|---|
| `upload_max_filesize` | `16M` |
| `post_max_size` | `32M` |
| `memory_limit` | `256M` |
| `max_execution_time` | `120` |

The CLI reads a different `php.ini`. Pass CLI values with `php -d`, as in the
cron lines.

**Deploy**

```bash
cd ~/public_html
git pull
composer install --no-dev --optimize-autoloader
# public/build: run `npm ci && npm run build` here if Node is available,
# otherwise build elsewhere and upload the directory
php artisan migrate --force
php artisan referentiel:sync-mime-types
php artisan config:cache
php artisan route:cache
php artisan queue:restart
php artisan vault:doctor
```

- `config:cache` and `route:cache` are for **production only**.
- **Never run the tests on the server.**
- Keep **offline backups** of `vault-master.key` and `APP_KEY`, apart from the
  data.
- Check from outside that `/.env`, `/storage/` and the vault are **not
  reachable over HTTP**, and that `public_html/public/storage` does not exist.
  Never run `php artisan storage:link` on a vault disk.

### Migration reset (once per environment, October 2026)

The migration history was consolidated into one `create_<table>_table`
migration per table, before production. Every environment that ran the old
migrations still lists their names in its `migrations` table, so it cannot
simply `migrate`. Run this **once** on each deployed server and each
teammate's machine. The old data is development data only.

1. Back up the database (`mysqldump --single-transaction <db> > backup.sql`).
2. Stop the queue workers (cPanel: comment out the worker cron lines; locally:
   stop the `php artisan queue:work` terminal).
3. `php artisan migrate:fresh --seed --force`
4. Empty the four storage roots (`VAULT_DISK_ROOT`, `INCOMING_DISK_ROOT`,
   `WORK_DISK_ROOT`, `VARIANTS_DISK_ROOT`): their files no longer match any
   database row. **Do not touch** `VAULT_MASTER_KEY_PATH` or `APP_KEY`.
5. `php artisan optimize:clear`, then, **on servers only**,
   `php artisan config:cache && php artisan route:cache`. Never cache config
   or routes on a dev machine (see CLAUDE.md).
6. `php artisan queue:restart`, then restart the workers.

> **Warning about `--seed` on a server.** `DatabaseSeeder` also runs
> `RoleAndUserSeeder` and `VaultDemoSeeder` and creates `test@example.com`,
> all with the password `password`. On a reachable server, change or delete
> those accounts right after step 3.

**From now on, every schema change is a new migration.** History was
rewritten this one time, before production, and never will be again.

## 10. Testing

```powershell
composer test          # config:clear + Pint check + PHPStan + Pest
npm run test:unit      # Vitest
npm run types:check    # vue-tsc
npm run lint:check     # ESLint
npm run format:check   # Prettier
composer ci:check      # lint:check + format:check + types:check, then composer test
```

- Use **`composer test`**, not a bare `php artisan test`, for the full suite.
  A single file: `php artisan config:clear; php artisan test --compact tests/Feature/...`.
- Tests run on **sqlite `:memory:`**, and every test gets its own temporary
  vault, incoming, work and variants roots. They never touch the real vault.
- **`Tests\Support\TestEnvironmentGuard`** aborts the run before any query if
  the config or routes are cached, `app.env` is not `testing`, or the database
  is not sqlite `:memory:` or a `*_test` database. It exists because a cached
  config once made `RefreshDatabase` wipe the development MySQL database.
- **Never run `optimize`, `config:cache` or `route:cache` locally.** If you
  did: `php artisan optimize:clear`.
- Tests that use real binaries skip with a reason when the tool is absent. When
  present, each tool is capped at 60 s and each test at 300 s.
- **Playwright e2e** (`npx playwright test`) is not part of `ci:check`. It
  drives the local Herd site and needs a seeded database, the pipeline worker,
  a current `npm run build` and `npx playwright install chromium`. Settings:
  `E2E_BASE_URL`, `E2E_EMAIL`, `E2E_PASSWORD`, `E2E_OEUVRE_UUID`,
  `E2E_LARGE_MB`, `E2E_UPLOAD_MBPS`, `E2E_START_PCT`. It uploads into the dev
  vault and cleans up with `php artisan e2e:cleanup --apply`
  (`E2E_SKIP_CLEANUP=1` keeps the files).
- A **stale `public/build`** causes phantom failures: the app serves old
  JavaScript while the source looks correct.
- After changing routes or controllers:
  `php artisan wayfinder:generate --with-form`. Without `--with-form`,
  `npm run types:check` fails.

## 11. Artisan commands

| Command | Options | Dry-run by default | Purpose |
|---|---|---|---|
| `vault:doctor` | `--fpm`, `--json` | read-only | Environment audit. `--fpm` also asks the web SAPI and compares |
| `vault:generate-previews` | `--uuid=`, `--all`, `--only-missing`, `--family=`, `--force` | no | Queues consultation previews |
| `vault:reprocess <uuid>` | | no | Re-runs the pipeline for one file |
| `vault:mark-missing-bytes` | `--dry-run`, `--apply` | **yes** | Marks rows whose bytes are gone as failed |
| `vault:quarantine-orphans` | `--apply`, `--older-than=7` | **yes** | Moves unreferenced vault files to `orphans/{date}/`; never deletes |
| `vault:recount-refs` | `--apply` | **yes** | Fixes `media_files.ref_count` |
| `e2e:cleanup` | `--apply`, `--prefix=e2e-`, `--email=` | **yes** | Purges the e2e suite's own deposits |
| `uploads:release-stale` | `--idle=30`, `--user=`, `--dry-run` | no | Aborts idle upload sessions |
| `referentiel:sync-mime-types` | `--dry-run` | no | Recomputes slot MIME lists from the registry |

`vault:doctor --fpm` works out of the box in `local`. Elsewhere the endpoint
answers only while `VAULT_DOCTOR_WEB_ENABLED=true`, and returns runtime php.ini
values only.

## 12. Troubleshooting

### Local (Windows / Herd)

| Symptom | Cause | Fix |
|---|---|---|
| `.env` "unexpected escape sequence" | `\` in a path | Use `/` |
| Doctor: "Master key file exists" FAIL | The key is never in git | Create it (section 6) |
| `Permission denied` on `laravel.log`, `bootstrap/cache` or `.tmp` despite Full Control | **Sophos Intercept X CryptoGuard** flags `php.exe` as ransomware | Check the Sophos Events tab. IT adds Sophos Central exclusions for Herd's `php.exe` and `php-cgi.exe` (every PHP version folder) and for `ffmpeg.exe` and `pdftoppm.exe` |
| The same error from one terminal only | A sandboxed or WSL shell | Use a normal PowerShell |
| Tests wiped the dev database, or 419 / "missing session" failures | Cached config | `php artisan optimize:clear`, `php artisan migrate:fresh --seed`, then use `composer test` |
| "Chunk 0 failed", or UI bugs that the source does not explain | Stale `public/build`, or Vite not running | `npm run build` or `npm run dev` |
| Uploads rejected while a big upload runs | PHP workers saturated | `VAULT_CLIENT_MAX_IN_FLIGHT=3` |
| Web and CLI behave differently | The Herd site runs PHP 8.5, the CLI 8.4 | Use one version for both |
| Preview stuck on "préparation en cours" | The previews worker is not running | Start it, then `php artisan vault:generate-previews --all --force` |
| `.doc`, `.odt`, `.ppt`: "aperçu indisponible" | Expected: those families are `unsupported` | Ask for docx, pptx or PDF |
| Video: "format non lisible" | A codec the browser cannot play (HEVC, AVI) | Recommend MP4 (H.264) |
| `npm ci` fails with ERESOLVE | Misaligned ESLint packages | Align the versions in `package.json`. Never use `--legacy-peer-deps`, on the command line or in `.npmrc` |
| Tools "not found" after `winget upgrade` | The version number is part of the install path | Update the `*_BINARY` paths in `.env` |

### cPanel

| Symptom | Cause | Fix |
|---|---|---|
| Doctor: `Extension: sodium` FAIL | The extension is not installed | WHM → EasyApache 4 → enable `ea-php84-php-sodium`. Check `php -m` for the CLI and a web probe for the site |
| Doctor shows `exec` / `shell_exec` disabled | Host policy | Fine. `proc_open` is what is used; the check fails only if all three are disabled |
| `vault:doctor --fpm` fails | The endpoint is off outside `local`, or the server cannot reach its own domain (NAT hairpin, SSL) | Set `VAULT_DOCTOR_WEB_ENABLED=true` and, behind NAT, `VAULT_DOCTOR_RESOLVE_IP`; rebuild the config cache; turn it off afterwards. Or use a temporary probe file and delete it |
| Upload limits are not what you set | The CLI and the web use different `php.ini` files | MultiPHP INI Editor for the web, `php -d` for the CLI |
| Requests from the server to its own domain fail | NAT loopback | Ask the host for an `/etc/hosts` entry |
| Jobs do not run | Cron lines missing, or workers running old code | Add the cron lines (section 8); `php artisan queue:restart` |
| Doctor mentions ffmpeg, pdftoppm or LibreOffice | Informational: optional tools | Nothing to do. They are not needed in production, and the hosting team does not support them |

## 13. Security model

**Protected**

- **At rest**: every file and every derivative is encrypted; keys are
  enveloped by a KEK outside the web root.
- **Integrity**: a MAC per 1 MiB segment, a SHA-256 of each deposit, and a
  hash-chained ledger.
- **Access**: files outside the web root, signed short-lived URLs bound to the
  issuing user, ownership policies, and 404 for another author's file.
- **Deletion safety**: bytes are removed only by `VaultBytesReleaser`, only
  when no row references them, and every decision is logged.

**Accepted trade-off**

For **PDF, DOCX, video and audio**, the decrypted original reaches the
reviewer's browser, because cPanel has no converters. Reviewers are
authenticated ONDA officials, and a leak is attributable through the watermark
and the `consult_original` audit rows. The viewers have no download or print
control, but extracting the file with the browser's developer tools is
possible.

**Unchanged**

- Images, spreadsheets, text and pptx are **derivative-only**: the original
  never leaves the server on the consultation path.
- Downloading an original is **owner-only**.

## 14. What changed

**Removed**

- ffmpeg, poppler and LibreOffice as production requirements.
- `StreamOriginalDialog` (the admin's access to the original).
- `MediaPreviewDialog` (the author's old preview), replaced by the shared
  viewer.

**Moved**

- Preview generation left the deposit chain. It runs on the `previews`
  connection after the deposit is `ready`.

**Added**

- The fair parallel upload scheduler and quota reservation at init.
- The dedup race fix and `VaultBytesReleaser`.
- Consistency and writability checks in `vault:doctor`.
- Housekeeping commands: `vault:mark-missing-bytes`, `vault:recount-refs`,
  `vault:quarantine-orphans`, `e2e:cleanup`.
- Browser-native preview (PDF.js, docx-preview, native media) and the pptx
  text outline.
- The author's preview of their own files.
- The `consult_original` audit action.
- `TestEnvironmentGuard` and per-test storage roots.

## More documentation

`CLAUDE.md` explains why the system is built this way. The files in `docs/`
(`installation.md`, `configuration.md`, `deployment-cpanel.md`, `storage.md`,
`troubleshooting.md`) go deeper, but they predate the browser-native preview.
Where they disagree with this README, this README and the code are right.
