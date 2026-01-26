# Theme and Plugin Distribution Refactor

## Specification Version
1.2.0

## Status
Draft

## Overview

Refactor the theme and plugin distribution system to separate core updates from theme/plugin updates.

---

## Complete Pathway Analysis

### All Installation/Update Scenarios

| # | Scenario | Current Behavior | New Behavior |
|---|----------|------------------|--------------|
| 1 | Fresh Docker Install | Download full tar.gz | Download core + system themes (auto) |
| 2 | Fresh Docker Install (selective) | N/A | Download core + specified themes via --themes flag |
| 3 | Fresh Bare-Metal Install | Download full tar.gz | Download core + system themes (auto) |
| 4 | Fresh Bare-Metal Install (selective) | N/A | Download core + specified themes via --themes flag |
| 5 | Upgrade Client Site | N/A | upgrade.php downloads core + individual themes |
| 6 | Upgrade Theme/Dev Server | deploy.sh pulls from git | **No change** |

### Theme/Plugin Selection Logic

```
install.sh site NAME PASS DOMAIN [PORT] [--themes="..."] [--plugins="..."]

If --themes specified:
  → Download only those themes

If --themes NOT specified:
  → Query server for system themes (is_system: true in theme.json)
  → Download all system themes automatically

If --plugins specified:
  → Download only those plugins

If --plugins NOT specified:
  → Query server for system plugins (is_system: true in plugin.json)
  → Download all system plugins automatically

Default active theme: falcon (unless --activate specified)
```

### Key Properties

- **is_system**: Theme/plugin is part of core install (auto-downloaded if no flag specified)
- **is_stock**: Theme/plugin is unmodified stock (updated during upgrades)

### Archive Structure

Theme archives contain just the theme directory:
```
falcon-2.0.0.tar.gz
└── falcon/
    ├── theme.json
    ├── views/
    └── ...
```

Extract with: `tar xz -C public_html/theme/` → creates `public_html/theme/falcon/`

---

## Detailed Pathway Flows

### PATH 1: Fresh Docker Install

```
User                          Distribution Server
  │                                   │
  │  curl .../publish_theme?core      │
  │ ─────────────────────────────────>│
  │                                   │
  │  joinery-core-2.3.tar.gz          │
  │ <─────────────────────────────────│
  │                                   │
  │  tar xz                           │
  │  ./install.sh docker              │
  │  ./install.sh site NAME PASS DOMAIN PORT [--themes="falcon"]
  │                                   │
  │  do_site_docker():                │
  │    - ARCHIVE_ROOT has NO themes   │
  │    - If --themes: download those  │
  │    - Else: query system themes ───>│ .../publish_theme?list=themes
  │      and download all system ones │<─ [{name:"falcon",is_system:true},...]
  │    - Download each theme ─────────>│ .../publish_theme?download=falcon
  │    - Extract to ARCHIVE_ROOT/     │<─ falcon-2.0.0.tar.gz
  │       public_html/theme/          │
  │    - Copy to BUILD_DIR            │
  │    - docker build                 │
  │    - Container starts             │
  └───────────────────────────────────┘
```

---

### PATH 2: Fresh Bare-Metal Install

```
User                          Distribution Server
  │                                   │
  │  curl .../publish_theme?core      │
  │ ─────────────────────────────────>│
  │                                   │
  │  joinery-core-2.3.tar.gz          │
  │ <─────────────────────────────────│
  │                                   │
  │  tar xz                           │
  │  ./install.sh server              │
  │  ./install.sh site NAME PASS DOMAIN [--themes="falcon"]
  │                                   │
  │  do_site_baremetal():             │
  │    - ARCHIVE_ROOT has NO themes   │
  │    - If --themes: download those  │
  │    - Else: download system themes │
  │    - deploy_application_code()    │
  │    - _site_init.sh                │
  └───────────────────────────────────┘
```

Same flow as Docker - themes downloaded before deployment.

---

### PATH 3: Upgrade Client Site

