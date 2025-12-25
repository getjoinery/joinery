# Component Type Discovery

Component types are discovered automatically from theme files during theme sync.

## Current vs Proposed

| Aspect | Current | Proposed |
|--------|---------|----------|
| Definition format | PHP arrays in `/definitions/*.php` | JSON files `*.json` |
| Definition location | Separate `/definitions/` subdirectory | Same directory as template |
| Sync trigger | Manual CLI: `php utils/seed_component_types.php` | Automatic with theme sync |
| Theme integration | Partial | Full (part of ThemeManager) |

---

## File Convention

Themes define components by placing paired files in `views/components/`:

```
/theme/{theme_name}/views/components/
├── hero_static.php           # Template
├── hero_static.json          # Metadata
├── feature_grid.php
├── feature_grid.json
└── ...

/views/components/            # Base (fallback)
├── html_block.php
├── html_block.json
└── ...
```

### JSON Format

```json
{
  "title": "Static Hero",
  "description": "Hero section with heading and CTA",
  "category": "hero",
  "icon": "bx bx-image",
  "order": 10,
  "css_framework": "bootstrap",
  "logic_function": null,
  "requires_plugin": null,
  "config_schema": {
    "fields": [
      {"name": "heading", "label": "Heading", "type": "textinput"},
      {"name": "subheading", "label": "Subheading", "type": "textarea"}
    ]
  }
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `title` | Yes | Display name |
| `config_schema` | Yes | Field definitions |
| `description` | No | Admin description |
| `category` | No | Category key (default: "custom") |
| `icon` | No | CSS icon class |
| `order` | No | Sort order (default: 100) |
| `css_framework` | No | Required CSS framework ("bootstrap", "tailwind", or null for universal) |
| `logic_function` | No | Logic function name |
| `requires_plugin` | No | Required plugin |

### Component Compatibility Levels

| Level | Location | css_framework | When Active |
|-------|----------|---------------|-------------|
| Universal | `/views/components/` | null/missing | Always |
| Framework-specific | `/views/components/` | "bootstrap" | When theme uses Bootstrap |
| Theme-specific | `/theme/{name}/views/components/` | any | When that theme is active |

---

## Implementation

Add to `ThemeManager`:

```php
/**
 * Override sync to include component type discovery
 */
public function sync() {
    $result = parent::sync();
    $result['components'] = $this->syncComponentTypes();
    return $result;
}

/**
 * Sync component types from theme JSON files
 */
public function syncComponentTypes() {
    // 1. Scan base /views/components/ and active theme
    // 2. Create/update component types in com_components
    // 3. Deactivate types whose templates no longer exist
    // Returns: ['created' => n, 'updated' => n, 'unchanged' => n, 'deactivated' => n]
}

protected function scanComponentDirectory($directory, $discovered) {
    // Parse JSON files with matching PHP templates
    // Return merged $discovered array
}
```

Update `admin_themes_logic.php`:

```php
case 'activate':
    $theme = Theme::get_by_theme_name($post['theme_name']);
    $theme->activate();
    $theme_manager->syncComponentTypes();  // Re-sync for new theme
    break;
