#!/bin/bash

# Symlink-Aware Directory Synchronization Script
# ===============================================
#
# PURPOSE: Monitor main directory + symbolic link targets for faster sync
#          Shows detailed file changes (added/removed/modified)
#
# USAGE: ./sync_continuous.sh [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]
#
# KEY OPTIONS:
#   --interval <seconds> Scan frequency (default: 1)
#   --verbose            Show scan times and debug info
#   --initial-sync       Sync once before monitoring
#   --no-delete         Don't delete remote files
#   --method <method>    Watch method: auto, inotify, or polling
#
# EXAMPLES:
#   ./sync_continuous.sh --verbose
#   ./sync_continuous.sh --interval 2 ./my-project server.com /var/www
#   ./sync_continuous.sh --initial-sync --verbose
#
# CONFIG FILE (.syncconfig):
#   DEFAULT_LOCAL_DIR=/path/to/working-directory
#   DEFAULT_REMOTE_HOST=server.example.com
#   DEFAULT_REMOTE_DIR=/var/www/html
#   DEFAULT_SSH_USER=deploy
#   DEFAULT_SYNC_INTERVAL=1
#
# FEATURES:
#   - Monitors main directory + follows symbolic links
#   - Fast change detection (typically 3-8s vs 15-18s)
#   - Shows detailed file changes (added/removed/modified)
#   - WSL/Windows mount compatible
#   - SSH key authentication
#
# ===============================================

set -e  # Exit on any error

# Configuration
DEFAULT_SSH_PORT=22
DEFAULT_SSH_USER="$USER"
DEFAULT_LOCAL_DIR=""
DEFAULT_REMOTE_HOST=""
DEFAULT_REMOTE_DIR=""
DEFAULT_SYNC_INTERVAL=1
DEFAULT_WATCH_METHOD="auto"

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
    local config_file="$1"
    
    if [ -n "$config_file" ]; then
        if [ ! -f "$config_file" ]; then
            print_error "Specified config file '$config_file' does not exist"
            exit 1
        fi
        print_status "Reading configuration from: $config_file"
    else
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
        while IFS= read -r line || [ -n "$line" ]; do
            line=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            if [ -n "$line" ] && [[ ! "$line" =~ ^[[:space:]]*# ]]; then
                if [[ "$line" =~ ^DEFAULT_(LOCAL_DIR|REMOTE_HOST|REMOTE_DIR|SSH_USER|SSH_PORT|SYNC_INTERVAL|WATCH_METHOD)= ]]; then
                    local key="${line%%=*}"
                    local value="${line#*=}"
                    value=$(echo "$value" | sed 's/^["'\'']*//;s/["'\'']*$//')
                    
                    case "$key" in
                        DEFAULT_LOCAL_DIR) DEFAULT_LOCAL_DIR="$value" ;;
                        DEFAULT_REMOTE_HOST) DEFAULT_REMOTE_HOST="$value" ;;
                        DEFAULT_REMOTE_DIR) DEFAULT_REMOTE_DIR="$value" ;;
                        DEFAULT_SSH_USER) DEFAULT_SSH_USER="$value" ;;
                        DEFAULT_SSH_PORT) DEFAULT_SSH_PORT="$value" ;;
                        DEFAULT_SYNC_INTERVAL) DEFAULT_SYNC_INTERVAL="$value" ;;
                        DEFAULT_WATCH_METHOD) DEFAULT_WATCH_METHOD="$value" ;;
                    esac
                fi
            fi
        done < "$config_file"
    fi
}

# Signal handlers
cleanup() {
    SHOULD_EXIT=true
    print_warning "Received interrupt signal. Stopping sync..."
    
    if [ -n "$SYNC_PID" ] && kill -0 "$SYNC_PID" 2>/dev/null; then
        kill "$SYNC_PID" 2>/dev/null || true
        wait "$SYNC_PID" 2>/dev/null || true
    fi
    
    pkill -P $ inotifywait 2>/dev/null || true
    print_status "Sync stopped."
    exit 0
}

