# Specification: Universal Site Installer Refactor

## Overview

Replace `docker_install_master.sh` with a single unified `install.sh` script that uses subcommands to handle all installation scenarios.

## Problem Statement

Currently, `docker_install_master.sh` combines two distinct responsibilities:
1. Setting up Docker infrastructure (one-time per server)
2. Creating and deploying a Joinery site (per-site operation)

This coupling means:
- Users must understand Docker internals even for simple site creation
- The script can't be reused for bare-metal installations
- Docker setup code runs unnecessarily on every site creation

## Goals

1. **Single entry point** - One script (`install.sh`) for all installation operations
2. **Clear subcommands** - Explicit operations: `docker`, `server`, `site`, `list`
3. **Environment auto-detection** - `site` command determines Docker vs bare-metal automatically
4. **Reuse existing scripts** - new_account.sh called for bare-metal site creation
5. **Explicit flow** - Users explicitly run prerequisite commands (no hidden magic)

## Proposed Solution

### Usage

```bash
# Docker installation flow
./install.sh docker                              # One-time: install Docker
./install.sh site SITENAME PASS DOMAIN PORT      # Each site

# Bare-metal installation flow
./install.sh server                              # One-time: set up Apache/PHP/PostgreSQL
./install.sh site SITENAME PASS DOMAIN           # Each site

# List existing sites
./install.sh list
```

### Example Workflows

#### Multi-Site Docker Deployment

```bash
# 1. One-time: Install Docker on fresh server
./install.sh docker

# 2. Create sites (each in its own container)
./install.sh site site1 SecurePass1! site1.com 8080
./install.sh site site2 SecurePass2! site2.com 8081
./install.sh site site3 SecurePass3! site3.com 8082

# 3. View all running sites
./install.sh list
```

#### Multi-Site Bare-Metal Deployment

```bash
# 1. One-time: Set up server (Apache, PHP, PostgreSQL)
./install.sh server

# 2. Create sites (each in /var/www/html/{sitename}/)
./install.sh site site1 SecurePass1! site1.com
./install.sh site site2 SecurePass2! site2.com
./install.sh site site3 SecurePass3! site3.com

# 3. View all sites
./install.sh list
```

### Auto-Detection Behavior

`install.sh site` automatically detects the environment:

| Environment | Result |
|-------------|--------|
| Docker installed and running | Creates Docker container |
| Docker not present | Creates bare-metal site via `new_account.sh` |

**The PORT parameter signals intent:**
- With port → Docker mode (port required for container mapping)
- Without port → Bare-metal mode (Apache virtualhost handles routing)

**Force a specific mode:**
```bash
./install.sh site --docker mysite Pass123 mysite.com 8080
./install.sh site --bare-metal mysite Pass123 mysite.com
```

### File Structure

```
install_tools/
├── install.sh                  # NEW: Universal installer (includes server setup logic)
├── INSTALL_README.md           # NEW: Unified installation guide (replaces separate READMEs)
├── new_account.sh              # UNCHANGED: Called by `install.sh site` (bare-metal)
├── Dockerfile.template         # UNCHANGED: Used by `install.sh site` (Docker)
├── fix_permissions.sh          # UNCHANGED
├── default_Globalvars_site.php # UNCHANGED
├── default_serve.php           # UNCHANGED
└── default_virtualhost.conf    # UNCHANGED
```

### Documentation Consolidation

Combine `docker_install_README.md` and `server_setup_README.txt` into a single unified `INSTALL_README.md`.

**Current documentation:**
- `docker_install_README.md` - Comprehensive Docker guide (642 lines)
- `server_setup_README.txt` - Brief bare-metal commands (8 lines)

**New unified structure:**

