#!/bin/bash

# Continuous Directory Synchronization Script
# Monitors and syncs local directory with remote directory using rsync over SSH
# Usage: ./sync-continuous.sh [config_file] [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]
#    or: ./sync-continuous.sh [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]

set -e  # Exit on any error

# Configuration
DEFAULT_SSH_PORT=22
DEFAULT_SSH_USER="$USER"
DEFAULT_LOCAL_DIR=""
DEFAULT_REMOTE_HOST=""
DEFAULT_REMOTE_DIR=""
DEFAULT_SYNC_INTERVAL=2  # seconds between sync checks
DEFAULT_WATCH_METHOD="auto"  # auto, inotify, or polling
DEFAULT_MONITOR_PLUGINS=""  # comma-separated list of plugins to monitor
DEFAULT_MONITOR_THEMES=""   # comma-separated list of themes to monitor

# Runtime variables
SYNC_PID=""
SHOULD_EXIT=false
LAST_SYNC_TIME=0
SYNC_IN_PROGRESS=false
SSH_KEY_AUTH=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

# Function to print colored output with timestamp
print_status() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${RED}[ERROR]${NC} $1"
}

print_change() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${MAGENTA}[CHANGE]${NC} $1"
}

# Function to read configuration file
read_config() {
    local config_file="$1"  # Optional specific config file path
    
    if [ -n "$config_file" ]; then
        # Use the specified config file
        if [ ! -f "$config_file" ]; then
            print_error "Specified config file '$config_file' does not exist"
            exit 1
        fi
        if [ ! -r "$config_file" ]; then
            print_error "Specified config file '$config_file' is not readable"
            exit 1
        fi
        print_status "Reading configuration from: $config_file"
    else
        # Look for .syncconfig in current directory first, then home directory
        if [ -f ".syncconfig" ]; then
            config_file=".syncconfig"
        elif [ -f "$HOME/.syncconfig" ]; then
            config_file="$HOME/.syncconfig"
        fi
        
        if [ -n "$config_file" ]; then
            print_status "Reading configuration from: $config_file"
        fi
    fi
    
    if [ -n "$config_file" ]; then
        # Source the config file safely
        while IFS= read -r line || [ -n "$line" ]; do
            # Skip empty lines and comments
            line=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            if [ -n "$line" ] && [[ ! "$line" =~ ^[[:space:]]*# ]]; then
                # Only allow specific variables for security
                if [[ "$line" =~ ^DEFAULT_(LOCAL_DIR|REMOTE_HOST|REMOTE_DIR|SSH_USER|SSH_PORT|SYNC_INTERVAL|WATCH_METHOD|MONITOR_PLUGINS|MONITOR_THEMES)= ]]; then
                    # Extract key and value
                    local key="${line%%=*}"
                    local value="${line#*=}"
                    
                    # Remove quotes if present
                    value=$(echo "$value" | sed 's/^["'\'']*//;s/["'\'']*$//')
                    
                    case "$key" in
                        DEFAULT_LOCAL_DIR)
                            DEFAULT_LOCAL_DIR="$value"
                            ;;
                        DEFAULT_REMOTE_HOST)
                            DEFAULT_REMOTE_HOST="$value"
                            ;;
                        DEFAULT_REMOTE_DIR)
                            DEFAULT_REMOTE_DIR="$value"
                            ;;
                        DEFAULT_SSH_USER)
                            DEFAULT_SSH_USER="$value"
                            ;;
                        DEFAULT_SSH_PORT)
                            DEFAULT_SSH_PORT="$value"
                            ;;
                        DEFAULT_SYNC_INTERVAL)
                            DEFAULT_SYNC_INTERVAL="$value"
                            ;;
                        DEFAULT_WATCH_METHOD)
                            DEFAULT_WATCH_METHOD="$value"
                            ;;
                        DEFAULT_MONITOR_PLUGINS)
                            DEFAULT_MONITOR_PLUGINS="$value"
                            ;;
                        DEFAULT_MONITOR_THEMES)
                            DEFAULT_MONITOR_THEMES="$value"
                            ;;
                    esac
                fi
            fi
        done < "$config_file"
    fi
}