trap cleanup SIGINT SIGTERM

# Function to perform sync
perform_sync() {
    if [ "$SYNC_IN_PROGRESS" = true ]; then
        print_warning "Sync already in progress, skipping..."
        return
    fi
    
    if [ "$SSH_KEY_AUTH" = false ]; then
        print_error "Cannot perform automatic sync - password authentication required!"
        return 1
    fi
    
    SYNC_IN_PROGRESS=true
    local start_time=$(date +%s)
    
    local RSYNC_OPTS=(
        -avzhL
        --no-perms --no-owner --no-group
        --stats --omit-dir-times
        -e "ssh -p $SSH_PORT"
        --exclude='.git/' --exclude='.gitignore'
        --exclude='.DS_Store' --exclude='Thumbs.db'
        --exclude='node_modules/' --exclude='venv/'
        --exclude='__pycache__/' --exclude='*.pyc'
        --exclude='.env' --exclude='*.log'
        --exclude='tmp/' --exclude='temp/'
        --exclude='.cache/' --exclude='dist/' --exclude='build/'
    )
    
    if [ "$NO_DELETE" = false ]; then
        RSYNC_OPTS+=("--delete")
    fi
    
    print_status "Starting sync..."
    rsync "${RSYNC_OPTS[@]}" "$LOCAL_DIR" "$REMOTE_PATH" 2>&1 | while IFS= read -r line; do
        if [ "$VERBOSE" = true ]; then
            if [[ "$line" =~ "Number of" ]] || [[ "$line" =~ "Total" ]] || [[ "$line" =~ "sent" ]]; then
                print_change "$line"
            fi
        fi
    done
    
    local exit_code=${PIPESTATUS[0]}
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ $exit_code -eq 0 ]; then
        print_success "Sync completed successfully (${duration}s)"
    else
        print_error "Sync failed (${duration}s, exit code: $exit_code)"
    fi
    
    LAST_SYNC_TIME=$(date +%s)
    SYNC_IN_PROGRESS=false
    return $exit_code
}

