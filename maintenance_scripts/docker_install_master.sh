#!/usr/bin/env bash
#VERSION 1.2 - Docker installation master script
#
# Usage: ./docker_install_master.sh SITENAME POSTGRES_PASSWORD [DOMAIN_NAME] [PORT]
#        ./docker_install_master.sh --list
#
# This script automates the entire Docker installation process.
# Run this script after extracting the joinery archive on your target server.
#
# Features:
#   - Automatic Docker installation if not present
#   - Port conflict detection with automatic suggestion
#   - Site-isolated build contexts (supports multiple sites)
#   - Automatic cleanup after build
#
# Example (single site):
#   tar -xzf joinery-2-21.tar.gz
#   cd maintenance_scripts
#   ./docker_install_master.sh mysite SecurePass123! mysite.com 8080
#
# Example (multiple sites):
#   ./docker_install_master.sh site1 Pass123! site1.com 8080
#   ./docker_install_master.sh site2 Pass456! site2.com 8081
#
# List existing sites:
#   ./docker_install_master.sh --list
#

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

#------------------------------------------------------------------------------
# Helper Functions
#------------------------------------------------------------------------------

print_header() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_step() {
    echo -e "${GREEN}[STEP]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

#------------------------------------------------------------------------------
# Port Management Functions
#------------------------------------------------------------------------------

# Check if a port is in use (by system or Docker)
is_port_in_use() {
    local port=$1

    # Check system ports using ss (preferred) or netstat
    if command -v ss &> /dev/null; then
        if ss -tuln | grep -q ":${port} "; then
            return 0
        fi
    elif command -v netstat &> /dev/null; then
        if netstat -tuln | grep -q ":${port} "; then
            return 0
        fi
    fi

    # Check Docker container port mappings
    if command -v docker &> /dev/null && docker info &> /dev/null 2>&1; then
        if docker ps --format '{{.Ports}}' 2>/dev/null | grep -q "0.0.0.0:${port}->"; then
            return 0
        fi
    fi

    return 1
}

# Find next available port starting from given port
find_available_port() {
    local start_port=$1
    local port=$start_port
    local max_port=$((start_port + 100))

    while [ $port -lt $max_port ]; do
        if ! is_port_in_use $port && ! is_port_in_use $((port + 1000)); then
            echo $port
            return 0
        fi
        port=$((port + 1))
    done

    echo ""
    return 1
}

# List existing Joinery containers with their ports
list_joinery_containers() {
    echo ""
    echo -e "${BLUE}Existing Joinery containers:${NC}"
    echo "───────────────────────────────────────────────────────────────"
    printf "%-20s %-15s %-12s %s\n" "SITE NAME" "WEB PORT" "DB PORT" "STATUS"
    echo "───────────────────────────────────────────────────────────────"

    local found=0
    while IFS= read -r line; do
        if [ -n "$line" ]; then
            local name=$(echo "$line" | awk '{print $1}')
            local ports=$(echo "$line" | awk '{print $2}')
            local status=$(echo "$line" | awk '{$1=$2=""; print $0}' | xargs)

            # Extract web port (format: 0.0.0.0:8080->80/tcp)
            local web_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->80)' | head -1)
            local db_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->5432)' | head -1)

            if [ -n "$web_port" ]; then
                printf "%-20s %-15s %-12s %s\n" "$name" "$web_port" "${db_port:-N/A}" "$status"
                found=1
            fi
        fi
    done < <(docker ps -a --filter "ancestor=joinery-*" --format "{{.Names}} {{.Ports}} {{.Status}}" 2>/dev/null)

    # Also check by naming convention if ancestor filter didn't work
    if [ $found -eq 0 ]; then
        while IFS= read -r line; do
            if [ -n "$line" ]; then
                local name=$(echo "$line" | awk '{print $1}')
                local image=$(echo "$line" | awk '{print $2}')
                local ports=$(echo "$line" | awk '{print $3}')
                local status=$(echo "$line" | awk '{$1=$2=$3=""; print $0}' | xargs)

                # Check if image starts with joinery-
                if [[ "$image" == joinery-* ]]; then
                    local web_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->80)' | head -1)
                    local db_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->5432)' | head -1)

                    printf "%-20s %-15s %-12s %s\n" "$name" "${web_port:-N/A}" "${db_port:-N/A}" "$status"
                    found=1
                fi
            fi
        done < <(docker ps -a --format "{{.Names}} {{.Image}} {{.Ports}} {{.Status}}" 2>/dev/null)
    fi

    if [ $found -eq 0 ]; then
        echo "  (no existing Joinery containers found)"
    fi
    echo "───────────────────────────────────────────────────────────────"
    echo ""
}

