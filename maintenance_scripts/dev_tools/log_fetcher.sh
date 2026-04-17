#!/bin/bash

# VPS Error Log Fetcher - Direct Remote Access
# Fetches the last X lines from VPS error log without saving locally
# Perfect for Claude Code integration
# Usage: ./fetch-vps-errors.sh [--lines N] [--setup-ssh-key]

set -e  # Exit on any error

# Configuration - UPDATE THESE VALUES
VPS_USER="user1"
VPS_HOST="69.164.209.253"
VPS_LOG_PATH="/var/www/html/joinerytest/logs/error.log"
DEFAULT_SSH_PORT=22
DEFAULT_LINES=20

# Runtime variables
SSH_KEY_AUTH=false
SSH_PORT="$DEFAULT_SSH_PORT"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to log with timestamp and colors
log() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${BLUE}[INFO]${NC} $1" >&2
}

success_log() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${GREEN}[SUCCESS]${NC} $1" >&2
}

error_log() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${RED}[ERROR]${NC} $1" >&2
}

warning_log() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${YELLOW}[WARNING]${NC} $1" >&2
}

# Function to test SSH connection quietly
test_ssh_connection() {
    # First try with key authentication only
    if ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o BatchMode=yes -o PasswordAuthentication=no "$VPS_USER@$VPS_HOST" exit 2>/dev/null; then
        SSH_KEY_AUTH=true
        return 0
    else
        # Key auth failed, try with password authentication
        if ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o PreferredAuthentications=password -o PubkeyAuthentication=no "$VPS_USER@$VPS_HOST" exit 2>/dev/null; then
            SSH_KEY_AUTH=false
            return 0
        else
            return 1
        fi
    fi
}

# Function to test SSH connection with verbose output
test_ssh_connection_verbose() {
    log "Testing SSH connection to $VPS_USER@$VPS_HOST:$SSH_PORT..."
    
    # First try with key authentication only
    if ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o BatchMode=yes -o PasswordAuthentication=no "$VPS_USER@$VPS_HOST" exit 2>/dev/null; then
        success_log "SSH connection successful (using key authentication)"
        SSH_KEY_AUTH=true
        return 0
    else
        # Key auth failed, try with password authentication
        warning_log "SSH key authentication not available. Trying password authentication..."
        echo ""
        echo "You may be prompted for your password:" >&2
        
        # Try actual connection with password (this will prompt)
        if ssh -p "$SSH_PORT" -o ConnectTimeout=10 -o PreferredAuthentications=password -o PubkeyAuthentication=no "$VPS_USER@$VPS_HOST" exit; then
            success_log "SSH connection successful with password"
            warning_log "Note: Password authentication detected."
            warning_log "For automated fetching, consider setting up SSH keys."
            echo "" >&2
            echo "To set up SSH key authentication automatically, run:" >&2
            echo "  $0 --setup-ssh-key" >&2
            echo "" >&2
            SSH_KEY_AUTH=false
            return 0
        else
            # Connection failed entirely
            error_log "SSH connection failed!"
            echo "" >&2
            echo "Error details:" >&2
            echo "- Connection to $VPS_USER@$VPS_HOST:$SSH_PORT failed" >&2
            echo "- Neither key nor password authentication worked" >&2
            echo "" >&2
            echo "Troubleshooting steps:" >&2
            echo "1. Verify connection details in script configuration" >&2
            echo "2. Check if you're blocked by fail2ban: wait a few minutes and try again" >&2
            echo "3. Test manual connection: ssh -p $SSH_PORT $VPS_USER@$VPS_HOST" >&2
            echo "4. Check firewall rules on both client and server" >&2
            echo "5. Verify SSH service is running on VPS" >&2
            echo "" >&2
            echo "For detailed debugging, run:" >&2
            echo "  ssh -vvv -p $SSH_PORT $VPS_USER@$VPS_HOST" >&2
            echo "" >&2
            return 1
        fi
    fi
}