# Function to watch with symlink-aware polling
watch_with_polling() {
    print_status "Using polling method (checking every ${SYNC_INTERVAL}s)"
    
    local monitor_dir="$LOCAL_DIR"
    
    if [ ! -d "$monitor_dir" ]; then
        print_error "Directory does not exist: $monitor_dir"
        return 1
    fi
    
    # Find all symlinks and their targets
    local symlink_targets=()
    local symlink_names=()
    while read -r symlink; do
        if [ -n "$symlink" ]; then
            local target=$(readlink -f "$symlink" 2>/dev/null)
            local name=$(basename "$symlink")
            if [ -d "$target" ]; then
                symlink_targets+=("$target")
                symlink_names+=("$name")
            fi
        fi
    done < <(find "$monitor_dir" -maxdepth 1 -type l 2>/dev/null)
    
    if [ "$VERBOSE" = true ] && [ ${#symlink_targets[@]} -gt 0 ]; then
        print_status "Found symbolic links:"
        for i in "${!symlink_names[@]}"; do
            print_status "  ${symlink_names[$i]} -> ${symlink_targets[$i]}"
        done
    fi
    
    # Count files for initial report
    local main_files=0
    local symlink_files=0
    
    # Count main directory files (excluding symlinked subdirs to avoid duplicates)
    local exclude_args=""
    for name in "${symlink_names[@]}"; do
        exclude_args="$exclude_args -not -path \"$monitor_dir/$name/*\""
    done
    
    if [ -n "$exclude_args" ]; then
        main_files=$(eval "find \"$monitor_dir\" -maxdepth 20 -type f -not -path \"*/.*\" $exclude_args 2>/dev/null" | wc -l)
    else
        main_files=$(find "$monitor_dir" -maxdepth 20 -type f -not -path "*/.*" 2>/dev/null | wc -l)
    fi
    
    # Count symlinked directory files
    for target in "${symlink_targets[@]}"; do
        local count=$(find "$target" -type f 2>/dev/null | wc -l)
        symlink_files=$((symlink_files + count))
    done
    
    local total_files=$((main_files + symlink_files))
    print_status "Monitoring $total_files files total:"
    print_status "  Main directory: $main_files files"
    print_status "  Symbolic directories: $symlink_files files"
    
    if [ "$total_files" -eq 0 ]; then
        print_error "No files detected!"
        return 1
    fi
    
    # Store initial state
    local last_state_file="/tmp/.sync_state_$$"
    
    # Scan main directory (excluding symlinked subdirs)
    if [ -n "$exclude_args" ]; then
        eval "find \"$monitor_dir\" -maxdepth 20 -type f -not -path \"*/.*\" $exclude_args 2>/dev/null" | while read -r file; do
            if [ -f "$file" ]; then
                stat -c '%n %Y %s' "$file" 2>/dev/null
            fi
        done > "$last_state_file"
    else
        find "$monitor_dir" -maxdepth 20 -type f -not -path "*/.*" 2>/dev/null | while read -r file; do
            if [ -f "$file" ]; then
                stat -c '%n %Y %s' "$file" 2>/dev/null
            fi
        done > "$last_state_file"
    fi
    
    # Scan symlinked directories
    for target in "${symlink_targets[@]}"; do
        find "$target" -type f 2>/dev/null | while read -r file; do
            if [ -f "$file" ]; then
                stat -c '%n %Y %s' "$file" 2>/dev/null
            fi
        done >> "$last_state_file"
    done
    
    # Sort the combined results
    sort "$last_state_file" -o "$last_state_file"
    
    local scan_count=0
    while [ "$SHOULD_EXIT" = false ]; do
        sleep "$SYNC_INTERVAL"
        scan_count=$((scan_count + 1))
        
        if [ "$SHOULD_EXIT" = true ]; then
            break
        fi
        
        local scan_start=$(date +%s)
        local current_state_file="/tmp/.sync_state_current_$$"
        
        # Scan main directory
        if [ -n "$exclude_args" ]; then
            eval "find \"$monitor_dir\" -maxdepth 20 -type f -not -path \"*/.*\" $exclude_args 2>/dev/null" | while read -r file; do
                if [ -f "$file" ]; then
                    stat -c '%n %Y %s' "$file" 2>/dev/null
                fi
            done > "$current_state_file"
        else
            find "$monitor_dir" -maxdepth 20 -type f -not -path "*/.*" 2>/dev/null | while read -r file; do
                if [ -f "$file" ]; then
                    stat -c '%n %Y %s' "$file" 2>/dev/null
                fi
            done > "$current_state_file"
        fi
        
        # Scan symlinked directories
        for target in "${symlink_targets[@]}"; do
            find "$target" -type f 2>/dev/null | while read -r file; do
                if [ -f "$file" ]; then
                    stat -c '%n %Y %s' "$file" 2>/dev/null
                fi
            done >> "$current_state_file"
        done
        
        # Sort the combined results
        sort "$current_state_file" -o "$current_state_file"
        
        local scan_duration=$(($(date +%s) - scan_start))
        local current_count=$(wc -l < "$current_state_file")
        local last_count=$(wc -l < "$last_state_file")
        
        if [ "$current_count" != "$last_count" ] || ! cmp -s "$last_state_file" "$current_state_file"; then
            if [ "$current_count" != "$last_count" ]; then
                print_change "File count changed: $last_count -> $current_count"
            fi
            
            # Show changed files with proper logic for add/remove/modify
            local added_files=$(comm -13 "$last_state_file" "$current_state_file")
            local removed_files=$(comm -23 "$last_state_file" "$current_state_file")
            
            # Extract filenames only for proper add/remove detection
            awk '{$NF=""; $(NF-1)=""; print}' "$last_state_file" | sed 's/[[:space:]]*$//' | sort > "/tmp/last_files_$$"
            awk '{$NF=""; $(NF-1)=""; print}' "$current_state_file" | sed 's/[[:space:]]*$//' | sort > "/tmp/current_files_$$"
            
            local truly_added=$(comm -13 "/tmp/last_files_$$" "/tmp/current_files_$$")
            local truly_removed=$(comm -23 "/tmp/last_files_$$" "/tmp/current_files_$$")
            local common_files=$(comm -12 "/tmp/last_files_$$" "/tmp/current_files_$$")
            
            # Show truly added files
            if [ -n "$truly_added" ]; then
                print_change "Files added:"
                echo "$truly_added" | head -5 | while read filename; do
                    echo "    + $filename"
                done
                local added_count=$(echo "$truly_added" | wc -l)
                if [ "$added_count" -gt 5 ]; then
                    echo "    ... and $((added_count - 5)) more files"
                fi
            fi
            
            # Show truly removed files
            if [ -n "$truly_removed" ]; then
                print_change "Files removed:"
                echo "$truly_removed" | head -5 | while read filename; do
                    echo "    - $filename"
                done
                local removed_count=$(echo "$truly_removed" | wc -l)
                if [ "$removed_count" -gt 5 ]; then
                    echo "    ... and $((removed_count - 5)) more files"
                fi
            fi
            
            # Show modified files (files that exist in both states but with different timestamps/sizes)
            if [ -n "$common_files" ] && [ -n "$added_files" ]; then
                local modified_files=""
                echo "$common_files" | while read filename; do
                    # Check if this filename appears in the stat differences (meaning it was modified)
                    if echo "$added_files" | awk '{$NF=""; $(NF-1)=""; print}' | sed 's/[[:space:]]*$//' | grep -Fxq "$filename"; then
                        echo "$filename"
                    fi
                done > "/tmp/modified_files_$$"
                
                modified_files=$(cat "/tmp/modified_files_$$")
                if [ -n "$modified_files" ]; then
                    print_change "Files modified:"
                    echo "$modified_files" | head -5 | while read filename; do
                        echo "    ~ $filename"
                    done
                    local modified_count=$(echo "$modified_files" | wc -l)
                    if [ "$modified_count" -gt 5 ]; then
                        echo "    ... and $((modified_count - 5)) more files"
                    fi
                fi
                rm -f "/tmp/modified_files_$$"
            fi
            
            rm -f "/tmp/last_files_$$" "/tmp/current_files_$$"
            
            if [ "$VERBOSE" = true ]; then
                print_status "Scan took ${scan_duration}s - changes detected"
            fi
            
            print_change "Detected changes in directory"
            perform_sync &
            SYNC_PID=$!
            wait "$SYNC_PID" 2>/dev/null || true
            
            mv "$current_state_file" "$last_state_file"
        else
            rm -f "$current_state_file"
            if [ "$VERBOSE" = true ] || [ $((scan_count % 20)) -eq 0 ]; then
                print_status "Scan #$scan_count took ${scan_duration}s - no changes"
            fi
        fi
    done
    
    rm -f "$last_state_file" "$current_state_file"
}

# Function to watch with inotify
watch_with_inotify() {
    print_status "Using inotify for file system monitoring"
    
    # Resolve the LOCAL_DIR to its actual path if it's a symlink
    local resolved_dir
    if [ -L "$LOCAL_DIR" ]; then
        resolved_dir=$(readlink -f "$LOCAL_DIR")
        print_status "Following symlink: $LOCAL_DIR -> $resolved_dir"
    else
        resolved_dir="$LOCAL_DIR"
    fi
    
    # Build exclude patterns for inotifywait
    local INOTIFY_EXCLUDES=""
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.git($|/)'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude 'node_modules($|/)'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '__pycache__($|/)'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.pyc$'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.log$'"
    INOTIFY_EXCLUDES="$INOTIFY_EXCLUDES --exclude '\.sw[px]$'"
    
    # Start inotifywait in background with -L flag to follow symlinks
    inotifywait -mrL --format '%w%f %e' \
        -e modify,create,delete,move \
        $INOTIFY_EXCLUDES \
        "$resolved_dir" 2>/dev/null | while IFS= read -r line; do
        
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
        
        # Show the change
        local relative_file=$(echo "$file" | sed "s|^$resolved_dir/||")
        case "$event" in
            CREATE|MOVED_TO)
                print_change "File added:"
                echo "    + $relative_file"
                ;;
            DELETE|MOVED_FROM)
                print_change "File removed:"
                echo "    - $relative_file"
                ;;
            MODIFY)
                print_change "File modified:"
                echo "    ~ $relative_file"
                ;;
            *)
                print_change "File changed ($event):"
                echo "    * $relative_file"
                ;;
        esac
        
        perform_sync &
        SYNC_PID=$!
    done
}

