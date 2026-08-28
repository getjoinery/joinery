#!/usr/bin/env bash

# reconcile_site.sh - a site's SHAPE: read it, or make a site match this machine's
# Version: 1.2.1 - the sudo capability probe asks with -v instead of running true,
#                  which sudo mails root about when the account may not. Same
#                  change in the sibling scripts.
# Version: 1.2.0 - the machine wins when the config claims a shape this box is not. A restore
#                  made before any of this existed wrote the source machine's config straight
#                  over the target's, so such a site has been insisting it is in a container
#                  ever since; reconciling to that claim would preserve the bug
# Version: 1.1.0 - --print-shape: the backup side of the same subject, in the same file
#
# Description:
#   The shape is the set of facts about the machine a site runs on that a restore
#   has to settle: container or plain server, which domain, which paths, which
#   PHP and PostgreSQL. Backups record it; restores reconcile to it. Both live
#   here, because they are the same subject read in two directions and splitting
#   them means two descriptions of one thing, free to drift.
#
#   --print-shape (the backup side). Prints this site's shape as JSON. Called by
#   both backup engines so the archive path and the chain path cannot describe a
#   site differently.
#
#   The default mode (the restore side). A backup can be rebuilt anywhere, which
#   means the machine it lands on is usually not the machine it came from. It may
#   be a plain server where the backup came from a container, or the other way
#   round; it will have its own PostgreSQL with its own password; and it very
#   often answers to a different domain. A site that disagrees with the machine
#   under it fails in ways that look like anything but a bad restore — so every
#   restore path ends here, and this is the only place those facts are settled.
#
#   What it settles:
#     * where the site thinks it is     (webDir, in the config AND in stg_settings)
#     * what shape it thinks it is in   (deployment_environment)
#     * where it thinks its files are   (baseDir, site_template)
#     * how it is served                (the virtualhost is REGENERATED, never
#                                        copied out of the backup)
#     * when its certificate arrives    (arms the DNS-gated retry for the new
#                                        domain, disarms the old one)
#
#   What it deliberately never touches: database credentials. Those belong to
#   the machine, they are already correct in the target's own config, and taking
#   them from a backup is what leaves every page logging SQLSTATE[08006].
#
#   deployment_environment is READ from the site's own config, not probed for:
#   the installer records it once (spec deployment_environment_flag) and
#   everything downstream reads that one value. The single exception is here, and
#   only in one direction — if the config claims a shape this machine plainly is
#   not, the machine wins and the correction is announced. A config can be wrong
#   about its machine (a restore predating this wrote the source's config over the
#   target's); a machine cannot be wrong about itself.
#
# Usage:
#   ./reconcile_site.sh SITENAME --domain DOMAIN [options]     reconcile
#   ./reconcile_site.sh SITENAME --print-shape [options]       read the shape
#
# Options:
#   --domain DOMAIN       REQUIRED to reconcile. The domain this site is to answer
#                         to. Never inferred: a rebuild keeps the site's own domain
#                         while a rehearsal must not claim it, and the same backup
#                         on the same box wants opposite answers.
#   --print-shape         Print this site's shape as JSON and stop. Missing facts
#                         are emitted as null rather than guessed — a restore reads
#                         an absent field as unknown, never as a default.
#   --vhost-captured yes|no   --print-shape only: whether the caller captured a
#                         virtualhost alongside, which decides vhost_role.
#   --out FILE            --print-shape only: write there instead of stdout.
#   --backup-meta DIR     Directory holding the backup's shape.json and
#                         apache_config/. Optional — its absence means the source
#                         shape is unknown, which is not an error.
#   --site-dir DIR        Site root. Default /var/www/html/SITENAME
#   --skip-web-config     Leave Apache alone entirely (for a files-only restore
#                         into a scratch directory, and for tests)
#   --skip-ssl            Do not arm the certificate retry
#   --help
#
# Output:
#   RECONCILE_* lines naming every value it changed, then RECONCILE_OK. A restore
#   that fixed things up silently is as hard to trust as one that broke them.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_VERSION="$(sed -n 's/^# Version: \([0-9][0-9.]*\).*/\1/p' "${BASH_SOURCE[0]}" | head -1)"
[ -n "$SCRIPT_VERSION" ] || SCRIPT_VERSION="unknown"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
print_info()    { echo -e "${BLUE}[INFO]${NC} $1" >&2; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1" >&2; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1" >&2; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }

SITENAME=""
DOMAIN=""
BACKUP_META=""
SITE_DIR=""
SKIP_WEB_CONFIG=false
SKIP_SSL=false
PRINT_SHAPE=false
VHOST_CAPTURED="no"
OUT=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --domain)          DOMAIN="$2"; shift 2 ;;
        --domain=*)        DOMAIN="${1#*=}"; shift ;;
        --print-shape)     PRINT_SHAPE=true; shift ;;
        --vhost-captured)  VHOST_CAPTURED="$2"; shift 2 ;;
        --vhost-captured=*) VHOST_CAPTURED="${1#*=}"; shift ;;
        --out)             OUT="$2"; shift 2 ;;
        --out=*)           OUT="${1#*=}"; shift ;;
        --backup-meta)     BACKUP_META="$2"; shift 2 ;;
        --backup-meta=*)   BACKUP_META="${1#*=}"; shift ;;
        --site-dir)        SITE_DIR="$2"; shift 2 ;;
        --site-dir=*)      SITE_DIR="${1#*=}"; shift ;;
        --skip-web-config) SKIP_WEB_CONFIG=true; shift ;;
        --skip-ssl)        SKIP_SSL=true; shift ;;
        --help|-h)         awk 'NR<3 {next} /^#/ {sub(/^# ?/,""); print; next} {exit}' "$0"; exit 0 ;;
        -*)                print_error "Unknown option: $1"; exit 1 ;;
        *)                 if [ -z "$SITENAME" ]; then SITENAME="$1"; else
                               print_error "Too many arguments: $1"; exit 1; fi
                           shift ;;
    esac
done

[ -n "$SITENAME" ] || { print_error "Site name is required."; exit 1; }
if [ -z "$DOMAIN" ] && [ "$PRINT_SHAPE" = false ]; then
    print_error "--domain is required. It is the one thing a restore cannot work out for itself:"
    print_error "a rebuild keeps the site's own domain, a rehearsal must not claim it, and the"
    print_error "backup looks identical either way."
    exit 1
fi

[ -n "$SITE_DIR" ] || SITE_DIR="/var/www/html/${SITENAME}"
SITE_DIR="${SITE_DIR%/}"
CONFIG="${SITE_DIR}/config/Globalvars_site.php"

if [ ! -f "$CONFIG" ]; then
    print_error "No site config at $CONFIG — there is nothing to reconcile."
    print_error "A restore must land on an INSTALLED site: the config is where this machine's"
    print_error "database password and secret_box_key live, and a backup never carries them."
    exit 1
fi

# -v asks the question without making the escalation attempt sudo mails root about;
# see backup_files.sh for why. Same test as the sibling scripts use.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo > /dev/null 2>&1 && sudo -n -v 2>/dev/null; then
        SUDO="sudo"
    fi
fi

config_value() {
    $SUDO sed -n "s/^[[:space:]]*\$this->settings\['$1'\][[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" \
        "$CONFIG" 2>/dev/null | head -1
}

# ── --print-shape: the backup side ─────────────────────────────────────────
#
# What a backup records so a restore can say what it is landing on versus what
# it came from. Everything below this block is the restore side.