# Function to read ignore patterns from file
read_ignore_patterns() {
    local ignore_file="$1"
    local patterns=()
    
    if [ -f "$ignore_file" ]; then
        # Read file line by line, skip empty lines and comments
        while IFS= read -r line || [ -n "$line" ]; do
            # Trim whitespace
            line=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            
            # Skip empty lines and comments
            if [ -n "$line" ] && [[ ! "$line" =~ ^[[:space:]]*# ]]; then
                patterns+=("--exclude=$line")
            fi
        done < "$ignore_file"
    fi
    
    # Return patterns as array elements
    printf '%s\n' "${patterns[@]}"
}

# Function to build selective plugin/theme includes and excludes
build_selective_monitoring() {
    local monitor_plugins="$1"
    local monitor_themes="$2"
    local local_dir="$3"
    local patterns=()
    
    # Only add selective monitoring if plugins or themes are specified
    if [ -n "$monitor_plugins" ] || [ -n "$monitor_themes" ]; then
        # Handle plugins
        if [ -n "$monitor_plugins" ]; then
            # Convert comma-separated list to array
            IFS=',' read -ra PLUGIN_ARRAY <<< "$monitor_plugins"
            local plugin_list=""
            
            # First include the plugins directory itself
            patterns+=("--include=plugins/")
            patterns+=("--include=/plugins/")
            
            for plugin in "${PLUGIN_ARRAY[@]}"; do
                # Trim whitespace
                plugin=$(echo "$plugin" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                if [ -n "$plugin" ]; then
                    # Check if the plugin directory actually exists
                    if [ -d "${local_dir%/}/plugins/$plugin" ] || [ -L "${local_dir%/}/plugins/$plugin" ]; then
                        patterns+=("--include=plugins/$plugin/")
                        patterns+=("--include=plugins/$plugin/***")
                        patterns+=("--include=/plugins/$plugin/")
                        patterns+=("--include=/plugins/$plugin/***")
                        plugin_list="${plugin_list}${plugin_list:+, }$plugin"
                    else
                        echo "WARNING: Plugin directory not found: plugins/$plugin" >&2
                    fi
                fi
            done
            # Exclude all other plugins
            patterns+=("--exclude=plugins/*/")
            patterns+=("--exclude=/plugins/*/")
        fi
        
        # Handle themes
        if [ -n "$monitor_themes" ]; then
            # Convert comma-separated list to array
            IFS=',' read -ra THEME_ARRAY <<< "$monitor_themes"
            local theme_list=""
            
            # First include the theme directory itself
            patterns+=("--include=theme/")
            patterns+=("--include=/theme/")
            
            for theme in "${THEME_ARRAY[@]}"; do
                # Trim whitespace
                theme=$(echo "$theme" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                if [ -n "$theme" ]; then
                    # Check if the theme directory actually exists
                    if [ -d "${local_dir%/}/theme/$theme" ] || [ -L "${local_dir%/}/theme/$theme" ]; then
                        patterns+=("--include=theme/$theme/")
                        patterns+=("--include=theme/$theme/***")
                        patterns+=("--include=/theme/$theme/")
                        patterns+=("--include=/theme/$theme/***")
                        theme_list="${theme_list}${theme_list:+, }$theme"
                    else
                        echo "WARNING: Theme directory not found: theme/$theme" >&2
                    fi
                fi
            done
            # Exclude all other themes
            patterns+=("--exclude=theme/*/")
            patterns+=("--exclude=/theme/*/")
        fi
        
        # If only plugins specified, exclude all themes
        if [ -n "$monitor_plugins" ] && [ -z "$monitor_themes" ]; then
            patterns+=("--exclude=theme/")
            patterns+=("--exclude=/theme/")
        fi
        
        # If only themes specified, exclude all plugins
        if [ -z "$monitor_plugins" ] && [ -n "$monitor_themes" ]; then
            patterns+=("--exclude=plugins/")
            patterns+=("--exclude=/plugins/")
        fi
    fi
    
    # Return patterns as array elements
    printf '%s\n' "${patterns[@]}"
}

# Function to build rsync exclude options (reusable for sync and counting)
build_rsync_excludes() {
    local monitor_plugins="$1"
    local monitor_themes="$2"
    local local_dir="$3"
    local excludes=()
    
    # Add selective monitoring patterns first (order matters in rsync)
    readarray -t SELECTIVE_PATTERNS < <(build_selective_monitoring "$monitor_plugins" "$monitor_themes" "$local_dir")
    excludes+=("${SELECTIVE_PATTERNS[@]}")
    
    # Add standard excludes
    excludes+=(
        --exclude='.git/'
        --exclude='.gitignore'
        --exclude='.DS_Store'
        --exclude='Thumbs.db'
        --exclude='node_modules/'
        --exclude='venv/'
        --exclude='__pycache__/'
        --exclude='*.pyc'
        --exclude='.env'
        --exclude='*.log'
        --exclude='tmp/'
        --exclude='temp/'
        --exclude='.cache/'
        --exclude='dist/'
        --exclude='build/'
        --exclude='.syncignore'
    )
    
    # Add custom ignore patterns
    if [ -n "$FULL_IGNORE_PATH" ]; then
        readarray -t CUSTOM_EXCLUDES < <(read_ignore_patterns "$FULL_IGNORE_PATH")
        excludes+=("${CUSTOM_EXCLUDES[@]}")
    fi
    
    printf '%s\n' "${excludes[@]}"
}

# Function to count trackable files
count_trackable_files() {
    local local_dir="$1"
    local monitor_plugins="$2"
    local monitor_themes="$3"
    
    # Debug: Show directory structure if selective monitoring is enabled
    if ([ -n "$monitor_plugins" ] || [ -n "$monitor_themes" ]) && [ "$DEBUG" = true ]; then
        print_status "Debug: Checking directory structure..."
        if [ -d "$local_dir/plugins" ] || [ -L "$local_dir/plugins" ]; then
            echo "  plugins/ directory exists"
            if [ -n "$monitor_plugins" ]; then
                IFS=',' read -ra PLUGIN_ARRAY <<< "$monitor_plugins"
                for plugin in "${PLUGIN_ARRAY[@]}"; do
                    plugin=$(echo "$plugin" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                    if [ -d "$local_dir/plugins/$plugin" ] || [ -L "$local_dir/plugins/$plugin" ]; then
                        local file_count=$(find -L "$local_dir/plugins/$plugin" -type f 2>/dev/null | wc -l)
                        echo "    plugins/$plugin exists ($file_count files)"
                    else
                        echo "    plugins/$plugin NOT FOUND"
                    fi
                done
            fi
        else
            echo "  plugins/ directory NOT FOUND"
        fi
        
        if [ -d "$local_dir/theme" ] || [ -L "$local_dir/theme" ]; then
            echo "  theme/ directory exists"
            if [ -n "$monitor_themes" ]; then
                IFS=',' read -ra THEME_ARRAY <<< "$monitor_themes"
                for theme in "${THEME_ARRAY[@]}"; do
                    theme=$(echo "$theme" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                    if [ -d "$local_dir/theme/$theme" ] || [ -L "$local_dir/theme/$theme" ]; then
                        local file_count=$(find -L "$local_dir/theme/$theme" -type f 2>/dev/null | wc -l)
                        echo "    theme/$theme exists ($file_count files)"
                    else
                        echo "    theme/$theme NOT FOUND"
                    fi
                done
            fi
        else
            echo "  theme/ directory NOT FOUND"
        fi
        
        # Debug: Show rsync patterns being used
        print_status "Debug: Rsync patterns being used:"
        readarray -t DEBUG_PATTERNS < <(build_rsync_excludes "$monitor_plugins" "$monitor_themes" "$local_dir")
        for pattern in "${DEBUG_PATTERNS[@]}"; do
            echo "    $pattern"
        done
    fi
    
    # Use rsync dry-run to count files that would be transferred
    local RSYNC_OPTS=(
        -avzhL
        --dry-run
        --stats
        --no-perms
        --no-owner
        --no-group
        --omit-dir-times
    )
    
    # Add exclude patterns
    readarray -t EXCLUDE_OPTS < <(build_rsync_excludes "$monitor_plugins" "$monitor_themes" "$local_dir")
    RSYNC_OPTS+=("${EXCLUDE_OPTS[@]}")
    
    # Run rsync dry-run and extract file count
    local count
    count=$(rsync "${RSYNC_OPTS[@]}" "$local_dir/" /tmp/dummy_target_$ 2>/dev/null | \
            grep "Number of regular files transferred:" | \
            sed 's/Number of regular files transferred: //' | \
            sed 's/,.*$//' || echo "0")
    
    # If that didn't work, fall back to find method with approximation
    if [ "$count" = "0" ] || [ -z "$count" ]; then
        print_warning "Rsync dry-run didn't work, using find approximation..."
        
        # If selective monitoring is enabled, count files in selected directories only
        if [ -n "$monitor_plugins" ] || [ -n "$monitor_themes" ]; then
            local total_count=0
            
            # Count plugin files
            if [ -n "$monitor_plugins" ]; then
                IFS=',' read -ra PLUGIN_ARRAY <<< "$monitor_plugins"
                for plugin in "${PLUGIN_ARRAY[@]}"; do
                    plugin=$(echo "$plugin" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                    if [ -d "$local_dir/plugins/$plugin" ] || [ -L "$local_dir/plugins/$plugin" ]; then
                        local plugin_count=$(find -L "$local_dir/plugins/$plugin" -type f 2>/dev/null | wc -l)
                        total_count=$((total_count + plugin_count))
                    fi
                done
            fi
            
            # Count theme files
            if [ -n "$monitor_themes" ]; then
                IFS=',' read -ra THEME_ARRAY <<< "$monitor_themes"
                for theme in "${THEME_ARRAY[@]}"; do
                    theme=$(echo "$theme" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                    if [ -d "$local_dir/theme/$theme" ] || [ -L "$local_dir/theme/$theme" ]; then
                        local theme_count=$(find -L "$local_dir/theme/$theme" -type f 2>/dev/null | wc -l)
                        total_count=$((total_count + theme_count))
                    fi
                done
            fi
            
            # Count other files (excluding plugins/themes directories entirely)
            local other_count=$(find -L "$local_dir" -path "$local_dir/plugins" -prune -o -path "$local_dir/theme" -prune -o -type f -print 2>/dev/null | wc -l)
            total_count=$((total_count + other_count))
            
            count=$total_count
        else
            # Standard counting without selective monitoring
            count=$(find -L "$local_dir" -type f 2>/dev/null | wc -l)
        fi
    fi
    
    echo "$count"
}

# Signal handlers
cleanup() {
    SHOULD_EXIT=true
    print_warning "Received interrupt signal. Stopping continuous sync..."
    
    # Kill any running sync process
    if [ -n "$SYNC_PID" ] && kill -0 "$SYNC_PID" 2>/dev/null; then
        kill "$SYNC_PID" 2>/dev/null || true
        wait "$SYNC_PID" 2>/dev/null || true
    fi
    
    # Kill any inotifywait process
    pkill -P $ inotifywait 2>/dev/null || true
    
    print_status "Continuous sync stopped."
    exit 0
}

# Set up signal handlers
trap cleanup SIGINT SIGTERM

# Function to perform sync
perform_sync() {
    if [ "$SYNC_IN_PROGRESS" = true ]; then
        print_warning "Sync already in progress, skipping..."
        return
    fi
    
    # Check if we're using password auth - if so, warn that this won't work
    if [ "$SSH_KEY_AUTH" = false ]; then
        print_error "Cannot perform automatic sync - password authentication required!"
        print_error "Continuous sync requires SSH key authentication to work."
        echo ""
        echo "To fix this, run:"
        printf "  ./$(basename $0) --setup-ssh-key %q %q %q %q %q\n" "${LOCAL_DIR%/}" "$REMOTE_HOST" "$REMOTE_DIR" "$SSH_USER" "$SSH_PORT"
        echo ""
        return 1
    fi
    
    SYNC_IN_PROGRESS=true
    local start_time=$(date +%s)
    
    # Build rsync options
    local RSYNC_OPTS=(
        -avzhL
        --no-perms
        --no-owner
        --no-group
        --stats
        --omit-dir-times
        -e "ssh -p $SSH_PORT"
    )
    
    # Add exclude patterns (without status messages during sync)
    readarray -t EXCLUDE_OPTS < <(build_rsync_excludes "$MONITOR_PLUGINS" "$MONITOR_THEMES" "${LOCAL_DIR%/}")
    RSYNC_OPTS+=("${EXCLUDE_OPTS[@]}")
    
    # Add delete option if not disabled
    if [ "$NO_DELETE" = false ]; then
        RSYNC_OPTS+=("--delete")
    fi
    
    # Run rsync and capture output
    local rsync_output
    rsync_output=$(rsync "${RSYNC_OPTS[@]}" "$LOCAL_DIR" "$REMOTE_PATH" 2>&1)
    local rsync_exit_code=$?
    
    # Process output line by line
    local files_transferred=""
    local total_size=""
    
    while IFS= read -r line; do
        # Capture stats for combined output
        if [[ "$line" =~ "Number of regular files transferred:" ]]; then
            files_transferred=$(echo "$line" | sed 's/Number of regular files transferred: //')
        elif [[ "$line" =~ "Total file size:" ]]; then
            total_size=$(echo "$line" | sed 's/Total file size: //')
        elif [ "$VERBOSE" = true ]; then
            # Verbose mode: show detailed statistics and most output
            if [[ "$line" =~ "Number of" ]] || [[ "$line" =~ "Total" ]] || [[ "$line" =~ "sent" ]] || 
               [[ "$line" =~ "Literal data:" ]] || [[ "$line" =~ "Matched data:" ]] || 
               [[ "$line" =~ "File list" ]] || [[ "$line" =~ "total size is" ]] ||
               [[ "$line" =~ "speedup is" ]] || [[ "$line" =~ "bytes/sec" ]]; then
                print_change "$line"
            elif [[ "$line" =~ "building file list" ]] || [[ "$line" =~ "sending incremental" ]]; then
                # Skip these lines even in verbose mode
                :
            elif [[ "$line" =~ "failed to set times on" ]] && [[ "$line" =~ "/\." ]]; then
                # Skip harmless directory time warnings
                :
            elif [[ -n "$line" ]] && [[ ! "$line" =~ ^[[:space:]]*$ ]]; then
                # Show file transfers and other output
                print_change "$line"
            fi
        else
            # Non-verbose mode: show minimal output
            if [[ "$line" =~ "building file list" ]] || [[ "$line" =~ "sending incremental" ]]; then
                # Skip these lines
                :
            elif [[ "$line" =~ "failed to set times on" ]] && [[ "$line" =~ "/\." ]]; then
                # Skip harmless directory time warnings
                :
            elif [[ -n "$line" ]] && [[ ! "$line" =~ ^[[:space:]]*$ ]] && 
                 [[ ! "$line" =~ "Number of files:" ]] && [[ ! "$line" =~ "Number of created" ]] && 
                 [[ ! "$line" =~ "Number of deleted" ]] && [[ ! "$line" =~ "Total transferred" ]] &&
                 [[ ! "$line" =~ "Total bytes" ]] && [[ ! "$line" =~ "bytes/sec" ]] &&
                 [[ ! "$line" =~ "speedup is" ]] && [[ ! "$line" =~ "Literal data:" ]] &&
                 [[ ! "$line" =~ "Matched data:" ]] && [[ ! "$line" =~ "File list" ]] &&
                 [[ ! "$line" =~ "Number of regular files transferred:" ]] && [[ ! "$line" =~ "Total file size:" ]]; then
                # Show only file transfers and important messages (not detailed stats)
                print_change "$line"
            fi
        fi
    done <<< "$rsync_output"
    
    # Show combined stats if we captured them
    if [ -n "$files_transferred" ] && [ -n "$total_size" ]; then
        echo "  Files transferred: $files_transferred, Total size: $total_size"
    fi
    
    local exit_code=${PIPESTATUS[0]}
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ $exit_code -eq 0 ]; then
        print_success "Sync completed successfully (${duration}s)"
    elif [ $exit_code -eq 23 ] || [ $exit_code -eq 24 ]; then
        print_warning "Sync completed with warnings (${duration}s, exit code: $exit_code)"
    else
        print_error "Sync failed (${duration}s, exit code: $exit_code)"
    fi
    
    LAST_SYNC_TIME=$(date +%s)
    SYNC_IN_PROGRESS=false
    
    return $exit_code
}

# Function to check if inotify-tools is available
check_inotify() {
    if command -v inotifywait >/dev/null 2>&1; then
        return 0
    else
        return 1
    fi
}

# Function to watch with inotify
watch_with_inotify() {
    print_status "Using inotify for file system monitoring"
    
    # Build exclude patterns for inotifywait
    local INOTIFY_EXCLUDES=""
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.git($|/)'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude 'node_modules($|/)'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '__pycache__($|/)'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.pyc"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.log"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.sw[px]"
    
    # Add selective monitoring excludes for inotify
    if [ -n "$MONITOR_PLUGINS" ] || [ -n "$MONITOR_THEMES" ]; then
        # If selective monitoring is enabled, we need to be more careful with inotify
        # For now, let inotify watch everything and let rsync handle the filtering
        print_status "Selective monitoring enabled - rsync will filter files"
    fi
    
    # Start inotifywait in background
    inotifywait -mr --format '%w%f %e' \
        -e modify,create,delete,move \
        $INOTIFY_EXCLUDES \
        "$LOCAL_DIR" 2>/dev/null | while IFS= read -r line; do
        
        if [ "$SHOULD_EXIT" = true ]; then
            break
        fi
        
        # Extract file and event
        local file="${line% *}"
        local event="${line##* }"
        
        # Skip if sync is already in progress
        if [ "$SYNC_IN_PROGRESS" = true ]; then
            continue
        fi
        
        # Debounce: only sync if last sync was more than 2 seconds ago
        local current_time=$(date +%s)
        local time_since_last_sync=$((current_time - LAST_SYNC_TIME))
        
        if [ $time_since_last_sync -lt 2 ]; then
            continue
        fi
        
        print_change "Detected: $event on $file"
        perform_sync &
        SYNC_PID=$!
    done
}

# Function to watch with polling
watch_with_polling() {
    print_status "Using polling method (checking every ${SYNC_INTERVAL}s)"
    
    # Store initial state
    local last_state_file="/tmp/.sync_state_$$"
    find -L "$LOCAL_DIR" -type f -newer /dev/null -exec stat -c '%n %Y' {} \; 2>/dev/null | sort > "$last_state_file"
    
    while [ "$SHOULD_EXIT" = false ]; do
        sleep "$SYNC_INTERVAL"
        
        if [ "$SHOULD_EXIT" = true ]; then
            break
        fi
        
        # Get current state (follow symlinks like rsync does)
        local current_state_file="/tmp/.sync_state_current_$$"
        find -L "$LOCAL_DIR" -type f -newer /dev/null -exec stat -c '%n %Y' {} \; 2>/dev/null | sort > "$current_state_file"
        
        # Check if state changed
        if ! diff -q "$last_state_file" "$current_state_file" >/dev/null 2>&1; then
            print_change "Detected changes in directory"
            perform_sync &
            SYNC_PID=$!
            wait "$SYNC_PID" 2>/dev/null || true
            
            # Update last state
            mv "$current_state_file" "$last_state_file"
        else
            rm -f "$current_state_file"
        fi
    done
    
    # Cleanup
    rm -f "$last_state_file" "$current_state_file"
}

# Function to set up SSH key authentication
setup_ssh_key() {
    print_status "SSH Key Setup Helper"
    echo "===================="
    echo ""
    
    # Check if key exists
    local key_file="$HOME/.ssh/id_rsa"
    if [ -f "$key_file" ]; then
        print_status "SSH key already exists at $key_file"
        read -p "Use existing key? (Y/n): " -n 1 -r
        echo ""
        if [[ $REPLY =~ ^[Nn]$ ]]; then
            read -p "Enter path for new key [$HOME/.ssh/id_rsa_sync]: " new_key_path
            key_file="${new_key_path:-$HOME/.ssh/id_rsa_sync}"
        fi
    else
        print_status "No SSH key found. Creating new key..."
        key_file="$HOME/.ssh/id_rsa"
    fi
    
    # Generate key if it doesn't exist
    if [ ! -f "$key_file" ]; then
        print_status "Generating SSH key..."
        ssh-keygen -t rsa -b 4096 -f "$key_file" -N "" || {
            print_error "Failed to generate SSH key"
            return 1
        }
        print_success "SSH key generated at $key_file"
    fi
    
    # Copy key to remote server
    print_status "Copying SSH key to remote server..."
    echo "You'll need to enter your password for the remote server:"
    
    if ssh-copy-id -p "$SSH_PORT" -i "$key_file" "$SSH_USER@$REMOTE_HOST"; then
        print_success "SSH key successfully copied to remote server!"
        echo ""
        print_status "Testing passwordless connection..."
        if ssh -p "$SSH_PORT" -i "$key_file" -o BatchMode=yes "$SSH_USER@$REMOTE_HOST" exit 2>/dev/null; then
            print_success "Passwordless SSH connection successful!"
            echo ""
            echo "You can now run the continuous sync without password prompts."
        else
            print_error "Passwordless connection test failed"
        fi
    else
        print_error "Failed to copy SSH key to remote server"
        return 1
    fi
    
    return 0
}

# Function to show usage
show_usage() {
    echo "Usage: $0 [config_file] [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]"
    echo "   or: $0 [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]"
    echo ""
    echo "Config File:"
    echo "  config_file            - Path to custom configuration file (e.g., .testserver)"
    echo "                          If not provided, looks for .syncconfig in current/home directory"
    echo ""
    echo "Options:"
    echo "  --interval <seconds>   - Sync check interval for polling mode (default: 2)"
    echo "  --method <method>      - Watch method: auto, inotify, or polling (default: auto)"
    echo "  --ignore-file <file>   - Use custom ignore file (default: .syncignore)"
    echo "  --initial-sync         - Perform initial sync before starting watch"
    echo "  --no-delete            - Don't delete files on remote that don't exist locally"
    echo "  --setup-ssh-key        - Interactive SSH key setup helper"
    echo "  --skip-ssh-test        - Skip SSH connection test (if you know it works)"
    echo "  --verbose              - Show detailed rsync statistics after each sync"
    echo "  --monitor-plugins <list> - Comma-separated list of plugins to monitor (e.g., bookings,controld)"
    echo "  --monitor-themes <list>  - Comma-separated list of themes to monitor (e.g., default,custom)"
    echo ""
    echo "Arguments (all optional if defaults configured):"
    echo "  local_dir   - Local directory to sync FROM"
    echo "  remote_host - Remote hostname or IP address"
    echo "  remote_dir  - Remote directory path to sync TO"
    echo "  ssh_user    - SSH username (optional, defaults to current user)"
    echo "  ssh_port    - SSH port (optional, defaults to 22)"
    echo ""
    echo "Watch Methods:"
    echo "  auto    - Automatically choose best method (inotify if available, else polling)"
    echo "  inotify - Use inotify for instant file change detection (Linux only)"
    echo "  polling - Check for changes at regular intervals"
    echo ""
    echo "Selective Monitoring:"
    echo "  By default, all files are monitored except standard exclusions (.git, node_modules, etc.)"
    echo "  Use --monitor-plugins and --monitor-themes to track only specific plugins/themes:"
    echo "    --monitor-plugins bookings,controld,items"
    echo "    --monitor-themes default"
    echo "  This will exclude all other plugins and themes, significantly reducing tracked files."
    echo ""
    echo "Note: On WSL monitoring Windows drives (/mnt/*), use polling mode as inotify"
    echo "      doesn't work with Windows filesystems."
    echo ""
    echo "Configuration File (.syncconfig):"
    echo "  Create a .syncconfig file with additional settings:"
    echo "    DEFAULT_LOCAL_DIR=/path/to/project"
    echo "    DEFAULT_REMOTE_HOST=server.example.com"
    echo "    DEFAULT_REMOTE_DIR=/var/www/html"
    echo "    DEFAULT_SSH_USER=deploy"
    echo "    DEFAULT_SSH_PORT=22"
    echo "    DEFAULT_SYNC_INTERVAL=2"
    echo "    DEFAULT_WATCH_METHOD=auto"
    echo "    DEFAULT_MONITOR_PLUGINS=bookings,controld,items"
    echo "    DEFAULT_MONITOR_THEMES=default"
    echo ""
    echo "Examples:"
    echo "  $0 ./my-project server.example.com /home/user/my-project"
    echo "  $0 --method inotify --initial-sync"
    echo "  $0 --monitor-plugins bookings,controld --monitor-themes default"
    echo "  $0 --interval 10 --method polling ./docs server.com /var/www/docs"
    echo "  $0 --setup-ssh-key ./project server.com /var/www/project deploy"
    echo "  $0 --skip-ssh-test ./src server.com /var/www (if connection test fails)"
    echo "  $0 --verbose --method inotify ./project server.com /var/www/project"
    echo "  $0 /path/to/.testserverconfig --verbose"
    echo "  $0 ~/.configs/production.sync --initial-sync"
    echo ""
    echo "Features:"
    echo "  - Follows symbolic links and copies actual files"
    echo "  - Real-time file change monitoring"
    echo "  - SSH key authentication setup"
    echo "  - Custom ignore patterns"
    echo "  - Selective plugin/theme monitoring to reduce tracked files"
    echo ""
    echo "To stop the continuous sync, press Ctrl+C"
}

# Read configuration
CUSTOM_CONFIG_FILE=""

# Check if first argument is a config file
if [[ $# -gt 0 ]] && [[ "$1" != --* ]] && [[ -f "$1" ]] && [[ ! -d "$1" ]]; then
    # First argument is a readable file (not directory) and not a flag
    CUSTOM_CONFIG_FILE="$1"
    shift  # Remove config file from arguments
    print_status "Using custom config file: $CUSTOM_CONFIG_FILE"
fi

read_config "$CUSTOM_CONFIG_FILE"

# Parse arguments
SYNC_INTERVAL="$DEFAULT_SYNC_INTERVAL"
WATCH_METHOD="$DEFAULT_WATCH_METHOD"
IGNORE_FILE=".syncignore"
INITIAL_SYNC=false
NO_DELETE=false
SKIP_SSH_TEST=false
VERBOSE=false
MONITOR_PLUGINS="$DEFAULT_MONITOR_PLUGINS"
MONITOR_THEMES="$DEFAULT_MONITOR_THEMES"

# Parse options
while [[ $# -gt 0 ]]; do
    case $1 in
        --interval)
            if [ -z "$2" ]; then
                print_error "--interval requires a number argument"
                show_usage
                exit 1
            fi
            SYNC_INTERVAL="$2"
            shift 2
            ;;
        --method)
            if [ -z "$2" ]; then
                print_error "--method requires an argument"
                show_usage
                exit 1
            fi
            WATCH_METHOD="$2"
            shift 2
            ;;
        --ignore-file)
            if [ -z "$2" ]; then
                print_error "--ignore-file requires a filename argument"
                show_usage
                exit 1
            fi
            IGNORE_FILE="$2"
            shift 2
            ;;
        --monitor-plugins)
            if [ -z "$2" ]; then
                print_error "--monitor-plugins requires a comma-separated list argument"
                show_usage
                exit 1
            fi
            MONITOR_PLUGINS="$2"
            shift 2
            ;;
        --monitor-themes)
            if [ -z "$2" ]; then
                print_error "--monitor-themes requires a comma-separated list argument"
                show_usage
                exit 1
            fi
            MONITOR_THEMES="$2"
            shift 2
            ;;
        --initial-sync)
            INITIAL_SYNC=true
            shift
            ;;
        --no-delete)
            NO_DELETE=true
            shift
            ;;
        --skip-ssh-test)
            SKIP_SSH_TEST=true
            shift
            ;;
        --verbose)
            VERBOSE=true
            shift
            ;;
        --setup-ssh-key)
            # Need to get the connection details first
            shift
            LOCAL_DIR="${1:-$DEFAULT_LOCAL_DIR}"
            REMOTE_HOST="${2:-$DEFAULT_REMOTE_HOST}"
            REMOTE_DIR="${3:-$DEFAULT_REMOTE_DIR}"
            SSH_USER="${4:-$DEFAULT_SSH_USER}"
            SSH_PORT="${5:-$DEFAULT_SSH_PORT}"
            
            if [ -z "$REMOTE_HOST" ]; then
                print_error "Remote host required for SSH setup"
                show_usage
                exit 1
            fi
            
            setup_ssh_key
            exit $?
            ;;
        -h|--help)
            show_usage
            exit 0
            ;;
        -*)
            print_error "Unknown option: $1"
            show_usage
            exit 1
            ;;
        *)
            break
            ;;
    esac
