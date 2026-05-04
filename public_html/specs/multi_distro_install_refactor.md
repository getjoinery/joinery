# Multi-Distro Install Refactor

## Goal

Refactor the install scripts (`install.sh`, `_site_init.sh`, `deploy.sh`, `fix_permissions.sh`, `Dockerfile.base`, `Dockerfile.template`) so that a single codebase can install and run Joinery on any mainstream Linux distribution, with minimal per-distro branching.

## Non-Goals

- Supporting BSD, macOS, or Windows
- Supporting non-systemd bare-metal deployments (OpenRC, runit, s6)
- Switching the web server from Apache to Nginx — Apache is assumed throughout
- Automating the selection of PHP version across distros — the required version is a constant
- Supporting distros that do not ship PHP 8.3 in their official repos or a well-known PPA

---

## Target Distro Families

### Tier 1 — Full support (install.sh works out of the box)
| Family | Representative Distros | Package Manager | Web User |
|--------|----------------------|-----------------|----------|
| Debian/Ubuntu | Ubuntu 24.04 LTS, Ubuntu 22.04 LTS, Debian 12 | apt | www-data |

### Tier 2 — Supported with distro profile (install.sh works after detecting family)
| Family | Representative Distros | Package Manager | Web User |
|--------|----------------------|-----------------|----------|
| RHEL | Rocky Linux 9, AlmaLinux 9, RHEL 9 | dnf | apache |
| Fedora | Fedora 40+ | dnf | apache |
| SUSE | openSUSE Leap 15.6 | zypper | wwwrun |

### Tier 3 — Community-supported / best-effort
| Family | Representative Distros | Package Manager | Web User |
|--------|----------------------|-----------------|----------|
| Arch | Arch Linux, Manjaro | pacman | http |
| Alpine | Alpine 3.19+ | apk | apache |

Docker-based installs only need Tier 1 since the container base image stays Ubuntu; Tier 2/3 matter primarily for bare-metal.

---

## Architecture

### Core Idea: Distro Profiles

Introduce a `distros/` directory inside `install_tools/`. Each profile is a sourced shell file that:

1. Defines package-manager functions (`pkg_install`, `pkg_update`, `pkg_remove`)
2. Exports path variables (`APACHE_CONF_DIR`, `APACHE_SITES_DIR`, `PHP_INI_PATH`, `PG_CONF_DIR`, etc.)
3. Declares the web server user (`WEB_USER`, `WEB_GROUP`)
4. Declares the Apache enable/disable helpers or provides equivalents
5. Identifies the init system (`INIT_SYSTEM`: `systemd` | `sysv`)

`install.sh` detects the running distro at startup, sources the correct profile, and then all downstream code calls the abstracted functions and variables rather than distro-specific ones.

### Directory Layout

```
maintenance_scripts/install_tools/
├── install.sh                     # Orchestrator (no distro-specific code)
├── _site_init.sh                  # Site initializer (no distro-specific code)
├── deploy.sh                      # Dev deploy (minimal changes needed)
├── fix_permissions.sh             # Uses $WEB_USER / $WEB_GROUP
├── Dockerfile.base                # Stays Ubuntu; distro work is bare-metal only
├── Dockerfile.template            # Stays Ubuntu
├── distros/
│   ├── _detect.sh                 # Detects distro family, sources correct profile
│   ├── debian_ubuntu.sh           # Profile: Debian/Ubuntu
│   ├── rhel_fedora.sh             # Profile: RHEL/Rocky/Alma/Fedora
│   ├── suse.sh                    # Profile: openSUSE
│   ├── arch.sh                    # Profile: Arch/Manjaro
│   └── alpine.sh                  # Profile: Alpine
└── ... (existing files)
```

---

## Distro Detection (`distros/_detect.sh`)