if [ "$PRINT_SHAPE" = true ]; then
    # A JSON string, or the bare literal null when the fact is not known. Quoting
    # an empty string would say "this site has no domain", which is a different
    # claim from "this backup does not record one".
    json_str() {
        if [ -z "${1:-}" ]; then printf 'null'
        else printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"; fi
    }
    json_num() { if [ -z "${1:-}" ]; then printf 'null'; else printf '%s' "$1"; fi; }

    SHAPE_ENV="$(config_value deployment_environment)"
    # 'bare-metal' is what the installer's command line calls it; 'baremetal' is
    # what it writes. Both mean one shape, and a restore must not treat them as two.
    case "$SHAPE_ENV" in bare-metal) SHAPE_ENV="baremetal" ;; esac

    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"

    # The server's version, not the client's: a dump restored onto a different
    # major is the normal case, and knowing which one it came off is what makes
    # an incompatibility legible later.
    PG_VERSION=""
    if command -v psql > /dev/null 2>&1; then
        export PGPASSWORD="$(config_value dbpassword)"
        PG_VERSION="$(psql -U "$(config_value dbusername)" -d "$(config_value dbname)" \
            -tAc 'SHOW server_version' 2>/dev/null \
            | sed -n 's/^[[:space:]]*\([0-9][0-9]*\).*/\1/p' | head -1)"
        unset PGPASSWORD
    fi

    # A container's virtualhost is its INTERNAL one — ServerName {site}.site on
    # port 80, with TLS terminated by the host's proxy outside the container
    # entirely. A bare-metal site's is the public face. Only the second could
    # ever be installed as-is, which is why the role travels with the capture.
    SHAPE_VHOST_ROLE="none"
    if [ "$VHOST_CAPTURED" = "yes" ]; then
        if [ "$SHAPE_ENV" = "docker" ]; then SHAPE_VHOST_ROLE="internal"; else SHAPE_VHOST_ROLE="public"; fi
    fi

    emit_shape() {
        printf '{\n'
        printf '  "version": 1,\n'
        printf '  "deployment_environment": %s,\n' "$(json_str "$SHAPE_ENV")"
        printf '  "project": %s,\n'                "$(json_str "$SITENAME")"
        printf '  "site_dir": %s,\n'               "$(json_str "$SITE_DIR")"
        printf '  "base_dir": %s,\n'               "$(json_str "$(config_value baseDir)")"
        printf '  "site_template": %s,\n'          "$(json_str "$(config_value site_template)")"
        printf '  "web_root": %s,\n'               "$(json_str "${SITE_DIR}/public_html")"
        printf '  "domain": %s,\n'                 "$(json_str "$(config_value webDir)")"
        printf '  "php_version": %s,\n'            "$(json_str "$PHP_VERSION")"
        printf '  "postgres_version": %s,\n'       "$(json_num "$PG_VERSION")"
        printf '  "vhost_captured": %s,\n'         "$([ "$VHOST_CAPTURED" = yes ] && echo true || echo false)"
        printf '  "vhost_role": %s,\n'             "$(json_str "$SHAPE_VHOST_ROLE")"
        printf '  "taken": %s\n'                   "$(json_str "$(date -u +%Y-%m-%dT%H:%M:%SZ)")"
        printf '}\n'
    }

    if [ -n "$OUT" ]; then
        emit_shape > "$OUT" || { print_error "Could not write $OUT"; exit 1; }
    else
        emit_shape
    fi
    exit 0
fi

# Rewrite one single-quoted setting in place. Values are matched and replaced
# with '|' delimiters and the replacement is escaped, so a domain or a path
# containing a slash cannot break the expression.
config_set() {
    local key="$1" value="$2"
    local esc
    esc=$(printf '%s' "$value" | sed 's/[&|\\]/\\&/g')
    $SUDO sed -i "s|^\([[:space:]]*\$this->settings\['${key}'\][[:space:]]*=[[:space:]]*\)'[^']*'|\1'${esc}'|" "$CONFIG"
}

CHANGES=0
note_change() {
    CHANGES=$((CHANGES + 1))
    echo "RECONCILE_SET $1"
}

echo "RECONCILE_START ${SITENAME} v${SCRIPT_VERSION}"

# ── 1. What shape did this come from, and what is it landing in? ────────────

SOURCE_ENV="unknown"
SOURCE_DOMAIN=""
SHAPE_FILE=""
if [ -n "$BACKUP_META" ] && [ -f "${BACKUP_META}/shape.json" ]; then
    SHAPE_FILE="${BACKUP_META}/shape.json"
fi
if [ -n "$SHAPE_FILE" ] && [ -f "$SHAPE_FILE" ]; then
    # Read with python3 when it is there (it always is on a Joinery box — the
    # chain restore requires it), fall back to grep so a shape file is never the
    # reason a restore fails.
    if command -v python3 > /dev/null 2>&1; then
        SOURCE_ENV=$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d.get('deployment_environment') or 'unknown')" "$SHAPE_FILE" 2>/dev/null || echo unknown)
        SOURCE_DOMAIN=$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d.get('domain') or '')" "$SHAPE_FILE" 2>/dev/null || echo '')
    else
        SOURCE_ENV=$(grep -o '"deployment_environment"[^,]*' "$SHAPE_FILE" | grep -o '"[^"]*"$' | tr -d '"')
        [ -n "$SOURCE_ENV" ] || SOURCE_ENV="unknown"
    fi
fi
case "$SOURCE_ENV" in bare-metal) SOURCE_ENV="baremetal" ;; esac

# What the MACHINE is. Docker writes /.dockerenv into every container it starts
# and podman writes /run/.containerenv, so the absence of both is a reliable
# "not in a container" — which is the direction that matters here.
MACHINE_ENV="baremetal"
if [ -f /.dockerenv ] || [ -f /run/.containerenv ]; then MACHINE_ENV="docker"; fi