#------------------------------------------------------------------------------
# Validation
#------------------------------------------------------------------------------

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root (use sudo)"
    exit 1
fi

# Handle --list flag to show existing containers
if [ "$1" = "--list" ] || [ "$1" = "-l" ]; then
    if command -v docker &> /dev/null && docker info &> /dev/null 2>&1; then
        list_joinery_containers
        exit 0
    else
        print_error "Docker is not installed or not running"
        exit 1
    fi
fi

# Check parameters
if [ -z "$1" ]; then
    echo "Usage: $0 SITENAME POSTGRES_PASSWORD [DOMAIN_NAME] [PORT]"
    echo "       $0 --list"
    echo ""
    echo "Parameters:"
    echo "  SITENAME          - Site/database name (required, e.g., 'mysite')"
    echo "  POSTGRES_PASSWORD - Database password (required)"
    echo "  DOMAIN_NAME       - Domain for VirtualHost (optional, defaults to server IP)"
    echo "  PORT              - Host port for web traffic (optional, defaults to 8080)"
    echo ""
    echo "Options:"
    echo "  --list, -l        - List existing Joinery containers and their ports"
    echo ""
    echo "Example:"
    echo "  $0 mysite YOUR_SECURE_PASSWORD mysite.com 8080"
    echo ""
    echo "Multiple sites:"
    echo "  $0 site1 YOUR_PASSWORD_1 site1.com 8080"
    echo "  $0 site2 YOUR_PASSWORD_2 site2.com 8081"
    exit 1
fi

if [ -z "$2" ]; then
    print_error "POSTGRES_PASSWORD is required"
    echo "Usage: $0 SITENAME POSTGRES_PASSWORD [DOMAIN_NAME] [PORT]"
    exit 1
fi

# Set parameters
SITENAME="$1"
POSTGRES_PASSWORD="$2"
DOMAIN_NAME="${3:-localhost}"
PORT="${4:-8080}"
DB_PORT=$((PORT + 1000))

# Auto-detect server IP if domain is localhost
if [ "$DOMAIN_NAME" = "localhost" ]; then
    SERVER_IP=$(hostname -I | awk '{print $1}')
    if [ -n "$SERVER_IP" ]; then
        DOMAIN_NAME="$SERVER_IP"
        print_info "Auto-detected server IP: $DOMAIN_NAME"
    fi
fi

#------------------------------------------------------------------------------
# Port Conflict Detection
#------------------------------------------------------------------------------

print_step "Checking port availability..."

PORT_CONFLICT=0
SUGGESTED_PORT=""

# Check web port
if is_port_in_use $PORT; then
    print_warning "Port $PORT is already in use"
    PORT_CONFLICT=1
fi

# Check database port
if is_port_in_use $DB_PORT; then
    print_warning "Database port $DB_PORT is already in use"
    PORT_CONFLICT=1
fi

if [ $PORT_CONFLICT -eq 1 ]; then
    # Show existing containers for context
    if command -v docker &> /dev/null && docker info &> /dev/null 2>&1; then
        list_joinery_containers
    fi

    # Find next available port
    SUGGESTED_PORT=$(find_available_port 8080)

    if [ -n "$SUGGESTED_PORT" ]; then
        echo ""
        echo -e "Suggested available port: ${GREEN}$SUGGESTED_PORT${NC} (database: $((SUGGESTED_PORT + 1000)))"
        echo ""
        read -p "Would you like to use port $SUGGESTED_PORT instead? [Y/n] " -n 1 -r
        echo ""

        if [[ ! $REPLY =~ ^[Nn]$ ]]; then
            PORT=$SUGGESTED_PORT
            DB_PORT=$((PORT + 1000))
            print_success "Using port $PORT (database: $DB_PORT)"
        else
            print_error "Cannot continue with port conflict. Please specify a different port."
            echo ""
            echo "Usage: $0 $SITENAME $POSTGRES_PASSWORD $DOMAIN_NAME <PORT>"
            echo "Example: $0 $SITENAME $POSTGRES_PASSWORD $DOMAIN_NAME $SUGGESTED_PORT"
            exit 1
        fi
    else
        print_error "Could not find an available port in range 8080-8180"
        print_error "Please specify a port manually or free up existing ports"
        exit 1
    fi
else
    print_success "Ports $PORT and $DB_PORT are available"
fi

#------------------------------------------------------------------------------
# Verify Archive Structure
#------------------------------------------------------------------------------

print_header "Joinery Docker Installation"

print_step "Verifying archive structure..."

# Check we're in the right place (maintenance_scripts directory)
if [ ! -f "$SCRIPT_DIR/server_setup.sh" ]; then
    print_error "Cannot find server_setup.sh in $SCRIPT_DIR"
    print_error "This script must be run from the maintenance_scripts directory of an extracted archive"
    exit 1
