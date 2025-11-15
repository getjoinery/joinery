#!/usr/bin/env bash
# Simple Local Development Deploy Script
# Deploys public_html + themes + plugins + maintenance scripts from consolidated repository

# Repository settings (single consolidated repository)
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
    echo "This script will deploy from the consolidated joinery repository:"
    echo "  1. public_html/ to target/public_html/"
    echo "  2. theme/ to target/public_html/theme/"
    echo "  3. plugins/ to target/public_html/plugins/"
    echo "  4. maintenance scripts/ to target/maintenance scripts/"
    echo ""
    echo "Note: Target directory must be empty or you will be prompted to clear it"
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

# Validate target directory
if [[ "$TARGET_DIR" == "" ]]; then
    echo "ERROR: Target directory cannot be empty."
    exit 1
fi

# Check for Windows-style paths and prevent them
if [[ "$TARGET_DIR" =~ ^[A-Za-z]: ]] || [[ "$TARGET_DIR" == *\\* ]]; then
    echo "ERROR: Windows-style path detected: $TARGET_DIR"
    echo ""
    echo "When running from WSL, please use WSL path format:"
    echo "  Instead of: C:\\Users\\username\\path"
    echo "  Use:        /mnt/c/Users/username/path"
    echo ""
    if [[ "$TARGET_DIR" =~ ^[A-Za-z]: ]]; then
        # Try to suggest WSL equivalent
        wsl_path=$(echo "$TARGET_DIR" | sed 's|^\\([A-Za-z]\\):|/mnt/\\L\\1|' | sed 's|\\\\|/|g')
        echo "Suggested WSL path: $wsl_path"
        echo ""
    fi
    echo "Please rerun with a proper WSL path."
    exit 1
fi

# Check if target directory exists and validate it
if [[ -d "$TARGET_DIR" ]]; then
    # Directory exists, check if it's empty
    if [[ "$(ls -A "$TARGET_DIR" 2>/dev/null)" ]]; then
        echo "WARNING: Target directory '$TARGET_DIR' exists and is not empty."
        echo ""
        echo "Contents:"
        ls -la "$TARGET_DIR" | head -10
        if [[ $(ls -la "$TARGET_DIR" | wc -l) -gt 11 ]]; then
            echo "... (and more files)"
        fi
        echo ""
        read -p "Clear this directory and proceed with deployment? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Deploy cancelled."
            exit 0
        fi
        echo "Clearing target directory (preserving config, cache, logs, backups, static_files)..."
        # Preserve .claude symlink during cleanup
        if [[ -L "$TARGET_DIR/.claude" ]]; then
            echo "Preserving .claude symlink..."
            CLAUDE_LINK_TARGET=$(readlink "$TARGET_DIR/.claude")
        fi

        # Only remove public_html and maintenance scripts (what we're deploying)
        # Preserve: config, cache, logs, backups, static_files, docs
        rm -rf "$TARGET_DIR/public_html"
        rm -rf "$TARGET_DIR/maintenance scripts"

        # Remove .claude if it exists (we'll restore it)
        rm -f "$TARGET_DIR/.claude"

        # Restore .claude symlink if it existed
        if [[ -n "$CLAUDE_LINK_TARGET" ]]; then
            echo "Restoring .claude symlink to $CLAUDE_LINK_TARGET..."
            ln -sf "$CLAUDE_LINK_TARGET" "$TARGET_DIR/.claude"
        fi
        echo "Directory cleared (config, cache, logs, backups, static_files preserved)."
    else
        echo "Using existing empty directory: $TARGET_DIR"
    fi
else
    # Directory doesn't exist, we'll create it
    echo "Target directory doesn't exist, will create: $TARGET_DIR"
fi

echo "========================================="
echo "LOCAL DEVELOPMENT DEPLOY"
echo "Target directory: $TARGET_DIR"
echo "Repository: getjoinery/joinery (consolidated)"
echo "========================================="
echo "This will deploy:"
echo "1. public_html/ to: $TARGET_DIR/public_html/"
echo "2. theme/ to: $TARGET_DIR/public_html/theme/"
echo "3. plugins/ to: $TARGET_DIR/public_html/plugins/"
echo "4. maintenance scripts/ to: $TARGET_DIR/maintenance scripts/"
echo "========================================="
read -p "Continue with deployment? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Deploy cancelled."
    exit 0
fi

# Create target directory if it doesn't exist
mkdir -p "$TARGET_DIR"

# Create temporary staging directory
TEMP_DIR=$(mktemp -d)
trap "rm -rf $TEMP_DIR" EXIT

echo "Deploying public_html..."

# Clone main repository
echo "Cloning main repository..."
git clone --no-checkout "$REPO_URL" "$TEMP_DIR/main_repo"
cd "$TEMP_DIR/main_repo"

# Sparse checkout: only extract public_html/ directory
echo "Extracting public_html directory..."
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set public_html
git checkout main

# Copy public_html directory to target
echo "Copying public_html directory..."
rm -rf "$TARGET_DIR/public_html"
cp -r public_html "$TARGET_DIR/"
echo "public_html deployment complete."

echo "Deploying themes..."

# Clone repository and extract theme from root
echo "Cloning repository for themes..."
git clone --no-checkout "$REPO_URL" "$TEMP_DIR/theme_repo"
cd "$TEMP_DIR/theme_repo"
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set theme
git checkout main

# Deploy themes to public_html/theme
if [[ -d "theme" ]]; then
    echo "Copying themes to public_html/theme..."
    rm -rf "$TARGET_DIR/public_html/theme"
    cp -r "theme" "$TARGET_DIR/public_html/"
    echo "Theme deployment complete."
else
    echo "WARNING: No theme directory found in repository."
fi

echo "Deploying plugins..."

# Clone repository and extract plugins from root
echo "Cloning repository for plugins..."
git clone --no-checkout "$REPO_URL" "$TEMP_DIR/plugin_repo"
cd "$TEMP_DIR/plugin_repo"
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set plugins
git checkout main

# Deploy plugins to public_html/plugins
if [[ -d "plugins" ]]; then
    echo "Copying plugins to public_html/plugins..."
    rm -rf "$TARGET_DIR/public_html/plugins"
    cp -r "plugins" "$TARGET_DIR/public_html/"
    echo "Plugin deployment complete."
else
    echo "WARNING: No plugins directory found in repository."
fi

echo "Deploying maintenance scripts..."

# Clone repository and extract maintenance scripts from root
echo "Cloning repository for maintenance scripts..."
git clone --no-checkout "$REPO_URL" "$TEMP_DIR/maintenance_repo"
cd "$TEMP_DIR/maintenance_repo"
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set "maintenance scripts"
git checkout main

# Deploy maintenance scripts to target root
if [[ -d "maintenance scripts" ]]; then
    echo "Copying maintenance scripts..."
    rm -rf "$TARGET_DIR/maintenance scripts"
    cp -r "maintenance scripts" "$TARGET_DIR/"
    echo "Maintenance scripts deployment complete."
else
    echo "WARNING: No maintenance scripts directory found in repository."
fi

echo "========================================="
echo "SUCCESS: Local development deploy complete!"
echo "Site deployed to: $TARGET_DIR"
echo ""
echo "Directory structure:"
echo "  $TARGET_DIR/"
echo "  ├── public_html/"
echo "  │   ├── adm/, ajax/, data/, includes/, etc."
echo "  │   ├── theme/ (from repository root)"
echo "  │   └── plugins/ (from repository root)"
echo "  └── maintenance scripts/"
echo ""
echo "All files deployed from consolidated getjoinery/joinery repository."
echo "Make changes and commit to the repository to update."
echo "========================================"