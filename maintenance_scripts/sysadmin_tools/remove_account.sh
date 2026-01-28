#!/usr/bin/env bash
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

IS_DOCKER=false
IS_BAREMETAL=false

# Check for Docker container
if command -v docker &> /dev/null; then
    if docker ps -a --format '{{.Names}}' | grep -qw "^${SITE_NAME}$"; then
        IS_DOCKER=true
    fi
fi

# Check for bare-metal installation
if [ -d "$SITE_ROOT" ] || [ -f "$VIRTUALHOST_FILE" ]; then
    IS_BAREMETAL=true
fi

if [ "$IS_DOCKER" = false ] && [ "$IS_BAREMETAL" = false ]; then
    echo "ERROR: No site found with name '$SITE_NAME'"
    echo "  - No Docker container named '$SITE_NAME'"
    echo "  - No directory at $SITE_ROOT"
    echo "  - No virtual host at $VIRTUALHOST_FILE"
    exit 1
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
    echo "  - PostgreSQL database: $SITE_NAME"
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

    # Clean up build directory if exists
    BUILD_DIR="/root/${SITE_NAME}-build"
    if [ -d "$BUILD_DIR" ]; then
        rm -rf "$BUILD_DIR"
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
        rm -rf "$SITE_ROOT"
        echo "Removed: $SITE_ROOT"
    else
        echo "Directory $SITE_ROOT does not exist"
    fi

    if [ -d "$TEST_SITE_ROOT" ]; then
        rm -rf "$TEST_SITE_ROOT"
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
# COMPLETE
# =============================================================================

echo ""
echo "=========================================="
echo "Site '$SITE_NAME' has been removed."
echo "=========================================="
echo ""
echo "You can now run 'install.sh site' to create a new site."