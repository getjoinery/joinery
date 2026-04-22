# Docker-Prod Disk Space Management

**Status:** Spec — awaiting item-by-item review  
**Context:** docker-prod (23.239.11.53) reached 73% disk usage (54GB / 79GB). This spec covers root causes, immediate fixes, and permanent install script changes for each contributing factor.

---

## Item 1: Docker Build Cache

**Current cost:** ~11.6GB reclaimable  
**Category:** Docker host configuration  
**Status:** ✅ Complete

### Root Cause
Docker BuildKit caches every layer from every image build and never auto-prunes. All 8 site images were built in January–February 2026, and intermediate cache layers accumulated since. Docker has no built-in GC policy unless explicitly configured.

### Why `defaultKeepStorage: 0`
docker-prod is a production server. Images are built once when a new site is set up; after that, code updates go through the upgrade system (`deploy.sh` / `upgrade.php`) and never touch the Docker image. There is no ongoing value in retaining build cache, so it is set to zero — BuildKit will GC all cache after each build, keeping disk usage permanently at the image layer floor.

### What Was Applied

**Immediate:** Ran `docker builder prune -f` — cleared 11.6GB of orphaned build cache. The remaining ~16GB shown in `docker system df` is image layer data backing running containers and cannot be pruned without removing images.

**Permanent:** Wrote `/etc/docker/daemon.json` on docker-prod:
```json
{
  "builder": {
    "gc": {
      "enabled": true,
      "defaultKeepStorage": "0"
    }
  }
}
```
Then `systemctl reload docker`.

### Install Script Change — ✅ Done
`host-harden` subcommand writes this `daemon.json` during host provisioning. `defaultKeepStorage` set to `"0"`. Synced to `/root/install_tools/install.sh` on docker-prod.

---

## Item 2: Systemd Journal

**Current cost:** ~2.4GB (2.2GB recoverable)  
**Category:** Host OS configuration  
**Status:** ✅ Complete

### Root Cause
Ubuntu ships `journald.conf` fully commented-out with no `SystemMaxUse` limit. The journal grew unchecked since January 23, 2026, inflated heavily by SSH brute force attempts (189,692 failed logins). With password auth now disabled (Item 4), that source is gone.

### Why 100MB
The journal is one shared log for the entire host — not per-site. Application logs live inside the containers. Host-level activity (Docker daemon events, SSH logins, fail2ban bans, kernel messages) generates roughly 1-3MB/day on a quiet Docker host. 100MB holds several months of genuine system events. 200MB was sized for a server under brute force attack; with that resolved, 100MB is appropriate.

### What Was Applied
Wrote `/etc/systemd/journald.conf.d/size-limit.conf` on docker-prod:
```ini
[Journal]
SystemMaxUse=100M
```
Restarted `systemd-journald` — journald auto-vacuumed on restart, shrinking from 2.4GB to 75.9MB immediately. 2.3GB freed.

### Install Script Change — ✅ Done
`host-harden` subcommand writes this drop-in config during host provisioning. Synced to `/root/install_tools/install.sh` on docker-prod.

---

## Item 3: Orphaned /root Source Directories

**Current cost:** ~670MB orphaned  
**Category:** Install script cleanup  
**Status:** ✅ Complete

### Root Cause
Three joinery source copies existed in `/root/`:
- `/root/joinery/` — the source archive the original install was run from. Expected — ARCHIVE_ROOT resolves here. Kept.
- `/root/joinery-source/` — a second copy with no script origin. Created manually during initial server setup, never cleaned up.
- `/root/joinery-docker-build-joineryclone/` — a leftover build context from an interrupted install attempt. The install script's trap handler should have removed it but didn't.

### What Was Applied
Deleted the two orphaned copies on docker-prod (672MB freed):
```bash
rm -rf /root/joinery-source
rm -rf /root/joinery-docker-build-joineryclone
```

### Install Script Changes — ✅ Done

**Orphaned build dir scan in `do_site_docker`:** Added a check at the start of the Docker build flow that scans for any `~/joinery-docker-build-*` directories not matching the current SITENAME. Prints each with its size and prompts to delete (auto-deletes in `-y` mode).