```
Client Site                   Distribution Server
  │                                   │
  │  /utils/upgrade                   │
  │                                   │
  │  upgrade.php:                     │
  │    - Get upgrade info ────────────>│ .../upgrade?serve-upgrade=1
  │                                   │<─ {version, core_location, ...}
  │                                   │
  │    - Download core ───────────────>│ .../publish_theme?core
  │                                   │<─ joinery-core-2.3.tar.gz
  │                                   │
  │    - For each installed theme     │
  │      with is_stock=true:          │
  │      - Download theme ────────────>│ .../publish_theme?download=falcon
  │                                   │<─ falcon-2.0.0.tar.gz
  │                                   │
  │    - For each installed plugin    │
  │      with is_stock=true:          │
  │      - Download plugin ───────────>│ .../publish_theme?download=bookings&type=plugin
  │                                   │<─ bookings-1.0.0.tar.gz
  │                                   │
  │    - Extract all to staging       │
  │    - Validate                     │
  │    - Move to live                 │
  │    - Run migrations               │
  └───────────────────────────────────┘
```

**Changes needed in upgrade.php:**
- Modify download logic to fetch core + themes + plugins separately
- Keep existing backup/rollback/validation logic

---

### PATH 4: Upgrade Theme/Dev Server (UNCHANGED)

```
Theme/Dev Server              Git Repository
  │                                   │
  │  ./deploy.sh SITENAME             │
  │                                   │
  │  deploy.sh:                       │
  │    - git sparse checkout ─────────>│
  │    - Update stock themes          │<─ Latest code
  │    - Preserve custom themes       │
  │    - Validate                     │
  │    - Move to live                 │
  │    - Run migrations               │
  └───────────────────────────────────┘
```

**No changes needed** - deploy.sh continues using git.

---

## Edge Cases and Considerations

### 1. Core Archive Structure

The core archive MUST include empty theme/ and plugins/ directories:

```
joinery-core-X.Y.tar.gz
├── public_html/
│   ├── includes/
│   ├── data/
│   ├── views/
│   ├── theme/          ← Empty directory (created by tar)
│   └── plugins/        ← Empty directory (created by tar)
├── config/
└── maintenance_scripts/
    └── install_tools/
        └── install.sh
```

**publish_upgrade.php change:** When creating core archive, include empty theme/ and plugins/ directories.

### 2. UPGRADE_SERVER Variable

install.sh needs to know where to fetch themes from:

```bash
# Default value (can be overridden)
UPGRADE_SERVER="${UPGRADE_SERVER:-https://joinerytest.site}"

# Or via flag
./install.sh site NAME PASS DOMAIN --themes="falcon" --upgrade-server="https://custom.server"
```

### 3. Theme Download Failure Handling

```bash
# In install.sh
download_theme() {
    local theme_name="$1"
    local target_dir="$2"

    print_info "Downloading theme: $theme_name"

    if ! curl -sL "${UPGRADE_SERVER}/utils/publish_theme?download=${theme_name}" | tar xz -C "$target_dir" 2>/dev/null; then
        print_error "Failed to download theme: $theme_name"
        return 1
    fi

    # Verify theme was extracted
    if [ ! -d "$target_dir/$theme_name" ]; then
        print_error "Theme directory not found after extraction: $theme_name"
        return 1
    fi

    print_success "Theme downloaded: $theme_name"
    return 0
}

# Usage with error handling
if [[ -n "$THEMES" ]]; then
    IFS=',' read -ra THEME_ARRAY <<< "$THEMES"
    for theme in "${THEME_ARRAY[@]}"; do
        theme=$(echo "$theme" | xargs)  # Trim whitespace
        if ! download_theme "$theme" "$ARCHIVE_ROOT/public_html/theme"; then
            print_error "Installation aborted due to theme download failure"
            exit 1
        fi
    done
fi
```

### 4. Active Theme Validation

Current upgrade.php checks if active theme is in the archive. With core-only:

```php
// BEFORE (in validation):
// Check that active theme is in staging
$staged_theme_path = $stage_directory . '/theme/' . $active_theme;
if (!is_dir($staged_theme_path)) {
    // Error: theme not in archive
}

// AFTER:
// For new approach, theme is downloaded separately, so check after download
// OR skip this check when using individual theme downloads
```

### 5. serve-upgrade Endpoint Changes

New `?serve-upgrade=1` response:
```json
{
  "system_version": "2.3",
  "core_location": "https://.../static_files/joinery-core-2.3.tar.gz",
  "theme_endpoint": "https://.../utils/publish_theme",
  "release_notes": "..."
}
```

Full bundle fields (`upgrade_name`, `upgrade_location`) are removed - no longer needed.

---

