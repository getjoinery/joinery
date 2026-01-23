# Install Script Refactoring Specification

## Overview

Refactor `install.sh` and `new_account.sh` so that:
1. `install.sh` becomes a "run once" script for initial server/Docker setup only
2. `new_account.sh` handles all site creation, including code deployment, for both Docker and bare-metal environments

## Current State Analysis

### install.sh (v1.2) - Current Responsibilities

| Subcommand | Purpose | One-time? |
|------------|---------|-----------|
| `docker` | Install Docker on server | Yes |
| `server` | Set up bare-metal server (Apache, PHP, PostgreSQL, security) | Yes |
| `site` | Create a new Joinery site | No (per-site) |
| `list` | List existing sites | No (utility) |

**Site creation in Docker mode (`do_site_docker`):**
- Verifies archive structure (needs `public_html/`, `config/`)
- Checks port availability
- Copies `public_html/` and `config/` to build context
- Builds Docker image using `Dockerfile.template`
- Runs container with volume mounts
- Container's CMD calls `new_account.sh` on first run

**Site creation in bare-metal mode (`do_site_baremetal`):**
- Sets password in `default_Globalvars_site.php`
- Calls `new_account.sh`
- Does NOT copy application code (gap!)

### new_account.sh (v2.14) - Current Responsibilities

1. Creates directory structure (`/var/www/html/{site}/`)
2. Copies config templates only (`Globalvars_site.php`, `serve.php`)
3. Creates PostgreSQL database
4. Loads database restore file (`joinery-install.sql.gz`)
5. Runs Composer install
6. Optionally activates a theme
7. Creates Apache virtualhost
8. Reloads Apache

**Critical Gap:** Does NOT copy application code (`public_html/` contents)

### Current Multi-Site Workflow

**Docker:**
```bash
# First site
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools
./install.sh docker           # One-time
./install.sh site site1 Pass1! site1.com 8080

# Second site - REQUIRES keeping/re-extracting archive
./install.sh site site2 Pass2! site2.com 8081
```

**Bare-metal:**
```bash
# First site
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools
./install.sh server           # One-time
./install.sh site site1 Pass1! site1.com

# Second site - BROKEN (no code deployment)
# Would need manual: cp -r public_html /var/www/html/site2/
./install.sh site site2 Pass2! site2.com  # Fails - no code
```

## Proposed Architecture

### install.sh - One-Time Setup Only

**Remove:** `site` subcommand
**Keep:** `docker`, `server`, `list`

```bash
# Usage after refactoring
./install.sh docker    # One-time: install Docker
./install.sh server    # One-time: set up bare-metal server
./install.sh list      # Utility: list existing sites
```

### new_account.sh - Complete Site Creation

**New responsibilities:**
1. Auto-detect environment (Docker available vs bare-metal)
2. Locate source files (archive location)
3. Copy ALL application code (not just config templates)
4. Handle Docker container creation (moved from install.sh)
5. Handle bare-metal site creation (enhanced)

```bash
# Usage after refactoring
./new_account.sh site_name password domain [port] [options]

# Examples
./new_account.sh site1 Pass1! site1.com              # Bare-metal
./new_account.sh site1 Pass1! site1.com 8080         # Docker (port implies Docker)
./new_account.sh site1 Pass1! site1.com --docker     # Force Docker
./new_account.sh site1 Pass1! site1.com --bare-metal # Force bare-metal
```

## Detailed Design

### new_account.sh Parameters

```bash
./new_account.sh SITENAME PASSWORD DOMAIN [PORT] [OPTIONS]

Required:
  SITENAME          Site/database name
  PASSWORD          PostgreSQL password (use "-" to auto-generate)
  DOMAIN            Domain name for the site

Optional:
  PORT              Web port (Docker only, implies Docker mode)

Options:
  --docker          Force Docker mode
  --bare-metal      Force bare-metal mode
  --activate THEME  Set active theme after installation
  --with-test-site  Create companion test site (bare-metal only)
  -y, --yes         Auto-accept prompts (non-interactive)
  -q, --quiet       Suppress most output
```