done

# Assign positional arguments or use defaults
LOCAL_DIR="${1:-$DEFAULT_LOCAL_DIR}"
REMOTE_HOST="${2:-$DEFAULT_REMOTE_HOST}"
REMOTE_DIR="${3:-$DEFAULT_REMOTE_DIR}"
SSH_USER="${4:-$DEFAULT_SSH_USER}"
SSH_PORT="${5:-$DEFAULT_SSH_PORT}"

# Validate required parameters
if [ -z "$LOCAL_DIR" ]; then
    print_error "Local directory not specified and no DEFAULT_LOCAL_DIR configured"
    show_usage
    exit 1
fi

if [ -z "$REMOTE_HOST" ]; then
    print_error "Remote host not specified and no DEFAULT_REMOTE_HOST configured"
    show_usage
    exit 1
fi

if [ -z "$REMOTE_DIR" ]; then
    print_error "Remote directory not specified and no DEFAULT_REMOTE_DIR configured"
    show_usage
    exit 1
fi

# Validate local directory
if [ ! -d "$LOCAL_DIR" ]; then
    print_error "Local directory '$LOCAL_DIR' does not exist"
    exit 1
fi

# Add trailing slash to local directory for rsync
if [[ "$LOCAL_DIR" != */ ]]; then
    LOCAL_DIR="$LOCAL_DIR/"