**Source archive reminder at completion:** After a successful Docker site install, the completion summary now prints the path and size of the source archive (`ARCHIVE_ROOT`) and reminds the operator that it can be deleted once all sites are installed.

---

## Item 4: SSH Brute Force — btmp Logs + Security Gap

**Current cost:** 208MB (btmp logs) + ongoing security risk  
**Category:** Host security — critical  
**Status:** ✅ Complete

### Root Cause
docker-prod had `PasswordAuthentication yes` and no fail2ban. 189,692 failed SSH login attempts from ~20 known botnet IP ranges had accumulated since January, generating 208MB of btmp log data with no rate limiting.

Root cause of the gap: `install.sh` correctly puts SSH hardening and fail2ban in the `else` branch of `if is_docker`. But docker-prod is a Docker *host*, not a container — it was provisioned without running that code path.

Note: the DNS primary had already been hardened in a prior session. The DNS secondary had fail2ban running but `PasswordAuthentication yes` still set.

### What Was Applied to Live Servers

**docker-prod (23.239.11.53):**
- Installed fail2ban, configured SSH jail (ban 1h after 3 failures in 10m)
- Set `PasswordAuthentication no`, `PermitRootLogin prohibit-password`
- Restarted sshd, confirmed still reachable via key
- Truncated btmp and btmp.1 (208MB freed)

**DNS secondary (97.107.131.227):**
- Set `PasswordAuthentication no`, `PermitRootLogin prohibit-password`
- Restarted sshd, confirmed still reachable via key
- (fail2ban was already running)

**DNS primary (45.56.103.84):** already complete from prior session, no changes needed.

### Install Script Changes

**`do_server_setup` (bare-metal path):** password auth left enabled by default. Added commented lines in the SSH block showing how to disable it once keys are confirmed:
```bash
# To disable password auth entirely (key-based only), uncomment these lines.
# Requires SSH keys to be in place first — run 'install.sh host-harden' after keys are confirmed.
# sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
# sed -i 's/#PermitRootLogin yes/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
# sed -i 's/PermitRootLogin yes/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
```

**New `host-harden` subcommand** (`./install.sh host-harden`) added for hardening Docker host servers after initial provisioning. Covers:
- SSH key presence check before touching sshd (guards against lockout)
- Confirmation prompt before proceeding
- `PasswordAuthentication no`, `PermitRootLogin prohibit-password`, `MaxAuthTries 3`
- fail2ban install + SSH jail (ban 1h after 3 failures in 10m)
- journald drop-in: `SystemMaxUse=200M`, `MaxRetentionSec=2weeks` (covers Item 2)
- Docker BuildKit GC policy: `defaultKeepStorage=2GB` in `daemon.json` (covers Item 1)
- Orphaned `joinery-docker-build-*` directory scan with prompt to delete (covers Item 3)
- btmp log truncation

Synced to `/root/install_tools/install.sh` on docker-prod.

**New server provisioning workflow:**
```bash
sudo ./install.sh docker
sudo ./install.sh host-harden   # run once keys are in place
sudo ./install.sh site SITENAME ...
```

---

## Item 5: Application Error Logs

**Current cost:** ~1.4GB across all 8 containers  
**Category:** Application logging + logrotate config  
**Status:** ✅ Complete

### Root Cause
Two separate causes:

**A) Route debug noise flooding error.log**  
`RouteHelper.php` had ~50 bare `error_log()` calls throughout `processRoutes()` that fired on every request — logging the full `$_REQUEST` array, all route keys, every custom route pattern test, step headers, load confirmations, etc. A proper debug infrastructure (`$debug_enabled` / `debugLog()`) already existed but these calls were never wired into it. They were a development session committed and never cleaned up.

On a site with 10 custom routes, every request generated 10+ log lines just from the pattern-testing loop alone. `getjoinery` had a 240MB `error.log.1` consisting almost entirely of this output.

**B) Logrotate retention too generous**  
The logrotate config kept:
```
rotate 4
size 100M
```
This allows ~500MB of error logs per container (4 files × ~100MB each). With 8 containers, the theoretical maximum is 4GB.

### What Was Applied

**For A:** Removed all ungated `error_log()` calls from `processRoutes()` in `RouteHelper.php`. Kept only legitimate runtime logs: security blocks, 404 reasons, exception catches, config errors, and plugin namespace violations. The existing `debugLog()` system (gated by `$debug_enabled`, off by default) is sufficient for debugging when needed.

