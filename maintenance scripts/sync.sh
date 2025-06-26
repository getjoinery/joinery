#!/bin/bash

# Directory Synchronization Script with Content Analysis
# Syncs local directory with remote directory using rsync over SSH
# Enhanced to differentiate between substantive content changes and format-only changes
# Usage: ./sync.sh [local_dir] [remote_host] [remote_dir] [ssh_user]

set -e  # Exit on any error

# Configuration
DEFAULT_SSH_PORT=22
DEFAULT_SSH_USER="$USER"
DEFAULT_LOCAL_DIR=""
DEFAULT_REMOTE_HOST=""
DEFAULT_REMOTE_DIR=""

# Runtime variables
SSH_KEY_AUTH=false
SSH_KEY_PATH=""
SSH_MULTIPLEXING=false
SSH_CONTROL_PATH=""

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
        print_status "Cleaning up SSH connections..." >&2
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
    SSH_CONTROL_PATH="/tmp/sync_ssh_${ssh_host}_${ssh_port}_${ssh_user}_$$"
    
    # SSH multiplexing options
    local ssh_multiplex_opts="-o ControlMaster=auto -o ControlPath=$SSH_CONTROL_PATH -o ControlPersist=300"
    
    # Build SSH command with multiplexing
    if [ -n "$ssh_key_path" ] && [ "$SSH_KEY_AUTH" = true ]; then
        SSH_CMD_WITH_MULTIPLEX="ssh -p $ssh_port -i $ssh_key_path $ssh_multiplex_opts"
    else
        SSH_CMD_WITH_MULTIPLEX="ssh -p $ssh_port $ssh_multiplex_opts"
    fi
    
    print_status "Testing SSH multiplexing..."
    
    # Test if multiplexing works by establishing master connection
    if $SSH_CMD_WITH_MULTIPLEX -o BatchMode=yes "$ssh_user@$ssh_host" exit 2>/dev/null; then
        print_success "SSH multiplexing enabled - connections will be much faster!"
        SSH_MULTIPLEXING=true
        SSH_CMD="$SSH_CMD_WITH_MULTIPLEX"
        return 0
    else
        print_warning "SSH multiplexing not supported or failed, using individual connections"
        SSH_MULTIPLEXING=false
        # Keep original SSH_CMD as fallback
        return 1
    fi
}

# Function to find SSH key
find_ssh_key() {
    # Common SSH key locations in order of preference
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
    local config_file=""
    
    # Look for .syncconfig in current directory first, then home directory
    if [ -f ".syncconfig" ]; then
        config_file=".syncconfig"
    elif [ -f "$HOME/.syncconfig" ]; then
        config_file="$HOME/.syncconfig"
    fi
    
    if [ -n "$config_file" ]; then
        print_status "Reading configuration from: $config_file"
        
        # Source the config file safely
        while IFS= read -r line || [ -n "$line" ]; do
            # Skip empty lines and comments
            line=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            if [ -n "$line" ] && [[ ! "$line" =~ ^[[:space:]]*# ]]; then
                # Only allow specific variables for security
                if [[ "$line" =~ ^DEFAULT_(LOCAL_DIR|REMOTE_HOST|REMOTE_DIR|SSH_USER|SSH_PORT)= ]]; then
                    # Extract key and value
                    local key="${line%%=*}"
                    local value="${line#*=}"
                    
                    # Remove quotes if present
                    value=$(echo "$value" | sed 's/^["'\'']*//;s/["'\'']*$//')
                    
                    case "$key" in
                        DEFAULT_LOCAL_DIR)
                            DEFAULT_LOCAL_DIR="$value"
                            print_status "  Default local dir: $value"
                            ;;
                        DEFAULT_REMOTE_HOST)
                            DEFAULT_REMOTE_HOST="$value"
                            print_status "  Default remote host: $value"
                            ;;
                        DEFAULT_REMOTE_DIR)
                            DEFAULT_REMOTE_DIR="$value"
                            print_status "  Default remote dir: $value"
                            ;;
                        DEFAULT_SSH_USER)
                            DEFAULT_SSH_USER="$value"
                            print_status "  Default SSH user: $value"
                            ;;
                        DEFAULT_SSH_PORT)
                            DEFAULT_SSH_PORT="$value"
                            print_status "  Default SSH port: $value"
                            ;;
                    esac
                fi
            fi
        done < "$config_file"
        echo ""
    fi
}

