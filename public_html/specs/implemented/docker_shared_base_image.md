# Docker Shared Base Image

**Status:** Implemented 2026-04-23 — all 8 docker-prod sites migrated to shared base (joinery-base:1.0), stock asset sync via _reconcile_stock_assets.sh, upgrade 0.8.23 → 0.8.24 applied. Net disk savings ~17GB.  
**Context:** docker-prod currently stores ~2.3 GB of OS/PHP/Apache layers independently inside each of 8 site images, totalling ~22 GB for image storage. All sites run identical system stacks. Splitting the build into a shared base image and thin per-site images reduces image storage to ~6 GB and makes new site installs much faster.

---

## Problem

Each site image is built with `FROM ubuntu:24.04` and runs `install.sh server` independently:

```
joinery-getjoinery:     [ubuntu][apt packages][php][apache][postgres][site code]  2.8 GB
joinery-scrolldaddy:    [ubuntu][apt packages][php][apache][postgres][site code]  2.8 GB
joinery-mapsofwisdom:   [ubuntu][apt packages][php][apache][postgres][site code]  2.8 GB
... × 8 sites
```

Even though layers 1–5 (OS + system packages) are conceptually identical, they were built in separate `docker build` invocations and are stored as independent copies in containerd. The ~2.3 GB runtime cost is paid 8 times.

**Current image storage:** ~22 GB  
**Target after this change:** ~6 GB (one 2.3 GB base + eight ~500 MB site layers)  
**Savings per new site added:** ~2.3 GB instead of ~2.8 GB

> **Note:** The ~500 MB per-site layer figure is an estimate (`public_html` + `maintenance_scripts` + vendor + static files). Validate empirically on one migrated site before committing to the "22 GB → 6 GB" headline number. If actual size is ~800 MB per site, savings are still substantial but the framing should be updated.

---

## Solution: `Dockerfile.base` + Thin `Dockerfile.template`

Split the build into two stages:

1. **`Dockerfile.base`** — builds `joinery-base:VERSION` once. Contains everything from `FROM ubuntu:24.04` through `install.sh server`. No site code, no site-specific config.
2. **`Dockerfile.template`** — changed to `FROM joinery-base:VERSION`. Only adds site code and the per-site Apache VirtualHost. Builds in seconds instead of minutes.

All 8 site images share the exact same base layer hash, so containerd stores it once.

---

## File Changes

### New file: `Dockerfile.base`

Located in `maintenance_scripts/install_tools/Dockerfile.base`.

```dockerfile
# Joinery Base Image
# VERSION 1.0
#
# Builds the shared OS/runtime layer used by all site images.
# Contains: Ubuntu 24.04 + Apache + PHP 8.3 + PostgreSQL + Composer + Cron
# Does NOT contain site code or site-specific config.
#
# Build:
#   ./install.sh build-base
# Or manually (from a prepared context containing install.sh):
#   docker build -f Dockerfile.base -t joinery-base:1.0 .
#
# After building, all `install.sh site` runs use this image automatically.

FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# Copy only the install script — site code is NOT included in the base.
# Build context is the prepared install_tools/ directory (see do_build_base),
# so install.sh is at the context root.
COPY install.sh /tmp/install.sh

# Install all system dependencies.
# --skip-postgres-password tells install.sh server not to set a postgres user
# password during the base build — that is a per-site concern and is handled
# by _site_init.sh at first container run. This avoids baking any credential
# (not even a placeholder) into the shared base image.
RUN chmod +x /tmp/install.sh && \
    /tmp/install.sh server --skip-postgres-password && \
    rm /tmp/install.sh

# Label the image with a hash of the install.sh server code path so
# do_site_docker can detect drift between the base and the current install.sh.
# (See "Drift detection" in the install.sh section.)
ARG INSTALL_SH_HASH=unknown
LABEL joinery.install_sh_hash="${INSTALL_SH_HASH}"

EXPOSE 80 5432
```

**Notes on this Dockerfile:**

