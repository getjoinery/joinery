# Docker Concepts: Images, Layers, Containers, and Volumes

This document explains how Docker works — theoretically and concretely, using the joinery-base migration as a running example throughout.

---

## The Core Mental Model

Docker separates two things that are normally fused together on a regular server:

- **What the software is** (the filesystem snapshot that makes the application run) → this is an **image**
- **What is happening right now** (a running process using that snapshot) → this is a **container**
- **What data persists** (files that outlive any particular container) → these are **volumes**

On a normal server, these three things are completely intertwined. The application code, the running process, and the database files all live together in `/var/www/html/` and `/var/lib/postgresql/`. You can't replace the application code without stopping the database, and if you wipe the directory you lose everything.

Docker keeps them separate on purpose. You can throw away a container and rebuild it from scratch, and the database doesn't care because it was never inside the container to begin with.

---

## Images

An image is a read-only filesystem snapshot. It's not running — it's more like a template or a blueprint. Think of it the way you'd think of a VM disk image before you boot it: it has all the files in place, but nothing is executing.

When you run `docker build`, Docker reads a `Dockerfile` and creates an image. The image contains:
- An operating system base (Ubuntu 24.04 in our case)
- Installed packages (PHP, Apache, PostgreSQL, Composer)
- Application code (`public_html/`, `maintenance_scripts/`, etc.)
- Configuration baked in at build time (the Apache VirtualHost for the site)