**For B:** Updated logrotate config on all 8 running containers:
```bash
for c in getjoinery phillyzouk jeremytunnell mapsofwisdom scrolldaddy galactictribune joinerydemo empoweredhealthtn; do
  docker exec $c sed -i 's/rotate 4/rotate 2/' /etc/logrotate.d/joinery-$c
  docker exec $c sed -i 's/size 100M/size 20M/' /etc/logrotate.d/joinery-$c
done
```

### Permanent Fix — ✅ Done

**RouteHelper.php** — ungated debug calls removed. The `debugLog()` system covers debug needs.

**`maintenance_scripts/install_tools/logrotate_joinery.conf`** — changed defaults to `rotate 2` / `size 20M`. This is the source template that `_site_init.sh` installs into `/etc/logrotate.d/joinery-{SITENAME}` on new container setup.

---

## Item 6: PostgreSQL WAL (all sites)

**Current cost:** ~929MB in pg_wal on ScrollDaddy alone; all sites affected  
**Category:** PostgreSQL configuration  
**Status:** ✅ Applied to all 8 containers on docker-prod; install script updated

### Root Cause
`max_wal_size = 1GB` is the PostgreSQL default and was explicitly set in every container's `postgresql.conf`. PostgreSQL pre-allocates and recycles WAL segment files up to this limit, resulting in ~944MB of WAL files per container regardless of actual write volume.

For all sites on docker-prod, actual WAL usage was measured on ScrollDaddy (the highest-write site due to blocklist imports): **23,575 timed checkpoints vs 22 WAL-triggered checkpoints** over 83 days (99.9% timer-driven). Normal WAL generation is ~1.5MB per 5-minute checkpoint interval. The 1GB ceiling never fires in steady state — it only briefly fired during the initial blocklist import.

64MB was chosen because:
- It is 40x the typical per-checkpoint WAL volume, so the timer always fires first in normal operation
- It is large enough that moderate write spikes (including blocklist re-imports) don't cause excessive checkpoint churn
- There are no replicas, no archiving, and no replication slots — nothing requires retaining more WAL

### Fix Applied
```bash
# Applied to all 8 containers:
for c in scrolldaddy getjoinery phillyzouk jeremytunnell mapsofwisdom galactictribune joinerydemo empoweredhealthtn; do
  docker exec $c sed -i 's/max_wal_size = 1GB/max_wal_size = 64MB/' /etc/postgresql/16/main/postgresql.conf
  docker exec $c service postgresql reload
done
```
pg_wal will shrink to ~64MB per container at the next checkpoint cycle (~5 minutes).

### Install Script Change (`install.sh`, PostgreSQL configuration block) — ✅ Done
Added after the existing `listen_addresses` / `port` sed commands:
```bash
sed -i "s/#max_wal_size = 1GB/max_wal_size = 64MB/" ${PG_CONFIG_DIR}/postgresql.conf
sed -i "s/max_wal_size = 1GB/max_wal_size = 64MB/" ${PG_CONFIG_DIR}/postgresql.conf
```
Applied to both the local repo (`maintenance_scripts/install_tools/install.sh`) and the copy on docker-prod (`/root/install_tools/install.sh`).

---

## Summary

| # | Item | Recoverable Now | Status | Permanent Fix Location |
|---|------|-----------------|--------|----------------------|
| 1 | Docker build cache | ~11.6GB | ✅ Complete | `daemon.json` GC policy via `host-harden` |
| 2 | Systemd journal | ~2.2GB | ✅ Complete | journald drop-in via `host-harden` |
| 3 | Orphaned /root dirs | ~670MB | ✅ Complete | Orphaned dir scan + archive reminder in `install.sh` |
| 4 | SSH brute force / btmp | ~208MB + security | ✅ Complete | `host-harden` subcommand added to `install.sh` |
| 5 | App error logs | ~1GB | ✅ Complete | `RouteHelper.php` cleanup + logrotate defaults |
| 6 | PostgreSQL WAL (all sites) | ~800MB+ | ✅ Complete | `install.sh` PostgreSQL config block |
| **Total** | | **~16.5GB** | | |
