#!/usr/bin/env bash
# Simple Local Development Deploy Script
# Deploys main site code + themes + plugins to an empty directory

# Repository settings
GITHUB_USER="jeremytunnell"
GITHUB_TOKEN="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"
REPO_URL="https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/Tunnell-Software/membership.git"

# Theme/Plugin repository settings
THEME_PLUGIN_USER="getjoinery"
THEME_PLUGIN_TOKEN="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"
THEME_PLUGIN_REPO_URL="https://${THEME_PLUGIN_USER}:${THEME_PLUGIN_TOKEN}@github.com/getjoinery/joinery.git"

# Function to show usage
show_usage() {
    echo "Simple Local Development Deploy Script"
    echo ""
    echo "Usage:"
    echo "  $0 [target_directory]"
    echo "  $0 [target_directory] [themes_directory]"
    echo ""
    echo "Examples:"
    echo "  $0 /home/user/dev/mysite"
    echo "  $0 \"/mnt/c/Users/user/Projects/testsite\""
    echo "  $0 /home/user/dev/mysite /home/user/dev/theme-repo"
    echo ""
    echo "This script will:"
    echo "  1. Deploy main site code to target directory"
    echo "  2. Deploy themes to target_directory/theme (or symlink if themes_directory provided)"
    echo "  3. Deploy plugins to target_directory/plugins"
    echo ""
    echo "Options:"
    echo "  themes_directory: Optional. If provided, target_directory/theme will be a symlink"
    echo "                   to this directory instead of copying themes from repository."
    echo ""
    echo "Note: Target directory must be empty or non-existent"
}

# Check arguments
if [[ $# -lt 1 ]] || [[ $# -gt 2 ]] || [[ "$1" == "--help" ]] || [[ "$1" == "-h" ]]; then
    show_usage
    exit 1
fi

TARGET_DIR="$1"
THEMES_DIR="$2"  # Optional themes directory

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

# Validate themes directory if provided
if [[ -n "$THEMES_DIR" ]]; then
    # Check for Windows-style paths in themes directory
    if [[ "$THEMES_DIR" =~ ^[A-Za-z]: ]] || [[ "$THEMES_DIR" == *\\* ]]; then
        echo "ERROR: Windows-style path detected for themes directory: $THEMES_DIR"
        echo "Please use WSL path format for themes directory as well."
        exit 1
    fi
    
    # Check if themes directory exists
    if [[ ! -d "$THEMES_DIR" ]]; then
        echo "ERROR: Themes directory '$THEMES_DIR' does not exist."
        exit 1
    fi
    
    echo "Themes directory provided: $THEMES_DIR"
    echo "Will create symlink instead of copying themes from repository."
fi

# Check if target directory exists and validate it
if [[ -d "$TARGET_DIR" ]]; then
    # Directory exists, check if it's empty
    if [[ "$(ls -A "$TARGET_DIR" 2>/dev/null)" ]]; then
        echo "ERROR: Target directory '$TARGET_DIR' exists and is not empty."
        echo "Please use an empty directory or a non-existent directory."
        exit 1
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
if [[ -n "$THEMES_DIR" ]]; then
    echo "Themes directory: $THEMES_DIR (will be symlinked)"
fi
echo "========================================="
echo "This will deploy:"
echo "1. Main site code to: $TARGET_DIR"
if [[ -n "$THEMES_DIR" ]]; then
    echo "2. Themes: symlink $TARGET_DIR/theme -> $THEMES_DIR"
else
    echo "2. Themes to: $TARGET_DIR/theme (copied from repository)"
fi
echo "3. Plugins to: $TARGET_DIR/plugins"
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

echo "Deploying main site code..."

# Clone main repository
echo "Cloning main repository..."
git clone --depth 1 "$REPO_URL" "$TEMP_DIR/main_repo"

# Copy everything from the main repository root to target directory (INCLUDING .git)
echo "Copying main site files..."
# Copy all files and directories from repo root, INCLUDING .git for the main repository
find "$TEMP_DIR/main_repo" -maxdepth 1 -mindepth 1 -exec cp -r {} "$TARGET_DIR/" \;
echo "Main site deployment complete."

echo "Deploying themes..."

if [[ -n "$THEMES_DIR" ]]; then
    # Create symlink to existing themes directory
    echo "Creating symlink to themes directory..."
    rm -rf "$TARGET_DIR/theme"
    ln -sf "$THEMES_DIR" "$TARGET_DIR/theme"
    echo "Symlink created: $TARGET_DIR/theme -> $THEMES_DIR"
    echo "Theme deployment complete (symlinked)."
else
    # Clone theme repository for themes
    echo "Cloning theme repository..."
    git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$TEMP_DIR/theme_repo"
    cd "$TEMP_DIR/theme_repo"
    git config core.sparseCheckout true
    git sparse-checkout init --cone
    git sparse-checkout set theme
    git checkout main

    # Deploy themes
    if [[ -d "theme" ]]; then
        echo "Copying themes..."
        rm -rf "$TARGET_DIR/theme"
        cp -r "theme" "$TARGET_DIR/"
        echo "Theme deployment complete (copied)."
    else
        echo "WARNING: No theme directory found in theme repository."
    fi
fi

echo "Deploying plugins..."

# Clone theme repository for plugins
echo "Cloning theme repository for plugins..."
git clone --no-checkout "$THEME_PLUGIN_REPO_URL" "$TEMP_DIR/plugin_repo"
cd "$TEMP_DIR/plugin_repo"
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set plugins
git checkout main

# Deploy plugins
if [[ -d "plugins" ]]; then
    echo "Copying plugins..."
    rm -rf "$TARGET_DIR/plugins"
    cp -r "plugins" "$TARGET_DIR/"
    echo "Plugin deployment complete."
else
    echo "WARNING: No plugins directory found in theme repository."
fi

echo "========================================="
echo "SUCCESS: Local development deploy complete!"
echo "Site deployed to: $TARGET_DIR"
echo ""
echo "Directory structure:"
echo "  $TARGET_DIR/"
echo "  ├── [main site files with git]"
if [[ -n "$THEMES_DIR" ]]; then
    echo "  ├── theme/ -> $THEMES_DIR (symlinked)"
else
    echo "  ├── theme/ (copied from repository)"
fi
echo "  └── plugins/ (copied from repository)"
echo ""
if [[ -n "$THEMES_DIR" ]]; then
    echo "Theme changes can be made in $THEMES_DIR and committed to theme repository."
fi
echo "Main site changes can be made in $TARGET_DIR and committed to main repository."
echo "========================================"