## Summary of All Changes

### Distribution Server

| File | Change | Complexity |
|------|--------|------------|
| `publish_upgrade.php` | Add core + individual archive generation | Medium |
| `publish_theme.php` | NEW - list/download endpoint | Low |
| `upgrade.php` (serve mode) | Add core_location to JSON response | Low |

### Client Sites

| File | Change | Complexity |
|------|--------|------------|
| `upgrade.php` | Download core + themes separately | Medium |
| `install.sh` | Add --themes/--plugins flags, download logic | Medium |

### No Changes

| File | Reason |
|------|--------|
| `deploy.sh` | Only used on theme/dev server, pulls from git |
| `_site_init.sh` | Receives themes already in place |
| `Dockerfile.template` | Receives themes already in build context |

---

## File Change Details

### 1. publish_upgrade.php (MODIFY)

Add archive generation functions:

```php
// Create core-only archive
if (isset($_REQUEST['create_core']) && $_REQUEST['create_core']) {
    create_core_archive($version_major, $version_minor, $file_output_folder);
}

// Create individual theme archives
if (isset($_REQUEST['create_themes']) && $_REQUEST['create_themes']) {
    foreach ($selected_themes as $theme_name) {
        create_theme_archive($theme_name, $theme_output_folder);
    }
}

// Create individual plugin archives
if (isset($_REQUEST['create_plugins']) && $_REQUEST['create_plugins']) {
    foreach ($available_plugins as $plugin_name => $plugin) {
        if ($plugin['is_stock']) {
            create_plugin_archive($plugin_name, $plugin_output_folder);
        }
    }
}
```

### 2. publish_theme.php (NEW)

~80 lines - handles ?list=themes, ?list=plugins, ?download=name, ?core

### 3. upgrade.php (MODIFY - serve mode)

Replace serve-upgrade response:

```php
$response['core_location'] = LibraryFunctions::get_absolute_url('/static_files/joinery-core-' .
    $upgrade->get('upg_major_version') . '.' . $upgrade->get('upg_minor_version') . '.tar.gz');
$response['theme_endpoint'] = LibraryFunctions::get_absolute_url('/utils/publish_theme');
// Remove: upgrade_name, upgrade_location (no more full bundle)
```

### 4. upgrade.php (MODIFY - client mode)

Replace single bundle download with:

```php
// Download core
download_and_extract_core($decode_response['core_location'], $stage_location);

// Download each installed stock theme
foreach (get_installed_stock_themes() as $theme_name) {
    $theme_url = $decode_response['theme_endpoint'] . '?download=' . urlencode($theme_name);
    download_and_extract($theme_url, $stage_location . '/theme/');
}

// Download each installed stock plugin
foreach (get_installed_stock_plugins() as $plugin_name) {
    $plugin_url = $decode_response['theme_endpoint'] . '?download=' . urlencode($plugin_name) . '&type=plugin';
    download_and_extract($plugin_url, $stage_location . '/plugins/');
}
```

### 5. install.sh (MODIFY)

Add to do_site_create() argument parsing:

```bash
--themes=*)
    THEMES="${1#*=}"
    shift
    ;;
--plugins=*)
    PLUGINS="${1#*=}"
    shift
    ;;
--upgrade-server=*)
    UPGRADE_SERVER="${1#*=}"
    shift
    ;;
```

Add before copying to BUILD_DIR (docker) or deploy_application_code (bare-metal):

```bash
UPGRADE_SERVER="${UPGRADE_SERVER:-https://joinerytest.site}"

download_themes() {
    local target_dir="$1"

    if [[ -n "$THEMES" ]]; then
        mkdir -p "$target_dir"
        IFS=',' read -ra THEME_ARRAY <<< "$THEMES"
        for theme in "${THEME_ARRAY[@]}"; do
            theme=$(echo "$theme" | xargs)
            print_info "Downloading theme: $theme"
            if ! curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${theme}" | tar xz -C "$target_dir"; then
                print_error "Failed to download theme: $theme"
                exit 1
            fi
            print_success "Downloaded: $theme"
        done
    fi
}

download_plugins() {
    local target_dir="$1"

    if [[ -n "$PLUGINS" ]]; then
        mkdir -p "$target_dir"
        IFS=',' read -ra PLUGIN_ARRAY <<< "$PLUGINS"
        for plugin in "${PLUGIN_ARRAY[@]}"; do
            plugin=$(echo "$plugin" | xargs)
            print_info "Downloading plugin: $plugin"
            if ! curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${plugin}&type=plugin" | tar xz -C "$target_dir"; then
                print_error "Failed to download plugin: $plugin"
                exit 1
            fi
            print_success "Downloaded: $plugin"
        done
    fi
}
```

