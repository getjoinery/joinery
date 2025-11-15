# Move Theme and Plugins to public_html/ in Repository

## Problem Statement

Currently, the GitHub repository (getjoinery/joinery) has theme/ and plugins/ at the root level:

```
getjoinery/joinery/
├── public_html/
├── theme/          # At root
├── plugins/        # At root
└── maintenance scripts/
```

However, for the application to work, Apache serves from `public_html/` and the application expects theme/ and plugins/ to be inside public_html/:

```
/var/www/html/joinerytest/
├── public_html/
│   ├── theme/      # Must be here
│   └── plugins/    # Must be here
```

This mismatch causes:
1. Deployment scripts must move theme/ and plugins/ after checkout
2. Git status shows all theme/plugin files as "deleted" after deployment
3. Confusing structure - repository layout doesn't match deployment reality

## Solution

Move theme/ and plugins/ to public_html/ in the actual GitHub repository structure.

## Benefits

1. **Simplicity**: Repository structure matches deployment structure
2. **Clean git status**: No file movement needed, no "deleted" files shown
3. **Easier development**: Developers see the same structure locally as in deployment
4. **Simpler deploy scripts**: No post-checkout file moving required
5. **Consistency**: What you see in GitHub is what you get in deployment

## Implementation Steps

### 1. Move Directories in Git

Use `git mv` to preserve history:

```bash
cd /var/www/html/joinerytest

# First, remove the moved directories from sparse checkout and restore full repo temporarily
git sparse-checkout disable

# Move theme to public_html
git mv theme public_html/theme

# Move plugins to public_html
git mv plugins public_html/plugins

# Commit the move
git commit -m "Move theme and plugins to public_html

Restructure repository to match deployment layout.
Theme and plugins now live inside public_html/ where the application expects them.

This eliminates the need for post-checkout file moving in deploy scripts."
```

### 2. Disable Sparse Checkout

Since this is the working deployment directory, use full checkout:

```bash
# Disable sparse checkout - we want everything
git sparse-checkout disable
```

### 3. Push Changes

```bash
git push origin main
```

### 4. Update deploy_working_directory.sh

**File**: `maintenance scripts/deploy_working_directory.sh`

**Changes needed**:

#### Current code (lines 130-134, 162-164, 194-196):
```bash
# Configure sparse checkout
echo "Configuring sparse checkout..."
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set public_html theme plugins "maintenance scripts" docs

# ...

# Move theme and plugins to proper location
echo "Setting up theme and plugins..."
if [[ -d theme ]]; then
    mv theme public_html/
fi
if [[ -d plugins ]]; then
    mv plugins public_html/
fi
```

#### New code:
```bash
# Configure sparse checkout (or just use full checkout)
echo "Configuring sparse checkout..."
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set public_html "maintenance scripts" docs

# No need to move theme/plugins - they're already in public_html/ in repository
```

**Lines to modify**: 134, 144-149, 164, 174-179, 196, 206-211

**Essentially**:
- Remove `theme` and `plugins` from sparse-checkout set commands (3 locations)
- Remove all the "Move theme and plugins to proper location" blocks (3 locations)

### 5. Update deploy.sh

**File**: `maintenance scripts/deploy.sh`

**Changes needed**:

#### Current approach:
The production deploy.sh uses sparse checkout to extract directories separately, then copies them. It currently:
1. Sparse checkout public_html/
2. Separately sparse checkout theme/ and plugins/
3. Copies theme/ and plugins/ to public_html/

#### New approach:
Since theme/ and plugins/ are now inside public_html/ in the repository:
1. Sparse checkout public_html/ (which includes theme/ and plugins/)
2. No separate theme/plugin checkout needed
3. No copying needed

#### Specific changes:

**Around lines 916-945** - Main repository checkout:
```bash
# OLD:
# SPARSE CHECKOUT: Only extract public_html/ directory
verbose_echo "Configuring sparse checkout for public_html directory"
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set public_html

# NEW: Same (no change needed - public_html/ now contains theme/plugins)
# SPARSE CHECKOUT: Only extract public_html/ directory
verbose_echo "Configuring sparse checkout for public_html directory"
git config core.sparseCheckout true
git sparse-checkout init --cone
git sparse-checkout set public_html
```