# Function to set up SSH key authentication
setup_ssh_key() {
    log "SSH Key Setup Helper"
    echo "===================="
    echo ""
    
    # Validate configuration first
    if [[ "$VPS_USER" == "your_username" || "$VPS_HOST" == "your-vps-ip-or-domain" ]]; then
        error_log "Please update the configuration variables at the top of this script first"
        echo ""
        echo "You need to set:"
        echo "  VPS_USER     - Your username on the VPS"
        echo "  VPS_HOST     - Your VPS IP address or domain name"
        echo "  VPS_LOG_PATH - Path to error log on your VPS"
        echo ""
        return 1
    fi
    
    # Check if key exists
    local key_file="$HOME/.ssh/id_rsa"
    if [ -f "$key_file" ]; then
        log "SSH key already exists at $key_file"
        read -p "Use existing key? (Y/n): " -n 1 -r
        echo ""
        if [[ $REPLY =~ ^[Nn]$ ]]; then
            read -p "Enter path for new key [$HOME/.ssh/id_rsa_vps]: " new_key_path
            key_file="${new_key_path:-$HOME/.ssh/id_rsa_vps}"
        fi
    else
        log "No SSH key found. Creating new key..."
        key_file="$HOME/.ssh/id_rsa"
    fi
    
    # Generate key if it doesn't exist
    if [ ! -f "$key_file" ]; then
        log "Generating SSH key..."
        ssh-keygen -t rsa -b 4096 -f "$key_file" -N "" || {
            error_log "Failed to generate SSH key"
            return 1
        }
        success_log "SSH key generated at $key_file"
    fi
    
    # Copy key to remote server
    log "Copying SSH key to VPS..."
    echo "You'll need to enter your VPS password:"
    echo ""
    
    if ssh-copy-id -p "$SSH_PORT" -i "$key_file" "$VPS_USER@$VPS_HOST"; then
        success_log "SSH key successfully copied to VPS!"
        echo ""
        log "Testing passwordless connection..."
        if ssh -p "$SSH_PORT" -i "$key_file" -o BatchMode=yes "$VPS_USER@$VPS_HOST" exit 2>/dev/null; then
            success_log "Passwordless SSH connection successful!"
            echo ""
            echo "✅ Setup complete! You can now fetch error logs without passwords."
            echo ""
            echo "Try it out:"
            echo "  $0                    # Fetch last 20 error lines"
            echo "  $0 --lines 50         # Fetch last 50 error lines"
            echo "  $0 --lines 100        # Fetch last 100 error lines"
        else
            error_log "Passwordless connection test failed"
            echo ""
            echo "The key was copied but passwordless connection isn't working."
            echo "This sometimes happens due to:"
            echo "- File permissions on the server"
            echo "- SSH server configuration"
            echo "- SELinux/AppArmor restrictions"
            echo ""
            echo "Try running this command on your VPS:"
            echo "  chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys"
        fi
    else
        error_log "Failed to copy SSH key to VPS"
        echo ""
        echo "Common causes:"
        echo "- Incorrect password"
        echo "- SSH server doesn't allow password authentication"
        echo "- Network connectivity issues"
        echo "- VPS firewall blocking connections"
        return 1
    fi
    
    return 0
}

# Function to fetch log lines directly from VPS
fetch_log_lines() {
    local lines=${1:-$DEFAULT_LINES}
    
    # Validate configuration
    if [[ "$VPS_USER" == "your_username" || "$VPS_HOST" == "your-vps-ip-or-domain" ]]; then
        error_log "Please update the configuration variables at the top of this script"
        echo "" >&2
        echo "You need to set:" >&2
        echo "  VPS_USER     - Your username on the VPS (e.g., 'deploy', 'ubuntu', 'root')" >&2
        echo "  VPS_HOST     - Your VPS IP or domain (e.g., '192.168.1.100', 'myserver.com')" >&2
        echo "  VPS_LOG_PATH - Path to error log (e.g., '/var/log/nginx/error.log')" >&2
        echo "" >&2
        echo "After updating the config, you can run:" >&2
        echo "  $0 --setup-ssh-key    # Set up passwordless authentication" >&2
        echo "  $0 --lines 50         # Fetch last 50 error lines" >&2
        return 1
    fi

    # Test connection quietly first
    if ! test_ssh_connection; then
        error_log "Cannot connect to VPS"
        echo "" >&2
        echo "Connection failed. This might be because:" >&2
        echo "- Incorrect connection details" >&2
        echo "- Network issues" >&2
        echo "- SSH service not running" >&2
        echo "- Firewall blocking connection" >&2
        echo "" >&2
        echo "To debug:" >&2
        echo "  $0 --test-connection    # Test connection with detailed output" >&2
        echo "  $0 --setup-ssh-key      # Set up SSH keys for passwordless access" >&2
        return 1
    fi
    
    # Build SSH command with appropriate authentication
    local ssh_opts="-p $SSH_PORT -o ConnectTimeout=10 -o StrictHostKeyChecking=no"
    if [ "$SSH_KEY_AUTH" = true ]; then
        ssh_opts="$ssh_opts -o BatchMode=yes"
    fi
    
    # Execute tail command on remote server
    local tail_cmd="tail -n $lines '$VPS_LOG_PATH' 2>/dev/null || (echo 'Error: Could not read log file $VPS_LOG_PATH' >&2; exit 1)"
    
    if ssh $ssh_opts "$VPS_USER@$VPS_HOST" "$tail_cmd"; then
        return 0
    else
        error_log "Failed to fetch log lines from VPS" >&2
        echo "" >&2
        echo "This could be because:" >&2
        echo "- Log file path is incorrect: $VPS_LOG_PATH" >&2
        echo "- File doesn't exist or isn't readable" >&2
        echo "- Permission issues" >&2
        echo "" >&2
        echo "To debug:" >&2
        echo "  ssh -p $SSH_PORT $VPS_USER@$VPS_HOST 'ls -la $VPS_LOG_PATH'" >&2
        echo "  ssh -p $SSH_PORT $VPS_USER@$VPS_HOST 'sudo tail -n 5 $VPS_LOG_PATH'" >&2
        return 1
    fi
}