- **Build context:** `Dockerfile.base` uses a minimal build context (just the prepared `install_tools/` directory containing `install.sh`) — no site code is present. The `do_build_base` function in `install.sh` creates this context.
- **Cron:** `cron` is added to the package list inside `install.sh server` (see install.sh changes below), not as a separate RUN. A once-built base image does not benefit from separating the layer for cache purposes, and folding it in means one less surface to keep in sync.
- **No placeholder password:** Earlier drafts of this spec set `ENV POSTGRES_PASSWORD=placeholder` so the interactive prompt in `do_server_setup` would be bypassed. Instead we add a `--skip-postgres-password` flag that skips the password-setting block entirely. No credential (real or dummy) is baked into the shared base.

### Modified file: `Dockerfile.template`

Changes `FROM ubuntu:24.04` to `FROM joinery-base:${BASE_IMAGE_VERSION}` and removes the `install.sh server` and cron install steps (they now come from the base). Everything else stays the same.

Key diff:

```diff
+ARG BASE_IMAGE_VERSION
-FROM ubuntu:24.04
+FROM joinery-base:${BASE_IMAGE_VERSION}
 
 ARG SITENAME=dockertest
 ARG POSTGRES_PASSWORD
 ARG DOMAIN_NAME=localhost
 
 ENV DEBIAN_FRONTEND=noninteractive
 ENV SITENAME=${SITENAME}
 ENV POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
 ENV DOMAIN_NAME=${DOMAIN_NAME}
 
 COPY ${SITENAME}/ /var/www/html/${SITENAME}/
 COPY maintenance_scripts/ /var/www/html/${SITENAME}/maintenance_scripts/
 
-RUN chmod +x /var/www/html/${SITENAME}/maintenance_scripts/install_tools/*.sh && \
-    cd /var/www/html/${SITENAME}/maintenance_scripts/install_tools && \
-    ./install.sh server
-
-RUN apt-get update -qq && apt-get install -y -qq cron > /dev/null 2>&1
-
 RUN cp /var/www/html/${SITENAME}/maintenance_scripts/install_tools/default_virtualhost.conf ...
```

`BASE_IMAGE_VERSION` is supplied as a build arg by `do_site_docker` (Docker natively supports `ARG` before the first `FROM`). This is the idiomatic mechanism and avoids mutating the Dockerfile with `sed` before each build — `Dockerfile.template` remains a valid, unmodified file in source control.

### Modified file: `install.sh`

Seven changes:

**1. Add `BASE_IMAGE_VERSION` constant** near the top of the file:

```bash
BASE_IMAGE_VERSION="1.0"   # Bump when Dockerfile.base or do_server_setup changes
```

**2. Add `cron` to the package list in `do_server_setup`.** Currently `cron` is installed as its own RUN in `Dockerfile.template`. Fold it into the existing package install in `do_server_setup` (around line 1107) so the base image picks it up automatically:

```diff
-    apt install -y curl wget git unzip rsync software-properties-common ...
+    apt install -y curl wget git unzip rsync software-properties-common \
+                   cron ...
```

**3. Add a `--skip-postgres-password` flag to `do_server_setup`.** The block that sets the postgres user password (lines ~1262-1280) is per-site state, not per-image state. `_site_init.sh` already performs the same trust→ALTER→md5 dance at first container run, so it is redundant during base image build. Parse the flag near the top of `do_server_setup`:

```bash
local SKIP_POSTGRES_PASSWORD=0
for arg in "$@"; do
    case "$arg" in
        --skip-postgres-password) SKIP_POSTGRES_PASSWORD=1 ;;
    esac
done
```

Then guard the existing password block:

```bash
if [ "$SKIP_POSTGRES_PASSWORD" -eq 0 ]; then
    # existing trust/ALTER/md5 block
fi
```

This also lets us drop the "prompt for password" interactive block when the flag is set — base builds never prompt.

**4. Add a `build-base` subcommand** (`do_build_base` function):

