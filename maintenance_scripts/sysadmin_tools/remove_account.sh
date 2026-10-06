#!/usr/bin/env bash
#VERSION 2.5 - The site's own network goes with its container, and a network left alone counts as
#              a docker site still to remove (specs/node_outbound_and_transfer.md WP2)
#VERSION 2.4 - The site's HTTPS certificates go with its vhost: every Let's Encrypt lineage and
#              placeholder the vhost named is removed by the sibling remove_site_certificate.sh
#              once nothing on the machine uses it, so certbot stops renewing a domain that left.
#              The site's certbot-made <site>-le-ssl.conf and its vhost backups
#              (<site>.conf.<suffix>) go with its vhost
#VERSION 2.3 - The site's run spec on the Docker host goes with its container (specs/
#              multi_tenant_docker_hosts.md WP0)
#VERSION 2.2 - Self-verifying: after removal the script re-probes (container, volumes, vhost,
#              web root) and emits DECOMMISSION_VERIFIED or DECOMMISSION_FAILED_VERIFY (exit 1),
#              so one run carries its own verdict instead of needing a separate verify step.
#              Orphaned volumes count as a docker site during detection, so a re-run after a
#              failed volume removal removes them instead of reporting nothing to do
#VERSION 2.1 - Machine-readable terminal markers (REMOVE_ACCOUNT_OK / REMOVE_ACCOUNT_NOTHING);
#              nothing-to-remove is now idempotent success (exit 0) so re-runs are safe as a job step
#VERSION 2.0 - Added Docker support
#Usage:  ./remove_account.sh site_name [-y]
#
# Removes a Joinery site completely, whether Docker or bare-metal.
# Automatically detects the deployment type.
#
# Options:
#   -y, --yes    Skip confirmation prompt

set -e

# =============================================================================
# ARGUMENT PARSING
# =============================================================================

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: You must run as sudo or root."
    exit 1
fi

SITE_NAME=""
ASSUME_YES=false

while [[ $# -gt 0 ]]; do
    case $1 in
        -y|--yes)
            ASSUME_YES=true
            shift
            ;;
        -*)
            echo "Unknown option: $1"
            exit 1
            ;;
        *)
            if [ -z "$SITE_NAME" ]; then
                SITE_NAME="$1"
            else
                echo "ERROR: Unexpected argument: $1"
                exit 1
            fi
            shift
            ;;
    esac
done

if [ -z "$SITE_NAME" ]; then
    echo "Usage:  sudo ./remove_account.sh site_name [-y]"
    echo "ERROR: You must pass the site name to remove as the first argument."
    exit 1
fi

# =============================================================================
# DETECT DEPLOYMENT TYPE
# =============================================================================

SITE_ROOT="/var/www/html/$SITE_NAME"
TEST_SITE_ROOT="/var/www/html/${SITE_NAME}_test"
VIRTUALHOST_FILE="/etc/apache2/sites-available/$SITE_NAME.conf"
# The SSL vhost certbot's Apache installer wrote beside it on older installs.
LE_SSL_FILE="/etc/apache2/sites-available/$SITE_NAME-le-ssl.conf"

IS_DOCKER=false
IS_BAREMETAL=false

# Check for Docker container — or its volumes. Volumes alone happen when an
# earlier removal deleted the container and then failed on `docker volume rm`;
# a re-run must see that as a docker site still needing removal, not as
# nothing (which would verify the site gone while its data sits orphaned).
if command -v docker &> /dev/null; then
    if docker ps -a --format '{{.Names}}' | grep -qw "^${SITE_NAME}$"; then
        IS_DOCKER=true
    elif docker volume ls --format '{{.Name}}' 2>/dev/null | grep -q "^${SITE_NAME}_"; then
        IS_DOCKER=true
    elif docker network inspect "${SITE_NAME}_net" > /dev/null 2>&1; then
        IS_DOCKER=true
    fi
fi

# Check for bare-metal installation
if [ -d "$SITE_ROOT" ] || [ -f "$VIRTUALHOST_FILE" ] || [ -f "$LE_SSL_FILE" ]; then
    IS_BAREMETAL=true
