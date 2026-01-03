# Docker Setup Attempt Report - January 3, 2026

## Summary

Attempted to set up Joinery on a fresh Ubuntu 24.04 server (23.239.11.53) using the `docker_minimal_setup.md` specification. The Docker build failed due to environment detection issues in `server_setup.sh`.

## Steps Completed Successfully

1. Removed stale SSH host key from known_hosts
2. Connected to fresh server (Ubuntu 24.04.3 LTS)
3. Installed Docker CE (version 29.1.3)
4. Verified Docker with `hello-world` container
5. Uploaded joinery-2-18.tar.gz (262MB) to server
6. Extracted archive and organized into Docker build structure
7. Created Dockerfile and .dockerignore per specification

## Problem Encountered

### Docker Build Failure

**Error Location:** `server_setup.sh` during the Dockerfile RUN step

**Error Message:**
```
System has not been booted with systemd as init system (PID 1). Can't operate.
Failed to connect to bus: Host is down
```

**Root Cause:** The `is_docker()` function in `server_setup.sh` (lines 43-45) checks for Docker environment:

```bash
is_docker() {
    [ -f /.dockerenv ] || grep -q docker /proc/1/cgroup 2>/dev/null
}
```

**Problem:** During `docker build`, neither condition is true:
- `/.dockerenv` file does **not exist** during build (only in running containers)
- `/proc/1/cgroup` does **not contain** "docker" during build phase

Because the script doesn't detect Docker, it uses `systemctl` commands instead of `service` commands, which fail because systemd isn't running during Docker build.

---

## Suggested Fixes

### Option 1: Modify Dockerfile (Minimal Change) - RECOMMENDED FOR QUICK FIX

Add `touch /.dockerenv` before running server_setup.sh:

```dockerfile
RUN touch /.dockerenv && \
    chmod +x /var/www/html/${SITENAME}/maintenance_scripts/*.sh && \
    cd /var/www/html/${SITENAME}/maintenance_scripts && \
    ./server_setup.sh
```

**Pros:** No script changes needed, follows spec principle of "no script modifications"
**Cons:** Workaround rather than proper fix

### Option 2: Improve Docker Detection in server_setup.sh - RECOMMENDED PERMANENT FIX

Update the `is_docker()` function to also detect Docker build environment:

```bash
is_docker() {
    # Check for running container
    [ -f /.dockerenv ] && return 0

    # Check cgroup for running container
    grep -q docker /proc/1/cgroup 2>/dev/null && return 0

    # Check for Docker build environment (no init system)
    [ ! -d /run/systemd/system ] && return 0

    return 1
}
```

**Pros:** Proper fix that handles both build and runtime
**Cons:** Requires modifying maintenance script

### Option 3: Add Environment Variable Check

Add an environment variable that can be set in Dockerfile:

```bash
# In server_setup.sh
is_docker() {
    [ "$DOCKER_BUILD" = "1" ] || [ -f /.dockerenv ] || grep -q docker /proc/1/cgroup 2>/dev/null
}
```

```dockerfile
# In Dockerfile
ENV DOCKER_BUILD=1
```

**Pros:** Explicit control, clear intent
**Cons:** Requires script modification

---

## Recommendation

**Option 1 (Dockerfile workaround)** is the quickest path to get Docker working without modifying the maintenance scripts. Update the Dockerfile in `docker_minimal_setup.md` to include `touch /.dockerenv`.

**Option 2 (improved detection)** should be implemented as a permanent fix in `server_setup.sh` since the current detection is incomplete for Docker build scenarios.

---

## Current State on Remote Server

Location: `root@23.239.11.53:/root/joinery-docker-build/`

```
joinery-docker-build/
├── Dockerfile           # Created per spec
├── .dockerignore        # Created per spec
├── maintenance_scripts/ # Copied from archive
│   ├── server_setup.sh
│   ├── new_account.sh
│   ├── joinery-install.sql.gz
│   └── ... (other scripts)
└── joinery/             # Site directory
    ├── config/
    ├── public_html/
    ├── uploads/
    ├── logs/
    ├── cache/
    ├── backups/
    └── static_files/
```

**Server Credentials:**
- IP: 23.239.11.53
- User: root
- Password: abc14#AA44sr

**Docker Status:** Installed and verified working

---

## Next Steps After Fix Applied

1. Choose a fix approach
2. Apply the fix (update Dockerfile or server_setup.sh)
3. Rebuild Docker image: `docker build -t joinery-joinery .`
4. Run container with persistent volumes
5. Verify application functionality at http://23.239.11.53:8080

---

## Related Files

- `/var/www/html/joinerytest/public_html/specs/docker_minimal_setup.md` - Main Docker specification
- `/var/www/html/joinerytest/maintenance_scripts/server_setup.sh` - Server setup script with Docker detection issue