In do_site_docker(), after ARCHIVE_ROOT verification:

```bash
# Download themes if --themes flag was used
download_themes "$ARCHIVE_ROOT/public_html/theme"
download_plugins "$ARCHIVE_ROOT/public_html/plugins"
```

In do_site_baremetal(), after ARCHIVE_ROOT verification:

```bash
# Download themes if --themes flag was used
download_themes "$ARCHIVE_ROOT/public_html/theme"
download_plugins "$ARCHIVE_ROOT/public_html/plugins"
```

---

## Testing Matrix

| Scenario | Test Command | Expected Result |
|----------|--------------|-----------------|
| Docker (system themes) | `./install.sh site test Pass123 localhost 8080` | Downloads core + system themes |
| Docker (selective) | `./install.sh site test Pass123 localhost 8080 --themes="falcon"` | Downloads core + falcon only |
| Bare-metal (system themes) | `./install.sh site test Pass123 localhost` | Downloads core + system themes |
| Bare-metal (selective) | `./install.sh site test Pass123 localhost --themes="falcon"` | Downloads core + falcon only |
| Upgrade client site | Visit /utils/upgrade | Downloads core + installed stock themes |
| Theme list | `curl .../publish_theme?list=themes` | Returns JSON with is_system flag; install.sh filters for auto-install |
| Theme download | `curl .../publish_theme?download=falcon` | Returns theme tar.gz |
| Plugin download | `curl .../publish_theme?download=bookings&type=plugin` | Returns plugin tar.gz |

---

## Implementation Plan

All changes implemented together, then tested as a complete flow.

### Files to Create/Modify

1. **publish_theme.php** (NEW)
   - Create endpoint for ?list=themes, ?list=plugins, ?download=name, ?core

2. **publish_upgrade.php** (MODIFY)
   - Add create_core_archive() function
   - Add create_theme_archive() function
   - Add create_plugin_archive() function
   - Create /static_files/themes/ and /static_files/plugins/ directories if needed
   - Generate all archives on publish

3. **upgrade.php** (MODIFY - serve mode)
   - Update serve-upgrade response with core_location and theme_endpoint
   - Remove full bundle fields

4. **upgrade.php** (MODIFY - client mode)
   - Replace bundle download with core + individual theme/plugin downloads
   - Add get_installed_stock_themes() helper
   - Add get_installed_stock_plugins() helper

5. **install.sh** (MODIFY)
   - Add --themes, --plugins, --upgrade-server argument parsing
   - Add download_themes() function
   - Add download_plugins() function
   - Add get_system_themes() function (queries server if no --themes flag)
   - Call download functions in do_site_docker() and do_site_baremetal()

### Testing Checklist

After all changes are complete, test these paths:

| Test | Command/Action | Expected Result |
|------|----------------|-----------------|
| Publish | Run publish_upgrade.php | Creates core + theme + plugin archives in /static_files/ |
| Theme list endpoint | `curl .../publish_theme?list=themes` | Returns JSON of stock themes (includes is_system flag) |
| Theme download endpoint | `curl .../publish_theme?download=falcon` | Returns theme tar.gz |
| Core download endpoint | `curl .../publish_theme?core` | Redirects to core archive |
| Fresh Docker (default) | `./install.sh site test Pass localhost 8080` | Downloads core + system themes |
| Fresh Docker (selective) | `./install.sh site test Pass localhost 8080 --themes="falcon"` | Downloads core + falcon only |
| Fresh Bare-Metal (default) | `./install.sh site test Pass localhost` | Downloads core + system themes |
| Fresh Bare-Metal (selective) | `./install.sh site test Pass localhost --themes="falcon"` | Downloads core + falcon only |
| Upgrade client site | Visit /utils/upgrade | Downloads core + installed stock themes/plugins |

### Rollout

1. Deploy all changes to distribution server (joinerytest.site)
2. Run publish_upgrade.php to generate new archives
3. Test fresh install on new server
4. Test upgrade on existing client site