# What the CONFIG claims. Normally these agree, and the config is the source of
# truth everything else reads.
CLAIMED_ENV="$(config_value deployment_environment)"
case "$CLAIMED_ENV" in bare-metal) CLAIMED_ENV="baremetal" ;; esac

TARGET_ENV="$CLAIMED_ENV"
if [ -z "$CLAIMED_ENV" ]; then
    # A config with no flag predates the installer recording one.
    TARGET_ENV="$MACHINE_ENV"
    print_warning "This site's config records no deployment_environment — treating it as ${TARGET_ENV}."
elif [ "$CLAIMED_ENV" != "$MACHINE_ENV" ]; then
    # The config is describing a machine this is not. That is the signature of a
    # restore made before any of this existed: the source machine's config was
    # written straight over the target's, so the site has been insisting it is in
    # a container while running on a plain server (or the reverse) ever since.
    # The machine wins — it is the thing that is actually true — and the
    # correction is stated rather than made quietly, because a site whose own
    # config lied about this has probably been misbehaving in other ways too.
    TARGET_ENV="$MACHINE_ENV"
    echo "RECONCILE_CONFIG_WAS_WRONG deployment_environment claimed ${CLAIMED_ENV}, machine is ${MACHINE_ENV}"
    print_warning "This site's config claims ${CLAIMED_ENV} but this machine is ${MACHINE_ENV}."
    print_warning "The machine wins. The config was describing the box the backup came from."
fi

echo "RECONCILE_SOURCE_SHAPE ${SOURCE_ENV}"
echo "RECONCILE_TARGET_SHAPE ${TARGET_ENV}"
echo "RECONCILE_DOMAIN ${DOMAIN}"
if [ "$SOURCE_ENV" != "unknown" ] && [ "$SOURCE_ENV" != "$TARGET_ENV" ]; then
    echo "RECONCILE_SHAPE_CHANGE ${SOURCE_ENV} -> ${TARGET_ENV}"
    print_info "Shape change: this backup was taken on ${SOURCE_ENV} and is landing on ${TARGET_ENV}."
fi
if [ -n "$SOURCE_DOMAIN" ] && [ "$SOURCE_DOMAIN" != "$DOMAIN" ]; then
    echo "RECONCILE_DOMAIN_CHANGE ${SOURCE_DOMAIN} -> ${DOMAIN}"
fi

# ── 2. Identity, in both places it lives ────────────────────────────────────

OLD_DOMAIN="$(config_value webDir)"
if [ "$OLD_DOMAIN" != "$DOMAIN" ]; then
    config_set webDir "$DOMAIN"
    note_change "webDir ${OLD_DOMAIN:-(unset)} -> ${DOMAIN} (config)"
fi

OLD_ENV="$(config_value deployment_environment)"
if [ "$OLD_ENV" != "$TARGET_ENV" ]; then
    config_set deployment_environment "$TARGET_ENV"
    note_change "deployment_environment ${OLD_ENV:-(unset)} -> ${TARGET_ENV} (config)"
fi

# baseDir and site_template together are how the platform finds its own files;
# siteDir, upload_dir and static_files_dir are derived from them in the config
# itself, so setting these two settles all five.
EXPECT_BASE="$(dirname "$SITE_DIR")/"
OLD_BASE="$(config_value baseDir)"
if [ "$OLD_BASE" != "$EXPECT_BASE" ]; then
    config_set baseDir "$EXPECT_BASE"
    note_change "baseDir ${OLD_BASE:-(unset)} -> ${EXPECT_BASE} (config)"
fi

EXPECT_TEMPLATE="$(basename "$SITE_DIR")"
OLD_TEMPLATE="$(config_value site_template)"
if [ "$OLD_TEMPLATE" != "$EXPECT_TEMPLATE" ]; then
    config_set site_template "$EXPECT_TEMPLATE"
    note_change "site_template ${OLD_TEMPLATE:-(unset)} -> ${EXPECT_TEMPLATE} (config)"
fi

# ── 3. The database must open with the TARGET's credentials ────────────────
#
# This is the check the drill needed and did not have. The restored database is
# the source's, but the role that opens it is this machine's, and the password
# that connects them is in the config we just refused to overwrite. If that does
# not work, every page on the site is about to log SQLSTATE[08006] — so fail
# here, where the message says what actually happened.

DB_NAME="$(config_value dbname)"
DB_USER="$(config_value dbusername)"
DB_PASS="$(config_value dbpassword)"