```bash
detect_distro() {
    # Parse /etc/os-release (present on all systemd-based and most modern distros)
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        DISTRO_ID="${ID}"
        DISTRO_ID_LIKE="${ID_LIKE:-}"
        DISTRO_VERSION_ID="${VERSION_ID:-}"
    else
        print_error "Cannot detect distro: /etc/os-release missing"
        exit 1
    fi

    case "$DISTRO_ID" in
        ubuntu|debian|linuxmint|pop)
            source "$SCRIPT_DIR/distros/debian_ubuntu.sh" ;;
        rhel|centos|rocky|almalinux|ol)
            source "$SCRIPT_DIR/distros/rhel_fedora.sh" ;;
        fedora)
            source "$SCRIPT_DIR/distros/rhel_fedora.sh" ;;
        opensuse*|sles)
            source "$SCRIPT_DIR/distros/suse.sh" ;;
        arch|manjaro|endeavouros)
            source "$SCRIPT_DIR/distros/arch.sh" ;;
        alpine)
            source "$SCRIPT_DIR/distros/alpine.sh" ;;
        *)
            # Fall back to ID_LIKE if direct match fails
            if [[ "$DISTRO_ID_LIKE" =~ debian ]]; then
                source "$SCRIPT_DIR/distros/debian_ubuntu.sh"
            elif [[ "$DISTRO_ID_LIKE" =~ rhel|fedora ]]; then
                source "$SCRIPT_DIR/distros/rhel_fedora.sh"
            else
                print_error "Unsupported distro: $DISTRO_ID"
                exit 1
            fi ;;
    esac
}
```

---

## Profile Interface

Every profile must export these symbols:

### Package Management
```bash
pkg_update()        # Refresh package index
pkg_upgrade()       # System upgrade
pkg_install()       # Install one or more packages: pkg_install curl wget git
```

### Path Variables
```bash
APACHE_CONF_DIR     # /etc/apache2       | /etc/httpd
APACHE_SITES_DIR    # .../sites-available | .../conf.d
APACHE_MODS_DIR     # .../mods-available  | (empty on RHEL)
APACHE_LOG_DIR      # /var/log/apache2    | /var/log/httpd
APACHE_SERVICE      # apache2             | httpd
PHP_INI_DIR         # /etc/php/8.3/apache2 | /etc/php.ini parent dir
PHP_FPM_SERVICE     # php8.3-fpm          | php-fpm
PG_CONF_DIR         # /etc/postgresql/N/main | /var/lib/pgsql/data
PG_HBA_CONF         # Full path to pg_hba.conf
PG_SERVICE          # postgresql          | postgresql
WEB_USER            # www-data            | apache
WEB_GROUP           # www-data            | apache
INIT_SYSTEM         # systemd             | sysv
```

### Apache Helpers
```bash
apache_enable_mod()   # a2enmod $1      | sed/ln equivalent
apache_disable_mod()  # a2dismod $1     | sed/ln equivalent
apache_enable_site()  # a2ensite $1     | ln -s + reload
apache_disable_site() # a2dissite $1    | rm + reload
apache_reload()       # service_reload $APACHE_SERVICE
```

### Package Name Maps
Each profile defines a function `pkg_name()` that translates canonical names to distro-specific ones:
```bash
pkg_name() {
    case "$1" in
        php)          echo "php8.3" ;;
        php-apache)   echo "libapache2-mod-php8.3" ;;
        php-fpm)      echo "php8.3-fpm" ;;
        php-pgsql)    echo "php8.3-pgsql" ;;
        php-mbstring) echo "php8.3-mbstring" ;;
        apache)       echo "apache2" ;;
        postgresql)   echo "postgresql" ;;
        certbot-apache) echo "python3-certbot-apache" ;;
        firewall)     echo "ufw" ;;
        # ... etc
    esac
}
```

RHEL equivalent:
```bash
pkg_name() {
    case "$1" in
        php)          echo "php8.3" ;;    # from Remi repo
        php-apache)   echo "php8.3-php" ;; # mod_php from Remi
        php-fpm)      echo "php8.3-php-fpm" ;;
        php-pgsql)    echo "php8.3-php-pgsql" ;;
        apache)       echo "httpd" ;;
        postgresql)   echo "postgresql-server" ;;
        certbot-apache) echo "python3-certbot-apache" ;;
        firewall)     echo "firewalld" ;;
        # ... etc
    esac
}
```