fi

# The certificates the vhost serves with, read before the vhost goes: each
# Let's Encrypt lineage (/etc/letsencrypt/live/<name>/) and placeholder
# directory (/etc/ssl/joinery/<domain>) it names. Names only; the sibling
# script re-validates each and refuses one another enabled site still uses.
CERT_NAMES=""
for vh in "$VIRTUALHOST_FILE" "$LE_SSL_FILE"; do
    [ -f "$vh" ] || continue
    CERT_NAMES="$CERT_NAMES"$'\n'$( { grep -oE '/etc/letsencrypt/live/[a-z0-9.-]+/' "$vh" | cut -d/ -f5
                    grep -oE '/etc/ssl/joinery/[a-z0-9.-]+' "$vh" | cut -d/ -f5; } 2>/dev/null )
done
CERT_NAMES=$(printf '%s\n' "$CERT_NAMES" | grep -v '^$' | sort -u)

if [ "$IS_DOCKER" = false ] && [ "$IS_BAREMETAL" = false ]; then
    # Nothing to remove. This is idempotent success, not an error: a decommission
    # job re-run (or a teardown that already happened) must not fail here. The
    # marker lets the caller distinguish "already gone" from "removed just now".
    echo "No site found with name '$SITE_NAME':"
    echo "  - No Docker container or volumes named '$SITE_NAME'"
    echo "  - No directory at $SITE_ROOT"
    echo "  - No virtual host at $VIRTUALHOST_FILE"
    echo "REMOVE_ACCOUNT_NOTHING $SITE_NAME"
    echo "DECOMMISSION_VERIFIED $SITE_NAME"
    exit 0
fi

# =============================================================================
# CONFIRMATION
# =============================================================================

echo "WARNING: This will completely remove site '$SITE_NAME' and all its data!"
echo ""

if [ "$IS_DOCKER" = true ]; then
    echo "Detected: DOCKER deployment"
    echo "This includes:"
    echo "  - Docker container: $SITE_NAME"
    echo "  - Docker volumes: ${SITE_NAME}_* (postgres, uploads, config, etc.)"
    echo "  - Docker image: joinery-${SITE_NAME}:latest"
fi

if [ "$IS_BAREMETAL" = true ]; then
    if [ "$IS_DOCKER" = true ]; then
        echo ""
        echo "Also detected: BARE-METAL components"
    else
        echo "Detected: BARE-METAL deployment"
    fi
    echo "This includes:"
    echo "  - Website files: $SITE_ROOT"
    echo "  - Test site files: $TEST_SITE_ROOT"
    echo "  - Apache virtual host: $VIRTUALHOST_FILE"
    if [ -f "$LE_SSL_FILE" ]; then
        echo "  - Apache SSL virtual host: $LE_SSL_FILE"
    fi
    echo "  - PostgreSQL database: $SITE_NAME"
    for cert in $CERT_NAMES; do
        echo "  - HTTPS certificate: $cert"
    done
fi

echo ""
echo "This action CANNOT be undone!"
echo ""

if [ "$ASSUME_YES" = false ]; then
    read -p "Are you sure you want to continue? (type 'yes' to confirm): " confirm
    if [ "$confirm" != "yes" ]; then
        echo "Operation cancelled."
        exit 0
    fi
fi

# =============================================================================
# DOCKER REMOVAL
# =============================================================================

