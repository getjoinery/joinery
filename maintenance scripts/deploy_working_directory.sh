#!/usr/bin/env bash
#version 2.0 - Repository restructure: theme/plugins now in public_html/
# MODIFIED v2.0: Removed theme/plugins from sparse checkout (now inside public_html/)
# MODIFIED v2.0: Removed post-checkout file moving logic
# MODIFIED v2.0: Simplified deployment - repository structure matches deployment

# Deploy script version
DEPLOY_VERSION="2.0"

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
    echo "Uses sparse checkout to only pull application code, preserving data directories."
    echo ""
    echo "Preserved directories (not in git):"
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

    # Directory exists but isn't a git repo
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
        echo "  - Initialize as git repository with sparse checkout"
        echo "  - Pull only: public_html/, theme/, plugins/, maintenance scripts/, docs/"
        echo "  - Preserve existing: config/, cache/, logs/, backups/, static_files/, uploads/"
        echo ""
        read -p "Proceed with deployment? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Deploy cancelled."
            exit 0
        fi

        cd "$TARGET_DIR"

        # Initialize git repository
        echo "Initializing git repository..."
        git init
        git config --global --add safe.directory "$TARGET_DIR"
        git remote add origin "$REPO_URL"

        # Configure sparse checkout
        echo "Configuring sparse checkout..."
        git config core.sparseCheckout true
        git sparse-checkout init --cone
        git sparse-checkout set public_html "maintenance scripts" docs

        # Fetch and checkout
        echo "Fetching repository..."
        git fetch origin main
        echo "Checking out main branch..."
        git checkout main

    else
        echo "Using existing empty directory: $TARGET_DIR"
        cd "$TARGET_DIR"

        # Initialize git repository
        echo "Initializing git repository..."
        git init
        git config --global --add safe.directory "$TARGET_DIR"
        git remote add origin "$REPO_URL"

        # Configure sparse checkout
        echo "Configuring sparse checkout..."
        git config core.sparseCheckout true
        git sparse-checkout init --cone
        git sparse-checkout set public_html "maintenance scripts" docs

        # Fetch and checkout
        echo "Fetching repository..."
        git fetch origin main
        echo "Checking out main branch..."
        git checkout main
    fi
else
    # Fresh install - directory doesn't exist
    echo "Creating directory: $TARGET_DIR"
    mkdir -p "$TARGET_DIR"
    cd "$TARGET_DIR"

    # Initialize git repository
    echo "Initializing git repository..."
    git init
    git remote add origin "$REPO_URL"

    # Configure sparse checkout
    echo "Configuring sparse checkout..."
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set public_html "maintenance scripts" docs

    # Fetch and checkout
    echo "Fetching repository..."
    git fetch origin main
    echo "Checking out main branch..."
    git checkout main
fi

echo ""
echo "========================================="
echo "SUCCESS: Deployment complete!"
echo "Site deployed to: $TARGET_DIR"
echo ""
echo "Directory structure:"
echo "  $TARGET_DIR/"
echo "  ├── .git/              (git repository - sparse checkout)"
echo "  ├── public_html/       (application code)"
echo "  │   ├── theme/         (themes)"
echo "  │   └── plugins/       (plugins)"
echo "  ├── maintenance scripts/"
echo "  ├── docs/"
echo "  └── [preserved dirs]/  (config, cache, logs, etc. - not tracked)"
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