---

## Key Refactoring Points in install.sh

### 1. Replace all `apt` calls
```bash
# Before
apt update && apt install -y curl wget git unzip rsync

# After
pkg_update
pkg_install curl wget git unzip rsync
```

### 2. Replace all Apache path literals
```bash
# Before
cp default_virtualhost.conf /etc/apache2/sites-available/${SITENAME}.conf
a2ensite ${SITENAME}

# After
cp default_virtualhost.conf "${APACHE_SITES_DIR}/${SITENAME}.conf"
apache_enable_site "${SITENAME}"
```

### 3. Replace all PHP config paths
```bash
# Before
sed -i 's/upload_max_filesize = .*/upload_max_filesize = 32M/' /etc/php/8.3/apache2/php.ini

# After
sed -i "s/upload_max_filesize = .*/upload_max_filesize = 32M/" "${PHP_INI_DIR}/php.ini"
```

### 4. Replace PostgreSQL config path
```bash
# Before
PG_HBA="/etc/postgresql/${PG_VERSION}/main/pg_hba.conf"

# After (set in profile, pg_version detection stays the same)
# $PG_HBA_CONF is set by profile after version detection
```

### 5. Replace `www-data` references
```bash
# Before
chown -R www-data:user1 /var/www/html/${SITENAME}

# After
chown -R "${WEB_USER}:${DEPLOY_USER}" "/var/www/html/${SITENAME}"
```

### 6. Replace `ufw` with profile firewall helper
```bash
# Before
ufw allow 80/tcp
ufw allow 443/tcp

# After
firewall_allow_port 80
firewall_allow_port 443
firewall_enable
```
Each profile implements `firewall_allow_port` and `firewall_enable` using its native tool (`ufw`, `firewalld`, `iptables`).

### 7. Replace `grep -oP` with portable grep
Several version-detection regexes use `grep -oP` (GNU Perl mode), which is absent on Alpine and macOS:
```bash
# Before
PG_VERSION=$(psql --version | grep -oP '\d+\.\d+' | head -1 | cut -d. -f1)

# After
PG_VERSION=$(psql --version | grep -o '[0-9][0-9]*\.[0-9]' | head -1 | cut -d. -f1)
```

### 8. Replace `dig` with curl fallback
`dig` is not always present on minimal installs:
```bash
resolve_domain() {
    if command -v dig &>/dev/null; then
        dig +short "$1" | tail -1
    elif command -v nslookup &>/dev/null; then
        nslookup "$1" | awk '/^Address: / { print $2 }' | tail -1
    else
        curl -sf "https://dns.google/resolve?name=$1&type=A" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['Answer'][0]['data'] if 'Answer' in d else '')" 2>/dev/null
    fi
}
```

---

## RHEL-Specific Concerns

RHEL-family distros require two extra setup steps for PHP 8.3:

1. **Enable EPEL and Remi repo** before installing PHP:
```bash
pkg_install epel-release
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf module enable -y php:remi-8.3
```

2. **No `a2ensite` / `a2enmod`** — Joinery's VirtualHost config must be placed directly in `/etc/httpd/conf.d/` and Apache module config is already enabled via `LoadModule` directives in the main config. The `apache_enable_site` and `apache_enable_mod` helpers on RHEL profile reduce to `cp` and a config file write + `systemctl reload httpd`.

3. **SELinux** — On RHEL, Apache cannot read `/var/www/html` content by default if the file context is wrong. The RHEL profile must include:
```bash
semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/${SITENAME}(/.*)?"
restorecon -R "/var/www/html/${SITENAME}"
setsebool -P httpd_can_network_connect_db 1
```
Or, for environments where SELinux is permissive (common in containers), this step can be skipped with a warning.

4. **PostgreSQL initialization** — On RHEL, PostgreSQL data directory is not initialized by the package:
```bash
postgresql-setup --initdb
```
The profile's `pg_init()` helper wraps this distro difference.

---

## Dockerfile Strategy