```markdown
# Joinery Installation Guide

## Quick Start

### Docker Deployment
./install.sh docker
./install.sh site mysite SecurePass123! mysite.com 8080

### Bare-Metal Deployment
./install.sh server
./install.sh site mysite SecurePass123! mysite.com

## Example Workflows

### Multi-Site Docker Deployment
./install.sh docker
./install.sh site site1 Pass1! site1.com 8080
./install.sh site site2 Pass2! site2.com 8081
./install.sh list

### Multi-Site Bare-Metal Deployment
./install.sh server
./install.sh site site1 Pass1! site1.com
./install.sh site site2 Pass2! site2.com
./install.sh list

## Auto-Detection Behavior
- Docker running → creates container (PORT required)
- No Docker → creates bare-metal site (no PORT)
- Override with --docker or --bare-metal flags

## Prerequisites
- Server requirements (Ubuntu 24.04, RAM, disk space)
- Required files (joinery archive)

## Docker Deployment (detailed)
- One-time Docker setup
- Creating sites
- Multi-site support
- Port management

## Bare-Metal Deployment (detailed)
- One-time server setup
- Creating user account
- Creating sites

## Site Management
- Starting/stopping sites
- Viewing logs
- Shell access

## Maintenance Operations
- Database backup/restore
- Updating application code
- Running migrations

## Troubleshooting
- Common issues and solutions

## Quick Reference
- Essential commands for both modes

## Script Reference
- install.sh subcommands and options
- Supporting scripts (new_account.sh, fix_permissions.sh)
- Sysadmin tools (backup_database.sh, restore_database.sh, etc.)
```

**Supporting scripts to document:**

| Script | Purpose | Called By |
|--------|---------|-----------|
| `new_account.sh` | Creates site directory, database, virtualhost, user | `install.sh site` (bare-metal) |
| `fix_permissions.sh` | Sets correct ownership and permissions on site files | `install.sh site`, manual use |
| `Dockerfile.template` | Template for building Docker images | `install.sh site` (Docker) |
| `default_Globalvars_site.php` | Template for site configuration | `new_account.sh` |
| `default_serve.php` | Template for front controller | `new_account.sh` |
| `default_virtualhost.conf` | Template for Apache virtualhost | `new_account.sh` |

**Note:** Server setup logic (Apache, PHP, PostgreSQL installation) is integrated directly into `install.sh server` - no separate script.

**Related sysadmin tools to reference:**

| Script | Purpose | Location |
|--------|---------|----------|
| `backup_database.sh` | Backup PostgreSQL database | `sysadmin_tools/` |
| `restore_database.sh` | Restore PostgreSQL database | `sysadmin_tools/` |
| `backup_project.sh` | Full site backup (files + database) | `sysadmin_tools/` |
| `restore_project.sh` | Full site restore | `sysadmin_tools/` |
| `copy_database.sh` | Copy database between sites | `sysadmin_tools/` |
| `remove_account.sh` | Remove a site completely | `sysadmin_tools/` |

**Files to remove after consolidation:**
- `docker_install_README.md`
- `server_setup_README.txt`

**Files to update:**
- `public_html/docs/docker_install.md` - Update to reference new unified guide
- `public_html/docs/deploy_and_upgrade.md` - Update references

### Subcommands

#### `install.sh docker`

**Purpose:** Install and configure Docker on a fresh server. Run once per server.

**What it does:**
- Check if Docker is already installed
- Install Docker CE if missing
- Start Docker daemon
- Verify Docker is operational
- Display success/failure status

**Usage:**
```bash
sudo ./install.sh docker
```

**Exit codes:**
- 0: Docker is installed and running
- 1: Installation failed

#### `install.sh server`

**Purpose:** Set up base server for bare-metal installation. Run once per server.

**What it does:**
- Installs Apache, PHP, PostgreSQL (logic integrated from server_setup.sh)
- Configures base environment
- Sets up required directories and permissions

**Usage:**
```bash
sudo ./install.sh server
```

**Note:** Not needed for Docker installations (server setup happens inside each container).

#### `install.sh site`

**Purpose:** Create a new Joinery site. Auto-detects Docker vs bare-metal.

**Usage:**
```bash
# Docker mode (auto-detected if Docker is running)
sudo ./install.sh site mysite SecurePass123! mysite.com 8080

# Bare-metal mode (auto-detected if Docker is not present)
sudo ./install.sh site mysite SecurePass123! mysite.com

# Force specific mode
sudo ./install.sh site --docker mysite SecurePass123! mysite.com 8080
sudo ./install.sh site --bare-metal mysite SecurePass123! mysite.com
```

**Parameters:**
| Parameter | Required | Description |
|-----------|----------|-------------|
| SITENAME | Yes | Site/database name |
| POSTGRES_PASSWORD | Yes | Database password |
| DOMAIN_NAME | No | Domain for VirtualHost (default: server IP) |
| PORT | No | Host port for web (Docker only, default: 8080) |