# Function to check if inotify-tools is available
check_inotify() {
    if command -v inotifywait >/dev/null 2>&1; then
        return 0
    else
        return 1
    fi
}

# Function to show usage
show_usage() {
    echo "Symlink-Aware Directory Synchronization Script"
    echo "=============================================="
    echo ""
    echo "Usage: $0 [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]"
    echo ""
    echo "Options:"
    echo "  --interval <seconds>   Scan frequency (default: 1)"
    echo "  --method <method>      Watch method: auto, inotify, or polling"
    echo "  --verbose              Show scan times and debug info"
    echo "  --initial-sync         Sync once before monitoring"
    echo "  --no-delete           Don't delete remote files"
    echo ""
    echo "Examples:"
    echo "  $0 --verbose"
    echo "  $0 --interval 2 ./my-project server.com /var/www"
    echo "  $0 --initial-sync --verbose"
    echo ""
    echo "Features:"
    echo "  - Monitors main directory + follows symbolic links"
    echo "  - Shows detailed file changes (added/removed/modified)"
    echo "  - Fast change detection (typically 3-8s)"
    echo "  - WSL/Windows mount compatible"
    echo ""
    echo "See documentation at top of script file for config file format."
}

# Read configuration
CUSTOM_CONFIG_FILE=""

if [[ $# -gt 0 ]] && [[ "$1" != --* ]] && [[ -f "$1" ]] && [[ ! -d "$1" ]]; then
    CUSTOM_CONFIG_FILE="$1"
    shift
    print_status "Using custom config file: $CUSTOM_CONFIG_FILE"
fi

read_config "$CUSTOM_CONFIG_FILE"

# Parse arguments
SYNC_INTERVAL="$DEFAULT_SYNC_INTERVAL"
WATCH_METHOD="$DEFAULT_WATCH_METHOD"
INITIAL_SYNC=false
NO_DELETE=false
VERBOSE=false

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
        --initial-sync)
            INITIAL_SYNC=true
            shift
            ;;
        --no-delete)
            NO_DELETE=true
            shift
            ;;
        --verbose)
            VERBOSE=true
            shift
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

# Build remote path
REMOTE_PATH="$SSH_USER@$REMOTE_HOST:$REMOTE_DIR"

# Print configuration
echo ""
print_status "Symlink-Aware Directory Synchronization"
echo "========================================"
echo "  Local:    $LOCAL_DIR"
echo "  Remote:   $REMOTE_PATH"
echo "  SSH Port: $SSH_PORT"
echo "  Method:   $WATCH_METHOD"
echo "  Interval: ${SYNC_INTERVAL}s"
echo "========================================"
echo ""

# Test SSH connection
print_status "Testing SSH connection..."
if ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o BatchMode=yes "$SSH_USER@$REMOTE_HOST" exit 2>/dev/null; then
    print_success "SSH connection successful (using key authentication)"
    SSH_KEY_AUTH=true
else
    print_error "SSH key authentication failed. Set up SSH keys for automatic sync."
    exit 1
fi

# Perform initial sync if requested
if [ "$INITIAL_SYNC" = true ]; then
    print_status "Performing initial sync..."
    perform_sync
    echo ""
fi

# Start monitoring
print_status "Starting continuous monitoring. Press Ctrl+C to stop."
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
        print_warning "inotify-tools not found. Using polling method."
        echo ""
    fi
fi

# Validate and start watch method
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