if command -v psql > /dev/null 2>&1 && [ -n "$DB_NAME" ]; then
    export PGPASSWORD="$DB_PASS"
    if ! psql -U "${DB_USER:-postgres}" -d "$DB_NAME" -tAc 'SELECT 1' > /dev/null 2>&1; then
        print_error "The restored database '${DB_NAME}' will not open with this machine's credentials."
        print_error "The site's config holds this machine's password; the role on this PostgreSQL"
        print_error "does not match it. Fix the role's password (or the config) and re-run."
        echo "RECONCILE_FAILED database_credentials"
        unset PGPASSWORD
        exit 1
    fi

    # webDir lives in stg_settings too, and the two disagreeing is worse than
    # either being wrong: the config decides where files are written and the
    # setting decides what links are built.
    DB_DOMAIN=$(psql -U "${DB_USER:-postgres}" -d "$DB_NAME" -tAc \
        "SELECT stg_value FROM stg_settings WHERE stg_name = 'webDir'" 2>/dev/null | head -1 | tr -d '[:space:]')
    if [ -n "$DB_DOMAIN" ] && [ "$DB_DOMAIN" != "$DOMAIN" ]; then
        if psql -U "${DB_USER:-postgres}" -d "$DB_NAME" -q -c \
                "UPDATE stg_settings SET stg_value = '$(printf '%s' "$DOMAIN" | sed "s/'/''/g")' WHERE stg_name = 'webDir'" > /dev/null 2>&1; then
            note_change "webDir ${DB_DOMAIN} -> ${DOMAIN} (stg_settings)"
        else
            print_warning "Could not update webDir in stg_settings."
        fi
    fi
    unset PGPASSWORD
else
    print_warning "psql is not available here — the database was not checked and stg_settings was not updated."
fi

# ── 4. How the site is served ──────────────────────────────────────────────
#
# The captured virtualhost is NEVER installed, whatever shape it came from.
#
# It is the one file the installer has just written correctly for this box, this
# domain and this shape. And the template keeps improving — the <IfFile> guard on
# the :443 block, the static_files alias, the www alias all arrived after sites
# were already running — so installing an old capture quietly reverts them, and
# the older the backup the worse the restore.
#
# The asymmetry is the other half of the reason. A container backup is missing
# the piece that terminates TLS (the host's proxy lives outside the container,
# so no backup contains it); a bare-metal backup carries a piece a container must
# never use. Neither direction can be handled by copying files.

# Looked for in apache_config/ specifically, which is where both backup engines
# put it. A wider search would happily pick up a .conf belonging to the site's
# own files and compare the live virtualhost against an application config.
CAPTURED_VHOST=""
if [ -n "$BACKUP_META" ] && [ -d "${BACKUP_META}/apache_config" ]; then
    CAPTURED_VHOST="$(find "${BACKUP_META}/apache_config" -maxdepth 1 -name '*.conf' -type f 2>/dev/null | head -1)"
fi

if [ "$SKIP_WEB_CONFIG" = true ]; then
    echo "RECONCILE_WEB_CONFIG skipped"
elif [ "$TARGET_ENV" = "docker" ]; then
    # The container's internal virtualhost was written by _site_init.sh at
    # install time and is already correct: ServerName {site}.site, port 80, no
    # certificate. The public face is the HOST's proxy virtualhost, which is
    # outside this filesystem — the restore job sets that with manage_domain.sh
    # on the host, and a restore run by hand from inside the container cannot.
    echo "RECONCILE_WEB_CONFIG container_internal_left_alone"
    print_info "This is a container: its internal virtualhost is correct as installed."
    print_info "The public name is served by the HOST's proxy — set it there with:"
    print_info "  manage_domain.sh set ${SITENAME} ${DOMAIN}"