```bash
do_build_base() {
    print_header "Building Joinery Base Image"

    # Build context: just install.sh at context root (matches Dockerfile.base's
    # `COPY install.sh /tmp/install.sh`)
    BUILD_DIR=$(mktemp -d)
    mkdir -p "$BUILD_DIR/install_tools"
    cp "$SCRIPT_DIR/install.sh" "$BUILD_DIR/install_tools/install.sh"
    cp "$SCRIPT_DIR/Dockerfile.base" "$BUILD_DIR/Dockerfile.base"

    # Hash the do_server_setup function body so we can label the image and
    # detect drift later. Anything simpler (e.g., whole-file hash) produces
    # too many false positives when unrelated functions change.
    INSTALL_SH_HASH=$(awk '/^do_server_setup\(\) \{/,/^\}/' \
        "$SCRIPT_DIR/install.sh" | sha256sum | cut -c1-16)

    print_step "Building joinery-base:${BASE_IMAGE_VERSION} (takes 5-10 minutes)..."

    docker build \
        -f "$BUILD_DIR/Dockerfile.base" \
        --build-arg "INSTALL_SH_HASH=${INSTALL_SH_HASH}" \
        -t "joinery-base:${BASE_IMAGE_VERSION}" \
        -t "joinery-base:latest" \
        "$BUILD_DIR/install_tools"

    rm -rf "$BUILD_DIR"

    if [ $? -eq 0 ]; then
        print_success "joinery-base:${BASE_IMAGE_VERSION} built successfully"
        print_info "install.sh hash: ${INSTALL_SH_HASH}"
        print_info "Run 'install.sh site SITENAME ...' to create a site using this base"
    else
        print_error "Base image build failed"
        exit 1
    fi
}
```

**5. Modify `do_site_docker`** to require the base image, check for drift, and pass `BASE_IMAGE_VERSION` as a build-arg.

Add the existence + drift check near the top of `do_site_docker`, before "Prepare build context":

```bash
# Require base image
print_step "Checking for joinery-base:${BASE_IMAGE_VERSION}..."
if ! docker image inspect "joinery-base:${BASE_IMAGE_VERSION}" > /dev/null 2>&1; then
    print_error "joinery-base:${BASE_IMAGE_VERSION} not found."
    print_info "Build it first with:  ./install.sh build-base"
    exit 1
fi
print_success "joinery-base:${BASE_IMAGE_VERSION} found"

# Drift detection: warn (do not fail) if the current install.sh do_server_setup
# differs from the hash baked into the base image. A mismatch means someone
# edited install.sh without bumping BASE_IMAGE_VERSION and rebuilding the base.
CURRENT_HASH=$(awk '/^do_server_setup\(\) \{/,/^\}/' \
    "$SCRIPT_DIR/install.sh" | sha256sum | cut -c1-16)
BASE_HASH=$(docker image inspect "joinery-base:${BASE_IMAGE_VERSION}" \
    --format '{{ index .Config.Labels "joinery.install_sh_hash" }}' 2>/dev/null)
if [ -n "$BASE_HASH" ] && [ "$CURRENT_HASH" != "$BASE_HASH" ]; then
    print_warning "install.sh do_server_setup has changed since joinery-base was built"
    print_warning "  base image hash:  ${BASE_HASH}"
    print_warning "  current hash:     ${CURRENT_HASH}"
    print_warning "  If system packages or PHP extensions changed, bump BASE_IMAGE_VERSION"
    print_warning "  and rebuild:  ./install.sh build-base"
fi
```

Pass the version through as a build arg (no `sed` substitution needed — `Dockerfile.template` uses `ARG BASE_IMAGE_VERSION` before `FROM`):

```bash
docker build \
    --build-arg BASE_IMAGE_VERSION="$BASE_IMAGE_VERSION" \
    --build-arg SITENAME="$SITENAME" \
    --build-arg POSTGRES_PASSWORD="$POSTGRES_PASSWORD" \
    --build-arg DOMAIN_NAME="$DOMAIN_NAME" \
    -t "joinery-$SITENAME" .
```

**6. Add `build-base` to the command dispatch** at the bottom of `install.sh`:

```bash
build-base)
    shift
    do_build_base "$@"
    ;;
```

**7. Bump install.sh VERSION** to 2.13.

---

## Bumping `BASE_IMAGE_VERSION`

Bump `BASE_IMAGE_VERSION` in `install.sh` when:
- Ubuntu base version changes
- PHP major/minor version changes
- New system packages are added to `install.sh server`
- Any other change to `Dockerfile.base`

After bumping: run `install.sh build-base` on the target host to build the new base. Existing containers keep running on their old base version until they are rebuilt. New sites use the new base automatically.

