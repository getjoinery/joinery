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

# CONFIGURE YOUR PATHS HERE
THEMES_SOURCE_DIR="/mnt/c/Users/jerem/Proton Drive/jeremy.tunnell/My files/joinery/joinery/theme"
PLUGINS_SOURCE_DIR="/mnt/c/Users/jerem/Proton Drive/jeremy.tunnell/My files/joinery/joinery/plugins"

# Default behavior settings
USE_SYMLINKS=true  # Default to symlinks, use --nosymlink to copy instead

# Function to show usage
show_usage() {
    echo "Simple Local Development Deploy Script"
    echo ""
    echo "Usage:"
    echo "  $0 [options] [target_directory]"
    echo ""
    echo "Options:"
    echo "  --nosymlink    Copy themes/plugins from repositories instead of symlinking"
    echo "  --help, -h     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 /home/user/dev/mysite                    # Deploy with symlinks (default)"
    echo "  $0 --nosymlink /home/user/dev/mysite        # Deploy with copies from repositories"
    echo "  $0 \"/mnt/c/Users/user/Projects/testsite\"    # Deploy to Windows filesystem"
    echo ""
    echo "This script will:"
    echo "  1. Deploy main site code to target directory"
    echo "  2. Deploy themes (symlink by default, copy with --nosymlink)"
    echo "  3. Deploy plugins (symlink by default, copy with --nosymlink)"
    echo ""
    echo "Configured paths:"
    echo "  Themes source:  $THEMES_SOURCE_DIR"
    echo "  Plugins source: $PLUGINS_SOURCE_DIR"
    echo ""
    echo "Note: Target directory must be empty or non-existent"
    echo "Note: Update THEMES_SOURCE_DIR and PLUGINS_SOURCE_DIR variables at top of script"
}

# Parse command line arguments
TARGET_DIR=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --nosymlink)
            USE_SYMLINKS=false
            shift
            ;;
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

# Validate source directories if using symlinks
if [[ "$USE_SYMLINKS" == true ]]; then
    if [[ ! -d "$THEMES_SOURCE_DIR" ]]; then
        echo "ERROR: Themes source directory '$THEMES_SOURCE_DIR' does not exist."
        echo "Please update THEMES_SOURCE_DIR variable at top of script or use --nosymlink option."
        exit 1
    fi
    
    if [[ ! -d "$PLUGINS_SOURCE_DIR" ]]; then
        echo "ERROR: Plugins source directory '$PLUGINS_SOURCE_DIR' does not exist."
        echo "Please update PLUGINS_SOURCE_DIR variable at top of script or use --nosymlink option."
        exit 1
    fi
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
        echo "Clearing target directory..."
        rm -rf "$TARGET_DIR"/*
        rm -rf "$TARGET_DIR"/.*
        echo "Directory cleared."
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
if [[ "$USE_SYMLINKS" == true ]]; then
    echo "Mode: SYMLINK (default)"
    echo "Themes source: $THEMES_SOURCE_DIR"
    echo "Plugins source: $PLUGINS_SOURCE_DIR"
else
    echo "Mode: COPY (--nosymlink)"
fi
echo "========================================="
echo "This will deploy:"
echo "1. Main site code to: $TARGET_DIR"
if [[ "$USE_SYMLINKS" == true ]]; then
    echo "2. Themes: symlink $TARGET_DIR/theme -> $THEMES_SOURCE_DIR"
    echo "3. Plugins: symlink $TARGET_DIR/plugins -> $PLUGINS_SOURCE_DIR"
else
    echo "2. Themes to: $TARGET_DIR/theme (copied from repository)"
    echo "3. Plugins to: $TARGET_DIR/plugins (copied from repository)"
fi
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

if [[ "$USE_SYMLINKS" == true ]]; then
    # Create symlink to configured themes directory
    echo "Creating symlink to themes directory..."
    rm -rf "$TARGET_DIR/theme"
    ln -sf "$THEMES_SOURCE_DIR" "$TARGET_DIR/theme"
    echo "Symlink created: $TARGET_DIR/theme -> $THEMES_SOURCE_DIR"
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

if [[ "$USE_SYMLINKS" == true ]]; then
    # Create symlink to configured plugins directory
    echo "Creating symlink to plugins directory..."
    rm -rf "$TARGET_DIR/plugins"
    ln -sf "$PLUGINS_SOURCE_DIR" "$TARGET_DIR/plugins"
    echo "Symlink created: $TARGET_DIR/plugins -> $PLUGINS_SOURCE_DIR"
    echo "Plugin deployment complete (symlinked)."
else
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
        echo "Plugin deployment complete (copied)."
    else
        echo "WARNING: No plugins directory found in theme repository."
    fi
fi

echo "========================================="
echo "SUCCESS: Local development deploy complete!"
echo "Site deployed to: $TARGET_DIR"
echo ""
echo "Directory structure:"
echo "  $TARGET_DIR/"
echo "  ├── [main site files with git]"
if [[ "$USE_SYMLINKS" == true ]]; then
    echo "  ├── theme/ -> $THEMES_SOURCE_DIR (symlinked)"
    echo "  └── plugins/ -> $PLUGINS_SOURCE_DIR (symlinked)"
else
    echo "  ├── theme/ (copied from repository)"
    echo "  └── plugins/ (copied from repository)"
fi
echo ""
if [[ "$USE_SYMLINKS" == true ]]; then
    echo "Theme changes can be made in working directory and committed from $THEMES_SOURCE_DIR"
    echo "Plugin changes can be made in working directory and committed from $PLUGINS_SOURCE_DIR"
else
    echo "To commit theme/plugin changes, copy them back to their respective repositories."
fi
echo "Main site changes can be made in $TARGET_DIR and committed to main repository."
echo "========================================"