else
    VHOST_PATH="/etc/apache2/sites-available/${SITENAME}.conf"
    GEN="${SCRIPT_DIR}/virtualhost_update_script.sh"
    if [ ! -f "$GEN" ]; then
        print_warning "virtualhost_update_script.sh is not beside this script — leaving Apache alone."
        echo "RECONCILE_WEB_CONFIG generator_missing"
    else
        # Keep the captured one for comparison BEFORE the generator overwrites
        # whatever is live.
        PREV_LIVE=""
        if [ -f "$VHOST_PATH" ]; then
            PREV_LIVE="$(mktemp)"
            $SUDO cat "$VHOST_PATH" > "$PREV_LIVE" 2>/dev/null || PREV_LIVE=""
        fi

        print_info "Regenerating the virtualhost for ${SITENAME} at ${DOMAIN}..."
        if echo y | $SUDO bash "$GEN" "${SITENAME}.conf" "$SITENAME" "$DOMAIN" > /dev/null 2>&1; then
            note_change "virtualhost regenerated for ${DOMAIN}"
            echo "RECONCILE_WEB_CONFIG regenerated"
        else
            print_error "Could not regenerate the virtualhost — the site may not be served correctly."
            echo "RECONCILE_FAILED virtualhost"
            [ -n "$PREV_LIVE" ] && rm -f "$PREV_LIVE"
            exit 1
        fi

        # Nothing is discarded. A hand-added redirect, alias or ServerAlias in the
        # backup's copy survives on disk and is named in the output, rather than
        # being applied unattended — applying an unknown config unattended is
        # exactly how a rebuild loses HTTPS.
        if [ -n "$CAPTURED_VHOST" ] && [ -f "$CAPTURED_VHOST" ]; then
            if ! $SUDO diff -q "$CAPTURED_VHOST" "$VHOST_PATH" > /dev/null 2>&1; then
                KEEP="/etc/apache2/sites-available/${SITENAME}.conf.from-backup"
                if $SUDO cp "$CAPTURED_VHOST" "$KEEP" 2>/dev/null; then
                    echo "RECONCILE_VHOST_KEPT ${KEEP}"
                    print_warning "The backup's virtualhost differs from the generated one."
                    print_warning "It was NOT installed. Kept at ${KEEP} for review."
                fi
            fi
        fi

        $SUDO a2ensite "$SITENAME" > /dev/null 2>&1 || true
        if $SUDO apache2ctl configtest > /dev/null 2>&1; then
            $SUDO systemctl reload apache2 > /dev/null 2>&1 \
                || $SUDO apache2ctl graceful > /dev/null 2>&1 || true
            print_success "Apache reloaded with the regenerated virtualhost."
        else
            print_error "Apache rejected the regenerated configuration — not reloading."
            echo "RECONCILE_FAILED apache_configtest"
            [ -n "$PREV_LIVE" ] && rm -f "$PREV_LIVE"
            exit 1
        fi
        [ -n "$PREV_LIVE" ] && rm -f "$PREV_LIVE"
    fi
fi

# ── 5. The certificate arrives on its own ──────────────────────────────────
#
# Never waited for, never a follow-up job. The retry timer already installed on
# every box checks DNS every five minutes and does nothing until the domain
# resolves HERE, then issues once and disables itself. The <IfFile> guard on the
# :443 block means the site serves HTTP until then rather than Apache refusing
# to start.
#
# The narrow gap this closes: the installer armed the timer for the domain IT
# installed. A restore that sets a different domain leaves that timer watching
# the old name and nothing watching the new one.

if [ "$SKIP_SSL" = true ]; then
    echo "RECONCILE_SSL skipped"
elif [ "$TARGET_ENV" = "docker" ]; then
    # Certbot, the certificate and the timer all live on the host in this shape.
    echo "RECONCILE_SSL host_owned"
else
    ARMER="${SCRIPT_DIR}/arm_ssl_retry.sh"
    SETUP_SSL="${SITE_DIR}/maintenance_scripts/sysadmin_tools/setup_ssl.sh"
    if [ -f "$ARMER" ]; then
        if $SUDO bash "$ARMER" "$DOMAIN" --setup-ssl "$SETUP_SSL" > /dev/null 2>&1; then
            echo "RECONCILE_SSL armed ${DOMAIN}"
            print_success "Certificate retry armed for ${DOMAIN} — it issues on its own once DNS points here."
            # The old name's timer is now watching for a certificate nobody wants.
            if [ -n "$OLD_DOMAIN" ] && [ "$OLD_DOMAIN" != "$DOMAIN" ]; then
                if $SUDO bash "$ARMER" "$OLD_DOMAIN" --disarm > /dev/null 2>&1; then
                    echo "RECONCILE_SSL disarmed ${OLD_DOMAIN}"
                fi
            fi
        else
            echo "RECONCILE_SSL unavailable ${DOMAIN}"
            print_warning "Could not arm the certificate retry. Once ${DOMAIN} resolves here, run:"
            print_warning "  sudo ${SETUP_SSL} ${DOMAIN}"
        fi
    fi
fi

echo "RECONCILE_CHANGES ${CHANGES}"
echo "RECONCILE_OK"
exit 0