---

## Migration: Existing Containers on docker-prod

Existing running containers do not need to be rebuilt immediately — they continue running on their current per-site images unchanged. The disk savings only materialise when images are rebuilt.

To migrate an existing site to the shared base:

```bash
# 1. Build the base image (once per host)
./install.sh build-base

# 2. For each site (example: getjoinery):
#    Stop and rename the current image so rollback is trivial.
docker stop getjoinery
docker rm getjoinery
docker tag joinery-getjoinery joinery-getjoinery:prev
docker rmi joinery-getjoinery   # removes only the un-prefixed tag; :prev remains

# 3. Rebuild using the current upgrade archive
./install.sh site getjoinery getjoinery.com PORT --clone-from=... --clone-key=...
# or restore from backup if not using clone

# 4. Verify the site starts cleanly
docker logs getjoinery
curl -I https://getjoinery.com
```

The data volumes (`getjoinery_postgres`, `getjoinery_uploads`, etc.) survive container removal and are reattached automatically. Only the image layer is replaced.

### Rollback

If the rebuilt site fails health checks, roll back to the tagged previous image with the same volumes:

```bash
docker stop getjoinery
docker rm getjoinery
docker run -d \
    --name getjoinery \
    --restart unless-stopped \
    -p PORT:80 -p DB_PORT:5432 \
    -v getjoinery_postgres:/var/lib/postgresql \
    -v getjoinery_uploads:/var/www/html/getjoinery/uploads \
    -v getjoinery_config:/var/www/html/getjoinery/config \
    -v getjoinery_backups:/var/www/html/getjoinery/backups \
    -v getjoinery_static:/var/www/html/getjoinery/static_files \
    -v getjoinery_logs:/var/www/html/getjoinery/logs \
    -v getjoinery_cache:/var/www/html/getjoinery/cache \
    -v getjoinery_sessions:/var/lib/php/sessions \
    -v getjoinery_apache_logs:/var/log/apache2 \
    -v getjoinery_pg_logs:/var/log/postgresql \
    joinery-getjoinery:prev
```

Keep `:prev` tagged until at least one full operational cycle has passed (a scheduled-task run, a user login, a deploy) so late-discovered regressions still have a path back. Once the new image is trusted, `docker rmi joinery-getjoinery:prev` reclaims the space.

**Order of operations for docker-prod:** Build base → migrate one test site → verify → migrate the rest. No need to do all at once.

### ⚠️ CRITICAL: Never Use `-y` on a Production Site

The `-y` / `--assume-yes` flag tells `install.sh site` to **delete the existing container AND all its data volumes** without prompting. This is correct for fresh installs. It is catastrophic on running production sites.

**`-y` is only for fresh installs on empty machines. Never pass it when a named container already exists with live data.**

For migrating a production site to a new image, always do the steps manually (stop → rm → tag :prev → rmi → install.sh without -y) so volumes are never touched.

```bash
# ✅ CORRECT: image-only rebuild, data volumes preserved
docker stop getjoinery
docker rm getjoinery            # removes container only; volumes survive
docker tag joinery-getjoinery joinery-getjoinery:prev
docker rmi joinery-getjoinery
./install.sh site getjoinery getjoinery.com PORT --no-ssl
# Docker reattaches existing volumes automatically on next run

# ❌ WRONG: wipes all production data
./install.sh site getjoinery getjoinery.com PORT -y --no-ssl
```

### Testing Changes Before Production

When validating new container startup behaviour (e.g. the plugin sync step), always test against a throwaway site, not a production container:

```bash
# Use joinerydemo — it is the designated test container
docker stop joinerydemo && docker rm joinerydemo
docker rmi joinery-joinerydemo
./install.sh site joinerydemo localhost 8099 -y --no-ssl
docker logs joinerydemo | grep '\[sync\]'
```

`-y` is safe here because joinerydemo has no irreplaceable data.

---

## Operational Notes

### Upgrade-flow interaction

This is the most important behavioural change for operators to internalise:

- **Code/theme/plugin changes** (PHP files under `public_html/`, migrations, settings) — deliver via the existing publish/upgrade pipeline (`php plugins/server_manager/includes/publish_upgrade.php` + `php utils/upgrade.php` on the remote). **No base image work required.** Nothing changes here.
- **System stack changes** (new apt package, new PHP extension, Ubuntu version bump, PHP version bump, anything in `do_server_setup`) — now require **base rebuild + container rebuild**, not just `upgrade.php`. `upgrade.php` cannot modify a running container's system packages; it only refreshes the application layer. Operators must:
  1. Bump `BASE_IMAGE_VERSION` in `install.sh`
  2. Run `./install.sh build-base` on the host
  3. Rebuild each site container (same steps as the migration section above)

The drift-detection warning in `do_site_docker` (see install.sh change #5) will fire whenever a site rebuild happens against an out-of-date base, which is the hint that a rebuild is needed.

This split should be called out in `docs/deploy_and_upgrade.md` as part of this work.

---

## Testing

1. `install.sh build-base` completes without error
2. `docker image ls | grep joinery-base` shows the image at ~2.3 GB
3. `install.sh site testsite localhost 8099` does **not** re-run `install.sh server` (verify by grepping build output for "Installing PHP 8.3" — it should be absent). On a warm host this typically completes in well under a minute, but the behavioural test is "no system-package install," not a wall-clock number.
4. `docker image ls | grep testsite` shows the site image at roughly ~500 MB (validate empirically — see Problem section note)
5. `docker image history joinery-testsite` shows `joinery-base:1.0` as the first layer
6. `docker image inspect joinery-base:1.0 --format '{{ index .Config.Labels "joinery.install_sh_hash" }}'` returns a 16-char hash (confirms drift-detection label is in place)
7. Editing `do_server_setup` without bumping `BASE_IMAGE_VERSION` produces the expected drift warning on the next `install.sh site` run
8. Site boots and passes the upgrade check: `php utils/upgrade.php --verbose`
9. After migrating all sites: `docker system df` shows images at roughly ~6 GB total (validate against the empirical per-site figure from step 4)

---

## Docs Update

Update `docs/deploy_and_upgrade.md` to cover:

- The two-step build process: `install.sh build-base` once per host, then `install.sh site ...` per site.
- The `BASE_IMAGE_VERSION` bump procedure.
- The **upgrade-flow split** (from the Operational Notes section above): code/theme/plugin changes still flow through `publish_upgrade.php` + `upgrade.php`; system-stack changes now require a base rebuild and container rebuild. This is the behavioural change most likely to trip up an operator who remembers the old "just run upgrade.php" model.

---

## Plugin / Theme Sync at Container Startup

### Problem

The Docker image bakes in only the plugins and themes present in the archive at build time plus whatever `download_themes_and_plugins` retrieves as "system" plugins (those with `is_system: true`). Site-specific plugins — like `scrolldaddy`, which serves as the active theme for scrolldaddy.app — are `is_stock: true` but `is_system: false`, so they are never downloaded during the build.

When a container is rebuilt (e.g., during base-image migration), the old container's writable layer is discarded. If the active plugin-theme is not in the new image, PathHelper throws on every request before Apache can serve anything — resulting in an immediate 500.

The general principle: the Docker build process has no way to know which site-specific plugins a given container needs. That information lives in the running site's database, which doesn't exist yet at build time.

### Solution

Add a plugin/theme sync step to the container startup CMD that runs **after PostgreSQL is up but before Apache starts**. This step:

1. Reads `upgrade_source` from the `stg_settings` table (already populated by `_site_init.sh`).
2. Fetches the stock plugin and theme lists from `${upgrade_source}/utils/publish_theme?list=plugins` and `?list=themes`.
3. For each `is_stock: true` item, downloads and installs it if the directory does not already exist under `public_html/plugins/` or `public_html/theme/`.
4. Falls back gracefully if the upgrade server is unreachable — logs a warning and continues. Apache starts regardless; a missing plugin will cause errors, but blocking the container from starting would be worse.

The download logic mirrors what `download_themes_and_plugins` does in `install.sh`, but runs inside the container at startup rather than on the host at build time.

### Implementation

**New file: `maintenance_scripts/install_tools/_reconcile_stock_assets.sh`**

A small standalone script (not install.sh) that performs the sync. Kept separate so the CMD line stays readable and the logic is testable independently.

```bash
#!/bin/bash
# Sync stock themes and plugins from the upgrade server.
# Called at container startup before Apache starts.
# Exits 0 always — a failed sync is non-fatal.

UPGRADE_SOURCE=$(psql -U postgres -d "${SITENAME}" -t -A \
    -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'upgrade_source'" \
    2>/dev/null | tr -d '[:space:]')

if [ -z "$UPGRADE_SOURCE" ]; then
    echo "[sync] upgrade_source not set — skipping stock asset sync"
    exit 0
fi

PUBLIC_HTML="/var/www/html/${SITENAME}/public_html"

sync_items() {
    local type="$1"       # "plugins" or "themes"
    local target_dir="$2" # absolute path to plugins/ or theme/

    local list_json
    list_json=$(curl -sf --max-time 20 "${UPGRADE_SOURCE}/utils/publish_theme?list=${type}" 2>/dev/null)
    if [ -z "$list_json" ]; then
        echo "[sync] Could not fetch ${type} list from ${UPGRADE_SOURCE} — skipping"
        return
    fi

    echo "$list_json" | grep -oP '"name"\s*:\s*"\K[^"]+' | while read -r name; do
        # Only download is_stock items
        if ! echo "$list_json" | grep -A10 "\"name\".*\"${name}\"" | grep -q '"is_stock"\s*:\s*true'; then
            continue
        fi
        if [ -d "${target_dir}/${name}" ]; then
            continue  # already present
        fi

        echo "[sync] Downloading missing ${type%s}: ${name}"
        local archive
        archive=$(curl -sf --max-time 20 \
            "${UPGRADE_SOURCE}/admin/server_manager/publish_theme?download=${name}&type=${type%s}" \
            2>/dev/null | grep -oP '"url"\s*:\s*"\K[^"]+' | head -1)

        if [ -z "$archive" ]; then
            echo "[sync] Could not get download URL for ${name} — skipping"
            continue
        fi

        local tmp
        tmp=$(mktemp /tmp/joinery-asset-XXXXXX.tar.gz)
        if curl -sf --max-time 120 -o "$tmp" "$archive" 2>/dev/null; then
            tar -xzf "$tmp" -C "$target_dir/" 2>/dev/null && \
                echo "[sync] Installed ${name}" || \
                echo "[sync] Extract failed for ${name}"
        else
            echo "[sync] Download failed for ${name}"
        fi
        rm -f "$tmp"
    done
}

sync_items "plugins" "${PUBLIC_HTML}/plugins"
sync_items "themes"  "${PUBLIC_HTML}/theme"
```

**Modified file: `Dockerfile.template` CMD**

Add the sync call after `_site_init.sh` / `update_database` and before `apache2ctl`:

```bash
# Sync any missing stock plugins/themes before Apache starts
PGPASSWORD="${POSTGRES_PASSWORD}" bash \
    /var/www/html/${SITENAME}/maintenance_scripts/install_tools/_reconcile_stock_assets.sh \
    || true && \
```

The `|| true` ensures a sync failure never prevents the container from starting.

**Modified file: `install.sh`**

- Copy `_reconcile_stock_assets.sh` into the build context alongside the other `install_tools/` files (it's already in `COPY maintenance_scripts/` so no Dockerfile change needed beyond the CMD addition).
- Bump VERSION to 2.15.

### Behaviour

| Scenario | Result |
|---|---|
| Fresh install, upgrade server reachable | All stock plugins/themes present before first request |
| Rebuild of existing site | Active plugin-theme downloaded on startup; 500 avoided |
| Upgrade server unreachable at startup | Warning logged; Apache starts anyway; missing plugins cause errors until server is reachable |
| Plugin already in image | Directory exists → skipped (no duplicate download) |
| Clone from another site | Runs same sync; clone source is the upgrade_source | 

### Testing

1. Build a site image that has `scrolldaddy` as the active plugin-theme but does not include the scrolldaddy plugin directory in the image (verify with `docker run --rm joinery-scrolldaddy ls /var/www/html/scrolldaddy/public_html/plugins/`).
2. Start the container; check `docker logs` for `[sync] Installed scrolldaddy`.
3. Verify `curl -sI https://scrolldaddy.app/` returns HTTP 200.
4. Restart the container; confirm sync step runs and is a no-op (directory already exists).
5. Test with upgrade server unreachable: container must still start and Apache must serve requests.

---

## Postmortem: scrolldaddy.app Data Loss (2026-04-22)

### What Happened

During implementation of the plugin startup sync feature, the running `scrolldaddy` container was used as a test target to validate the full build→startup flow. The rebuild command included the `-y` flag:

```bash
./install.sh site scrolldaddy scrolldaddy.app 8087 -y --no-ssl
```

The `-y` flag triggered automatic removal of the existing container **and all its named data volumes** (`scrolldaddy_postgres`, `scrolldaddy_uploads`, `scrolldaddy_config`, `scrolldaddy_backups`, and six others). A fresh install ran in their place, destroying all production data: user accounts, device configurations, subscriptions, DNS block schedules, and uploads.

The site was restored from a host-level backup. The box was rolled back to the state before the migration session began.

### Root Causes

**1. Wrong tool for the job.** The `-y` flag exists to automate fresh installs on clean machines. It is explicitly unsafe on production containers because `install.sh site` has only one code path when it finds an existing container — wipe it. There is no "rebuild image only, keep data" mode invoked by any flag. The operator (Claude) did not check what `-y` does to existing containers before using it.

**2. Testing on production.** The plugin sync script had already been validated correctly against the running container using `docker exec`. A full container rebuild was not required to validate that the script ran at startup — checking `docker logs` on the already-running container after copying the script in was sufficient. The decision to do a full rebuild, and the choice to do it against the live site rather than `joinerydemo`, were both errors in judgement.

**3. No safeguard in install.sh against volume deletion on named containers.** The `-y` flag deletes volumes unconditionally. There is no check like "this container has a postgres volume with data — are you sure you want to destroy it?" Volume deletion is irreversible and should require stronger confirmation than a general-purpose `-y` flag.

### Fixes Being Implemented

**1. `install.sh` safety guard for volume deletion (install.sh v2.16)**

Split the meaning of `-y` for Docker mode: when an existing container is found, `-y` will stop and remove the container (safe — volumes survive `docker rm`) but will **not** delete data volumes without an explicit `--wipe-data` flag. Volume deletion in unattended mode now requires both `-y --wipe-data`.

```bash
# New behaviour:
./install.sh site mysite example.com 8080 -y           # stops/removes container, reuses volumes
./install.sh site mysite example.com 8080 -y --wipe-data  # full wipe (fresh install)
```

**2. Spec updated** with explicit warnings in the Migration section about `-y` and a testing procedure that uses `joinerydemo` as the designated throwaway container.

**3. `joinerydemo` designated as the canonical test container** — it has no irreplaceable data and exists for exactly this purpose.

### What Not to Do

- **Never run `./install.sh site SITENAME ... -y` on a container that has live user data.** The `-y` flag will delete the database, uploads, and config with no confirmation and no recovery path.
- **Never test container startup behaviour against a production site.** Copy the script into the running container with `docker cp` and test with `docker exec`, or use `joinerydemo` for full rebuild tests.
- **Never assume `:prev` image is a recovery path for data.** `:prev` is an image tag, not a volume snapshot. It contains only the application layer baked at build time. Postgres data, uploads, and config all live in volumes and are not in the image.

---

## Future Consideration: Option B — Single Runtime Image

Option A still bakes site code into each image. A further step (Option B) would remove site code from images entirely: one `joinery-runtime` image shared by all containers, with site code mounted as a volume or injected at container startup via the upgrade mechanism.

**Result:** All 8+ containers share a single ~2.8 GB image. Adding a new site costs zero additional image storage, only volume storage (~500 MB uploads/DB). Upgrades become: rebuild one image → rolling restart of all containers.

**Trade-off:** The container startup sequence becomes more complex — it must pull or mount the correct site version before Apache starts. The current `_site_init.sh` / `upgrade.php` flow would need to run as a pre-start step rather than being baked in. Option A is a cleaner intermediate step that delivers most of the savings while keeping the current startup model intact.
