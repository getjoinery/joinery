#!/bin/bash

# Selective Plugin/Theme Directory Synchronization Script
# ========================================================
#
# PURPOSE: Monitor only specific plugins/themes for faster sync (1-5s vs 15-18s)
#          Shows detailed file changes (added/removed/modified)
#
# USAGE: ./selective_sync.sh [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]
#
# KEY OPTIONS:
#   --plugins <list>     Monitor specific plugins: --plugins bookings,payments
#   --themes <list>      Monitor specific themes: --themes main,mobile  
#   --interval <seconds> Scan frequency (default: 1)
#   --verbose            Show scan times and debug info
#   --initial-sync       Sync once before monitoring
#
# EXAMPLES:
#   ./selective_sync.sh --plugins bookings --verbose
#   ./selective_sync.sh --plugins bookings,payments --themes main --interval 2
#   ./selective_sync.sh --themes main --initial-sync
#
# CONFIG FILE (.syncconfig):
#   DEFAULT_LOCAL_DIR=/path/to/joinery-working
#   DEFAULT_REMOTE_HOST=server.example.com
#   DEFAULT_REMOTE_DIR=/var/www/html
#   DEFAULT_SSH_USER=deploy
#   DEFAULT_MONITOR_PLUGINS=bookings,payments
#   DEFAULT_MONITOR_THEMES=main
#
# PERFORMANCE:
#   All files:     1781 files, 15-18s detection
#   Selective:     491 files,  8-10s detection  
#   Single plugin: 452 files,  6-8s detection
#   Plugins only:  51 files,   3-4s detection
#   Shows detailed file changes (added/removed/modified)
#
# ========================================================

# Monitors and syncs local directory with remote directory using rsync over SSH

set -e  # Exit on any error

# Configuration
DEFAULT_SSH_PORT=22
DEFAULT_SSH_USER="$USER"
DEFAULT_LOCAL_DIR=""
DEFAULT_REMOTE_HOST=""
DEFAULT_REMOTE_DIR=""
DEFAULT_SYNC_INTERVAL=1
DEFAULT_WATCH_METHOD="auto"
DEFAULT_MONITOR_PLUGINS=""  # Comma-separated list of plugin names to monitor
DEFAULT_MONITOR_THEMES=""   # Comma-separated list of theme names to monitor

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
                if [[ "$line" =~ ^DEFAULT_(LOCAL_DIR|REMOTE_HOST|REMOTE_DIR|SSH_USER|SSH_PORT|SYNC_INTERVAL|WATCH_METHOD|MONITOR_PLUGINS|MONITOR_THEMES)= ]]; then
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
                        DEFAULT_MONITOR_PLUGINS) DEFAULT_MONITOR_PLUGINS="$value" ;;
                        DEFAULT_MONITOR_THEMES) DEFAULT_MONITOR_THEMES="$value" ;;
                    esac
                fi
            fi
        done < "$config_file"
    fi
}