```

Delete `/utils/seed_component_types.php`.

---

## Behavior

**Sync triggers (all now include component sync):**
- Filesystem sync button
- Theme ZIP upload (via postInstall → sync)
- Deployment migration
- Theme activation (explicit call)

**Discovery:**
- Base `/views/components/` scanned first, then active theme (theme overrides base)
- Only `.json` files with matching `.php` templates are processed
- Invalid JSON or missing required fields logged and skipped

**Framework compatibility check:**
- Get active theme's `cssFramework` from theme.json metadata
- For each discovered component with `css_framework` set:
  - If theme framework matches component framework → include
  - If no match → skip (or deactivate if already in DB)
- Components without `css_framework` → always included (universal)

**Database sync:**
- New types created, existing types updated if changed
- Types whose templates no longer exist are deactivated
- Types whose framework doesn't match active theme are deactivated
- Returns: `['created' => n, 'updated' => n, 'unchanged' => n, 'deactivated' => n]`

**Rendering (already implemented):**
- `ComponentRenderer::render_component()` checks `$component_type->is_available()`
- Deactivated types return debug comment: "Component type 'x' is inactive"
- Missing templates return: "Template file not found: x"
- Component instances preserved for recovery if theme restored

---

## Code Changes

1. **components_class.php** - Add field:
   - `com_css_framework` - varchar(32), nullable

2. **ThemeManager.php** - Add 3 methods:
   - `sync()` - Override to call `parent::sync()` then `syncComponentTypes()`
   - `syncComponentTypes()` - Scan directories, sync to DB, check framework compatibility, deactivate orphans
   - `scanComponentDirectory()` - Parse JSON files with matching PHP templates

3. **admin_themes_logic.php** - Add 1 line:
   - In `case 'activate':` add `$theme_manager->syncComponentTypes();`

4. **Create JSON files** - For each component in `/views/components/definitions/`, create `.json` next to `.php`

5. **Delete**:
   - `/utils/seed_component_types.php`
   - `/views/components/definitions/` directory

---

## Documentation Changes

### /docs/component_system.md

Update "Creating a Component Type" section (around line 150):

**Current:** Describes using admin interface to create types
**Change to:** Describe creating JSON file:

```markdown
### Creating a Component Type

1. Create template file: `/views/components/{type_key}.php`
2. Create metadata file: `/views/components/{type_key}.json`
3. Run "Sync with Filesystem" from `/admin/admin_themes`

The JSON file defines the component:
```json
{
  "title": "My Component",
  "category": "content",
  "config_schema": { "fields": [...] }
}
```

For theme-specific components, place files in `/theme/{theme}/views/components/`.
```

### /docs/creating_components_from_themes.md

Update Step 5 "Register the Component Type" (line 137-148):

**Current:** Go to admin, click Add, fill in form, paste JSON
**Change to:**

```markdown
### Step 5: Create the Metadata File

Create `/views/components/hero_simple.json` (or in your theme's `views/components/`):

```json
{
  "title": "Simple Hero",
  "description": "Hero section with heading, subheading, and CTA",
  "category": "hero",
  "css_framework": "bootstrap",
  "config_schema": {
    "fields": [
      // paste fields from Step 3
    ]
  }
}
```

**css_framework options:**
- `"bootstrap"` - Only active when a Bootstrap theme is active
- `"tailwind"` - Only active when a Tailwind theme is active
- `null` or omit - Universal, always active regardless of theme

### Step 6: Sync Component Types

Go to `/admin/admin_themes` and click "Sync with Filesystem". Your component type will be registered automatically.

### Step 7: Create a Component Instance
(unchanged - still use admin to create instances)
```

### /docs/component_system.md

Add to Config Schema Reference section:

```markdown
### Component Compatibility

Components can specify CSS framework requirements:

| css_framework | Behavior |
|---------------|----------|
| `null` / omit | Universal - works with any theme |
| `"bootstrap"` | Only active when theme uses Bootstrap |
| `"tailwind"` | Only active when theme uses Tailwind |

Theme-specific components (in `/theme/{name}/views/components/`) are only available when that theme is active, regardless of `css_framework` setting.
```

---

## Migration

1. Add methods to `ThemeManager`
2. Update `admin_themes_logic.php`
3. Convert existing PHP definitions to JSON:
   - For each `/views/components/definitions/*.php`
   - Create `/views/components/{type_key}.json`
   - Copy fields from PHP array to JSON format
4. Test sync, verify components work
5. Delete:
   - `/utils/seed_component_types.php`
   - `/views/components/definitions/` directory
6. Update documentation

---

## Future: Plugin Components

Plugins could provide components at `/plugins/{plugin}/views/components/`. Would require scanning plugin directories in `syncComponentTypes()`.

---

## See Also

- [Component System Documentation](/docs/component_system.md)
- [Creating Components from Themes](/docs/creating_components_from_themes.md)