fi

# Determine archive root (parent of maintenance_scripts)
ARCHIVE_ROOT="$(dirname "$SCRIPT_DIR")"

# Check for required directories/files
if [ ! -d "$ARCHIVE_ROOT/public_html" ]; then
    print_error "Cannot find public_html directory in $ARCHIVE_ROOT"
    print_error "Make sure you've extracted the joinery archive correctly"
    exit 1
fi

if [ ! -d "$ARCHIVE_ROOT/config" ]; then
    print_error "Cannot find config directory in $ARCHIVE_ROOT"
    exit 1
fi

if [ ! -f "$SCRIPT_DIR/Dockerfile.template" ]; then
    print_error "Cannot find Dockerfile.template in $SCRIPT_DIR"
    exit 1
fi

print_success "Archive structure verified"

#------------------------------------------------------------------------------
# Docker Installation Check
#------------------------------------------------------------------------------

print_step "Checking Docker installation..."

if command -v docker &> /dev/null; then
    DOCKER_VERSION=$(docker --version)
    print_success "Docker is installed: $DOCKER_VERSION"
else
    print_warning "Docker is not installed"
    echo ""
    read -p "Would you like to install Docker now? [y/N] " -n 1 -r
    echo ""

    if [[ $REPLY =~ ^[Yy]$ ]]; then
        print_step "Installing Docker..."

        # Update packages
        apt-get update

        # Install prerequisites
        apt-get install -y ca-certificates curl gnupg lsb-release

        # Add Docker's GPG key
        mkdir -m 0755 -p /etc/apt/keyrings
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg

        # Add Docker repository
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

        # Install Docker
        apt-get update
        apt-get install -y docker-ce docker-ce-cli containerd.io

        # Verify installation
        if command -v docker &> /dev/null; then
            print_success "Docker installed successfully"
        else
            print_error "Docker installation failed"
            exit 1
        fi
    else
        print_error "Docker is required. Please install Docker and run this script again."
        exit 1
    fi
fi

# Verify Docker is running
if ! docker info &> /dev/null; then
    print_warning "Docker daemon is not running. Starting Docker..."
    systemctl start docker
    sleep 2
    if ! docker info &> /dev/null; then
        print_error "Failed to start Docker daemon"
        exit 1
    fi
    print_success "Docker daemon started"
fi

#------------------------------------------------------------------------------
# Check for Existing Container
#------------------------------------------------------------------------------

print_step "Checking for existing container named '$SITENAME'..."

if docker ps -a --format '{{.Names}}' | grep -q "^${SITENAME}$"; then
    print_warning "A container named '$SITENAME' already exists"
    echo ""
    read -p "Would you like to remove it and continue? [y/N] " -n 1 -r
    echo ""

    if [[ $REPLY =~ ^[Yy]$ ]]; then
        print_info "Stopping and removing existing container..."
        docker stop "$SITENAME" 2>/dev/null || true
        docker rm "$SITENAME" 2>/dev/null || true
        print_success "Existing container removed"
    else
        print_error "Cannot continue with existing container. Please remove it or choose a different SITENAME."
        exit 1
    fi
else
    print_success "No existing container found"
fi

#------------------------------------------------------------------------------
# Prepare Build Context
#------------------------------------------------------------------------------

print_step "Preparing build context..."

# Use site-isolated build directory (prevents any shared file issues)
BUILD_DIR=~/joinery-docker-build-${SITENAME}

# Clean up any existing build directory for this site
if [ -d "$BUILD_DIR" ]; then
    print_info "Cleaning up existing build directory..."
    rm -rf "$BUILD_DIR"
fi

# Create build directory structure
mkdir -p "$BUILD_DIR/$SITENAME"

# Copy files
print_info "Copying public_html..."
cp -r "$ARCHIVE_ROOT/public_html" "$BUILD_DIR/$SITENAME/"

print_info "Copying config..."
cp -r "$ARCHIVE_ROOT/config" "$BUILD_DIR/$SITENAME/"