# Function to parse comma-separated list into array
parse_list() {
    local input="$1"
    local -n output_array=$2
    
    if [ -n "$input" ]; then
        IFS=',' read -ra temp_array <<< "$input"
        for item in "${temp_array[@]}"; do
            # Trim whitespace
            item=$(echo "$item" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            if [ -n "$item" ]; then
                output_array+=("$item")
            fi
        done
    fi
}

# Function to build selective find command
build_selective_find() {
    local monitor_dir="$1"
    local -n plugins_ref=$2
    local -n themes_ref=$3
    
    local find_parts=()
    
    # Get actual symlink targets to build proper exclusions
    local plugins_exclude=""
    local themes_exclude=""
    
    if [ -L "$monitor_dir/plugins" ]; then
        plugins_exclude="$monitor_dir/plugins/*"
    elif [ -d "$monitor_dir/plugins" ]; then
        plugins_exclude="$monitor_dir/plugins/*"
    fi
    
    if [ -L "$monitor_dir/theme" ]; then
        themes_exclude="$monitor_dir/theme/*"
    elif [ -d "$monitor_dir/theme" ]; then
        themes_exclude="$monitor_dir/theme/*"
    fi
    
    # Build main directory find command with proper exclusions
    local main_find="find \"$monitor_dir\" -maxdepth 20 -type f -not -path \"*/.*\""
    if [ -n "$plugins_exclude" ]; then
        main_find="$main_find -not -path \"$plugins_exclude\""
    fi
    if [ -n "$themes_exclude" ]; then
        main_find="$main_find -not -path \"$themes_exclude\""
    fi
    main_find="$main_find 2>/dev/null"
    
    find_parts+=("$main_find")
    
    # Add specific plugins
    if [ ${#plugins_ref[@]} -gt 0 ]; then
        local plugins_target
        if [ -L "$monitor_dir/plugins" ]; then
            plugins_target=$(readlink -f "$monitor_dir/plugins")
        else
            plugins_target="$monitor_dir/plugins"
        fi
        
        if [ -d "$plugins_target" ]; then
            for plugin in "${plugins_ref[@]}"; do
                if [ -d "$plugins_target/$plugin" ]; then
                    find_parts+=("find \"$plugins_target/$plugin\" -type f 2>/dev/null")
                fi
            done
        fi
    fi
    
    # Add specific themes
    if [ ${#themes_ref[@]} -gt 0 ]; then
        local themes_target
        if [ -L "$monitor_dir/theme" ]; then
            themes_target=$(readlink -f "$monitor_dir/theme")
        else
            themes_target="$monitor_dir/theme"
        fi
        
        if [ -d "$themes_target" ]; then
            for theme in "${themes_ref[@]}"; do
                if [ -d "$themes_target/$theme" ]; then
                    find_parts+=("find \"$themes_target/$theme\" -type f 2>/dev/null")
                fi
            done
        fi
    fi
    
    # Return the combined command
    printf '%s\n' "${find_parts[@]}"
}

# Signal handlers
cleanup() {
    SHOULD_EXIT=true
    print_warning "Received interrupt signal. Stopping selective sync..."
    
    if [ -n "$SYNC_PID" ] && kill -0 "$SYNC_PID" 2>/dev/null; then
        kill "$SYNC_PID" 2>/dev/null || true
        wait "$SYNC_PID" 2>/dev/null || true
    fi
    
    pkill -P $ inotifywait 2>/dev/null || true
    print_status "Selective sync stopped."
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
    
    print_status "Starting selective sync..."
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
        print_success "Selective sync completed successfully (${duration}s)"
    else
        print_error "Selective sync failed (${duration}s, exit code: $exit_code)"
    fi
    
    LAST_SYNC_TIME=$(date +%s)
    SYNC_IN_PROGRESS=false
    return $exit_code
}

# Function to watch with selective polling
watch_with_selective_polling() {
    print_status "Using selective polling method (checking every ${SYNC_INTERVAL}s)"
    
    local monitor_dir="$LOCAL_DIR"
    
    if [ ! -d "$monitor_dir" ]; then
        print_error "Directory does not exist: $monitor_dir"
        return 1
    fi
    
    # Parse plugin and theme lists
    local monitor_plugins=()
    local monitor_themes=()
    parse_list "$MONITOR_PLUGINS" monitor_plugins
    parse_list "$MONITOR_THEMES" monitor_themes
    
    print_status "Selective monitoring configuration:"
    if [ ${#monitor_plugins[@]} -gt 0 ]; then
        print_status "  Plugins: ${monitor_plugins[*]}"
    else
        print_status "  Plugins: ALL (no filter)"
    fi
    
    if [ ${#monitor_themes[@]} -gt 0 ]; then
        print_status "  Themes: ${monitor_themes[*]}"
    else
        print_status "  Themes: ALL (no filter)"
    fi
    
    # Build selective find commands
    local find_commands=()
    readarray -t find_commands < <(build_selective_find "$monitor_dir" monitor_plugins monitor_themes)
    
    if [ "$VERBOSE" = true ]; then
        print_status "Debug: Find commands being used:"
        for i in "${!find_commands[@]}"; do
            echo "    [$i] ${find_commands[$i]}"
        done
    fi
    
    # Count files for initial report
    local total_files=0
    local main_files=0
    local plugin_files=0
    local theme_files=0
    
    for i in "${!find_commands[@]}"; do
        local cmd="${find_commands[$i]}"
        local count=$(eval "$cmd" | wc -l)
        total_files=$((total_files + count))
        
        if [ "$VERBOSE" = true ]; then
            print_status "Debug: Command [$i] found $count files"
        fi
        
        # Classify the command type more precisely
        if [ $i -eq 0 ]; then
            # First command is always the main directory
            main_files=$count
        elif [[ "$cmd" =~ "/plugins/" ]] && [[ ! "$cmd" =~ "-not -path" ]]; then
            # Plugin directory scan (not an exclusion)
            plugin_files=$((plugin_files + count))
        elif [[ "$cmd" =~ "/theme/" ]] && [[ ! "$cmd" =~ "-not -path" ]]; then
            # Theme directory scan (not an exclusion)
            theme_files=$((theme_files + count))
        else
            # Fallback to main
            main_files=$((main_files + count))
        fi
    done
    
    print_status "Monitoring $total_files files total:"
    print_status "  Main directory: $main_files files"
    print_status "  Selected plugins: $plugin_files files"
    print_status "  Selected themes: $theme_files files"
    
    if [ "$total_files" -eq 0 ]; then
        print_error "No files detected! Check your plugin/theme selection."
        return 1
    fi
    
    # Store initial state
    local last_state_file="/tmp/.selective_sync_state_$$"
    
    for cmd in "${find_commands[@]}"; do
        eval "$cmd" | while read -r file; do
            if [ -f "$file" ]; then
                stat -c '%n %Y %s' "$file" 2>/dev/null
            fi
        done
    done | sort > "$last_state_file"
    
    local scan_count=0
    while [ "$SHOULD_EXIT" = false ]; do
        sleep "$SYNC_INTERVAL"
        scan_count=$((scan_count + 1))
        
        if [ "$SHOULD_EXIT" = true ]; then
            break
        fi
        
        local scan_start=$(date +%s)
        local current_state_file="/tmp/.selective_sync_state_current_$$"
        
        for cmd in "${find_commands[@]}"; do
            eval "$cmd" | while read -r file; do
                if [ -f "$file" ]; then
                    stat -c '%n %Y %s' "$file" 2>/dev/null
                fi
            done
        done | sort > "$current_state_file"
        
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
            awk '{$NF=""; $(NF-1)=""; print}' "$last_state_file" | sed 's/[[:space:]]*$//' | sort > "/tmp/last_files_$"
            awk '{$NF=""; $(NF-1)=""; print}' "$current_state_file" | sed 's/[[:space:]]*$//' | sort > "/tmp/current_files_$"
            
            local truly_added=$(comm -13 "/tmp/last_files_$" "/tmp/current_files_$")
            local truly_removed=$(comm -23 "/tmp/last_files_$" "/tmp/current_files_$")
            local common_files=$(comm -12 "/tmp/last_files_$" "/tmp/current_files_$")
            
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
                done > "/tmp/modified_files_$"
                
                modified_files=$(cat "/tmp/modified_files_$")
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
                rm -f "/tmp/modified_files_$"
            fi
            
            rm -f "/tmp/last_files_$" "/tmp/current_files_$"
            
            if [ "$VERBOSE" = true ]; then
                print_status "Scan took ${scan_duration}s - changes detected"
            fi
            
            print_change "Detected changes in selected plugins/themes"
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

# Function to show usage
show_usage() {
    echo "Selective Plugin/Theme Directory Synchronization Script"
    echo "======================================================"
    echo ""
    echo "Usage: $0 [options] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]"
    echo ""
    echo "Options:"
    echo "  --plugins <list>       Monitor specific plugins: --plugins bookings,payments"
    echo "  --themes <list>        Monitor specific themes: --themes main,mobile"
    echo "  --interval <seconds>   Scan frequency (default: 1)"
    echo "  --verbose              Show scan times and debug info"
    echo "  --initial-sync         Sync once before monitoring"
    echo "  --no-delete           Don't delete remote files"
    echo ""
    echo "Examples:"
    echo "  $0 --plugins bookings --verbose"
    echo "  $0 --plugins bookings,payments --themes main"
    echo "  $0 --themes main --interval 5"
    echo ""
    echo "Performance:"
    echo "  Monitor 1 plugin:  ~6-8s detection time"
    echo "  Monitor 3 plugins: ~8-10s detection time" 
    echo "  Monitor all:       ~15-18s detection time"
    echo "  Shows detailed file changes (added/removed/modified)"
    echo ""
    echo "See documentation at top of script file for config file format and advanced usage."
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
MONITOR_PLUGINS="$DEFAULT_MONITOR_PLUGINS"
MONITOR_THEMES="$DEFAULT_MONITOR_THEMES"
INITIAL_SYNC=false
NO_DELETE=false
VERBOSE=false

# Parse options
while [[ $# -gt 0 ]]; do
    case $1 in
        --plugins)
            if [ -z "$2" ]; then
                print_error "--plugins requires a comma-separated list"
                show_usage
                exit 1
            fi
            MONITOR_PLUGINS="$2"
            shift 2
            ;;
        --themes)
            if [ -z "$2" ]; then
                print_error "--themes requires a comma-separated list"
                show_usage
                exit 1
            fi
            MONITOR_THEMES="$2"
            shift 2
            ;;
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
print_status "Selective Directory Synchronization"
echo "===================================="
echo "  Local:    $LOCAL_DIR"
echo "  Remote:   $REMOTE_PATH"
echo "  SSH Port: $SSH_PORT"
echo "  Method:   $WATCH_METHOD"
echo "  Interval: ${SYNC_INTERVAL}s"
if [ -n "$MONITOR_PLUGINS" ]; then
    echo "  Plugins:  $MONITOR_PLUGINS"
fi
if [ -n "$MONITOR_THEMES" ]; then
    echo "  Themes:   $MONITOR_THEMES"
fi
echo "===================================="
echo ""

# Test SSH connection (simplified for brevity)
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
    print_status "Performing initial selective sync..."
    perform_sync
    echo ""
fi

# Start selective monitoring
print_status "Starting selective monitoring. Press Ctrl+C to stop."
echo ""

# Force polling method for now (can add selective inotify later)
watch_with_selective_polling