**Environment Detection Logic:**
```
1. If --docker flag: use Docker mode (error if Docker not running)
2. If --bare-metal flag: use bare-metal mode (error if prerequisites not met)
3. If Docker is installed AND running: use Docker mode
4. Otherwise: use bare-metal mode (error if prerequisites not met)
```

**Docker Mode Flow:**
1. Verify Docker is running (error if not - tell user to run `install.sh docker`)
2. Check for port conflicts, suggest available port if needed
3. Check for existing container with same name
4. Prepare build context (copy files to temp directory)
5. Build Docker image using Dockerfile.template
6. Run container with persistent volumes
7. Verify site is responding
8. Cleanup build directory
9. Display success summary

**Bare-Metal Mode Flow:**
1. Check prerequisites (Apache, PHP, PostgreSQL installed)
2. Error if not met - tell user to run `install.sh server`
3. Call `new_account.sh` with provided parameters
4. Verify site is responding
5. Display success summary

#### `install.sh list`

**Purpose:** List existing Joinery sites.

**What it does:**
- Docker mode: Lists containers with their ports and status
- Bare-metal mode: Lists sites in /var/www/html/

**Usage:**
```bash
sudo ./install.sh list
```

### Help Output

```bash
$ ./install.sh --help

Joinery Installation Script

Usage:
  ./install.sh <command> [options]

Commands:
  docker    Install Docker (one-time, for Docker deployments)
  server    Set up base server (one-time, for bare-metal deployments)
  site      Create a new Joinery site
  list      List existing Joinery sites

Examples:
  # Docker deployment
  sudo ./install.sh docker
  sudo ./install.sh site mysite SecurePass123! mysite.com 8080

  # Bare-metal deployment
  sudo ./install.sh server
  sudo ./install.sh site mysite SecurePass123! mysite.com

Run './install.sh <command> --help' for command-specific help.
```

## Implementation Details

### Difficulty Level: Low

This is primarily a reorganization task, not a rewrite. Most code already exists and works.

**Implementation approach:**
1. **DO NOT rewrite existing scripts** - `new_account.sh`, `fix_permissions.sh` remain unchanged
2. **Copy, don't recreate** - Extract working code from `docker_install_master.sh` and `server_setup.sh` as-is
3. **Thin wrapper + integrated server setup** - `install.sh` dispatches to existing scripts OR runs integrated logic
4. **Merge docs, don't rewrite** - Combine README content with minimal editing

**What's actually new:**
- `install.sh` (~350 lines) - Case statement + integrated server setup logic
- `INSTALL_README.md` - Merged from two existing files with updated commands

**What's copied verbatim:**

From `docker_install_master.sh`:
- Helper functions (colors, print_*, etc.)
- Docker installation logic
- Port management functions
- Container creation logic
- Verification logic

From `server_setup.sh`:
- All server setup logic (Apache, PHP, PostgreSQL installation)
- Integrated directly into `install.sh server` subcommand

**What stays completely unchanged:**
- `new_account.sh` - Called by `install.sh site` for bare-metal
- `fix_permissions.sh`
- `Dockerfile.template`
- All `default_*` template files

### install.sh Structure

```bash
#!/usr/bin/env bash
# VERSION 1.0 - Universal Joinery Installer

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ... helper functions (colors, print_*, etc.) ...

case "${1:-}" in
    docker)
        shift
        # Docker installation logic (extracted from docker_install_master.sh lines 338-392)
        ;;
    server)
        shift
        # Server setup logic (integrated from server_setup.sh)
        do_server_setup "$@"
        ;;
    site)
        shift
        # Parse --docker/--bare-metal flags
        # Detect environment
        # Route to appropriate flow
        ;;
    list)
        shift
        # List sites (Docker containers or /var/www/html directories)
        ;;
    --help|-h|"")
        show_help
        ;;
    *)
        echo "Unknown command: $1"
        show_help
        exit 1
        ;;
esac
```

### Code Migration

**From docker_install_master.sh:**