# Function to show usage
show_usage() {
    echo "VPS Error Log Fetcher - Direct Access" >&2
    echo "====================================" >&2
    echo "" >&2
    echo "Usage: $0 [OPTIONS]" >&2
    echo "" >&2
    echo "Options:" >&2
    echo "  --lines [N]           Number of lines to fetch (default: $DEFAULT_LINES)" >&2
    echo "  --setup-ssh-key       Interactive SSH key setup for passwordless access" >&2
    echo "  --test-connection     Test SSH connection and show detailed output" >&2
    echo "  --port [port]         SSH port (default: $DEFAULT_SSH_PORT)" >&2
    echo "  --help               Show this help message" >&2
    echo "" >&2
    echo "Examples:" >&2
    echo "  $0                    Fetch last $DEFAULT_LINES error lines" >&2
    echo "  $0 --lines 50         Fetch last 50 error lines" >&2
    echo "  $0 --lines 100        Fetch last 100 error lines" >&2
    echo "  $0 --setup-ssh-key    Set up SSH key authentication" >&2
    echo "  $0 --test-connection  Test connection with verbose output" >&2
    echo "  $0 --port 2222        Use SSH port 2222" >&2
    echo "" >&2
    echo "Configuration:" >&2
    echo "  Edit the script to set:" >&2
    echo "    VPS_USER     - Your VPS username" >&2
    echo "    VPS_HOST     - Your VPS IP/hostname" >&2
    echo "    VPS_LOG_PATH - Path to error log on VPS" >&2
    echo "" >&2
    echo "For Claude Code Integration:" >&2
    echo "  Claude Code can run '$0 --lines 50' to get the latest" >&2
    echo "  error logs when it needs to analyze your application." >&2
    echo "" >&2
    echo "Output:" >&2
    echo "  The script outputs only the log lines to stdout." >&2
    echo "  All status messages go to stderr to keep output clean." >&2
}

# Parse command line arguments
LINES="$DEFAULT_LINES"

while [[ $# -gt 0 ]]; do
    case $1 in
        --lines)
            LINES="${2:-$DEFAULT_LINES}"
            # Validate lines is a number
            if ! [[ "$LINES" =~ ^[0-9]+$ ]] || [ "$LINES" -eq 0 ]; then
                error_log "Number of lines must be a positive number"
                exit 1
            fi
            shift 2
            ;;
        --setup-ssh-key)
            setup_ssh_key
            exit $?
            ;;
        --test-connection)
            test_ssh_connection_verbose
            exit $?
            ;;
        --port)
            SSH_PORT="${2:-$DEFAULT_SSH_PORT}"
            if ! [[ "$SSH_PORT" =~ ^[0-9]+$ ]]; then
                error_log "SSH port must be a number"
                exit 1
            fi
            shift 2
            ;;
        --help|-h)
            show_usage
            exit 0
            ;;
        *)
            error_log "Unknown option: $1"
            show_usage
            exit 1
            ;;
    esac
done

# Main execution - fetch and display log lines
fetch_log_lines "$LINES"