What an image does **not** contain:
- Any running processes
- Any database data (that's in a volume)
- Any user uploads
- Any runtime state

**In our migration:**
Before this work, each site had its own image built `FROM ubuntu:24.04`. The images looked like:

```
joinery-scrolldaddy:latest   — Ubuntu + PHP + Apache + Postgres + scrolldaddy code   2.8 GB
joinery-getjoinery:latest    — Ubuntu + PHP + Apache + Postgres + getjoinery code     2.8 GB
joinery-phillyzouk:latest    — Ubuntu + PHP + Apache + Postgres + phillyzouk code     2.8 GB
```

Eight sites × 2.8 GB = ~22 GB. Each image was completely independent, even though the first ~2.3 GB (OS + packages) was logically identical across all of them.

---

## Layers

This is the key concept that makes Docker storage efficient — and also the source of the "layers" terminology.

An image is not a single monolithic blob. It is a **stack of layers**, where each layer represents a change to the filesystem. Layers are created by instructions in the Dockerfile: each `RUN`, `COPY`, and `ADD` instruction produces a new layer on top of the previous one.

Each layer stores only the diff — the files that were added, modified, or deleted relative to the layer below it.

```
Layer 4:  COPY public_html/ ...     [adds site-specific PHP files]
Layer 3:  RUN composer install      [adds vendor/ directory]
Layer 2:  RUN install.sh server     [adds PHP, Apache, PostgreSQL, all packages]
Layer 1:  FROM ubuntu:24.04         [base OS filesystem]
```

**The critical insight:** layers are identified by a content hash. If two images share an identical layer — same content, same hash — Docker stores that layer **once on disk**, regardless of how many images reference it.

Before the migration, even though all 8 site images started from the same `ubuntu:24.04` base, the layer containing PHP/Apache/PostgreSQL was built separately for each site inside a separate `docker build` invocation. Each build produced a layer with a different hash (because build timestamps, apt package versions, and minor differences crept in). Docker saw 8 different layers and stored 8 copies.

```
Before migration — layers on disk:
  [ubuntu:24.04 base]            × 1  (actually shared because it's the same pull)
  [PHP+Apache+Postgres install]  × 8  (each built independently = 8 separate copies ~2.3 GB each)
  [site code]                    × 8  (each unique anyway)
```

After building `joinery-base:1.0`, all 8 site images share **the exact same layer** for the PHP/Apache/PostgreSQL stack. Docker stores it once.

```
After migration — layers on disk:
  [ubuntu:24.04 base]            × 1
  [PHP+Apache+Postgres install]  × 1  (joinery-base:1.0 — shared by all 8 sites)
  [site code]                    × 8  (each unique, ~200–300 MB each)
```

**Concrete numbers from our build:**
- `joinery-base:1.0` — 386 MB (OS + all system packages)
- `joinery-joinerydemo:latest` — reported as 2.46 GB total, but the 386 MB base is already on disk from `joinery-base`. The actual new storage for the site layer is the difference.
- The `:prev` images (old builds) — 2.8 GB each, fully independent = ~22 GB total
- After all `:prev` images are deleted: `docker system df` will show ~6 GB instead of ~22 GB

---

## Containers

A container is a **running instance of an image**. When you run `docker run joinery-scrolldaddy`, Docker:

1. Takes the image (the read-only layer stack)
2. Adds a thin **writable layer** on top — this is the container's own private scratch space
3. Starts the process defined in the image's `CMD`

The writable layer captures everything the running process writes to the filesystem that isn't redirected to a volume: log entries, temp files, anything the application touches in-place. This layer exists only for the lifetime of the container.

```
┌─────────────────────────────────┐
│  Writable layer (container)     │  ← exists only while container exists
├─────────────────────────────────┤
│  Layer: site code (image)       │  ← read-only
├─────────────────────────────────┤
│  Layer: joinery-base:1.0        │  ← read-only, shared
├─────────────────────────────────┤
│  Layer: ubuntu:24.04            │  ← read-only, shared
└─────────────────────────────────┘
```

**`docker stop` and `docker start`:** The container and its writable layer are paused/resumed. No data is lost.

**`docker rm`:** The container and its writable layer are deleted. The image layers underneath are untouched. Named volumes (see below) are untouched. This is like unbooting a VM — the disk image survives.

**`docker rmi`:** The image itself is deleted. If no containers are using it, the image layers are removed from disk (unless another image shares those layers, in which case only the reference is removed — the shared layers stay).

**In our migration:**
For each site, we ran:
```bash
docker stop scrolldaddy    # stop the running process
docker rm scrolldaddy      # delete the container + its writable layer
```
This is completely safe. It's equivalent to shutting down and unregistering a VM. The data (database, uploads) lives in volumes, not in the container's writable layer.

---

## Volumes

Named volumes are the answer to: "where does data live if containers are ephemeral?"

A named volume is a directory on the Docker host that Docker manages. It exists completely independently of any container. When you start a container with `-v scrolldaddy_postgres:/var/lib/postgresql`, Docker mounts that host directory at `/var/lib/postgresql` inside the container. PostgreSQL writes its data files there.

When the container is removed, the volume stays. When a new container starts with the same `-v scrolldaddy_postgres:/var/lib/postgresql`, PostgreSQL finds its data files exactly where it left them. From PostgreSQL's perspective, nothing happened.

**Our volumes per site:**
```
scrolldaddy_postgres    → /var/lib/postgresql           (database files)
scrolldaddy_config      → /var/www/html/scrolldaddy/config    (Globalvars_site.php)
scrolldaddy_uploads     → /var/www/html/scrolldaddy/uploads   (user-uploaded files)
scrolldaddy_static      → /var/www/html/scrolldaddy/static_files
scrolldaddy_logs        → /var/www/html/scrolldaddy/logs
scrolldaddy_backups     → /var/www/html/scrolldaddy/backups
scrolldaddy_cache       → /var/www/html/scrolldaddy/public_html/cache
scrolldaddy_sessions    → /var/www/html/scrolldaddy/public_html/sessions
scrolldaddy_apache_logs → /var/log/apache2
scrolldaddy_pg_logs     → /var/log/postgresql
```

Everything that needs to survive a container rebuild lives in a volume. Everything that can be rebuilt from source (application code, themes, plugins) lives in the image.

**Why the database survived the migration:**
When we ran `docker rm scrolldaddy`, Docker deleted the container. The `scrolldaddy_postgres` volume was untouched. When `install.sh site` ran and started the new container, it included `-v scrolldaddy_postgres:/var/lib/postgresql`. PostgreSQL started, looked at `/var/lib/postgresql`, found a fully-initialized database cluster with all the site's data, and simply started serving it. No restore, no migration, no data movement happened.

**Why the data loss was catastrophic:**
The `-y` flag in `install.sh` called:
```bash
docker volume rm scrolldaddy_postgres
docker volume rm scrolldaddy_uploads
docker volume rm scrolldaddy_config
# ... and 7 more
```
`docker volume rm` permanently deletes the directory and all its contents. Unlike `docker rm`, there is no recovery path. The database files were gone. This is why the fix was to split `-y` (remove container) from `--wipe-data` (also remove volumes) — these are fundamentally different operations with very different consequences.

---

## The `FROM` Instruction and Layer Sharing

`FROM` in a Dockerfile says: "start my layer stack from this existing image." Everything in the referenced image becomes the base layers, and subsequent instructions add layers on top.

**Before: `FROM ubuntu:24.04`**
```dockerfile
FROM ubuntu:24.04                        # Layer 1: base OS
RUN install.sh server                    # Layer 2: PHP + Apache + Postgres (2.3 GB)
COPY scrolldaddy/ /var/www/html/...      # Layer 3: site code
```
Each site ran `install.sh server` independently inside its own `docker build`, producing a unique layer 2 with a unique hash. 8 unique copies stored on disk.

**After: `FROM joinery-base:${BASE_IMAGE_VERSION}`**
```dockerfile
# Dockerfile.base (built once):
FROM ubuntu:24.04
RUN install.sh server --skip-postgres-password   # produces joinery-base:1.0

# Dockerfile.template (built per site):
FROM joinery-base:1.0                    # reuses joinery-base:1.0's layers
COPY scrolldaddy/ /var/www/html/...      # Layer: site code only
```

Because all site images now reference `joinery-base:1.0` in their `FROM`, they all point to the same layer stack. Docker's content-addressable storage sees the same hash and serves it from a single copy on disk.

---

## The `:prev` Tag

Docker image tags are just labels pointing to a specific image hash. The same image can have multiple tags.

```bash
docker tag joinery-scrolldaddy joinery-scrolldaddy:prev
```
This doesn't copy the image. It creates a second pointer (`joinery-scrolldaddy:prev`) to the same image content that `joinery-scrolldaddy:latest` was pointing to.

```bash
docker rmi joinery-scrolldaddy
```
This removes only the `:latest` tag. The image content stays on disk as long as `:prev` still points to it.

**Why we did this:**
Before rebuilding each site, we tagged the existing image as `:prev`. If the rebuilt container had failed (HTTP 500, database connection error, etc.), we could have rolled back instantly:

```bash
docker stop scrolldaddy
docker rm scrolldaddy
docker run -d --name scrolldaddy ... joinery-scrolldaddy:prev
```
No rebuild required — just start a container from the old image with the same volumes attached. The site would be back in seconds.

The `:prev` images are what `docker system df` showed as ~28 GB reclaimable. Once you're confident in the new images, `docker rmi joinery-SITENAME:prev` removes that old image, and Docker reclaims the disk space for any layers not referenced by anything else.

---

## The Build Context

When you run `docker build`, Docker sends a directory (the "build context") to the Docker daemon. The Dockerfile's `COPY` instructions can only reference files within this context.

`install.sh` creates a temporary build directory (`~/joinery-docker-build-SITENAME/`) structured like this:

```
joinery-docker-build-scrolldaddy/
├── Dockerfile                    (copied from Dockerfile.template)
├── .dockerignore
└── scrolldaddy/
    ├── public_html/              (application code + themes + plugins)
    │   ├── data/
    │   ├── includes/
    │   ├── theme/                (downloaded fresh from joinerytest.site)
    │   ├── plugins/              (downloaded fresh from joinerytest.site)
    │   └── ...
    ├── config/                   (Globalvars_site.php template)
    └── maintenance_scripts/
        └── install_tools/
            ├── _sync_stock_assets.sh
            ├── Dockerfile.template
            └── ...
```

The Dockerfile's `COPY scrolldaddy/ /var/www/html/scrolldaddy/` bakes this directory into the image layer. This is application code that is safe to bake in — it's stateless and can always be rebuilt. Data (the database, uploads) is never in the build context because it lives in volumes.

---

## Container Startup vs. Image Build

A common source of confusion: some things happen at **build time** (when `docker build` runs) and some things happen at **runtime** (when the container starts). These are very different moments.

**Build time** (`RUN` instructions in Dockerfile):
- Install Composer dependencies (`composer install`)
- Configure Apache VirtualHost
- Enable Apache site

**Runtime** (`CMD` at container start):
- Start PostgreSQL
- Set postgres password (first run only, if `Globalvars_site.php` doesn't exist)
- Run `_site_init.sh` (first run only — creates database, configures site)
- Run `update_database.php` (applies schema migrations)
- Run `_sync_stock_assets.sh` (download any missing stock plugins/themes)
- Start cron
- Start Apache in the foreground

**Why this matters:**
The sync script (`_sync_stock_assets.sh`) runs at startup, not at build time. This means even if the image was built without the `scrolldaddy` plugin, by the time Apache starts serving its first request, the plugin is already in place. The startup sequence is:

```
PostgreSQL up → DB initialized → plugins synced → Apache starts → requests served
```

If `_sync_stock_assets.sh` ran at build time instead, it couldn't read `upgrade_source` from the database (because PostgreSQL hasn't started yet and the database may not exist). Running it at startup, after the database is ready, is what makes the pattern work.

---

## Summary: What Changed in the Migration

| | Before | After |
|---|---|---|
| Base for each image | `FROM ubuntu:24.04` | `FROM joinery-base:1.0` |
| PHP/Apache/Postgres layers | 8 independent copies (~2.3 GB × 8) | 1 shared copy (386 MB) |
| Total image storage | ~22 GB | ~6 GB (after `:prev` cleanup) |
| Build time for new site | ~10 minutes (installs packages) | ~1–2 minutes (packages already in base) |
| Missing plugins on rebuild | 500 error until manually fixed | `_sync_stock_assets.sh` fixes at startup |
| Stale core files on rebuild | Silently used old code | `download_core_archive` pulls fresh (now fixed) |
| `-y` flag behavior | Deleted container + volumes | Deletes container only; `--wipe-data` required for volumes |

The database contents were never part of this story. They lived in named volumes throughout — before, during, and after. The migration only ever touched the image layers.