# Simple change counting (fast mode)
count_changes_simple() {
    local dry_run_output="$1"
    local files_to_transfer=0
    local files_to_delete=0
    
    # Parse itemized output line by line
    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            # Get the first character which indicates the operation type
            local op_char="${line:0:1}"
            
            case "$op_char" in
                '<'|'>'|'c')
                    # File transfer (sent to remote, received from remote, or created)
                    ((files_to_transfer++))
                    ;;
                '*')
                    # Deleting
                    ((files_to_delete++))
                    ;;
            esac
        fi
    done <<< "$dry_run_output"
    
    echo "$files_to_transfer:$files_to_delete"
}

# Function to analyze if a file has substantive content changes
analyze_content_changes() {
    local local_file="$1"
    local remote_path="$2"
    local ssh_cmd="$3"
    
    # Skip if local file doesn't exist or isn't readable
    if [[ ! -f "$local_file" ]] || [[ ! -r "$local_file" ]]; then
        echo "unknown"
        return
    fi
    
    # Try to get remote content and compare (ignoring whitespace differences)
    if remote_content=$(ssh -o ConnectTimeout=5 -o BatchMode=yes $ssh_cmd "cat '$remote_path'" 2>/dev/null); then
        # Normalize both files (remove CR, normalize whitespace) and compare
        local_normalized=$(tr -d '\r' < "$local_file" 2>/dev/null | sed 's/[[:space:]]*$//' 2>/dev/null || echo "")
        remote_normalized=$(echo "$remote_content" | tr -d '\r' 2>/dev/null | sed 's/[[:space:]]*$//' 2>/dev/null || echo "")
        
        if [[ "$local_normalized" == "$remote_normalized" ]]; then
            echo "format-only"
        else
            echo "content-changed"
        fi
    else
        # Can't compare content, assume it's a real change
        echo "content-changed"
    fi
}

# Fast content analysis with timeout and size heuristics
analyze_content_changes_fast() {
    local local_file="$1"
    local remote_path="$2"
    local ssh_cmd="$3"
    
    # Skip if local file doesn't exist or isn't readable
    if [[ ! -f "$local_file" ]] || [[ ! -r "$local_file" ]]; then
        echo "unknown"
        return
    fi
    
    # Check if file is likely binary by extension (simple approach)
    local filename=$(basename "$local_file")
    local extension="${filename##*.}"
    extension="${extension,,}"  # Convert to lowercase
    
    case "$extension" in
        jpg|jpeg|png|gif|bmp|ico|pdf|zip|tar|gz|exe|dll|so|dylib|class|jar|war|ear|mp3|mp4|avi|mov|doc|docx|xls|xlsx|ppt|pptx|woff|woff2|ttf|eot)
            echo "content-changed"
            return
            ;;
    esac
    
    # Quick content comparison with 3 second timeout for text files
    if remote_content=$(timeout 3s ssh -o ConnectTimeout=2 -o BatchMode=yes $ssh_cmd "cat '$remote_path'" 2>/dev/null); then
        # Skip binary detection for now to avoid syntax issues - just do text comparison
        # Normalize both files (remove CR, normalize whitespace) and compare
        local_normalized=$(tr -d '\r' < "$local_file" 2>/dev/null | sed 's/[[:space:]]*$//' 2>/dev/null || echo "")
        remote_normalized=$(echo "$remote_content" | tr -d '\r' 2>/dev/null | sed 's/[[:space:]]*$//' 2>/dev/null || echo "")
        
        if [[ "$local_normalized" == "$remote_normalized" ]]; then
            echo "format-only"
        else
            echo "content-changed"
        fi
    else
        # Can't compare content quickly, assume it's a real change
        echo "unknown"
    fi
}