The Docker path (`Dockerfile.base`, `Dockerfile.template`) stays Ubuntu 24.04 and is unaffected by this refactor. Docker is the primary deployment model for production, and container images are inherently single-distro. The multi-distro work is for bare-metal installs only.

If a future operator wants to build Joinery containers on a non-Ubuntu base, that is a separate Dockerfile concern and out of scope here.

---

## Service Management

The existing `service_start()` / `service_stop()` pattern in `install.sh` already abstracts Docker vs. bare-metal. It needs one addition: the Docker path uses SysV `service`, the bare-metal path uses `systemctl`. If a future bare-metal init system is not systemd, add a third branch:

```bash
service_start() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" start || true
    elif [ "$INIT_SYSTEM" = "systemd" ]; then
        systemctl start "$service_name"
        systemctl enable "$service_name"
    else
        print_error "Unsupported init system: $INIT_SYSTEM"
        exit 1
    fi
}
```

---

## fix_permissions.sh

This script hardcodes `www-data:user1`. After the refactor it should source `_detect.sh` and use `$WEB_USER` for the web-server side of ownership. The `user1` side is a deployment convention, not a distro fact, and can remain a parameter.

---

## deploy.sh

`deploy.sh` is a Git-based dev tool and has fewer distro dependencies. Changes needed:

- Replace hardcoded `/usr/bin/php` with `$(command -v php)` 
- Replace `www-data` with `$WEB_USER` sourced from `_detect.sh`
- Replace `shopt -s dotglob` ... this is Bash 4+ and fine, but annotate it

---

## Testing Matrix

Each distro profile should be validated by a smoke test that:
1. Runs `install.sh server` on a fresh VM
2. Runs `install.sh site testsite localhost`
3. Curls `http://localhost` and confirms 200
4. Runs the PHP validator against a known file

CI/CD can run this via GitHub Actions using the official Docker images for each distro family as lightweight test environments (without full VM overhead).

---

## Implementation Phases

### Phase 1 — Extraction (no behavior change)
- Create `distros/` directory
- Extract all distro-specific constants from `install.sh` into `distros/debian_ubuntu.sh`
- Source `distros/_detect.sh` at startup of `install.sh`
- Replace all inline literals with the exported variables/functions
- Run existing Ubuntu tests to confirm no regression

**Risk:** Low. Pure refactor; Debian/Ubuntu profile is a direct extract of current behavior.

### Phase 2 — RHEL/Fedora profile
- Write `distros/rhel_fedora.sh`
- Handle Remi PHP repo, `firewalld`, SELinux, `postgresql-setup --initdb`
- Validate on a Rocky Linux 9 VM

**Risk:** Medium. SELinux adds complexity; Remi repo is a third-party dependency.

### Phase 3 — SUSE profile
- Write `distros/suse.sh`
- Handle `zypper`, openSUSE PHP package names (`php8-apache2`, etc.)
- Validate on openSUSE Leap 15.6

**Risk:** Low-medium. PHP package naming is the main difference; service management is the same (systemd).

### Phase 4 — Alpine (Docker-targeted bare-metal)
- Write `distros/alpine.sh`
- Handle `apk`, OpenRC vs. systemd consideration, musl-related grep differences
- This is primarily useful for lightweight VPS or embedded installs

**Risk:** Medium-high. Alpine uses OpenRC, not systemd; musl libc affects some `grep`/`sed` behaviors.

---

## Open Questions

1. **PHP version pinning**: RHEL/Fedora ship older PHP in default repos; Remi is the de-facto standard for PHP 8.x. Is a third-party repo acceptable in Tier 2 support, or should we note this as a user responsibility?

2. **PostgreSQL version**: Should the profiles pin a specific PostgreSQL major version (e.g., 16) or accept whatever the distro ships? Joinery has no known PG version constraints beyond 12+.

3. **Certbot on RHEL**: `certbot` is available via EPEL on RHEL. If EPEL is already added for PHP, this is free. Worth confirming the interaction.

4. **Alpine / musl**: Alpine uses musl libc which affects regex behavior in `grep` and `sed`. The `grep -oP` removal (noted above) handles the most common case, but a full grep audit may be needed before declaring Alpine Tier 3.
