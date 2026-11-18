# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Project

Laravel 13 + Inertia.js v3 + Vue 3 (TypeScript) application, based on the `laravel/vue-starter-kit`. It's the digital portal for ONDA (Office National des Droits d'Auteur, Algeria) — copyright/author registration with Algeria-specific geography (wilayas/communes) and full French/Arabic/English localization.

## Commands

**Backend (PHP / Composer)**
- `composer run dev` — run server + queue listener + Vite together (primary local dev command)
- `composer lint` / `composer lint:check` — Pint (Laravel preset), fix vs. check-only
- `composer types:check` — `phpstan analyse` (Larastan, level 7, configured in `phpstan.neon`)
- `php artisan test --compact` — run the full Pest suite
- `php artisan test --compact --filter=testName` — run a single test
- `php artisan test --compact tests/Feature/Auth/RegistrationTest.php` — run one test file
- `composer test` — `config:clear` + `lint:check` + `types:check` + `artisan test` (what CI-equivalent local check runs)
- `composer ci:check` — full CI gate: npm lint/format/types-check + `composer test`
- New Pest tests: `php artisan make:test --pest {Name}` (name is relative to `tests/Feature`, don't prefix with `Feature/`; add `--unit` for `tests/Unit`)

**Frontend (npm)**
- `npm run dev` / `npm run build` / `npm run build:ssr`
- `npm run lint` / `npm run lint:check` — ESLint
- `npm run format` / `npm run format:check` — Prettier (with `prettier-plugin-tailwindcss`)
- `npm run types:check` — `vue-tsc --noEmit`

## Architecture

**Locale routing is split by area.** Public/crawlable routes are locale-prefixed (`/{locale}/...`, e.g. the `home` route), resolved from the route segment. Authenticated app routes (dashboard, settings) are *not* locale-prefixed — their locale comes from a non-httpOnly `locale` cookie instead, so the client-side language switcher can update it instantly without a round trip. Both paths are handled by `App\Http\Middleware\SetLocale` (`routes/web.php`, `routes/settings.php`), which shares `locale`/`direction` to both Blade and Inertia.

**Languages are database-driven.** The `languages` table (`App\Models\Language`) is the single source of truth for which locales exist, which are active, which is the default and each one's `direction` (rtl/ltr); all reads go through `App\Domain\Localization\LanguageService` (cached forever, flushed by the model's `saved`/`deleted` events — a bare query-builder `update()` bypasses that, so go through the model). There is no list of locales in PHP or TS: the `{locale}` route constraint only checks the *shape* (`Language::CODE_PATTERN`) and `SetLocale`/`LocaleController` 404 anything not active. The frontend gets `languages`/`defaultLocale`/`direction` as shared Inertia props; only the UI-string bundles are build-time (`resources/js/locales/{code}.json`, auto-discovered by `import.meta.glob` — a language without a bundle renders in the default's strings). Admins manage languages at `/admin/languages`; there is no delete (deactivate instead), the default can't be deactivated or deleted, `code` is immutable. `.env` keeps only `APP_LOCALE`/`APP_FALLBACK_LOCALE` as the last-resort bootstrap value.

Because Inertia visits never re-run `app.blade.php`, `resources/js/app.ts` re-applies the active vue-i18n locale and `<html dir/lang>` on every `router.on('navigate')` — see the comments there and in `i18n.ts` before touching locale-switching logic.

**Layout selection is name-based, not per-page.** `resources/js/app.ts` picks the Inertia layout purely from the page component's name: `Home` → `PublicLayout`, `auth/*` → `AuthLayout`, `settings/*` → `[AppLayout, SettingsLayout]` (nested), everything else → `AppLayout`. New pages need to follow this directory/naming convention rather than importing a layout manually.

**Auth is Fortify-driven, not Breeze-style controllers.** All auth view rendering and action wiring lives in `App\Providers\FortifyServiceProvider::configureViews()`/`configureActions()`. Custom behavior (registration, password reset, login/2FA responses) goes in `app/Actions/Fortify/*` and `app/Http/Responses/*`, registered there — not in ad hoc controllers. Passkeys (`@laravel/passkeys`) and 2FA are both enabled.

**Authorization uses spatie/laravel-permission role gates on routes**, not policies: `Route::middleware(['auth','verified','role:author'])` / `role:admin'` (see `routes/web.php`). Two seeded roles exist: `admin` and `author` (`database/seeders/RoleAndUserSeeder.php`). `App\Models\User` uses `HasRoles`.

**Algeria geography** (`Country`, `Wilaya`, `Commune` models) is seeded from JSON fixtures in `public/assets/seeders/` (`countries.json`, `algeria_cities.json`) via `database/seeders/{Country,Wilaya,Commune}Seeder.php`. Registration dynamically loads wilaya→commune via `GET /api/wilayas/{wilaya}/communes` (`routes/web.php`).

**Frontend routes/actions are generated, not hand-written.** `@laravel/vite-plugin-wayfinder` generates typed wrappers under `resources/js/routes/` and `resources/js/actions/` from PHP routes/controllers — call backend endpoints through those generated functions rather than hardcoding URLs. Regenerate with `php artisan wayfinder:generate` if generated files look stale (the vite plugin also does this during `dev`/`build`).

**UI components** live in `resources/js/components/ui/` and are built on `reka-ui` (Radix Vue) + Tailwind v4 + `class-variance-authority`, matching the shadcn-vue pattern. Fonts (IBM Plex Sans / Sans Arabic / Mono) are loaded via `laravel-vite-plugin/fonts` in `vite.config.ts`.

**Path alias:** `@/*` → `resources/js/*` (see `tsconfig.json`).

## ONDA Storage (Vault)

A deposit-vault subsystem being built inside this same portal (branch `feat/upload-files`). Authors upload original works — video, audio, PDF, PPTX — up to 5 GB per file, multiple files per work. Files are legal deposits: integrity and audit trail matter more than convenience. Built phase-by-phase (P0–P8); each phase has a hard ownership boundary and a gate command — do not touch files outside the current phase's ownership list, and do not proceed past a gate without it passing.

**Architecture — Ports & Adapters, applied to exactly 4 seams.** Local dev (Herd/Windows) and cPanel production differ materially, so these four are interfaces with swappable implementations bound in a service provider from config — nothing else gets an interface:

| Contract | local (Herd) | production (cPanel) |
|---|---|---|
| `Delivery` | `StreamDelivery` | `LiteSpeedDelivery` |
| `Scanner` | `NullScanner` | `ClamavScanner` |
| `ChunkTracker` | `DatabaseChunkTracker` | `RedisChunkTracker` |
| `MediaProbe` | `FfmpegProbe` | `FfmpegProbe` or `NullProbe` |

No Repository pattern — Eloquent is the data layer; single-purpose Query Objects only where a query is genuinely complex.

**Directory layout**
```
app/Domain/Vault/{Contracts,Crypto,Value}   ← storage + encryption core
app/Domain/Deposit/                          ← Oeuvre, MediaFile lifecycle
app/Domain/Access/                           ← policies, signed links, audit
app/Domain/Quota/
app/Actions/                                 ← one class, one public handle()
app/Infrastructure/                          ← contract implementations
app/Jobs/
```
Dependencies point downward only. `Domain` must not import from `Http` or `Infrastructure`.

**The two rules everything derives from**
1. No large file ever traverses a single HTTP request. The browser splits into 8 MiB chunks (`8388608`, divisible by 16 so every chunk starts on an AES block boundary); the server writes each chunk at its byte offset into a pre-allocated file.
2. No plaintext byte is ever written to the vault. Encryption happens in-memory as each chunk arrives.

**Upload mechanics.** `POST /uploads` pre-allocates with `ftruncate($fh, $totalSize)` and generates a per-file DEK. `POST /uploads/{uuid}/chunk/{index}` — body is raw `application/octet-stream`, NOT multipart, read via `php://input`. Up to 3 concurrent chunks per file (non-overlapping offsets, safe on ext4/NTFS). `POST /uploads/{uuid}/complete` verifies the received-chunk mask, then `rename()`s into the vault — instant only because all vault disks share one partition, which is a hard requirement.

**Cryptography.** AES-256-CTR — length-preserving (offset writes stay valid) and seekable (HTTP Range works); never substitute GCM or CBC. Envelope encryption: random 32-byte DEK per file, wrapped by a master KEK read from `VAULT_MASTER_KEY_PATH` (a file OUTSIDE the project directory and outside backups). IV = `nonce8 . pack('J', intdiv($byteOffset, 16))` (16 bytes). Keys are always derived via `hash_hkdf('sha256', $dek, 32, 'onda-enc'|'onda-mac')`, never used raw. CTR is unauthenticated, so it's encrypt-then-MAC per 1 MiB segment: `HMAC-SHA256(macKey, fileUuid . segmentIndex . ciphertextSegment)`, stored in a sidecar `{uuid}.mac` file.

**Storage.** Vault bytes use native PHP I/O (`fopen`/`fseek`/`fwrite`/`ftruncate`) — Flysystem has no seek-on-write, so `Storage::put()` on a chunk would truncate the whole file. `Storage` disks are used only for path resolution, permissions and config; reach the absolute path with `->path()`, and only inside `EncryptedLocalVault`. `Storage` IS appropriate for the `variants` and `public` disks (small, write-once). Disks: `vault`, `incoming`, `work`, `variants` — all rooted OUTSIDE the project, all on one partition, `0700` dirs / `0600` files, all with `'throw' => true`. Vault paths are sharded: `{uuid[0:2]}/{uuid[2:4]}/{uuid}.bin`.

**Schema conventions.** Every domain table: `id` (bigint PK, used for FKs) + `uuid` (UUIDv7, unique, the route key — `getRouteKeyName()` returns `'uuid'`, never expose sequential ids in URLs). `sha256_plain` is a plain index, NOT unique — a soft-deleted row must not block re-upload.

**Soft-delete classification (every table — audited 2026-09-13).** This table is the rule; a table not listed here gets a row added before it gets a `deleted_at`. "Keep" means both the `deleted_at` column AND the `SoftDeletes` trait — one without the other is a half-applied state. `deleted_at` is cast to datetime by the trait itself; no explicit cast needed. `SchemaTest` asserts the exact set of tables carrying `deleted_at`. A `softDeletes()` added to a "Never" table was already reverted once (`2026_09_03_090001_revert_soft_deletes_drift.php`) — do not re-add it.

| Table | Soft deletes | Reason |
|---|---|---|
| `users` | Keep | Owns oeuvres, media files, quota and access-log rows; hard delete would cascade away legal deposits (`oeuvres.author_id` / `media_files.uploaded_by` are `cascadeOnDelete`). |
| `oeuvres` | Keep | The legal deposit record; must be recoverable, and hard delete cascades to `media_files` rows while the bytes stay on disk. |
| `media_files` | Keep | The deposited file record; soft-deleted rows still own bytes on disk until `purged_at` (see below). |
| `countries` | Keep | Seeded reference data. `users.country_id` / `wilayas.country_id` are `nullOnDelete`, so a hard delete would silently erase existing authors' addresses; soft delete retires the entry and keeps them. |
| `wilayas` | Keep | Same as `countries` (`users.wilaya_id` is `nullOnDelete`), and hard delete cascades to all its `communes`. A trashed wilaya 404s on `GET /api/wilayas/{wilaya}/communes` — intended: retired entries are not selectable. |
| `communes` | Keep | Same as `countries` (`users.commune_id` is `nullOnDelete`). |
| `register_types` | Keep | Classification reference data (declarant types) an admin may retire; a retired entry must stay resolvable for anything classified under it. `MembershipTypeSeeder` never deletes. |
| `type_gestions` | Keep | Same as `register_types` (labels for the Auteur type-de-gestion select). |
| `register_type_colleges` | Keep | Same as `register_types`; `code_college` is load-bearing for the documents mapping, so a retired college is soft-deleted, never removed. |
| `register_type_members` | Keep | Same as `register_types` (qualités within a college). |
| `register_role_auteurs` | Keep | Same as `register_types` (contributor roles within a college). |
| `college_oeuvre_files` | Keep | Same as `register_types`: required-document definitions per college (a template, not uploads — those are `media_files`) that an officer may retire. `CollegeOeuvreFileSeeder` never deletes and updates retired rows in place via `withTrashed()`, since the `(register_type_college_id, document_key)` unique index covers them. |
| `upload_sessions` | Never | Ephemeral, swept after expiry, hard-deleted by `CompleteUpload`. A trashed session keeps its uuid alive; any lookup not excluding trashed rows would resolve a dead session and write chunks into a finalised upload. |
| `file_access_logs` | Never | Append-only legal audit trail. A soft-deletable row lets an actor hide their own access. |
| `media_variants` | Never | Regenerable derivatives. Must be hard-deleted so their bytes go with them; soft delete just leaks disk. |
| `storage_quotas` | Never | `user_id` is unique: a trashed quota row permanently blocks creating a new one, and the create path fails with a constraint violation instead of a clear error. |
| `passkeys` | Never | Package table; `Laravel\Passkeys\Passkey` has no trait and `DeletePasskey` hard-deletes. A revoked credential must be gone, and a column-only `deleted_at` would be a half-applied state. |
| `roles` | Never | spatie/laravel-permission's own queries ignore `deleted_at`: a trashed role would match some checks and not others. |
| `permissions` | Never | Same as `roles`. |
| `model_has_roles` | Never | Same as `roles` (pivot, written/detached directly by the package). |
| `model_has_permissions` | Never | Same as `roles`. |
| `role_has_permissions` | Never | Same as `roles`. |
| `sessions` | Never | Framework infrastructure; Laravel deletes directly via the query builder, bypassing Eloquent. |
| `password_reset_tokens` | Never | Framework infrastructure; a trashed token must never be redeemable. |
| `cache` / `cache_locks` | Never | Framework infrastructure (cache store and atomic locks, e.g. the dedup lock). |
| `jobs` / `job_batches` / `failed_jobs` | Never | Framework queue infrastructure; the worker deletes rows directly. |
| `migrations` | Never | Framework bookkeeping. |
| `notifications` | Never | Framework infrastructure backing the `database` notification channel; written and deleted by Laravel's own `DatabaseNotification` model, which has no trait. A read notification is marked, not removed; a dismissed one must be gone. |
| `languages` | Never | Config-like reference data with no delete flow: retiring a language is `is_active = false`, and the default can never be deleted (model guard). Keyed by `code`, not `uuid`, because the code is the locale identifier used in URLs and cookies. |
| `download_tickets` *(not yet created)* | Never | Short-lived like `upload_sessions`; expiry is its lifecycle. A trashed ticket resolvable by uuid could re-authorise a download, and the durable evidence already lives in `file_access_logs`. |
| `oeuvre_reviews` | Never | Append-only decision history (who moved the deposit, from what, to what, why) — the artifact that makes a registration defensible. Same reasoning as `file_access_logs`: a decision record that can be edited or removed defeats its purpose, so it has no `deleted_at` and no `updated_at` either. `actor_id` is `restrictOnDelete`, unlike `file_access_logs.user_id`: `users` is soft-deleted, so the officer never actually vanishes, and a hard delete that would orphan a decision must fail loudly. |
| any future append-only decision/audit table *(not yet created)* | Never | Same reasoning as `oeuvre_reviews`. |

**Soft delete does NOT free disk.** `$mediaFile->delete()` only sets `deleted_at` — **the 5 GB ciphertext and its `.mac` sidecar stay exactly where they are**, and deduplicated rows may still point at them. Freeing bytes is a separate step: `purged_at` is set by a purge job after the 30-day retention window (`VAULT_RETENTION_DAYS`; the job is P7 and does not exist yet — today nothing frees a soft-deleted file's bytes). Disk usage is therefore defined as `MediaFile::withTrashed()->whereNull('purged_at')->sum('size_bytes')`; the runtime quota is the `storage_quotas.used_bytes` counter charged in `CompleteUpload`, which must reconcile to that sum. Anyone who reads "soft deleted" as "gone" will miscount storage — `DeduplicateFile::findOriginal()` uses `withTrashed()` for exactly this reason. Soft-deleting an oeuvre does **not** cascade to its `media_files` (DB `cascadeOnDelete` only fires on a hard delete); there is currently no oeuvre- or file-delete flow at all, only `ProfileController::destroy()` soft-deleting a user, which leaves their oeuvres, files, quota and bytes untouched.

**Queue.** Driver `database`; Redis is optional and must never be a hard dependency. `retry_after` MUST be 3600 in `config/queue.php` — the 90-second default causes a 5 GB hash or an ffmpeg run to be re-dispatched while still executing. Long jobs declare `public int $timeout` and implement `ShouldBeUnique` keyed on the file uuid. Heavy work goes on the `media` queue so it can't block notifications on `default`. The post-upload pipeline is an explicit `Bus::chain()`, not events — events are for side effects only (notifications, audit, quota).

**Read paths are two architecturally distinct flows.** Preview/stream (~95%): PHP decrypts on the fly with Range support, occupies a worker for seconds. Full download (~5%): a queued job decrypts to `work/`, then hands off to `Delivery`; the temp file is deleted after 30 minutes — this exists because a 5 GB download over a slow link would otherwise pin a PHP-FPM worker for an hour.

**Access control (all four layers required):** file outside webroot → signed URL (15 min) → Policy on ownership → bound to the issuing session (`?u=` must equal `auth()->id()`) → every access written to `file_access_logs`.

**Local environment (Windows 11 + Herd free).** Herd free has no bundled MySQL/Redis — MySQL runs in WSL2, reached at `127.0.0.1:3306`. Queue worker is NOT managed by Herd — run `php artisan queue:work` in its own terminal. Keep `post_max_size=32M` / `upload_max_filesize=16M` locally too (low limits surface architecture violations immediately instead of after deploy — do not raise them). `.env` paths use forward slashes even on Windows. Storage root lives on a separate drive, excluded from Windows Defender, never inside OneDrive. `SCAN_DRIVER=null` locally — ClamAV is not practical on Windows.

**Hard prohibitions**
- Never `php artisan storage:link` on a vault disk — one command undoes every protection.
- Never `Storage::get()` a vault file — it loads 5 GB into memory.
- Never `readfile()` / `response()->download()` for vault files.
- Never place the master key inside the repo, inside `storage/`, or inside any backup set.
- Never make `sha256_plain` unique.
- Never use multipart form encoding for chunk bodies.

**Testing.** Pest — feature tests for HTTP flows, unit tests for `Domain/Vault/Crypto`. Encryption tests must cover: offset round-trip at a non-zero chunk index, Range read across a segment boundary, and MAC failure detection on a flipped bit.

**Phase log** (deviations approved between phases get one line here):
- P-BOOT (2026-09-01): initial audit found no contradictions — PHP 8.4.21 installed (spec wants ≥8.4), `QUEUE_CONNECTION` already defaults to `database`, `role:author`/`role:admin` route middleware already in place. No vault code exists yet; phases P0 onward start clean.
- Post-P6, ad hoc (2026-09-06): admin moved from a `role:admin` gate on the single `web` guard to its own `admin` session guard (`config/auth.php`), same `users` table/provider — session isolation, not a separate identity. Hand-rolled `AdminSessionController` (Fortify is pinned to `guard: web`, see `config/fortify.php`); role check re-verified after `attempt()` since guard membership alone doesn't imply the role. `bootstrap/app.php` gained path-based (`admin`/`admin/*`) overrides for both `redirectGuestsTo` and `redirectUsersTo`, since Laravel's default `Authenticate`/`RedirectIfAuthenticated` middleware ignore which guard rejected a request. `HandleInertiaRequests` shares a new `auth.guard` prop so components used by both areas (`UserMenuContent.vue`) can branch on it. Known follow-up, not fixed here: `AppSidebar.vue`'s nav items are author-oriented and mostly still point at the `web`-guard `dashboard()` route, so an admin using most sidebar links would be bounced to `/login` instead of `/admin/login`.
- Oeuvre classification, Task A (2026-09-14): `works` → `oeuvres` everywhere — table, `Work` → `Oeuvre` model/policy/factory, `work_id` → `oeuvre_id` FKs, `works.*`/`admin.works.*` routes (`/author/oeuvres`, `/admin/oeuvres`), pages, `works.*`/`admin.works.*` i18n keys (values unchanged). Done as a new migration (`2026_09_14_090000_rename_works_to_oeuvres`) that drops and recreates FKs/indexes under fresh names, not by editing create migrations. The `work` **disk** (pipeline plaintext, `WORK_DISK_ROOT`) is unrelated to the table and deliberately keeps its name.

## Laravel Boost skills

This project has Laravel Boost installed with Codex skills in `.Codex/skills/` (`fortify-development`, `inertia-vue-development`, `wayfinder-development`, `pest-testing`, `laravel-best-practices`, `tailwindcss-development`, `infer-conventions`). These auto-activate for matching tasks (see each skill's trigger description) — prefer letting them load rather than re-deriving their guidance from scratch.