if [ "$IS_DOCKER" = true ]; then
    echo ""
    echo "=== Removing Docker site ==="

    # Stop container
    echo "Stopping container..."
    if docker stop "$SITE_NAME" 2>/dev/null; then
        echo "Container stopped: $SITE_NAME"
    else
        echo "Container was not running or already stopped"
    fi

    # Remove container
    echo "Removing container..."
    if docker rm "$SITE_NAME" 2>/dev/null; then
        echo "Container removed: $SITE_NAME"
    else
        echo "WARNING: Failed to remove container"
    fi

    # The site's own network (specs/node_outbound_and_transfer.md WP2): the one
    # its run spec names, or the one named for it when the spec is already gone.
    SITE_NETWORK=$(sed -n 's/^network=//p' "/etc/joinery/sites/${SITE_NAME}/run_spec" 2>/dev/null | tail -1)
    SITE_NETWORK="${SITE_NETWORK:-${SITE_NAME}_net}"
    if docker network inspect "$SITE_NETWORK" > /dev/null 2>&1; then
        if docker network rm "$SITE_NETWORK" > /dev/null 2>&1; then
            echo "Network removed: $SITE_NETWORK"
        else
            echo "WARNING: Failed to remove network: $SITE_NETWORK"
        fi
    fi

    # Remove volumes
    echo "Removing volumes..."
    VOLUMES=$(docker volume ls --format '{{.Name}}' | grep "^${SITE_NAME}_" || true)
    if [ -n "$VOLUMES" ]; then
        for vol in $VOLUMES; do
            if docker volume rm "$vol" 2>/dev/null; then
                echo "  Removed volume: $vol"
            else
                echo "  WARNING: Failed to remove volume: $vol"
            fi
        done
    else
        echo "  No volumes found for $SITE_NAME"
    fi

    # Remove image
    echo "Removing Docker image..."
    if docker rmi "joinery-${SITE_NAME}:latest" 2>/dev/null; then
        echo "Image removed: joinery-${SITE_NAME}:latest"
    else
        echo "Image joinery-${SITE_NAME}:latest does not exist or already removed"
    fi

    # How the container was run (_site_run_spec.sh's run_spec_path; spelled
    # here because this script ships alone and sources nothing). The rest of
    # the site's state directory is not this branch's to remove.
    rm -f "/etc/joinery/sites/${SITE_NAME}/run_spec"

    # Clean up build directory if exists
    BUILD_DIR="/root/${SITE_NAME}-build"
    if [ -d "$BUILD_DIR" ]; then
        rm -rf "${BUILD_DIR:?}"
        echo "Removed build directory: $BUILD_DIR"
    fi

    echo "Docker cleanup complete."
fi

# =============================================================================
# BARE-METAL REMOVAL
# =============================================================================

