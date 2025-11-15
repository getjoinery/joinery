#!/usr/bin/env bash
# Simple Local Development Deploy Script
# Clones the consolidated joinery repository directly to target directory

# Repository settings
GITHUB_USER="getjoinery"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/getjoinery/joinery.git"

# Function to show usage
show_usage() {
    echo "Simple Local Development Deploy Script"
    echo ""
    echo "Usage:"
    echo "  $0 [target_directory]"
    echo ""
    echo "Options:"
    echo "  --help, -h     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 /var/www/html/mysite"
    echo "  $0 /home/user/dev/testsite"
    echo ""
    echo "This script clones the joinery repository to the target directory."
    echo "The target directory becomes a git repository that can be updated with 'git pull'."
    echo ""
    echo "Preserved directories (not tracked by git):"
    echo "  - config/      (contains Globalvars_site.php)"
    echo "  - cache/       (runtime cache)"
    echo "  - logs/        (application logs)"
    echo "  - backups/     (backup files)"
    echo "  - static_files/ (uploaded static content)"
    echo "  - uploads/     (user uploads)"
}

# Parse command line arguments
TARGET_DIR=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --help|-h)
            show_usage
            exit 0
            ;;
        -*)
            echo "ERROR: Unknown option $1"
            show_usage
            exit 1
            ;;
        *)
            if [[ -z "$TARGET_DIR" ]]; then
                TARGET_DIR="$1"
            else
                echo "ERROR: Multiple target directories specified: '$TARGET_DIR' and '$1'"
                show_usage
                exit 1
            fi
            shift
            ;;
    esac
done

# Check if target directory was provided
if [[ -z "$TARGET_DIR" ]]; then
    echo "ERROR: Target directory is required."
    show_usage
    exit 1
fi

# Check for Windows-style paths
if [[ "$TARGET_DIR" =~ ^[A-Za-z]: ]] || [[ "$TARGET_DIR" == *\\* ]]; then
    echo "ERROR: Windows-style path detected: $TARGET_DIR"
    echo ""
    echo "When running from WSL, please use WSL path format:"
    echo "  Instead of: C:\\Users\\username\\path"
    echo "  Use:        /mnt/c/Users/username/path"
    exit 1
fi

# Check if target directory exists
if [[ -d "$TARGET_DIR" ]]; then
    # Check if it's already a git repository
    if [[ -d "$TARGET_DIR/.git" ]]; then
        echo "Target directory '$TARGET_DIR' is already a git repository."
        echo ""
        read -p "Update it with 'git pull'? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            cd "$TARGET_DIR"
            echo "Pulling latest changes..."
            git pull origin main
            echo ""
            echo "========================================="
            echo "SUCCESS: Repository updated!"
            echo "========================================="
        else
            echo "Operation cancelled."
        fi
        exit 0
    fi

    # Directory exists but isn't a git repo - need to handle existing files
    if [[ "$(ls -A "$TARGET_DIR" 2>/dev/null)" ]]; then
        echo "Target directory '$TARGET_DIR' exists and is not empty."
        echo ""
        echo "Contents:"
        ls -la "$TARGET_DIR" | head -10
        if [[ $(ls -la "$TARGET_DIR" | wc -l) -gt 11 ]]; then
            echo "... (and more files)"
        fi
        echo ""
        echo "This deployment will:"
        echo "  - Clone the git repository to this directory"
        echo "  - Preserve these directories (they're in .gitignore):"
        echo "    config/, cache/, logs/, backups/, static_files/, uploads/"
        echo "  - Replace everything else with fresh repository files"
        echo ""
        read -p "Proceed with deployment? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Deploy cancelled."
            exit 0
        fi

        # Back up preserved directories
        echo "Backing up preserved directories..."
        TEMP_BACKUP=$(mktemp -d)
        trap "rm -rf $TEMP_BACKUP" EXIT

        for dir in config cache logs backups static_files uploads docs; do
            if [[ -d "$TARGET_DIR/$dir" ]]; then
                echo "  Backing up $dir/..."
                cp -r "$TARGET_DIR/$dir" "$TEMP_BACKUP/"
            fi
        done

        # Preserve .claude symlink
        if [[ -L "$TARGET_DIR/.claude" ]]; then
            CLAUDE_LINK_TARGET=$(readlink "$TARGET_DIR/.claude")
        fi

        # Remove everything in target directory
        echo "Removing existing files..."
        rm -rf "$TARGET_DIR"/*
        rm -rf "$TARGET_DIR"/.??*  # Remove hidden files/dirs

        # Clone repository
        echo "Cloning repository..."
        git clone "$REPO_URL" "$TARGET_DIR"
        cd "$TARGET_DIR"
        git checkout main

        # Restore preserved directories
        echo "Restoring preserved directories..."
        for dir in config cache logs backups static_files uploads docs; do
            if [[ -d "$TEMP_BACKUP/$dir" ]]; then
                echo "  Restoring $dir/..."
                cp -r "$TEMP_BACKUP/$dir" "$TARGET_DIR/"
            fi
        done

        # Restore .claude symlink
        if [[ -n "$CLAUDE_LINK_TARGET" ]]; then
            echo "Restoring .claude symlink..."
            ln -sf "$CLAUDE_LINK_TARGET" "$TARGET_DIR/.claude"
        fi

    else
        echo "Using existing empty directory: $TARGET_DIR"
        echo "Cloning repository..."
        git clone "$REPO_URL" "$TARGET_DIR"
        cd "$TARGET_DIR"
        git checkout main
    fi
else
    # Fresh install - directory doesn't exist
    echo "Creating directory: $TARGET_DIR"
    echo "Cloning repository..."
    git clone "$REPO_URL" "$TARGET_DIR"
    cd "$TARGET_DIR"
    git checkout main
fi

echo ""
echo "========================================="
echo "SUCCESS: Deployment complete!"
echo "Site deployed to: $TARGET_DIR"
echo ""
echo "Directory structure:"
echo "  $TARGET_DIR/"
echo "  ├── .git/              (git repository)"
echo "  ├── public_html/       (application code)"
echo "  │   ├── theme/         (themes)"
echo "  │   └── plugins/       (plugins)"
echo "  ├── maintenance scripts/"
echo "  ├── docs/"
echo "  └── [preserved dirs]/  (config, cache, logs, etc.)"
echo ""
echo "To update this site in the future:"
echo "  cd $TARGET_DIR && git pull"
echo ""
echo "To make changes:"
echo "  1. Edit files in $TARGET_DIR"
echo "  2. git add <files>"
echo "  3. git commit -m 'description'"
echo "  4. git push"
echo "========================================"
