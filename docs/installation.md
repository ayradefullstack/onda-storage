# Installation — Windows 11 + Herd

Ordered steps. Each one ends with a way to check it worked. Do not skip the
verifications: several failures in this stack are silent, and the point of the
checks is to make them loud.

Target environment: **Windows 11, Laravel Herd (free tier), MySQL, Node 20+.**

---

## Prerequisites

| Requirement | Version | How to check |
|---|---|---|
| PHP | 8.4+, **64-bit** | `php -v` |
| Node.js | 20+ | `node -v` |
| npm | 10+ | `npm -v` |
| Composer | 2.x | `composer -V` |
| Git | any | `git --version` |
| MySQL | 8.0+ | `mysql --version` |
| ffmpeg + ffprobe | any recent | `ffmpeg -version` |

```powershell
php -v
node -v
npm -v
composer -V
git --version
```

### PHP must be 64-bit

```powershell
php -r "echo PHP_INT_SIZE === 8 ? '64-bit OK' : 'FAIL: 32-bit PHP';"
```

32-bit PHP cannot `fseek()` past 2 GB. Since this application writes 5 GB files
by seeking to a byte offset, a 32-bit build does not fail loudly — it corrupts
files beyond the 2 GB mark. `vault:doctor` FAILs on this and will not let you
proceed.

### The PHP version constraint disagrees with itself

`composer.json` declares `"php": "^8.3"`, but
`app/Domain/Vault/Doctor/VaultDoctor.php` FAILs anything below **8.4.0**:

```php
$versionOk = version_compare(PHP_VERSION, '8.4.0', '>=');
```

`CLAUDE.md`'s phase log also records 8.4 as the target. **Treat 8.4 as the real
minimum.** Composer will happily install on 8.3 and then the doctor will refuse
to pass — this is a known inconsistency, listed in the gap report.

---

## 1. Clone and install dependencies

```powershell
git clone <repository-url> onda-storage
cd C:\Herd\onda-storage

composer install
npm install
```

**Verify**

```powershell
Test-Path vendor\autoload.php    # True
Test-Path node_modules           # True
```

---

## 2. Herd: link the site and pin the PHP version

Herd serves any directory under its configured paths as `<folder>.test`.

> **Check whether you have the `herd` CLI before using it.**
>
> ```powershell
> Get-Command herd -ErrorAction SilentlyContinue
> ```
>
> Herd can be installed with or without a CLI shim on `PATH`. On the machine
> this guide was walked on, Herd is present as the desktop app only
> (`C:\Program Files\Herd\Herd.exe`) and **every `herd ...` command in this
> document returns "command not found"** — while the site itself is served
> perfectly well.
>
> If the command is missing, do the same things through the Herd GUI:
>
> | CLI | GUI equivalent |
> |---|---|
> | `herd link <folder>` | **Sites → Add site**, pointing at the project directory |
> | `herd links` | the **Sites** list |
> | `herd isolate 8.4` | the per-site **PHP version** dropdown in the Sites list |
> | `herd restart` | **Stop** then **Start** from the Herd menu-bar icon |
>
> The verification commands below use `php artisan` and `curl`, which work
> either way.

```powershell
herd link onda-storage
herd links
```

**Verify the site answers**, regardless of how you linked it:

```powershell
curl.exe -s -o NUL -w "%{http_code}`n" http://onda-storage.test/login
```

`200` means Herd is serving the project.

### Pin the PHP version per site

This is the trap that cost this project two sessions: **Herd can serve a site on
a different PHP version than the one your CLI runs.** The symptom is 500 errors
in the browser while every command-line check passes, and it looks exactly like
a routing bug.

```powershell
herd isolate 8.4
herd restart
```

(Or set the version in the Sites list and restart Herd from its icon.)

**Verify — the two versions must match**

```powershell
php -v                                          # CLI
php artisan vault:doctor --fpm | Select-String "PHP version"
```

The `--fpm` flag makes the doctor call the site over HTTP and report the web
server's PHP alongside the CLI's. If the two columns differ, the isolation did
not take effect.

---

## 3. Environment file

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

`APP_KEY` matters more here than in a typical Laravel application: each file's
data encryption key is stored in `media_files.dek_wrapped`, which is encrypted
by the vault master key **and** by Laravel's `encrypted` cast, which uses
`APP_KEY`. Losing either one loses the files. See
[storage.md](storage.md#the-two-keys).

Set `APP_URL` to the address Herd serves. `.env.example` ships
`http://localhost:8000`, which matches `php artisan serve`, not Herd:

```dotenv
APP_URL=http://onda-storage.test
```

Every key is documented in [configuration.md](configuration.md).

---

## 4. MySQL

Herd's free tier bundles **no database**. You need one from somewhere else.

`CLAUDE.md` records that MySQL runs in WSL2 on the original developer's
machine, reached at `127.0.0.1:3306`. On the machine these docs were written
on, MySQL is provided by a Laragon install
(`C:\laragon\bin\mysql\mysql-8.0.30-winx64`). **Either works.** What the
application requires is only that a MySQL 8 server answers on the host and port
in `.env`.

### Option A — MySQL in WSL2

```powershell
wsl --install -d Ubuntu     # if WSL2 is not already present
wsl
```

Inside the WSL2 shell:

```bash
sudo apt update && sudo apt install -y mysql-server
sudo service mysql start

# Bind to all interfaces so Windows can reach it.
sudo sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf
sudo service mysql restart

sudo mysql -e "CREATE DATABASE onda_storage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'admin123';"
sudo mysql -e "CREATE USER 'root'@'%' IDENTIFIED BY 'admin123'; GRANT ALL ON *.* TO 'root'@'%'; FLUSH PRIVILEGES;"
```

> WSL2 forwards `localhost` to Windows automatically on recent builds, so
> `127.0.0.1:3306` from Windows reaches MySQL in WSL2. If it does not, get the
> WSL IP with `wsl hostname -I` and put that in `DB_HOST` instead — but note it
> changes on reboot.

### Option B — a native Windows MySQL (Laragon, XAMPP, the MySQL installer)

Start the service, then create the database:

```powershell
mysql -u root -p -e "CREATE DATABASE onda_storage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Either way

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=onda_storage
DB_USERNAME=root
DB_PASSWORD=<your password>
```

> `.env.example` ships `DB_PASSWORD=admin123`. That is a placeholder committed
> to the repository — change it, and never use it anywhere reachable.

**Verify**

```powershell
php artisan db:show
```

---

## 5. Redis — optional, and it must stay that way

Redis is a **deliberate non-dependency**. `VaultDoctor` reports it as WARN when
absent and says so explicitly: *"This is fine — Redis is optional by design."*
Nothing in the application requires it locally.

Use these drivers locally, all of which are already the `.env.example` defaults:

| Key | Local value | Why |
|---|---|---|
| `QUEUE_CONNECTION` | `database` | The queue lives in the `jobs` table; no broker needed. |
| `CACHE_STORE` | `database` | Also backs the deduplication lock (`Cache::lock()`). |
| `SESSION_DRIVER` | `database` | Survives restarts; no extra service. |

If you want Redis anyway (it is only worth it in production, where
`CHUNK_TRACKER_DRIVER=redis` reduces write pressure during uploads), install it
in WSL2:

```bash
sudo apt install -y redis-server
sudo service redis-server start
redis-cli ping        # PONG
```

**Verify from the Windows side**

```powershell
php artisan vault:doctor | Select-String "Redis"
```

`PASS … available` means PHP reached it. `WARN … unavailable` is a correct and
acceptable local result — do not "fix" it by making Redis mandatory.

---

## 6. php.ini, and the SAPI trap

PHP reads a configuration file per **SAPI** — the command line and the web
server each get their own. Editing one does not affect the other. A CLI check
can report every limit correct while the web server still rejects chunk uploads
with HTTP 413.

### Find the file each SAPI actually loaded

```powershell
php --ini
php artisan vault:doctor --fpm | Select-String "Loaded php.ini"
```

The `--fpm` row is the one that governs uploads.

