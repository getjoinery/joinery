#!/bin/bash

# Reverse Directory Synchronization Script
# Syncs FROM remote directories TO local directories using rsync over SSH
# Compatible with existing .syncconfig format
# Usage: ./sync_reverse.sh [config_file]

set -e  # Exit on any error

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Configuration - Supports both single and dual directory pairs
DEFAULT_LOCAL_DIR=""
DEFAULT_REMOTE_DIR=""
DEFAULT_LOCAL_DIR_1=""
DEFAULT_REMOTE_DIR_1=""
DEFAULT_LOCAL_DIR_2=""
DEFAULT_REMOTE_DIR_2=""
DEFAULT_REMOTE_HOST=""
DEFAULT_SSH_USER="$USER"
DEFAULT_SSH_PORT=22
DEFAULT_SYNC_INTERVAL=5  # Default interval, can be overridden by config
DEFAULT_FOLLOW_SYMLINKS=true  # Default to following symlinks for backwards compatibility
DEFAULT_DELETE_BEHAVIOR="skip"  # Default to skip deletions (options: skip, ask)

# Runtime variables
SSH_KEY_AUTH=false
SSH_KEY_PATH=""
SSH_MULTIPLEXING=false
SSH_CONTROL_PATH=""
DELETE_CONFIRMED=false  # Track if user has confirmed deletions for this session

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# SSH multiplexing cleanup function
cleanup_ssh() {
    if [ "$SSH_MULTIPLEXING" = true ]; then
        ssh -O exit -o ControlPath="$SSH_CONTROL_PATH" "$SSH_USER@$REMOTE_HOST" 2>/dev/null || true
        rm -f "$SSH_CONTROL_PATH" 2>/dev/null || true
    fi
}

# Set up cleanup trap
trap cleanup_ssh EXIT INT TERM

# Function to setup SSH multiplexing
setup_ssh_multiplexing() {
    local ssh_user="$1"
    local ssh_host="$2" 
    local ssh_port="$3"
    local ssh_key_path="$4"
    
    # Create unique control path
    SSH_CONTROL_PATH="/tmp/sync_reverse_ssh_${ssh_host}_${ssh_port}_${ssh_user}_$$"
    
    # SSH multiplexing options
    local ssh_multiplex_opts="-o ControlMaster=auto -o ControlPath=$SSH_CONTROL_PATH -o ControlPersist=300"
    
    # Build SSH command with multiplexing
    if [ -n "$ssh_key_path" ] && [ "$SSH_KEY_AUTH" = true ]; then
        SSH_CMD_WITH_MULTIPLEX="ssh -p $ssh_port -i $ssh_key_path $ssh_multiplex_opts"
    else
        SSH_CMD_WITH_MULTIPLEX="ssh -p $ssh_port $ssh_multiplex_opts"
    fi
    
    # Test if multiplexing works
    if $SSH_CMD_WITH_MULTIPLEX -o BatchMode=yes "$ssh_user@$ssh_host" exit 2>/dev/null; then
        SSH_MULTIPLEXING=true
        SSH_CMD="$SSH_CMD_WITH_MULTIPLEX"
        return 0
    else
        SSH_MULTIPLEXING=false
        return 1
    fi
}

# Function to find SSH key
find_ssh_key() {
    local key_locations=(
        "$HOME/.ssh/id_rsa"
        "$HOME/.ssh/id_ed25519"
        "$HOME/.ssh/id_ecdsa"
        "$HOME/.ssh/id_rsa_sync"
    )
    
    for key in "${key_locations[@]}"; do
        if [ -f "$key" ]; then
            SSH_KEY_PATH="$key"
            return 0
        fi
    done
    
    return 1
}