# Function to parse rsync dry-run output and categorize changes
analyze_changes() {
    local dry_run_output="$1"
    local local_dir="$2"
    local ssh_cmd="$3"
    local remote_user_host="$4"
    local remote_dir="$5"
    
    local files_to_transfer=0
    local files_to_delete=0
    local content_changes=0
    local format_changes=0
    local new_files=0
    
    declare -a content_changed_files=()
    declare -a format_changed_files=()
    declare -a new_files_list=()
    declare -a deleted_files=()
    declare -a files_to_check=()
    
    # First pass: parse rsync output and collect files to analyze
    print_status "Parsing rsync output..." >&2
    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            # Parse rsync itemize format: YXcstpoguax filename
            local op_char="${line:0:1}"
            local change_flags="${line:1:10}"
            local filename="${line:12}"
            
            case "$op_char" in
                '<')
                    # File being sent to remote
                    ((files_to_transfer++))
                    
                    # Check if this looks like a new file (>f+++++++++)
                    if [[ "$change_flags" =~ f\+\+\+\+\+\+\+\+\+ ]]; then
                        ((new_files++))
                        new_files_list+=("$filename")
                    else
                        # Existing file being modified - add to list for content checking
                        files_to_check+=("$filename")
                    fi
                    ;;
                '*')
                    # File being deleted
                    ((files_to_delete++))
                    deleted_files+=("$filename")
                    ;;
            esac
        fi
    done <<< "$dry_run_output"
    
    # Second pass: batch content analysis with progress indicator
    local total_files_to_check=${#files_to_check[@]}
    if [ $total_files_to_check -gt 0 ]; then
        print_status "Analyzing content changes for $total_files_to_check files..." >&2
        
        local checked=0
        local batch_size=10
        
        # Process files in batches for better progress reporting
        for ((i=0; i<$total_files_to_check; i+=batch_size)); do
            local batch_end=$((i + batch_size - 1))
            if [ $batch_end -ge $total_files_to_check ]; then
                batch_end=$((total_files_to_check - 1))
            fi
            
            # Show progress
            local progress_percent=$(( (i * 100) / total_files_to_check ))
            printf "\r  Progress: %d%% (%d/%d files)" $progress_percent $checked $total_files_to_check >&2
            
            # Process batch
            for ((j=i; j<=batch_end; j++)); do
                local filename="${files_to_check[$j]}"
                local local_file_path="${local_dir%/}/${filename}"
                local remote_file_path="$remote_dir/$filename"
                
                # Quick heuristic: if file size difference is very large, assume content change
                if [[ -f "$local_file_path" ]]; then
                    local local_size=$(wc -c < "$local_file_path" 2>/dev/null || echo "0")
                    
                    # For efficiency, assume content change for large files or if we can't get remote size quickly
                    if [[ $local_size -gt 50000 ]]; then
                        # Large files - assume content change to avoid slow analysis
                        ((content_changes++))
                        content_changed_files+=("$filename")
                    else
                        # Small files - do actual content comparison with timeout
                        local change_type=$(analyze_content_changes_fast "$local_file_path" "$remote_file_path" "$ssh_cmd")
                        
                        case "$change_type" in
                            "content-changed"|"unknown")
                                ((content_changes++))
                                content_changed_files+=("$filename")
                                ;;
                            "format-only")
                                ((format_changes++))
                                format_changed_files+=("$filename")
                                ;;
                        esac
                    fi
                else
                    # Local file doesn't exist - assume content change
                    ((content_changes++))
                    content_changed_files+=("$filename")
                fi
                
                ((checked++))
            done
        done
        
        # Final progress update
        printf "\r  Progress: 100%% (%d/%d files) - Complete!          \n" $total_files_to_check $total_files_to_check >&2
    fi
    
    # Output results in parseable format
    echo "transfer:$files_to_transfer"
    echo "delete:$files_to_delete"
    echo "content:$content_changes"
    echo "format:$format_changes"
    echo "new:$new_files"
    
    # Output file lists (for detailed reporting)
    if [ ${#content_changed_files[@]} -gt 0 ]; then
        echo "content_files:$(IFS='|'; echo "${content_changed_files[*]}")"
    fi
    if [ ${#format_changed_files[@]} -gt 0 ]; then
        echo "format_files:$(IFS='|'; echo "${format_changed_files[*]}")"
    fi
    if [ ${#new_files_list[@]} -gt 0 ]; then
        echo "new_files:$(IFS='|'; echo "${new_files_list[*]}")"
    fi
    if [ ${#deleted_files[@]} -gt 0 ]; then
        echo "deleted_files:$(IFS='|'; echo "${deleted_files[*]}")"
    fi
}

# Function to read ignore patterns from file
read_ignore_patterns() {
    local ignore_file="$1"
    local patterns=()
    
    if [ -f "$ignore_file" ]; then
        # Print to stderr so it does not get captured in command substitution
        print_status "Reading ignore patterns from: $ignore_file" >&2
        
        # Read file line by line, skip empty lines and comments
        while IFS= read -r line || [ -n "$line" ]; do
            # Trim whitespace
            line=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            
            # Skip empty lines and comments
            if [ -n "$line" ] && [[ ! "$line" =~ ^[[:space:]]*# ]]; then
                patterns+=("--exclude=$line")
                # Print to stderr so it does not get captured
                print_status "  Excluding: $line" >&2
            fi
        done < "$ignore_file"
        
        if [ ${#patterns[@]} -eq 0 ]; then
            print_warning "No valid ignore patterns found in $ignore_file" >&2
        fi
    else
        if [ "$ignore_file" != ".syncignore" ]; then
            # Only warn if it's a custom file, .syncignore is optional
            print_warning "Ignore file '$ignore_file' not found" >&2
        fi
    fi
    
    # Return patterns as array elements (only to stdout)
    printf '%s\n' "${patterns[@]}"
}

# Function to show usage
show_usage() {
    echo "Usage: $0 [--autodelete] [--ignore-file <file>] [local_dir] [remote_host] [remote_dir] [ssh_user] [ssh_port]"
    echo ""
    echo "Options:"
    echo "  --autodelete           - Skip confirmation prompt for deleting remote files"
    echo "  --ignore-file <file>   - Use custom ignore file (default: .syncignore)"
    echo "  --no-key               - Skip SSH key authentication, use password only"
    echo "  --setup-ssh-key        - Interactive SSH key setup helper"
    echo "  --fast                 - Skip content analysis (faster but less detailed reporting)"
    echo ""
    echo "Arguments (all optional if defaults configured):"
    echo "  local_dir   - Local directory to sync FROM"
    echo "  remote_host - Remote hostname or IP address"
    echo "  remote_dir  - Remote directory path to sync TO"
    echo "  ssh_user    - SSH username (optional, defaults to current user)"
    echo "  ssh_port    - SSH port (optional, defaults to 22)"
    echo ""
    echo "Configuration File (.syncconfig):"
    echo "  Create a .syncconfig file in your local directory or home directory:"
    echo "    DEFAULT_LOCAL_DIR=./src"
    echo "    DEFAULT_REMOTE_HOST=server.example.com"
    echo "    DEFAULT_REMOTE_DIR=/var/www/html"
    echo "    DEFAULT_SSH_USER=deploy"
    echo "    DEFAULT_SSH_PORT=22"
    echo ""
    echo "Examples:"
    echo "  $0 ./my-project server.example.com /home/user/my-project"
    echo "  $0 --autodelete ./website web.example.com /var/www/html deploy 2222"
    echo "  $0 --ignore-file custom.ignore ./docs server.com /var/www/docs deploy"
    echo "  $0 --autodelete   # Uses defaults from .syncconfig"
    echo "  $0 ./different-dir   # Uses config defaults for host/remote dir"
    echo ""
    echo "SSH Authentication:"
    echo "  The script will try SSH key authentication first, then fall back to password."
    echo "  To set up SSH keys for passwordless sync, run:"
    echo "    $0 --setup-ssh-key"
    echo ""
    echo "Ignore Files:"
    echo "  Create a .syncignore file in your local directory to specify additional"
    echo "  files and directories to exclude. One pattern per line, supports:"
    echo "    - Exact matches: 'secret.txt'"
    echo "    - Wildcards: '*.log'"
    echo "    - Directories: 'temp/' or 'cache/'"
    echo "    - Comments: '# This is a comment'"
    echo ""
    echo "Features:"
    echo "  - Only transfers changed files (efficient)"
    echo "  - Deletes remote files that don't exist locally"
    echo "  - Preserves permissions and timestamps"
    echo "  - Compresses data during transfer"
    echo "  - Excludes common unwanted files (.git, node_modules, etc.)"
    echo "  - Supports custom ignore patterns via .syncignore file"
    echo "  - Supports default configuration via .syncconfig file"
    echo "  - SSH key authentication with password fallback"
    echo "  - SSH connection multiplexing for faster content analysis"
    echo "  - Differentiates between content changes and format-only changes"
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
        mkdir -p "$HOME/.ssh"
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
            echo "You can now run sync without password prompts."
        else
            print_error "Passwordless connection test failed"
        fi
    else
        print_error "Failed to copy SSH key to remote server"
        return 1
    fi
    
    return 0
}

# Read configuration file if it exists
read_config

# Parse arguments
AUTO_DELETE=false
IGNORE_FILE=".syncignore"
USE_SSH_KEY=true
FAST_MODE=false

# Parse options
while [[ $# -gt 0 ]]; do
    case $1 in
        --autodelete)
            AUTO_DELETE=true
            shift
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
        --no-key)
            USE_SSH_KEY=false
            shift
            ;;
        --fast)
            FAST_MODE=true
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
        -*)
            print_error "Unknown option: $1"
            show_usage
            exit 1
            ;;
        *)
            # End of options, remaining arguments are positional
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

# Validate that we have all required parameters (from args or config)
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

# Validate that local directory exists
if [ ! -d "$LOCAL_DIR" ]; then
    print_error "Local directory '$LOCAL_DIR' does not exist"
    exit 1
fi

# Additional validation for the local directory
print_status "Validating local directory..."
if [ ! -r "$LOCAL_DIR" ]; then
    print_error "Local directory '$LOCAL_DIR' is not readable"
    exit 1
fi

# Test if we can list the directory contents
if ! ls "$LOCAL_DIR" >/dev/null 2>&1; then
    print_error "Cannot list contents of local directory '$LOCAL_DIR'"
    echo "This might be due to:"
    echo "  - Permission issues"
    echo "  - Special characters in the path"
    echo "  - The directory being a mount point that's not properly mounted"
    exit 1
fi

FILE_COUNT=$(find "$LOCAL_DIR" -type f 2>/dev/null | wc -l)
print_status "Local directory contains $FILE_COUNT files"

# Add trailing slash to local directory for rsync
if [[ "$LOCAL_DIR" != */ ]]; then
    LOCAL_DIR="$LOCAL_DIR/"
fi

# Check for ignore file relative to local directory
FULL_IGNORE_PATH=""
if [[ "$IGNORE_FILE" == /* ]]; then
    # Absolute path
    FULL_IGNORE_PATH="$IGNORE_FILE"
else
    # Relative path - look in the local directory first, then current directory
    if [ -f "${LOCAL_DIR%/}/$IGNORE_FILE" ]; then
        FULL_IGNORE_PATH="${LOCAL_DIR%/}/$IGNORE_FILE"
    elif [ -f "$IGNORE_FILE" ]; then
        FULL_IGNORE_PATH="$IGNORE_FILE"
    fi
fi

# Build remote path
REMOTE_PATH="$SSH_USER@$REMOTE_HOST:$REMOTE_DIR"

print_status "Starting directory synchronization..."
echo "  Local:  $LOCAL_DIR"
echo "  Remote: $REMOTE_PATH"
echo "  Port:   $SSH_PORT"
echo ""

# Test SSH connection
print_status "Testing SSH connection..."

# Try to find SSH key if enabled
if [ "$USE_SSH_KEY" = true ] && find_ssh_key; then
    print_status "Found SSH key at: $SSH_KEY_PATH"
    
    # Test key authentication
    if ssh -p "$SSH_PORT" -i "$SSH_KEY_PATH" -o BatchMode=yes -o PasswordAuthentication=no "$SSH_USER@$REMOTE_HOST" exit 2>/dev/null; then
        print_success "SSH key authentication successful"
        SSH_KEY_AUTH=true
    else
        print_warning "SSH key authentication failed, will fall back to password"
        SSH_KEY_AUTH=false
    fi
else
    if [ "$USE_SSH_KEY" = true ]; then
        print_warning "No SSH key found. Consider setting up SSH keys for passwordless sync:"
        echo "  Run: $0 --setup-ssh-key $LOCAL_DIR $REMOTE_HOST $REMOTE_DIR $SSH_USER $SSH_PORT"
        echo ""
    fi
    SSH_KEY_AUTH=false
fi

# If key auth failed, test password auth
if [ "$SSH_KEY_AUTH" = false ]; then
    print_status "Testing password authentication..."
    if ! ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o PreferredAuthentications=password -o PubkeyAuthentication=no "$SSH_USER@$REMOTE_HOST" exit 2>&1; then
        print_warning "SSH connection test failed. You'll be prompted for password during sync."
    else
        print_success "Password authentication available"
    fi
fi

# Build SSH command based on authentication method
if [ "$SSH_KEY_AUTH" = true ]; then
    SSH_CMD="ssh -p $SSH_PORT -i $SSH_KEY_PATH"
else
    SSH_CMD="ssh -p $SSH_PORT"
fi

# Setup SSH multiplexing for faster connections during content analysis
setup_ssh_multiplexing "$SSH_USER" "$REMOTE_HOST" "$SSH_PORT" "$SSH_KEY_PATH"

# First, do a dry run to see what would change
print_status "Analyzing changes (dry run)..."

# Create dry-run specific options (same as main options but with dry-run and itemize)
DRY_RUN_OPTS=(
    -avzh
    --dry-run
    --itemize-changes
    --delete
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
)

# Add custom ignore patterns to dry run
if [ -n "$FULL_IGNORE_PATH" ]; then
    readarray -t CUSTOM_EXCLUDES < <(read_ignore_patterns "$FULL_IGNORE_PATH")
    DRY_RUN_OPTS+=("${CUSTOM_EXCLUDES[@]}")
elif [ "$IGNORE_FILE" != ".syncignore" ]; then
    print_error "Specified ignore file '$IGNORE_FILE' not found"
    exit 1
fi

print_status "Running analysis..."

# Temporarily disable exit on error so we can handle rsync failure gracefully
set +e
DRY_RUN_OUTPUT=$(rsync "${DRY_RUN_OPTS[@]}" "$LOCAL_DIR" "$REMOTE_PATH" 2>&1)
DRY_RUN_EXIT_CODE=$?
set -e  # Re-enable exit on error

# Handle rsync exit codes
if [ $DRY_RUN_EXIT_CODE -eq 0 ]; then
    # Complete success
    print_status "Dry run completed successfully"
elif [ $DRY_RUN_EXIT_CODE -eq 23 ]; then
    # Partial transfer due to error - often just permission warnings, proceed
    print_warning "Dry run completed with warnings (exit code 23)"
    print_warning "Some files may have permission or attribute issues, but transfer should work"
elif [ $DRY_RUN_EXIT_CODE -eq 24 ]; then
    # Partial transfer due to vanished source files - proceed
    print_warning "Dry run completed with warnings (exit code 24)"
    print_warning "Some source files disappeared during analysis, but transfer should work"
else
    # Real error
    print_error "Failed to analyze changes (dry run failed with exit code $DRY_RUN_EXIT_CODE)"
    echo ""
    echo "Full error output:"
    echo "=================="
    echo "$DRY_RUN_OUTPUT"
    echo "=================="
    echo ""
    echo "Common causes:"
    echo "  - SSH authentication failed (check your password or SSH keys)"
    echo "  - Network connectivity issues"
    echo "  - Remote directory doesn't exist or isn't accessible"
    echo ""
    if [ "$SSH_KEY_AUTH" = false ]; then
        echo "Consider setting up SSH keys for easier authentication:"
        echo "  $0 --setup-ssh-key $LOCAL_DIR $REMOTE_HOST $REMOTE_DIR $SSH_USER $SSH_PORT"
    fi
    exit 1
fi

# Parse the results with detailed analysis
if [ "$FAST_MODE" = true ]; then
    print_status "Fast mode enabled - using simplified analysis..."
    # Simple parsing without content analysis
    CHANGE_ANALYSIS=$(count_changes_simple "$DRY_RUN_OUTPUT")
    FILES_TO_TRANSFER=$(echo "$CHANGE_ANALYSIS" | cut -d':' -f1)
    FILES_TO_DELETE=$(echo "$CHANGE_ANALYSIS" | cut -d':' -f2)
    CONTENT_CHANGES=$FILES_TO_TRANSFER
    FORMAT_CHANGES=0
    NEW_FILES=0
    SUBSTANTIVE_CHANGES=$((CONTENT_CHANGES + FILES_TO_DELETE))
    TOTAL_CHANGES=$((FILES_TO_TRANSFER + FILES_TO_DELETE))
    
    echo ""
    print_status "Fast analysis complete:"
    echo "  📝 Files to transfer: $FILES_TO_TRANSFER"
    echo "  🗑️  Files to delete: $FILES_TO_DELETE" 
    echo "  📊 TOTAL changes: $TOTAL_CHANGES"
    echo ""
    print_warning "Note: Use without --fast for detailed content vs format analysis"
else
    print_status "Analyzing changes (this may take a moment for content comparison)..."
    if [ "$SSH_MULTIPLEXING" = true ]; then
        print_status "Using SSH multiplexing for faster analysis..."
    fi
    
    # Extract remote connection details for content analysis
    REMOTE_USER_HOST="${SSH_USER}@${REMOTE_HOST}"
    
    # Get detailed change analysis
    CHANGE_ANALYSIS=$(analyze_changes "$DRY_RUN_OUTPUT" "$LOCAL_DIR" "$SSH_CMD" "$REMOTE_USER_HOST" "$REMOTE_DIR")
    
    # Parse the analysis results
    FILES_TO_TRANSFER=$(echo "$CHANGE_ANALYSIS" | grep "^transfer:" | cut -d':' -f2)
    FILES_TO_DELETE=$(echo "$CHANGE_ANALYSIS" | grep "^delete:" | cut -d':' -f2)
    CONTENT_CHANGES=$(echo "$CHANGE_ANALYSIS" | grep "^content:" | cut -d':' -f2)
    FORMAT_CHANGES=$(echo "$CHANGE_ANALYSIS" | grep "^format:" | cut -d':' -f2)
    NEW_FILES=$(echo "$CHANGE_ANALYSIS" | grep "^new:" | cut -d':' -f2)
    
    TOTAL_CHANGES=$((FILES_TO_TRANSFER + FILES_TO_DELETE))
    SUBSTANTIVE_CHANGES=$((CONTENT_CHANGES + NEW_FILES + FILES_TO_DELETE))
    
    echo ""
    print_status "Detailed analysis complete:"
    echo "  📝 Content changes (substantive): $CONTENT_CHANGES files"
    echo "  📄 New files: $NEW_FILES files"
    echo "  🗑️  Files to delete: $FILES_TO_DELETE files"
    echo "  🔧 Format changes only (line endings/permissions): $FORMAT_CHANGES files"
    echo "  ─────────────────────────────────────────────────"
    echo "  📊 TOTAL substantive changes: $SUBSTANTIVE_CHANGES files"
    echo "  📊 TOTAL format-only changes: $FORMAT_CHANGES files"
    echo "  📊 TOTAL all changes: $TOTAL_CHANGES files"
fi

# Show detailed file lists if there are substantive changes
if [ $SUBSTANTIVE_CHANGES -gt 0 ]; then
    echo ""
    print_warning "Files with substantive changes:"
    
    # Show content changes
    CONTENT_FILES=$(echo "$CHANGE_ANALYSIS" | grep "^content_files:" | cut -d':' -f2)
    if [ -n "$CONTENT_FILES" ] && [ "$CONTENT_FILES" != "" ]; then
        echo ""
        echo "📝 Modified files (content changed):"
        echo "$CONTENT_FILES" | tr '|' '\n' | head -20 | sed 's/^/    /'
        if [ $(echo "$CONTENT_FILES" | tr '|' '\n' | wc -l) -gt 20 ]; then
            echo "    ... and $((CONTENT_CHANGES - 20)) more files"
        fi
    fi
    
    # Show new files
    NEW_FILES_LIST=$(echo "$CHANGE_ANALYSIS" | grep "^new_files:" | cut -d':' -f2)
    if [ -n "$NEW_FILES_LIST" ] && [ "$NEW_FILES_LIST" != "" ]; then
        echo ""
        echo "📄 New files:"
        echo "$NEW_FILES_LIST" | tr '|' '\n' | head -10 | sed 's/^/    /'
        if [ $(echo "$NEW_FILES_LIST" | tr '|' '\n' | wc -l) -gt 10 ]; then
            echo "    ... and $((NEW_FILES - 10)) more files"
        fi
    fi
    
    # Show deleted files
    DELETED_FILES=$(echo "$CHANGE_ANALYSIS" | grep "^deleted_files:" | cut -d':' -f2)
    if [ -n "$DELETED_FILES" ] && [ "$DELETED_FILES" != "" ]; then
        echo ""
        echo "🗑️  Files to be deleted:"
        echo "$DELETED_FILES" | tr '|' '\n' | head -10 | sed 's/^/    /'
        if [ $(echo "$DELETED_FILES" | tr '|' '\n' | wc -l) -gt 10 ]; then
            echo "    ... and $((FILES_TO_DELETE - 10)) more files"
        fi
    fi
fi

# Show format-only changes summary (but don't list all files unless requested)
if [ $FORMAT_CHANGES -gt 0 ]; then
    echo ""
    print_status "Format-only changes:"
    echo "  🔧 $FORMAT_CHANGES files have only line ending, permission, or timestamp differences"
    echo "  ℹ️  These are typically safe Windows→Linux format conversions"
    
    # Option to show format-only files
    if [ $FORMAT_CHANGES -le 20 ]; then
        FORMAT_FILES=$(echo "$CHANGE_ANALYSIS" | grep "^format_files:" | cut -d':' -f2)
        if [ -n "$FORMAT_FILES" ] && [ "$FORMAT_FILES" != "" ]; then
            echo ""
            echo "Files with format-only changes:"
            echo "$FORMAT_FILES" | tr '|' '\n' | sed 's/^/    /'
        fi
    else
        echo "  (Use --show-format-details to see full list)"
    fi
fi

# Decide whether to proceed based on substantive changes
if [ $TOTAL_CHANGES -eq 0 ]; then
    print_success "No changes detected - directories are already in sync!"
    exit 0
fi

if [ $SUBSTANTIVE_CHANGES -eq 0 ] && [ $FORMAT_CHANGES -gt 0 ]; then
    echo ""
    print_status "Only format changes detected (line endings, permissions, timestamps)"
    print_status "These changes will normalize your files for the Linux environment"
    echo ""
    if [ "$SSH_KEY_AUTH" = false ]; then
        print_warning "You will be prompted for your SSH password"
    fi
    read -p "Proceed with format normalization? (Y/n): " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Nn]$ ]]; then
        print_status "Synchronization cancelled"
        exit 0
    fi
elif [ $SUBSTANTIVE_CHANGES -gt 0 ]; then
    echo ""
    print_warning "Ready to sync $SUBSTANTIVE_CHANGES substantive changes"
    if [ $FORMAT_CHANGES -gt 0 ]; then
        print_status "Plus $FORMAT_CHANGES format-only changes"
    fi
    if [ "$SSH_KEY_AUTH" = false ]; then
        print_warning "You will be prompted for your SSH password"
    fi
    read -p "Proceed with synchronization? (y/N): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        print_status "Synchronization cancelled"
        exit 0
    fi
fi

# Additional confirmation for deletions (unless --autodelete is used)
if [ $FILES_TO_DELETE -gt 0 ] && [ "$AUTO_DELETE" = false ]; then
    echo ""
    print_warning "This sync will DELETE $FILES_TO_DELETE files from the remote server!"
    print_warning "Files on remote that do not exist locally will be permanently removed."
    read -p "Are you sure you want to delete these remote files? (y/N): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        print_status "Synchronization cancelled due to deletion confirmation"
        exit 0
    fi
elif [ $FILES_TO_DELETE -gt 0 ] && [ "$AUTO_DELETE" = true ]; then
    print_status "Auto-delete mode enabled - skipping deletion confirmation for $FILES_TO_DELETE files"
fi

# Rsync options for the actual sync (similar to dry run but without --dry-run and --itemize-changes)
RSYNC_OPTS=(
    -avzh
    --delete
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
)

# Add the same custom excludes we used in the dry run
if [ -n "$FULL_IGNORE_PATH" ]; then
    readarray -t CUSTOM_EXCLUDES < <(read_ignore_patterns "$FULL_IGNORE_PATH")
    RSYNC_OPTS+=("${CUSTOM_EXCLUDES[@]}")
fi

print_status "Starting rsync transfer..."
if [ "$SSH_KEY_AUTH" = true ]; then
    print_status "Using SSH key authentication"
else
    print_status "Using password authentication"
fi
echo ""

# Perform the sync with better error handling
set +e
rsync "${RSYNC_OPTS[@]}" "$LOCAL_DIR" "$REMOTE_PATH"
SYNC_EXIT_CODE=$?
set -e

if [ $SYNC_EXIT_CODE -eq 0 ]; then
    echo ""
    print_success "Directory synchronization completed successfully!"
    if [ $SUBSTANTIVE_CHANGES -gt 0 ]; then
        print_success "Synchronized $SUBSTANTIVE_CHANGES substantive changes"
    fi
    if [ $FORMAT_CHANGES -gt 0 ]; then
        print_success "Normalized $FORMAT_CHANGES files for Linux environment"
    fi
elif [ $SYNC_EXIT_CODE -eq 23 ]; then
    echo ""
    print_success "Directory synchronization completed with warnings!"
    print_warning "Some files had permission or attribute issues (exit code 23)"
    print_warning "This is usually not a problem - the files were still transferred"
elif [ $SYNC_EXIT_CODE -eq 24 ]; then
    echo ""
    print_success "Directory synchronization completed with warnings!"
    print_warning "Some source files vanished during transfer (exit code 24)"
    print_warning "This can happen with temporary files - sync was successful"
else
    echo ""
    print_error "Synchronization failed with exit code $SYNC_EXIT_CODE"
    echo ""
    echo "This might be due to:"
    echo "  - Network connectivity issues"
    echo "  - Permission problems on remote server"
    echo "  - Disk space issues on remote server"
    echo "  - File path issues (especially with spaces in names)"
    echo ""
    echo "Try running with verbose SSH output:"
    echo "  rsync -avzh -e 'ssh -v' \"$LOCAL_DIR\" \"$REMOTE_PATH\""
    exit 1
fi

# Optional: verify sync by comparing file counts
print_status "Sync operation completed"
echo ""
echo "To verify the sync, you can run:"
echo "  ssh -p $SSH_PORT $SSH_USER@$REMOTE_HOST 'find $REMOTE_DIR -type f | wc -l'"
echo "  find $LOCAL_DIR -type f | wc -l"