**Around lines 980-1070** - Theme deployment section:
```bash
# OLD: Separate sparse checkout for themes
verbose_echo "Deploying themes from repository root to public_html/theme/"
# ... complex sparse checkout and copy logic ...

# NEW: Remove this entire section - themes are already in public_html/
# No theme deployment section needed
```

**Around lines 1072-1160** - Plugin deployment section:
```bash
# OLD: Separate sparse checkout for plugins
verbose_echo "Deploying plugins from repository root to public_html/plugins/"
# ... complex sparse checkout and copy logic ...

# NEW: Remove this entire section - plugins are already in public_html/
# No plugin deployment section needed
```

**Summary of deploy.sh changes**:
1. Keep public_html sparse checkout (lines ~916-945) - no changes needed
2. Delete entire theme deployment section (lines ~980-1070)
3. Delete entire plugin deployment section (lines ~1072-1160)
4. Update version number to 3.7
5. Update changelog at top of file

### 6. Verify Changes

After all changes are complete:

```bash
cd /var/www/html/joinerytest

# Check git status - should be clean or only show modified deploy scripts
git status

# Verify structure
ls -la public_html/ | grep -E "theme|plugins"

# Should see:
# drwxrwxr-x  5 user1 user1  4096 date theme
# drwxrwxr-x 12 user1 user1  4096 date plugins

# Verify no theme/plugins at root
ls -la | grep -E "theme|plugins"
# Should return nothing
```

### 7. Update Documentation

Update relevant documentation:
- CLAUDE.md - Update directory structure diagrams
- Any deployment documentation
- README files

## Files Modified

1. **GitHub Repository Structure**:
   - `theme/` → `public_html/theme/`
   - `plugins/` → `public_html/plugins/`

2. **maintenance scripts/deploy_working_directory.sh**:
   - Remove theme/plugins from sparse-checkout set (3 locations)
   - Remove "Move theme and plugins" blocks (3 locations)

3. **maintenance scripts/deploy.sh**:
   - Keep public_html sparse checkout section
   - Remove theme deployment section (~90 lines)
   - Remove plugin deployment section (~90 lines)
   - Update version to 3.7

4. **.gitignore** (if needed):
   - Remove theme/plugins exclusions

5. **Documentation**:
   - Update CLAUDE.md directory structure
   - Update any deployment guides

## Expected Final Structure

**GitHub Repository (getjoinery/joinery):**
```
getjoinery/joinery/
├── public_html/
│   ├── adm/
│   ├── ajax/
│   ├── data/
│   ├── includes/
│   ├── theme/          # ← Moved here
│   │   ├── falcon/
│   │   ├── galactictribune/
│   │   └── tailwind/
│   ├── plugins/        # ← Moved here
│   │   ├── bookings/
│   │   ├── controld/
│   │   └── ...
│   └── serve.php
├── maintenance scripts/
│   ├── deploy.sh
│   └── deploy_working_directory.sh
└── docs/
```

**Deployed Site (/var/www/html/joinerytest):**
```
/var/www/html/joinerytest/
├── .git/
├── public_html/
│   ├── theme/          # ← Same location as repository
│   ├── plugins/        # ← Same location as repository
│   └── ... (all other files)
├── maintenance scripts/
├── docs/
├── config/             # Not in git
├── cache/              # Not in git
├── logs/               # Not in git
├── backups/            # Not in git
├── static_files/       # Not in git
└── uploads/            # Not in git
```

**Perfect alignment** - what you see in GitHub is what you get in deployment!

## Success Criteria

1. ✅ `git status` shows clean working directory (no deleted files)
2. ✅ `ls public_html/` shows theme/ and plugins/ directories
3. ✅ `ls /` shows NO theme/ or plugins/ directories at root
4. ✅ Website loads correctly with themes and plugins working
5. ✅ Fresh deployment via deploy_working_directory.sh works without errors
6. ✅ Production deployment via deploy.sh works without errors
7. ✅ `git pull` updates theme and plugin files correctly

## Timeline

Estimated time: 1-2 hours for complete implementation and testing

## Risk Assessment

**Risk Level**: Low

**Why Low Risk**:
- `git mv` preserves complete file history
- Repository structure will match deployment reality
- Application expects files in public_html/ - that's where we're putting them
- Can test deploy scripts immediately after changes
- No runtime application changes needed