| Source Lines | Destination |
|--------------|-------------|
| 45-71 | install.sh (helper functions) |
| 78-172 | install.sh site (port management) |
| 338-392 | install.sh docker |
| 196-238 | install.sh site (parameter validation) |
| 244-295 | install.sh site (port conflict detection) |
| 303-332 | install.sh site (archive verification) |
| 398-417 | install.sh site (container check) |
| 423-459 | install.sh site (build context) |
| 465-480 | install.sh site (image build) |
| 486-509 | install.sh site (container run) |
| 515-542 | install.sh site (verification) |
| 548-552 | install.sh site (cleanup) |
| 558-589 | install.sh site (summary) |

**From server_setup.sh:**

| Source | Destination |
|--------|-------------|
| Entire file | install.sh server (as `do_server_setup()` function) |

### Bare-Metal Prerequisites Check

```bash
check_bare_metal_ready() {
    local missing=()

    command -v apache2 &> /dev/null || missing+=("Apache")
    command -v psql &> /dev/null || missing+=("PostgreSQL")
    command -v php &> /dev/null || missing+=("PHP")

    if [ ${#missing[@]} -gt 0 ]; then
        print_error "Missing prerequisites: ${missing[*]}"
        print_info "Run './install.sh server' first to set up the base server"
        return 1
    fi
    return 0
}
```

## File Disposition

| Current File | Action |
|--------------|--------|
| docker_install_master.sh | Archive (replaced by install.sh) |
| server_setup.sh | Archive (integrated into install.sh) |
| docker_install_README.md | Delete (replaced by INSTALL_README.md) |
| server_setup_README.txt | Delete (replaced by INSTALL_README.md) |
| new_account.sh | Keep unchanged (called by install.sh site) |
| Dockerfile.template | Keep unchanged |

## Testing Plan

### install.sh docker Tests

1. **Fresh server without Docker**
   - Run `install.sh docker`
   - Verify Docker is installed and running
   - Verify exit code 0

2. **Server with Docker already installed**
   - Run `install.sh docker`
   - Verify it detects existing Docker
   - Verify it doesn't reinstall
   - Verify exit code 0

### install.sh server Tests

1. **Fresh server**
   - Run `install.sh server`
   - Verify Apache, PHP, PostgreSQL installed
   - Verify exit code 0

2. **Already configured server**
   - Run `install.sh server`
   - Verify it handles gracefully

### install.sh site Tests - Docker Mode

1. **Basic site creation**
   - Run with valid parameters
   - Verify container is created
   - Verify site responds on specified port

2. **Port conflict handling**
   - Create site on port 8080
   - Try to create another on same port
   - Verify conflict detection and suggestion

3. **Missing Docker**
   - On server without Docker
   - Run `install.sh site --docker ...`
   - Verify helpful error message

### install.sh site Tests - Bare-Metal Mode

1. **Prerequisites not met**
   - On fresh server (no Apache/PHP/PostgreSQL)
   - Run `install.sh site ...`
   - Verify error message suggests running `install.sh server`

2. **Prerequisites met**
   - Run `install.sh server` first
   - Run `install.sh site ...`
   - Verify site is created and responds

### Environment Detection Tests

1. **Docker installed and running** → Docker mode
2. **Docker not installed** → Bare-metal mode
3. **--docker flag without Docker** → Error with helpful message
4. **--bare-metal flag** → Bare-metal mode (error if prerequisites missing)

## Success Criteria

1. `install.sh docker` successfully installs Docker on Ubuntu 24.04
2. `install.sh server` successfully sets up bare-metal server
3. `install.sh site` creates working sites in both Docker and bare-metal modes
4. Auto-detection correctly identifies environment
5. Helpful error messages guide users to correct prerequisites
6. `install.sh list` shows existing sites in both modes

## Rollback Plan

If issues arise, revert to the pre-implementation commit:

```bash
git revert <COMMIT_HASH>
```

**Pre-implementation commit:** _(to be recorded when implementation begins)_

## Future Enhancements

After initial implementation, consider adding:
- `install.sh remove SITENAME` - Remove a site (Docker container or bare-metal)
- `install.sh backup SITENAME` - Backup a site
- `install.sh restore SITENAME BACKUP_FILE` - Restore a site
- `install.sh upgrade SITENAME` - Upgrade a site

## References

- Current Docker script: `install_tools/docker_install_master.sh`
- Current server setup: `install_tools/server_setup.sh`
- Docker documentation: https://docs.docker.com/engine/install/ubuntu/
- Bare-metal site creation: `new_account.sh` (remains separate)