### Environment Detection Logic

```
1. If --docker flag: Docker mode
2. If --bare-metal flag: Bare-metal mode
3. If PORT specified: Docker mode (port implies container)
4. If Docker available and running: Docker mode
5. Otherwise: Bare-metal mode (requires Apache/PHP/PostgreSQL)
```

### Archive Location Detection

The script needs to find the source files (`public_html/`, `config/`, etc.).

**Detection:** Relative to `SCRIPT_DIR` - the standard archive structure places `install_tools/` at `maintenance_scripts/install_tools/`, so archive root is `../../` from `SCRIPT_DIR`.

```bash
ARCHIVE_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

# Verify required directories exist
if [ ! -d "$ARCHIVE_ROOT/public_html" ]; then
    echo "ERROR: Cannot find public_html in $ARCHIVE_ROOT"
    echo "Make sure you're running from an extracted joinery archive"
    exit 1
fi
```

### Docker Mode Flow

**Host-side (new_account.sh detects it's NOT in a container):**
```
1. Verify Docker is available
2. Locate archive (source files)
3. Check port availability, suggest alternatives if conflict
4. Prepare build context:
   - Copy public_html/ from archive
   - Copy config/ from archive
   - Copy maintenance_scripts/ from archive
   - Copy Dockerfile.template
5. Build Docker image
6. Run container with volume mounts
7. Wait for container to initialize
8. Verify site is responding
9. Clean up build directory
10. Display summary
```

**Container-side (new_account.sh detects it's IN a container via Dockerfile CMD):**
```
1. Detect running inside container (/.dockerenv or cgroup check)
2. Skip Docker deployment logic
3. Create config directories, copy templates
4. Create PostgreSQL database
5. Load database restore file
6. Run Composer install
7. Activate theme if specified
8. Configure Apache virtualhost
9. Exit (Apache started by Dockerfile CMD)
```

### Bare-Metal Mode Flow

```
1. Verify prerequisites (Apache, PHP, PostgreSQL)
2. Locate archive (source files)
3. Check site doesn't already exist
4. Copy application code:
   - rsync public_html/ to /var/www/html/{site}/public_html/
   - Create config/, uploads/, logs/, etc. directories
5. Copy and configure Globalvars_site.php
6. Create PostgreSQL database
7. Load database restore file
8. Run Composer install
9. Activate theme if specified
10. Create Apache virtualhost
11. Enable site and reload Apache
12. (Optional) Create test site if --with-test-site specified
13. Verify site is responding
14. Display summary
```

### Code Deployment Details

**New function in new_account.sh:**

```bash
deploy_application_code() {
    local site_name="$1"
    local archive_root="$2"
    local site_root="/var/www/html/$site_name"

    echo "Deploying application code..."

    # Copy public_html (excluding runtime directories)
    rsync -av --exclude='.git' \
              --exclude='uploads' \
              --exclude='cache' \
              --exclude='logs' \
              --exclude='.playwright-mcp' \
              "$archive_root/public_html/" \
              "$site_root/public_html/"

    echo "Application code deployed."
}
```

## Migration Path

### Phase 1: Enhance new_account.sh
1. Add environment detection (Docker vs bare-metal)
2. Add archive location detection
3. Add `deploy_application_code()` function
4. Add Docker container creation logic (port from install.sh)
5. Add new parameters (PORT, --docker, --bare-metal, --archive)
6. Keep backward compatibility with existing parameters

### Phase 2: Simplify install.sh
1. Remove `site` subcommand
2. Update help text
3. Update INSTALL_README.md
4. Keep `docker`, `server`, `list` subcommands

### Phase 3: Update Documentation
1. Update INSTALL_README.md with new workflow
2. Update docker_install.md
3. Add migration notes for existing users

## Backward Compatibility

- `install.sh docker` - Unchanged
- `install.sh server` - Unchanged
- `install.sh list` - Unchanged
- `install.sh site` - Removed (error message points to new_account.sh)
- `new_account.sh` with old parameters - Works (bare-metal, assumes code already deployed)

## Files Affected

| File | Changes |
|------|---------|
| `new_account.sh` | Major rewrite - add Docker support, code deployment |
| `install.sh` | Remove `site` subcommand, update help |
| `Dockerfile.template` | May need updates for new new_account.sh interface |
| `INSTALL_README.md` | Update workflows and examples |
| `docker_install.md` | Update to reflect new workflow |

## Example Workflows After Refactoring

### Docker Multi-Site

```bash
# Extract archive (one time)
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools

# Install Docker (one time)
./install.sh docker

# Create sites (each call is self-contained)
./new_account.sh site1 Pass1! site1.com 8080
./new_account.sh site2 Pass2! site2.com 8081
./new_account.sh site3 Pass3! site3.com 8082

# List sites
./install.sh list
```

### Bare-Metal Multi-Site

```bash
# Extract archive (one time)
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools

# Set up server (one time)
./install.sh server

# Create sites (each call deploys code + configures)
./new_account.sh site1 Pass1! site1.com
./new_account.sh site2 Pass2! site2.com
./new_account.sh site3 Pass3! site3.com

# List sites
./install.sh list
```

## Design Decisions

### 1. Archive Persistence

**Decision:** No automatic persistence. The extracted archive location IS the source of truth.

**Rationale:**
- Simple and predictable - archive is where you extracted it
- No hidden file copying or disk space surprises
- `SCRIPT_DIR` already provides reliable path to archive root (`../../` from `install_tools/`)
- If users delete the extraction, they re-extract when needed (rare operation)
- Avoids version conflict complexity (what if `/opt/joinery/` has old version?)

**Workflow:**
```bash
# Extract once, use for all sites
tar -xzf joinery-2-31.tar.gz
cd maintenance_scripts/install_tools

# Create multiple sites - archive stays in place
./new_account.sh site1 Pass1! site1.com 8080
./new_account.sh site2 Pass2! site2.com 8081

# Months later, upgrade: extract new archive, create new sites
tar -xzf joinery-2-32.tar.gz
cd maintenance_scripts/install_tools
./new_account.sh site3 Pass3! site3.com 8082
```

**Removed from spec:** The `--install-archive` flag and `/opt/joinery/` persistent location.

### 2. Dockerfile.template Changes

**Decision:** Minimal changes. Dockerfile.template stays mostly the same.

**Rationale:**
The same `new_account.sh` script runs in two contexts:
1. **Host context:** Detects Docker, builds image, runs container
2. **Container context:** Detects it's inside Docker, does DB/config setup only

**Detection logic in new_account.sh:**
```bash
is_inside_container() {
    # Check for Docker container indicators
    [ -f /.dockerenv ] && return 0
    grep -q docker /proc/1/cgroup 2>/dev/null && return 0
    return 1
}

if is_inside_container; then
    # We're inside a container - do internal setup only
    do_internal_setup "$@"
else
    # We're on the host
    if should_use_docker; then
        do_docker_deployment "$@"
    else
        do_baremetal_deployment "$@"
    fi
fi
```

**Dockerfile.template changes:**
- Update CMD to pass any new parameters to new_account.sh
- No structural changes needed

### 3. Container Naming

**Decision:** Keep current `joinery-{sitename}` convention for both image and container names.

**Rationale:**
- Descriptive and consistent
- Easy to identify Joinery containers in `docker ps`
- No conflicts with other applications
- Already established pattern

### 4. Test Sites

**Decision:** Make test site creation optional, disabled by default.

**Rationale:**
- Many deployments don't need test sites
- Reduces disk usage and complexity for default case
- Docker users can spin up separate test containers instead
- Those who need test sites can explicitly request them

**Implementation:**
```bash
# Default: no test site
./new_account.sh site1 Pass1! site1.com

# With test site (bare-metal only)
./new_account.sh site1 Pass1! site1.com --with-test-site
```

**Behavior by environment:**
| Environment | Default | With `--with-test-site` |
|-------------|---------|-------------------------|
| Bare-metal | No test site | Creates `{site}_test` |
| Docker | No test site | Ignored (use separate container) |

---

*Version: 1.1*
*Date: 2026-01-23*