print_info "Copying maintenance_scripts..."
mkdir -p "$BUILD_DIR/maintenance_scripts"
cp -r "$SCRIPT_DIR"/* "$BUILD_DIR/maintenance_scripts/"

# Copy Dockerfile
print_info "Setting up Dockerfile..."
cp "$SCRIPT_DIR/Dockerfile.template" "$BUILD_DIR/Dockerfile"

# Create .dockerignore
cat > "$BUILD_DIR/.dockerignore" << 'EOF'
.git
*.log
*/backups/*
EOF

print_success "Build context prepared at $BUILD_DIR"

#------------------------------------------------------------------------------
# Build Docker Image
#------------------------------------------------------------------------------

print_step "Building Docker image (this may take 5-10 minutes)..."

cd "$BUILD_DIR"

docker build \
    --build-arg SITENAME="$SITENAME" \
    --build-arg POSTGRES_PASSWORD="$POSTGRES_PASSWORD" \
    --build-arg DOMAIN_NAME="$DOMAIN_NAME" \
    -t "joinery-$SITENAME" .

if [ $? -eq 0 ]; then
    print_success "Docker image built successfully"
else
    print_error "Docker image build failed"
    exit 1
fi

#------------------------------------------------------------------------------
# Run Container
#------------------------------------------------------------------------------

print_step "Starting container..."

docker run -d \
    --name "$SITENAME" \
    -p "$PORT":80 \
    -p "$DB_PORT":5432 \
    -v "${SITENAME}_postgres":/var/lib/postgresql \
    -v "${SITENAME}_uploads":/var/www/html/"${SITENAME}"/uploads \
    -v "${SITENAME}_config":/var/www/html/"${SITENAME}"/config \
    -v "${SITENAME}_backups":/var/www/html/"${SITENAME}"/backups \
    -v "${SITENAME}_static":/var/www/html/"${SITENAME}"/static_files \
    -v "${SITENAME}_logs":/var/www/html/"${SITENAME}"/logs \
    -v "${SITENAME}_cache":/var/www/html/"${SITENAME}"/cache \
    -v "${SITENAME}_sessions":/var/lib/php/sessions \
    -v "${SITENAME}_apache_logs":/var/log/apache2 \
    -v "${SITENAME}_pg_logs":/var/log/postgresql \
    "joinery-$SITENAME"

if [ $? -eq 0 ]; then
    print_success "Container started"
else
    print_error "Failed to start container"
    exit 1
fi

#------------------------------------------------------------------------------
# Verify Installation
#------------------------------------------------------------------------------

print_step "Waiting for services to initialize..."

# Wait for container to be healthy (up to 60 seconds)
MAX_ATTEMPTS=12
ATTEMPT=1

while [ $ATTEMPT -le $MAX_ATTEMPTS ]; do
    print_info "Checking site availability (attempt $ATTEMPT/$MAX_ATTEMPTS)..."

    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$PORT/" 2>/dev/null || echo "000")

    if [ "$HTTP_CODE" = "200" ]; then
        print_success "Site is responding with HTTP 200"
        break
    elif [ "$HTTP_CODE" = "500" ]; then
        print_warning "Site returned HTTP 500 - may still be initializing..."
    else
        print_info "HTTP response: $HTTP_CODE"
    fi

    if [ $ATTEMPT -eq $MAX_ATTEMPTS ]; then
        print_warning "Site not responding after $MAX_ATTEMPTS attempts"
        print_info "This may be normal - check logs with: docker logs $SITENAME"
    fi

    ATTEMPT=$((ATTEMPT + 1))
    sleep 5
done

#------------------------------------------------------------------------------
# Cleanup Build Directory
#------------------------------------------------------------------------------

print_step "Cleaning up build directory..."
if [ -d "$BUILD_DIR" ]; then
    rm -rf "$BUILD_DIR"
    print_success "Build directory removed"
fi

#------------------------------------------------------------------------------
# Summary
#------------------------------------------------------------------------------

print_header "Installation Complete!"

echo -e "Site Name:        ${GREEN}$SITENAME${NC}"
echo -e "Domain:           ${GREEN}$DOMAIN_NAME${NC}"
echo -e "Web Port:         ${GREEN}$PORT${NC}"
echo -e "Database Port:    ${GREEN}$DB_PORT${NC}"
echo ""
echo -e "Access your site: ${GREEN}http://$DOMAIN_NAME:$PORT/${NC}"
echo ""
echo "Default admin login:"
echo -e "  Email:    ${YELLOW}admin@example.com${NC}"
echo -e "  Password: ${YELLOW}(check documentation)${NC}"
echo ""
echo "Useful commands:"
echo -e "  View logs:      ${BLUE}docker logs $SITENAME${NC}"
echo -e "  Shell access:   ${BLUE}docker exec -it $SITENAME bash${NC}"
echo -e "  Stop container: ${BLUE}docker stop $SITENAME${NC}"
echo -e "  Start container:${BLUE}docker start $SITENAME${NC}"
echo ""

# Check final status
CONTAINER_STATUS=$(docker ps --filter "name=$SITENAME" --format "{{.Status}}" 2>/dev/null)
if [ -n "$CONTAINER_STATUS" ]; then
    echo -e "Container status: ${GREEN}$CONTAINER_STATUS${NC}"
else
    print_warning "Container may not be running. Check logs with: docker logs $SITENAME"
fi

# Show all running Joinery containers
list_joinery_containers

print_success "Docker installation complete!"
