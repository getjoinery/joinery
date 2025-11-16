#!/bin/bash
# setup-dev.sh - Works as both setup and reset script

WEB_DIR="/var/www/html/joinerytest/public_html"
THEME_REPO_DIR="/home/user1/joinery/joinery"
CLAUDE_DIR="/home/user1/joinery/joinery-claude"

echo "=== Joinery Development Environment Setup/Reset ==="

# Determine if this is initial setup or reset
if [ -d "$WEB_DIR/.git" ]; then
    echo "Found git repository in web directory - running RESET mode"
    MODE="reset"
else
    echo "No git repository found - running SETUP mode (converting deployment to dev)"
    MODE="setup"
fi

# Create project Claude directory
mkdir -p "$CLAUDE_DIR"

# Initialize with basic Claude configuration if empty
if [ ! -f "$CLAUDE_DIR/.gitignore" ]; then
    echo "Initializing Claude project directory..."
    echo "# Claude project settings" > "$CLAUDE_DIR/.gitignore"
    echo "*.log" >> "$CLAUDE_DIR/.gitignore"
    echo "  Created basic .gitignore"
fi

# Handle main repository
if [ "$MODE" = "setup" ]; then
    echo "Converting deployed site to git repository..."
    # Backup the existing deployed files
    sudo cp -r "$WEB_DIR" "${WEB_DIR}.backup"
    echo "  Created backup at ${WEB_DIR}.backup"
    
    # Remove the deployed directory
    sudo rm -rf "$WEB_DIR"
    
    # Clone fresh from repository
    git clone https://jeremytunnell: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@github.com/Tunnell-Software/membership.git "$WEB_DIR"
    echo "  Cloned fresh repository"
else
    echo "Updating main repository..."
    cd "$WEB_DIR"
    git fetch origin
    git reset --hard origin/main  # Reset to latest main branch
    git clean -fd  # Remove untracked files
    cd - > /dev/null
fi

# Handle theme/plugin repository
if [ ! -d "$THEME_REPO_DIR" ]; then
    echo "Cloning theme/plugin repository..."
    mkdir -p /home/user1/joinery
    git clone https://getjoinery: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@github.com/getjoinery/joinery.git "$THEME_REPO_DIR"
else
    echo "Updating theme/plugin repository..."
    cd "$THEME_REPO_DIR"
    git fetch origin
    git reset --hard origin/main  # Reset to latest main branch
    git clean -fd  # Remove untracked files
    cd - > /dev/null
fi

# Handle symlinks in web directory
cd "$WEB_DIR"

echo "Creating symlinks..."
# Remove any existing theme/plugins (whether directories or symlinks)
sudo rm -rf theme plugins .claude

# Create fresh symlinks
sudo ln -s "$THEME_REPO_DIR/theme" theme
sudo ln -s "$THEME_REPO_DIR/plugins" plugins  
sudo ln -s "$CLAUDE_DIR" .claude

echo "  Created theme -> $THEME_REPO_DIR/theme"
echo "  Created plugins -> $THEME_REPO_DIR/plugins"  
echo "  Created .claude -> $CLAUDE_DIR"

# Set proper permissions
echo "Setting permissions..."
sudo chown -R www-data:www-data "$WEB_DIR"
sudo chmod -R 755 "$WEB_DIR"

echo ""
echo "✓ Development environment ready!"
echo "✓ Mode: $MODE"
echo "✓ Work in: $WEB_DIR"
echo "✓ Main repo: Direct git commands from web directory"
echo "✓ Theme/plugins: Git commands from $THEME_REPO_DIR"
echo ""
echo "Next steps:"
echo "1. cd $WEB_DIR"
echo "2. claude"