# Function to read configuration file
read_config() {
    local config_file="$1"
    
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
        # Look for .syncreverseconfig in order: script dir, current dir, home dir
        if [ -f "$SCRIPT_DIR/.syncreverseconfig" ]; then
            config_file="$SCRIPT_DIR/.syncreverseconfig"
        elif [ -f ".syncreverseconfig" ]; then
            config_file=".syncreverseconfig"
        elif [ -f "$HOME/.syncreverseconfig" ]; then
            config_file="$HOME/.syncreverseconfig"
        fi
        
        if [ -n "$config_file" ]; then
            print_status "Reading configuration from: $config_file"
        else
            print_error "No .syncreverseconfig file found"
            echo ""
            echo "Usage: $0 [config_file]"
            echo ""
            echo "You can either:"
            echo "1. Pass your .syncconfig as an argument: $0 .syncconfig"
            echo "2. Create a .syncreverseconfig file in one of these locations:"
            echo "   - Script directory: $SCRIPT_DIR/.syncreverseconfig"
            echo "   - Current directory: ./.syncreverseconfig"
            echo "   - Home directory: $HOME/.syncreverseconfig"
            echo ""
            echo "DEFAULT_REMOTE_HOST=server.example.com"
            echo "DEFAULT_SSH_USER=username"
            echo "DEFAULT_SSH_PORT=22"
            echo "DEFAULT_LOCAL_DIR=/home/user/local_folder"
            echo "DEFAULT_REMOTE_DIR=/remote/folder"
            echo "DEFAULT_SYNC_INTERVAL=5  # Optional, seconds between syncs"
            echo "DEFAULT_FOLLOW_SYMLINKS=false  # Optional, set to false to skip symlinks"
            echo "DEFAULT_DELETE_BEHAVIOR=skip  # Optional, 'skip' or 'ask' (default: skip)"
            echo ""
            echo "For two directory pairs, you can also use:"
            echo "DEFAULT_LOCAL_DIR_1=/home/user/local_folder1"
            echo "DEFAULT_REMOTE_DIR_1=/remote/folder1"
            echo "DEFAULT_LOCAL_DIR_2=/home/user/local_folder2"
            echo "DEFAULT_REMOTE_DIR_2=/remote/folder2"
            exit 1
        fi
    fi
    
    if [ -n "$config_file" ]; then
        # Source the config file safely
        while IFS= read -r line || [ -n "$line" ]; do
            # Skip empty lines and comments
            line=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            if [ -n "$line" ] && [[ ! "$line" =~ ^[[:space:]]*# ]]; then
                # Only allow specific variables for security
                if [[ "$line" =~ ^DEFAULT_(LOCAL_DIR|REMOTE_DIR|LOCAL_DIR_[12]|REMOTE_DIR_[12]|REMOTE_HOST|SSH_USER|SSH_PORT|SYNC_INTERVAL|UPDATE_INTERVAL|FOLLOW_SYMLINKS|DELETE_BEHAVIOR)= ]]; then
                    # Extract key and value
                    local key="${line%%=*}"
                    local value="${line#*=}"
                    
                    # Remove quotes if present
                    value=$(echo "$value" | sed 's/^["'\'']*//;s/["'\'']*$//')
                    
                    case "$key" in
                        DEFAULT_LOCAL_DIR)
                            DEFAULT_LOCAL_DIR="$value"
                            print_status "  Local dir: $value"
                            ;;
                        DEFAULT_REMOTE_DIR)
                            DEFAULT_REMOTE_DIR="$value"
                            print_status "  Remote dir: $value"
                            ;;
                        DEFAULT_LOCAL_DIR_1)
                            DEFAULT_LOCAL_DIR_1="$value"
                            print_status "  Local dir 1: $value"
                            ;;
                        DEFAULT_REMOTE_DIR_1)
                            DEFAULT_REMOTE_DIR_1="$value"
                            print_status "  Remote dir 1: $value"
                            ;;
                        DEFAULT_LOCAL_DIR_2)
                            DEFAULT_LOCAL_DIR_2="$value"
                            print_status "  Local dir 2: $value"
                            ;;
                        DEFAULT_REMOTE_DIR_2)
                            DEFAULT_REMOTE_DIR_2="$value"
                            print_status "  Remote dir 2: $value"
                            ;;
                        DEFAULT_REMOTE_HOST)
                            DEFAULT_REMOTE_HOST="$value"
                            print_status "  Remote host: $value"
                            ;;
                        DEFAULT_SSH_USER)
                            DEFAULT_SSH_USER="$value"
                            print_status "  SSH user: $value"
                            ;;
                        DEFAULT_SSH_PORT)
                            DEFAULT_SSH_PORT="$value"
                            print_status "  SSH port: $value"
                            ;;
                        DEFAULT_SYNC_INTERVAL|DEFAULT_UPDATE_INTERVAL)
                            DEFAULT_SYNC_INTERVAL="$value"
                            print_status "  Sync interval: $value seconds"
                            ;;
                        DEFAULT_FOLLOW_SYMLINKS)
                            # Convert to lowercase and check for false/no/0
                            value_lower=$(echo "$value" | tr '[:upper:]' '[:lower:]')
                            if [[ "$value_lower" == "false" ]] || [[ "$value_lower" == "no" ]] || [[ "$value_lower" == "0" ]]; then
                                DEFAULT_FOLLOW_SYMLINKS=false
                                print_status "  Follow symbolic links: disabled"
                            else
                                DEFAULT_FOLLOW_SYMLINKS=true
                                print_status "  Follow symbolic links: enabled"
                            fi
                            ;;
                        DEFAULT_DELETE_BEHAVIOR)
                            # Convert to lowercase
                            value_lower=$(echo "$value" | tr '[:upper:]' '[:lower:]')
                            if [[ "$value_lower" == "ask" ]]; then
                                DEFAULT_DELETE_BEHAVIOR="ask"
                                print_status "  Delete behavior: ask for confirmation"
                            else
                                DEFAULT_DELETE_BEHAVIOR="skip"
                                print_status "  Delete behavior: skip (no deletions)"
                            fi
                            ;;
                    esac
                fi
            fi
        done < "$config_file"
        echo ""
    fi
}