> **On the machine these docs were written on, both SAPIs load the same file**
> (`C:\Users\<user>\.config\herd\bin\php84\php.ini`) — and `max_execution_time`
> and `max_input_time` **still differ** between them (`0`/`-1` on CLI versus
> `300`/`60` on FPM), because Herd applies pool-level overrides on top of the
> ini. The lesson is unchanged and slightly stronger than "two files": never
> infer the web server's effective limits from the CLI's. Read them from
> `vault:doctor --fpm`, which marks SAPI-sensitive rows `[sapi]` and flags
> differing values with `*`.

### The values, and why each is what it is

| Setting | Value | Reason |
|---|---|---|
| `post_max_size` | `32M` | A chunk is 8 MiB. 32M leaves headroom without hiding mistakes. **Deliberately low** — a generous limit lets a single-request upload work locally and fail only after deploy. |
| `upload_max_filesize` | `16M` | Same reasoning. Chunk bodies are raw `application/octet-stream`, not multipart, so this is a backstop rather than the operative limit. |
| `memory_limit` | `256M` | The pipeline streams; it never loads a file whole. If 256M is not enough, something is reading a whole file into memory and that is the bug. |
| `max_execution_time` | `120` | No HTTP request in this application does heavy work — that is what the queue is for. |
| `session.gc_maxlifetime` | `≥ 14400` | A 5 GB upload can outlive a default 24-minute session, and losing the session mid-upload fails every remaining chunk. |