fi

# Check for ignore file
FULL_IGNORE_PATH=""
if [[ "$IGNORE_FILE" == /* ]]; then
    FULL_IGNORE_PATH="$IGNORE_FILE"
else
    if [ -f "${LOCAL_DIR%/}/$IGNORE_FILE" ]; then
        FULL_IGNORE_PATH="${LOCAL_DIR%/}/$IGNORE_FILE"
    elif [ -f "$IGNORE_FILE" ]; then
        FULL_IGNORE_PATH="$IGNORE_FILE"
    fi
fi

# Build remote path
REMOTE_PATH="$SSH_USER@$REMOTE_HOST:$REMOTE_DIR"

# Count trackable files
print_status "Analyzing directory structure..."

# Show selective monitoring status if enabled
if [ -n "$MONITOR_PLUGINS" ] || [ -n "$MONITOR_THEMES" ]; then
    print_status "Using selective monitoring mode"
    
    if [ -n "$MONITOR_PLUGINS" ]; then
        print_status "Monitoring plugins: $MONITOR_PLUGINS"
    fi
    if [ -n "$MONITOR_THEMES" ]; then
        print_status "Monitoring themes: $MONITOR_THEMES"
    fi
    
    # If only plugins specified, exclude all themes
    if [ -n "$MONITOR_PLUGINS" ] && [ -z "$MONITOR_THEMES" ]; then
        print_status "Excluding all themes (none specified)"
    fi
    
    # If only themes specified, exclude all plugins
    if [ -z "$MONITOR_PLUGINS" ] && [ -n "$MONITOR_THEMES" ]; then
        print_status "Excluding all plugins (none specified)"
    fi
fi

TRACKABLE_FILES=$(count_trackable_files "${LOCAL_DIR%/}" "$MONITOR_PLUGINS" "$MONITOR_THEMES")

# Print configuration
echo ""
print_status "Continuous Directory Synchronization"
echo "====================================="
echo "  Local:    $LOCAL_DIR"
echo "  Remote:   $REMOTE_PATH"
echo "  SSH Port: $SSH_PORT"
echo "  Method:   $WATCH_METHOD"
if [ "$WATCH_METHOD" = "polling" ] || [ "$WATCH_METHOD" = "auto" ]; then
    echo "  Interval: ${SYNC_INTERVAL}s"
fi
if [ -n "$FULL_IGNORE_PATH" ]; then
    echo "  Ignores:  $FULL_IGNORE_PATH"
fi
if [ -n "$MONITOR_PLUGINS" ] || [ -n "$MONITOR_THEMES" ]; then
    echo "  Selective Monitoring:"
    if [ -n "$MONITOR_PLUGINS" ]; then
        echo "    Plugins: $MONITOR_PLUGINS"
    fi
    if [ -n "$MONITOR_THEMES" ]; then
        echo "    Themes:  $MONITOR_THEMES"
    fi
fi
if [ "$VERBOSE" = true ]; then
    echo "  Verbose:  Enabled (detailed rsync statistics)"
fi
echo "  Files:    $TRACKABLE_FILES files being tracked"
echo "====================================="
echo ""

# Test SSH connection
if [ "$SKIP_SSH_TEST" = true ]; then
    print_warning "Skipping SSH connection test as requested."
    print_warning "Assuming password authentication is needed."
    SSH_KEY_AUTH=false
else
    print_status "Testing SSH connection..."
    echo "Attempting to connect to $SSH_USER@$REMOTE_HOST:$SSH_PORT"

    # First try with key authentication only
    if ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o BatchMode=yes -o PasswordAuthentication=no "$SSH_USER@$REMOTE_HOST" exit 2>/dev/null; then
        print_success "SSH connection successful (using key authentication)"
        SSH_KEY_AUTH=true
    else
        # Key auth failed, try with password authentication
        print_warning "SSH key authentication not available. Trying password authentication..."
        echo ""
        echo "You may be prompted for your password:"
        
        # Try actual connection with password (this will prompt)
        if ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o PreferredAuthentications=password -o PubkeyAuthentication=no "$SSH_USER@$REMOTE_HOST" exit; then
            print_success "SSH connection successful with password"
            print_warning "Note: You'll need to enter your password for EACH sync operation."
            print_warning "This means continuous sync will NOT work automatically!"
            echo ""
            echo "Consider setting up SSH key authentication for automated syncing:"
            echo ""
            echo "  Option 1: Use the automated setup (recommended):"
            printf "    ./$(basename $0) --setup-ssh-key %q %q %q %q %q\n" "${LOCAL_DIR%/}" "$REMOTE_HOST" "$REMOTE_DIR" "$SSH_USER" "$SSH_PORT"
            echo ""
            echo "    This will:"
            echo "    • Generate an SSH key (if needed)"
            echo "    • Copy it to the remote server"
            echo "    • Test the passwordless connection"
            echo ""
            echo "  Option 2: Manual setup:"
            echo "    1. Generate key: ssh-keygen -t rsa -b 4096"
            echo "    2. Copy to server: ssh-copy-id -p $SSH_PORT $SSH_USER@$REMOTE_HOST"
            echo ""
            SSH_KEY_AUTH=false
        else
            # Connection failed entirely
            print_error "SSH connection failed!"
            echo ""
            echo "Error details:"
            echo "- Connection to $SSH_USER@$REMOTE_HOST:$SSH_PORT failed"
            echo "- Neither key nor password authentication worked"
            echo ""
            echo "Troubleshooting steps:"
            echo "1. Verify connection details are correct"
            echo "2. Check if you're blocked by fail2ban: wait a few minutes and try again"
            echo "3. Test manual connection: ssh -p $SSH_PORT $SSH_USER@$REMOTE_HOST"
            echo "4. Check firewall rules on both client and server"
            echo "5. Verify SSH service is running: systemctl status sshd"
            echo ""
            echo "For detailed debugging, run:"
            echo "  ssh -vvv -p $SSH_PORT $SSH_USER@$REMOTE_HOST"
            echo ""
            read -p "Continue anyway? (y/N): " -n 1 -r
            echo ""
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                exit 1
            fi
            SSH_KEY_AUTH=false
        fi
    fi
fi

# Perform initial sync if requested
if [ "$INITIAL_SYNC" = true ]; then
    if [ "$SSH_KEY_AUTH" = false ]; then
        print_warning "Initial sync with password authentication - you'll need to enter your password."
        echo ""
        # For initial sync with password, we can't use our perform_sync function
        # so we'll do a direct rsync call that allows password input
        
        # Build rsync options
        RSYNC_OPTS=(
            -avzhL
            --no-perms
            --no-owner
            --no-group
            --stats
            --omit-dir-times
            -e "ssh -p $SSH_PORT"
        )
        
        # Add exclude patterns
        readarray -t EXCLUDE_OPTS < <(build_rsync_excludes "$MONITOR_PLUGINS" "$MONITOR_THEMES" "${LOCAL_DIR%/}")
        RSYNC_OPTS+=("${EXCLUDE_OPTS[@]}")
        
        # Add delete option if not disabled
        if [ "$NO_DELETE" = false ]; then
            RSYNC_OPTS+=("--delete")
        fi
        
        # Run rsync directly (will prompt for password)
        if [ "$VERBOSE" = true ]; then
            rsync "${RSYNC_OPTS[@]}" "$LOCAL_DIR" "$REMOTE_PATH"
        else
            rsync "${RSYNC_OPTS[@]}" "$LOCAL_DIR" "$REMOTE_PATH" 2>&1 | grep -E "(Number of regular files transferred:|Total file size:|^[^[:space:]])" | grep -v "building file list\|sending incremental"
        fi
    else
        perform_sync
    fi
    echo ""
fi

# Start continuous monitoring
print_status "Starting continuous monitoring. Press Ctrl+C to stop."

# Warn if using password authentication
if [ "$SSH_KEY_AUTH" = false ]; then
    echo ""
    print_warning "IMPORTANT: Password authentication detected!"
    print_warning "Continuous sync may not work properly as it cannot prompt for passwords automatically."
    print_warning "The sync will likely fail silently. Set up SSH keys for proper operation."
    echo ""
    echo "  To set up SSH key authentication automatically, run:"
    printf "    ./$(basename $0) --setup-ssh-key %q %q %q %q %q\n" "${LOCAL_DIR%/}" "$REMOTE_HOST" "$REMOTE_DIR" "$SSH_USER" "$SSH_PORT"
    echo ""
    echo "  This will generate an SSH key, copy it to the server, and test the connection."
    echo ""
    read -p "Continue anyway? (y/N): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi
echo ""

# Determine watch method
if [ "$WATCH_METHOD" = "auto" ]; then
    # Check if we're on WSL monitoring a Windows mount
    if grep -qi microsoft /proc/version 2>/dev/null && [[ "$LOCAL_DIR" == /mnt/* ]]; then
        WATCH_METHOD="polling"
        print_warning "Detected WSL with Windows filesystem mount (/mnt/*)."
        print_warning "inotify doesn't work on Windows mounts - using polling method instead."
        echo ""
    elif check_inotify; then
        WATCH_METHOD="inotify"
    else
        WATCH_METHOD="polling"
        print_warning "inotify-tools not found. Install it for better performance:"
        print_warning "  Ubuntu/Debian: sudo apt-get install inotify-tools"
        print_warning "  RHEL/CentOS: sudo yum install inotify-tools"
        print_warning "  macOS: Not supported, using polling method"
        echo ""
    fi
fi

# Validate watch method
case "$WATCH_METHOD" in
    inotify)
        if ! check_inotify; then
            print_error "inotify method requested but inotify-tools is not installed"
            exit 1
        fi
        watch_with_inotify
        ;;
    polling)
        watch_with_polling
        ;;
    *)
        print_error "Invalid watch method: $WATCH_METHOD"
        show_usage
        exit 1
        ;;
esac