# Function to read ignore patterns from file
read_ignore_patterns() {
    local ignore_file="$1"
    local patterns=()
    
    if [ -f "$ignore_file" ]; then
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

# Simple change counting
count_changes_simple() {
    local dry_run_output="$1"
    local files_to_transfer=0
    local files_to_delete=0
    
    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            local op_char="${line:0:1}"
            
            case "$op_char" in
                '<'|'>'|'c')
                    files_to_transfer=$((files_to_transfer + 1))
                    ;;
                '*')
                    files_to_delete=$((files_to_delete + 1))
                    ;;
            esac
        fi
    done <<< "$dry_run_output"
    
    echo "$files_to_transfer:$files_to_delete"
}

# Function to sync a single directory pair
sync_directory_pair() {
    local local_dir="$1"
    local remote_dir="$2"
    local pair_name="$3"
    local suppress_output="$4"
    local first_run="${5:-false}"
    
    # Validate local directory exists (create if needed)
    if [ ! -d "$local_dir" ]; then
        if [ "$suppress_output" != "true" ]; then
            print_status "Creating local directory: $local_dir"
        fi
        mkdir -p "$local_dir"
    fi
    
    # Build remote path
    local remote_path="$SSH_USER@$REMOTE_HOST:$remote_dir"
    
    # Add trailing slash to remote directory for rsync
    if [[ "$remote_dir" != */ ]]; then
        remote_dir="$remote_dir/"
    fi
    
    # Build rsync options
    local dry_run_opts=(
        -avzh
    )
    
    # Add symlink handling based on configuration
    if [ "$FOLLOW_SYMLINKS" = true ]; then
        dry_run_opts+=(
            --copy-links
            --copy-unsafe-links
        )
    fi
    
    dry_run_opts+=(
        --dry-run
        --itemize-changes
    )
    
    # Only add --delete if DELETE_BEHAVIOR is not "skip"
    if [ "$DELETE_BEHAVIOR" != "skip" ]; then
        dry_run_opts+=( --delete )
    fi
    
    dry_run_opts+=(
        --stats
        --omit-dir-times
        -e "$SSH_CMD"
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
        --exclude='.syncreverseignore'
        --exclude='.syncconfig'
        --exclude='.syncreverseconfig'
    )
    
    # Add custom ignore patterns if they exist (check for .syncreverseignore)
    local ignore_file=""
    if [ -f "${local_dir%/}/.syncreverseignore" ]; then
        ignore_file="${local_dir%/}/.syncreverseignore"
    fi
    
    if [ -n "$ignore_file" ]; then
        readarray -t custom_excludes < <(read_ignore_patterns "$ignore_file")
        if [ ${#custom_excludes[@]} -gt 0 ]; then
            dry_run_opts+=("${custom_excludes[@]}")
        fi
    fi
    
    # Dry run to check for changes (with timeout to prevent hanging)
    set +e
    # Use timeout to prevent hanging (30 seconds should be enough for dry-run)
    local dry_run_output=$(timeout 30 rsync "${dry_run_opts[@]}" "$SSH_USER@$REMOTE_HOST:$remote_dir" "$local_dir" 2>&1)
    local dry_run_exit_code=$?
    set -e
    
    # Check for timeout
    if [ $dry_run_exit_code -eq 124 ]; then
        print_error "Rsync timed out after 30 seconds. Check network connection and paths."
        print_error "Local: $local_dir"
        print_error "Remote: $SSH_USER@$REMOTE_HOST:$remote_dir"
        return 1
    fi
    
    # Parse changes
    local change_analysis=$(count_changes_simple "$dry_run_output")
    local files_to_transfer=$(echo "$change_analysis" | cut -d':' -f1)
    local files_to_delete=$(echo "$change_analysis" | cut -d':' -f2)
    local total_changes=$((files_to_transfer + files_to_delete))
    
    # If no changes and suppressing output, return
    if [ "$suppress_output" = "true" ] && [ $total_changes -eq 0 ]; then
        return 0
    fi
    
    # Report changes if any
    if [ $total_changes -gt 0 ]; then
        echo ""
        if [ -n "$pair_name" ]; then
            print_status "[$pair_name] Changes detected:"
        else
            print_status "Changes detected:"
        fi
        echo "  📥 Files to download: $files_to_transfer"
        echo "  🗑️  Files to delete locally: $files_to_delete"
        echo "  📊 TOTAL changes: $total_changes"
        
        # Handle deletion behavior based on configuration
        if [ $files_to_delete -gt 0 ]; then
            if [ "$DELETE_BEHAVIOR" = "skip" ]; then
                # Skip deletions entirely
                if [ -n "$pair_name" ]; then
                    print_warning "[$pair_name] Skipping deletion of $files_to_delete files (delete behavior: skip)"
                else
                    print_warning "Skipping deletion of $files_to_delete files (delete behavior: skip)"
                fi
                # We'll continue with the sync but without --delete flag
            elif [ "$DELETE_BEHAVIOR" = "ask" ]; then
                # Ask for deletion confirmation
                # Only ask on first run or if not yet confirmed for this session
                if [ "$first_run" = "true" ] || [ "$DELETE_CONFIRMED" = false ]; then
                    echo ""
                    print_warning "This sync will DELETE $files_to_delete local files!"
                    read -p "Proceed with deletion? (y/n/a for always): " -n 1 -r delete_response
                    echo ""
                    
                    if [[ $delete_response =~ ^[Yy]$ ]]; then
                        # Proceed with this sync
                        print_status "Deletion confirmed for this sync"
                    elif [[ $delete_response =~ ^[Aa]$ ]]; then
                        # Always allow deletions for this session
                        DELETE_CONFIRMED=true
                        print_status "Deletions will be allowed for all syncs in this session"
                    else
                        # Skip this sync
                        if [ -n "$pair_name" ]; then
                            print_warning "[$pair_name] Sync skipped - deletion not confirmed"
                        else
                            print_warning "Sync skipped - deletion not confirmed"
                        fi
                        return 0
                    fi
                fi
            fi
        fi
        
        # Perform the actual sync
        if [ -n "$pair_name" ]; then
            print_status "[$pair_name] Syncing from remote to local..."
        else
            print_status "Syncing from remote to local..."
        fi
        
        # Build actual rsync options (without dry-run)
        local rsync_opts=(
            -avzh
        )
        
        # Add symlink handling based on configuration
        if [ "$FOLLOW_SYMLINKS" = true ]; then
            rsync_opts+=(
                --copy-links
                --copy-unsafe-links
            )
        fi
        
        # Only add --delete if DELETE_BEHAVIOR is not "skip"
        if [ "$DELETE_BEHAVIOR" != "skip" ]; then
            rsync_opts+=( --delete )
        fi
        
        rsync_opts+=(
            --progress
            --stats
            --omit-dir-times
            -e "$SSH_CMD"
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
            --exclude='.syncreverseignore'
            --exclude='.syncconfig'
            --exclude='.syncreverseconfig'
        )
        
        if [ ${#custom_excludes[@]} -gt 0 ]; then
            rsync_opts+=("${custom_excludes[@]}")
        fi
        
        set +e
        rsync "${rsync_opts[@]}" "$SSH_USER@$REMOTE_HOST:$remote_dir" "$local_dir"
        local sync_exit_code=$?
        set -e
        
        if [ $sync_exit_code -eq 0 ]; then
            if [ -n "$pair_name" ]; then
                print_success "[$pair_name] Sync completed successfully!"
            else
                print_success "Sync completed successfully!"
            fi
        elif [ $sync_exit_code -eq 23 ] || [ $sync_exit_code -eq 24 ]; then
            if [ -n "$pair_name" ]; then
                print_success "[$pair_name] Sync completed with minor warnings"
            else
                print_success "Sync completed with minor warnings"
            fi
        else
            if [ -n "$pair_name" ]; then
                print_error "[$pair_name] Sync failed with exit code $sync_exit_code"
            else
                print_error "Sync failed with exit code $sync_exit_code"
            fi
            return 1
        fi
    elif [ "$suppress_output" != "true" ]; then
        if [ -n "$pair_name" ]; then
            print_status "[$pair_name] No changes detected - already in sync"
        else
            print_status "No changes detected - already in sync"
        fi
    fi
    
    return 0
}

# Function to perform sync for all directory pairs
perform_full_sync() {
    local suppress_no_changes="${1:-false}"
    local first_run="${2:-false}"
    
    # Test SSH connection on first run
    if [ "$first_run" = "true" ]; then
        print_status "Testing SSH connection to $REMOTE_HOST..."
        
        # Try to find SSH key
        if find_ssh_key; then
            print_status "Found SSH key at: $SSH_KEY_PATH"
            
            # Test key authentication
            if ssh -p "$SSH_PORT" -i "$SSH_KEY_PATH" -o BatchMode=yes -o PasswordAuthentication=no "$SSH_USER@$REMOTE_HOST" exit 2>/dev/null; then
                print_success "SSH key authentication successful"
                SSH_KEY_AUTH=true
            else
                print_warning "SSH key authentication failed, will use password"
                SSH_KEY_AUTH=false
            fi
        else
            print_warning "No SSH key found, using password authentication"
            SSH_KEY_AUTH=false
        fi
        
        # Build SSH command
        if [ "$SSH_KEY_AUTH" = true ]; then
            SSH_CMD="ssh -p $SSH_PORT -i $SSH_KEY_PATH"
        else
            SSH_CMD="ssh -p $SSH_PORT"
        fi
        
        # Setup SSH multiplexing
        setup_ssh_multiplexing "$SSH_USER" "$REMOTE_HOST" "$SSH_PORT" "$SSH_KEY_PATH"
        if [ "$SSH_MULTIPLEXING" = true ]; then
            print_success "SSH multiplexing enabled for faster connections"
        fi
    fi
    
    # Determine which directory pairs to sync
    local has_numbered_pairs=false
    local has_single_pair=false
    
    # Check for numbered pairs (_1 and _2)
    if [ -n "$LOCAL_DIR_1" ] && [ -n "$REMOTE_DIR_1" ]; then
        has_numbered_pairs=true
    fi
    if [ -n "$LOCAL_DIR_2" ] && [ -n "$REMOTE_DIR_2" ]; then
        has_numbered_pairs=true
    fi
    
    # Check for single pair (standard .syncconfig format)
    if [ -n "$LOCAL_DIR" ] && [ -n "$REMOTE_DIR" ]; then
        has_single_pair=true
    fi
    
    # Sync based on what's configured
    if [ "$has_numbered_pairs" = true ]; then
        # Sync numbered pairs
        if [ -n "$LOCAL_DIR_1" ] && [ -n "$REMOTE_DIR_1" ]; then
            sync_directory_pair "$LOCAL_DIR_1" "$REMOTE_DIR_1" "Pair 1" "$suppress_no_changes" "$first_run"
        fi
        
        if [ -n "$LOCAL_DIR_2" ] && [ -n "$REMOTE_DIR_2" ]; then
            sync_directory_pair "$LOCAL_DIR_2" "$REMOTE_DIR_2" "Pair 2" "$suppress_no_changes" "$first_run"
        fi
    elif [ "$has_single_pair" = true ]; then
        # Sync single pair (compatible with standard .syncconfig)
        sync_directory_pair "$LOCAL_DIR" "$REMOTE_DIR" "" "$suppress_no_changes" "$first_run"
    else
        print_error "No directory pairs configured"
        return 1
    fi
}

# Function to show usage
show_usage() {
    echo "Usage: $0 [config_file]"
    echo ""
    echo "Reverse Directory Synchronization Script"
    echo "Continuously syncs FROM remote directories TO local directories"
    echo ""
    echo "Config File:"
    echo "  config_file - Path to configuration file (e.g., .syncconfig)"
    echo "                If not provided, defaults to .syncreverseconfig"
    echo ""
    echo "Examples:"
    echo "  $0                    # Uses .syncreverseconfig from current or home dir"
    echo "  $0 .syncconfig        # Uses your existing .syncconfig file"
    echo "  $0 /path/to/config    # Uses specified config file"
    echo ""
    echo "Configuration File Format:"
    echo "  DEFAULT_REMOTE_HOST=server.example.com"
    echo "  DEFAULT_SSH_USER=username"
    echo "  DEFAULT_SSH_PORT=22"
    echo "  DEFAULT_LOCAL_DIR=/home/user/local_folder"
    echo "  DEFAULT_REMOTE_DIR=/remote/folder"
    echo "  DEFAULT_SYNC_INTERVAL=5  # Optional, seconds between syncs"
    echo "  DEFAULT_FOLLOW_SYMLINKS=true  # Optional, set to false to skip symlinks"
    echo "  DEFAULT_DELETE_BEHAVIOR=skip  # Optional, 'skip' or 'ask' (default: skip)"
    echo ""
    echo "For two directory pairs, you can also use:"
    echo "  DEFAULT_LOCAL_DIR_1=/home/user/local_folder1"
    echo "  DEFAULT_REMOTE_DIR_1=/remote/folder1"
    echo "  DEFAULT_LOCAL_DIR_2=/home/user/local_folder2"
    echo "  DEFAULT_REMOTE_DIR_2=/remote/folder2"
    echo ""
    echo "Features:"
    echo "  - Syncs FROM remote TO local (reverse of normal sync)"
    echo "  - Supports single or dual directory pairs"
    echo "  - Continuously monitors and syncs at specified interval"
    echo "  - Compatible with existing .syncconfig format"
    echo "  - SSH key authentication with password fallback"
    echo "  - SSH connection multiplexing for efficiency"
    echo "  - Excludes common unwanted files"
    echo "  - Custom exclude patterns via .syncreverseignore files"
    echo "  - Configurable deletion behavior (skip or ask)"
    echo ""
    echo "Ignore Files:"
    echo "  Place a .syncreverseignore file in each local directory"
    echo "  to specify patterns to exclude from that sync pair"
    echo ""
    echo "Deletion Confirmation:"
    echo "  Controlled by DEFAULT_DELETE_BEHAVIOR setting:"
    echo "    skip - Never delete local files (default)"
    echo "    ask  - Prompt for confirmation when files need deletion"
    echo ""
    echo "  When DELETE_BEHAVIOR is 'ask', you'll be prompted:"
    echo "    y - Confirm deletion for this sync only"
    echo "    n - Skip this sync (no deletions)"
    echo "    a - Always allow deletions for this session"
    echo ""
    echo "To stop monitoring, press Ctrl+C"
}

# Main execution

# Check for help flag
if [[ "$1" == "-h" ]] || [[ "$1" == "--help" ]]; then
    show_usage
    exit 0
fi

# Read configuration
CUSTOM_CONFIG_FILE=""
if [[ $# -gt 0 ]] && [[ -f "$1" ]]; then
    CUSTOM_CONFIG_FILE="$1"
    print_status "Using config file from command line: $CUSTOM_CONFIG_FILE"
fi

read_config "$CUSTOM_CONFIG_FILE"

# Assign configuration values
REMOTE_HOST="$DEFAULT_REMOTE_HOST"
SSH_USER="${DEFAULT_SSH_USER:-$USER}"
SSH_PORT="${DEFAULT_SSH_PORT:-22}"
UPDATE_INTERVAL="${DEFAULT_SYNC_INTERVAL:-5}"
FOLLOW_SYMLINKS="${DEFAULT_FOLLOW_SYMLINKS:-true}"
DELETE_BEHAVIOR="${DEFAULT_DELETE_BEHAVIOR:-skip}"

# Handle both single and dual pair configurations
LOCAL_DIR="$DEFAULT_LOCAL_DIR"
REMOTE_DIR="$DEFAULT_REMOTE_DIR"
LOCAL_DIR_1="$DEFAULT_LOCAL_DIR_1"
REMOTE_DIR_1="$DEFAULT_REMOTE_DIR_1"
LOCAL_DIR_2="$DEFAULT_LOCAL_DIR_2"
REMOTE_DIR_2="$DEFAULT_REMOTE_DIR_2"

# Validate required configuration
if [ -z "$REMOTE_HOST" ]; then
    print_error "REMOTE_HOST not configured"
    show_usage
    exit 1
fi

# Check that at least one directory pair is configured
has_config=false
if [ -n "$LOCAL_DIR" ] && [ -n "$REMOTE_DIR" ]; then
    has_config=true
fi
if [ -n "$LOCAL_DIR_1" ] && [ -n "$REMOTE_DIR_1" ]; then
    has_config=true
fi
if [ -n "$LOCAL_DIR_2" ] && [ -n "$REMOTE_DIR_2" ]; then
    has_config=true
fi

if [ "$has_config" = false ]; then
    print_error "No directory pairs configured"
    show_usage
    exit 1
fi

# Display configuration
print_success "Starting reverse sync monitor"
print_status "Remote host: $REMOTE_HOST:$SSH_PORT"
print_status "SSH user: $SSH_USER"
print_status "Update interval: $UPDATE_INTERVAL seconds"
if [ "$FOLLOW_SYMLINKS" = false ]; then
    print_status "Symbolic links: Will be skipped"
else
    print_status "Symbolic links: Will be followed"
fi
if [ "$DELETE_BEHAVIOR" = "skip" ]; then
    print_status "Delete behavior: Skip (no files will be deleted)"
else
    print_status "Delete behavior: Ask for confirmation"
fi
echo ""

# Display configured directory pairs
if [ -n "$LOCAL_DIR_1" ] && [ -n "$REMOTE_DIR_1" ]; then
    print_status "Directory Pair 1:"
    echo "  Remote: $REMOTE_DIR_1"
    echo "  Local:  $LOCAL_DIR_1"
fi

if [ -n "$LOCAL_DIR_2" ] && [ -n "$REMOTE_DIR_2" ]; then
    print_status "Directory Pair 2:"
    echo "  Remote: $REMOTE_DIR_2"
    echo "  Local:  $LOCAL_DIR_2"
fi

# If using single pair configuration
if [ -n "$LOCAL_DIR" ] && [ -n "$REMOTE_DIR" ] && [ -z "$LOCAL_DIR_1" ]; then
    print_status "Directory:"
    echo "  Remote: $REMOTE_DIR"
    echo "  Local:  $LOCAL_DIR"
fi

echo ""
print_status "Press Ctrl+C to stop monitoring"
echo ""

# Main monitoring loop
FIRST_RUN=true
SYNC_COUNT=0

while true; do
    SYNC_COUNT=$((SYNC_COUNT + 1))
    
    # Perform sync with suppression if no changes
    perform_full_sync "true" "$FIRST_RUN"
    
    # Mark first run as complete
    if [ "$FIRST_RUN" = "true" ]; then
        print_success "Initial sync complete - monitoring for changes..."
        FIRST_RUN=false
    fi
    
    # Wait before next check
    sleep "$UPDATE_INTERVAL"
done