if [ "$IS_BAREMETAL" = true ]; then
    echo ""
    echo "=== Removing bare-metal site ==="

    # Disable Apache site if enabled
    echo "Disabling Apache site..."
    if [ -f "$VIRTUALHOST_FILE" ]; then
        a2dissite "$SITE_NAME.conf" 2>/dev/null || echo "Site was not enabled or already disabled"
    else
        echo "Virtual host file does not exist, skipping Apache disable"
    fi
    if [ -f "$LE_SSL_FILE" ]; then
        a2dissite "$SITE_NAME-le-ssl.conf" 2>/dev/null || echo "SSL site was not enabled or already disabled"
    fi

    # Test Apache configuration
    echo "Testing Apache configuration..."
    if ! apache2ctl configtest 2>/dev/null; then
        echo "WARNING: Apache configuration test failed, but continuing with cleanup"
    fi

    # Reload Apache
    echo "Reloading Apache..."
    if ! systemctl reload apache2 2>/dev/null; then
        echo "WARNING: Failed to reload Apache, but continuing with cleanup"
    fi

    # Remove website directories
    echo "Removing website directories..."
    if [ -d "$SITE_ROOT" ]; then
        rm -rf "${SITE_ROOT:?}"
        echo "Removed: $SITE_ROOT"
    else
        echo "Directory $SITE_ROOT does not exist"
    fi

    if [ -d "$TEST_SITE_ROOT" ]; then
        rm -rf "${TEST_SITE_ROOT:?}"
        echo "Removed: $TEST_SITE_ROOT"
    else
        echo "Directory $TEST_SITE_ROOT does not exist"
    fi

    # Remove Apache virtual host file
    echo "Removing Apache virtual host..."
    if [ -f "$VIRTUALHOST_FILE" ]; then
        rm -f "$VIRTUALHOST_FILE"
        echo "Removed: $VIRTUALHOST_FILE"
    else
        echo "Virtual host file $VIRTUALHOST_FILE does not exist"
    fi
    if [ -f "$LE_SSL_FILE" ]; then
        rm -f "$LE_SSL_FILE"
        echo "Removed: $LE_SSL_FILE"
    fi
    # The vhost's backups (render_vhost.sh's .before-render.<time>, .bak, .new).
    for backup in "$VIRTUALHOST_FILE".*; do
        [ -f "$backup" ] || continue
        rm -f "$backup"
        echo "Removed: $backup"
    done

    # Remove the certificates the vhost named, now that no vhost serves with
    # them: certbot stops renewing a domain that has left this machine.
    if [ -n "$CERT_NAMES" ]; then
        echo "Removing HTTPS certificates..."
        CERT_SCRIPT="$(dirname "$0")/remove_site_certificate.sh"
        for cert in $CERT_NAMES; do
            if [ -f "$CERT_SCRIPT" ]; then
                if ! result=$(bash "$CERT_SCRIPT" "$cert" 2>&1); then
                    echo "  WARNING: certificate $cert kept: $result"
                else
                    echo "  $result"
                fi
            else
                echo "  WARNING: certificate $cert kept: remove_site_certificate.sh is not beside this script"
            fi
        done
    fi

    # Remove PostgreSQL database
    echo "Removing PostgreSQL database..."
    if dropdb -U postgres "$SITE_NAME" 2>/dev/null; then
        echo "Removed database: $SITE_NAME"
    else
        echo "Database '$SITE_NAME' does not exist or already removed"
    fi

    # Also try to remove test database
    if dropdb -U postgres "${SITE_NAME}_test" 2>/dev/null; then
        echo "Removed test database: ${SITE_NAME}_test"
    fi

    echo "Bare-metal cleanup complete."
fi

# =============================================================================
# VERIFY GONE
# =============================================================================
# Re-probe everything a removal is supposed to have taken away. The verdict
# marker is what a caller trusts — not the exit codes of the removal commands
# above, several of which warn-and-continue.

LEFTOVERS=""

if command -v docker &> /dev/null; then
    if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qw "^${SITE_NAME}$"; then
        LEFTOVERS="${LEFTOVERS}  - Docker container still present: $SITE_NAME"$'\n'
    fi
    LEFT_VOLUMES=$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep "^${SITE_NAME}_" || true)
    if [ -n "$LEFT_VOLUMES" ]; then
        LEFTOVERS="${LEFTOVERS}  - Docker volumes still present: $(echo "$LEFT_VOLUMES" | tr '\n' ' ')"$'\n'
    fi
    if docker network inspect "${SITE_NETWORK:-${SITE_NAME}_net}" > /dev/null 2>&1; then
        LEFTOVERS="${LEFTOVERS}  - Docker network still present: ${SITE_NETWORK:-${SITE_NAME}_net}"$'\n'
    fi
fi

for vh in "$VIRTUALHOST_FILE" "$LE_SSL_FILE"; do
    if [ -f "$vh" ]; then
        LEFTOVERS="${LEFTOVERS}  - Virtual host still present: $vh"$'\n'
    fi
done

if [ -d "$SITE_ROOT" ]; then
    LEFTOVERS="${LEFTOVERS}  - Web root still present: $SITE_ROOT"$'\n'
fi

# =============================================================================
# COMPLETE
# =============================================================================

echo ""
echo "=========================================="
echo "Site '$SITE_NAME' has been removed."
echo "=========================================="
echo ""
echo "REMOVE_ACCOUNT_OK $SITE_NAME"

if [ -n "$LEFTOVERS" ]; then
    echo "Removal ran but the site could not be verified gone:"
    printf '%s' "$LEFTOVERS"
    echo "DECOMMISSION_FAILED_VERIFY $SITE_NAME"
    exit 1
fi

echo "DECOMMISSION_VERIFIED $SITE_NAME"
echo "You can now run 'install.sh site' to create a new site."