Edit the file `php --ini` named, then restart Herd (CLI, or Stop/Start from
the Herd icon — see [step 2](#2-herd-link-the-site-and-pin-the-php-version)):

```powershell
herd restart
```

**Verify**

```powershell
php artisan vault:doctor --fpm | Select-String "post_max_size|upload_max|memory_limit|max_execution"
```

### What the doctor actually enforces

Only `post_max_size` is range-checked (WARN below 32M, WARN above 256M). The
other four are reported as informational values, in both SAPIs, and never
influence the pass/fail count.

That means the table above is **guidance, not enforcement**. On the machine
these docs were written on the live values are `upload_max_filesize=20M` and
`memory_limit=20000M` — the latter roughly eighty times the documented figure —
and the doctor reports PASS regardless. A memory limit that high defeats the
purpose of having one: a regression that buffers a 5 GB file would run to
completion locally and fall over in production. Bring the values in line with
the table unless you have a specific reason not to.

---

## 7. ffmpeg

Used to probe media metadata (duration, dimensions) and to generate posters,
previews and waveforms.

```powershell
winget install Gyan.FFmpeg
```

**Verify**

```powershell
ffmpeg -version
ffprobe -version
```

### The part people miss

A binary on **your** PATH is not necessarily on the PATH that PHP-FPM or the
queue worker inherits. A freshly `winget`-installed ffmpeg is frequently absent
from both until the terminal — sometimes the session — is restarted.

The durable answer is absolute paths in `.env`:

```dotenv
FFMPEG_BINARY=C:/Users/<you>/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_.../bin/ffmpeg.exe
FFPROBE_BINARY=C:/Users/<you>/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_.../bin/ffprobe.exe
```

Find the real paths:

```powershell
(Get-Command ffmpeg).Source
(Get-Command ffprobe).Source
```

`config/vault.php` says the same thing in a comment, and the reason generalises:
on cPanel the binary is often at `/usr/local/bin/ffmpeg` with a PATH that PHP
never sees. Absolute paths work identically on both platforms.

Note that `.env.example` ships the bare values `FFMPEG_BINARY=ffmpeg` and
`FFPROBE_BINARY=ffprobe`, which rely on PATH resolution — the thing the comment
directly above them warns against. Set absolute paths yourself.

If you would rather not install ffmpeg at all:

```dotenv
MEDIA_PROBE_DRIVER=null
```

Uploads still work; files simply carry no duration or dimensions.

---

## 8. Storage directories

Four directories, all **outside** the project. `VaultDoctor` FAILs any disk
whose root resolves inside `base_path()`.

```powershell
New-Item -ItemType Directory -Force -Path C:\onda-storage\vault
New-Item -ItemType Directory -Force -Path C:\onda-storage\incoming
New-Item -ItemType Directory -Force -Path C:\onda-storage\work
New-Item -ItemType Directory -Force -Path C:\onda-storage\variants
```

```dotenv
VAULT_DISK_ROOT=C:/onda-storage/vault
INCOMING_DISK_ROOT=C:/onda-storage/incoming
WORK_DISK_ROOT=C:/onda-storage/work
VARIANTS_DISK_ROOT=C:/onda-storage/variants
```

Use **forward slashes** even on Windows — `CLAUDE.md` requires it, and it
avoids escaping problems in `.env` parsing.

### Rules, and the reason for each

| Rule | Reason |
|---|---|
| Outside the project directory | A disk root inside the repo risks being served, committed, or wiped by a deploy. `vault:doctor` FAILs it. |
| All four on **one partition** | Completing an upload is a `rename()` from `incoming` to `vault`. Across partitions `rename()` silently degrades to a full copy: minutes, and double the space, for a 5 GB file. The doctor FAILs a split. |
| Prefer a non-system drive | Keeps deposit growth away from the drive Windows needs to stay healthy. The doctor WARNs when the root is on `%SystemDrive%`. |
| Never inside OneDrive | Sync can rewrite or lock a file mid-write. The doctor WARNs when it sees `onedrive` in the path. |
| Excluded from Windows Defender | Real-time scanning inspects every chunk write and throttles uploads measurably. |

### Exclude from Defender

Run PowerShell **as Administrator**:

```powershell
Add-MpPreference -ExclusionPath "C:\onda-storage"
```

**Verify**

```powershell
(Get-MpPreference).ExclusionPath
```

> `Get-MpPreference` fails with `Operation failed with the following error:
> 0x%1!x!` on machines where Defender is disabled, replaced by third-party
> antivirus, or managed by group policy — this happened on the machine this
> guide was walked on. If you see that error, Defender is not the component
> scanning your files; add the equivalent exclusion in whichever antivirus is
> actually running, or skip this step.

**Verify the directories**

```powershell
php artisan vault:doctor | Select-String "Disk '"
```

Expect `PASS` for exists / writable / outside repo / offset write-read proof on
all four, plus `PASS` on *All vault disks share one partition*. The offset proof
is worth understanding: it truncates a 64 MiB file, seeks to 32 MiB, writes a
random megabyte, reads it back and compares. That is the exact mechanism every
chunk upload uses, tested directly.

---

## 9. The vault master key

> ### Read this before generating anything
>
> The master key (KEK) wraps every per-file data key. **If this file is lost,
> every stored file is permanently unrecoverable.** There is no recovery
> procedure, no vendor to call, and no way to derive it from the data — that is
> the design, not an oversight.
>
> Equally important: **a backup that contains both the key and the encrypted
> data provides no protection at all.** Anyone holding that backup holds the
> plaintext. The key and the data must live in separate backup sets with
> separate access.
>
> **Two** secrets must be kept, not one: the vault master key *and* `APP_KEY`.
> `media_files.dek_wrapped` is encrypted by both.

Create it outside the repository and outside every backup path:

```powershell
New-Item -ItemType Directory -Force -Path C:\onda-secrets

$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
[System.IO.File]::WriteAllBytes("C:\onda-secrets\vault-master.key", $bytes)
```

```dotenv
VAULT_MASTER_KEY_PATH=C:/onda-secrets/vault-master.key
```

Restrict access to your user only:

```powershell
icacls "C:\onda-secrets\vault-master.key" /inheritance:r /grant:r "$($env:USERNAME):(R)"
```

**Verify**

```powershell
php artisan vault:doctor | Select-String "Master key|VAULT_MASTER_KEY_PATH"
```

Expect PASS on: path set, file exists, readable, **at least 32 bytes**, outside
repo. The doctor deliberately prints `hidden` rather than the path for the
outside-repo check.

> On Linux the doctor also checks permissions and WARNs on anything looser than
> `0600`. On Windows that check does not run — `icacls` is your responsibility.

---

## 10. Database migration and seed

```powershell
php artisan migrate --seed
```

35 migrations run. The seeders produce:

| Seeder | Produces |
|---|---|
| `CountrySeeder`, `WilayaSeeder`, `CommuneSeeder` | Algeria geography, from JSON in `public/assets/seeders/` |
| `MembershipTypeSeeder` | The classification reference data: 4 declarant types, 3 gestion labels, 22 collèges, 100 qualités, author roles |
| `CollegeOeuvreFileSeeder` | 69 required-document definitions across the 22 collèges |
| `RoleAndUserSeeder` | The `admin` and `author` roles, plus three accounts |
| `VaultDemoSeeder` | Demo authors and works with storage quotas — no media files |

Seeded accounts, all with the password `password`:

| Email | Role |
|---|---|
| `admin@gmail.com` | admin |
| `author1@onda.dz` | author |
| `author2@onda.dz` | author |
| `test@example.com` | author |

These are development fixtures. Do not seed them into anything reachable.

**Verify**

```powershell
php artisan migrate:status
php artisan db:show --counts
```

---

## 11. Build the frontend

```powershell
npm run build
```

**Verify**

```powershell
Test-Path public\build\manifest.json     # True
```

---

## 12. Run it — three processes

| Process | Command | What breaks without it |
|---|---|---|
| Web server | Herd, automatic | Nothing serves. |
| Vite | `npm run dev` | Laravel falls back to the last `public/build`. Your source changes are invisible and the app *looks* fine. |
| Queue worker | `php artisan queue:work --queue=media,default` | Uploads complete but stay at `scanning` forever. No hash, no scan, no variants. |

```powershell
# terminal 1
npm run dev

# terminal 2
php artisan queue:work --queue=media,default
```

### Herd does not manage the queue worker

There is no supervisor. If you close the terminal, the pipeline stops, silently.
`vault:doctor` checks for a running `queue:work` process and WARNs when it finds
none.

### Why `--queue=media,default` and not plain `queue:work`

Heavy work (hashing, scanning, ffmpeg) is dispatched to the `media` queue so it
cannot block notifications on `default`. A worker started without the flag
listens only to `default` and will never pick up a single pipeline job.

This is worth stating plainly because **`composer run dev` gets it wrong**: its
queue process is `php artisan queue:listen --tries=1 --timeout=0`, with no
`--queue` argument, so the `media` queue is never drained.

### Do not use `composer run dev` for upload work

`CLAUDE.md` calls it "the primary local dev command", and for pure frontend work
it is fine. For anything touching uploads it is actively misleading, for two
reasons:

1. It starts `php artisan serve`, which is **single-process on Windows**. The
   uploader issues up to three concurrent chunk requests per file; they queue
   behind one another, throughput collapses, and the chunked architecture looks
   broken when it is working exactly as designed.
2. Its queue listener never drains the `media` queue, as above.

Use Herd for the web server and start the two other processes yourself.

---

## 13. Final verification

```powershell
php artisan vault:doctor
php artisan vault:doctor --fpm
```

**The bar is zero FAILs.** WARNs are expected locally. A healthy local run on
the reference machine reports `PASS: 48  WARN: 7  FAIL: 0`, with the WARNs being:

| WARN | Why it is fine locally |
|---|---|
| `Binary: clamdscan — not found` | ClamAV is not practical on Windows. `SCAN_DRIVER=null` is correct here. |
| `Disk '<name>' system drive check` (×4) | The reference machine keeps storage on `C:`. Move it to a data drive if you have one. |
| `Queue worker running — unknown` | Detection uses `wmic`, which is deprecated and absent on some Windows 11 builds. Confirm by looking at your terminal. |
| `Redis — unavailable` | Optional by design. |

Finally, confirm the application itself answers:

```powershell
php artisan test --compact
```

Then open `http://onda-storage.test` and sign in as `author1@onda.dz` /
`password`.

---

## Where to go next

- [configuration.md](configuration.md) — what every `.env` key does
- [storage.md](storage.md) — where the bytes live and how to back them up
- [troubleshooting.md](troubleshooting.md) — when one of the above goes wrong
