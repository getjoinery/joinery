# Plugin-Based Theme Support Specification

## Overview

This specification defines how plugins can act as valid themes throughout the system, enabling plugins to provide complete UI experiences while maintaining compatibility with existing directory-based themes.

## Goals

1. **Enable plugins to provide themes** - Allow ANY active plugin to serve as a complete theme
2. **Maintain backward compatibility** - Existing directory-based themes continue working unchanged
3. **Unified theme management** - Single interface for switching between directory and plugin themes
4. **Proper asset routing** - Plugin theme assets served correctly through existing infrastructure
5. **No new manifest requirements** - All active plugins automatically qualify as themes
6. **No new complex abstractions** - Use existing framework patterns wherever possible

## Current State

### Directory-Based Themes
- Located in `/theme/[name]/`
- Require `theme.json` manifest
- Discovered via `ThemeHelper::getAvailableThemes()`
- Assets served directly via filesystem paths

### Plugin Infrastructure
- Located in `/plugins/[name]/`
- Have `plugin.json` manifests
- Can have complete theme-like structures (views, assets, logic)
- Already have routing through `serve.php`

## Implementation Design

### 1. Theme Discovery - Separation of Concerns

Keep ThemeHelper and PluginHelper independent. Combine them at the point of use (PublicPageBase.php) to maintain clean separation of concerns and avoid circular dependencies:

```php
// In PublicPageBase.php where themes are listed
$directory_themes = ThemeHelper::getAvailableThemes();
$plugins = PluginHelper::getActivePlugins(); 

// Combine them
$all_themes = $directory_themes;
foreach ($plugins as $pluginName => $plugin) {
    $all_themes[$pluginName] = $plugin;
}
```

This approach ensures:
- ThemeHelper only handles directory-based themes
- PluginHelper only handles plugins  
- No combination needed - they're displayed separately in UI
- No cross-dependencies between helper classes

### 2. Plugin Display Methods

Methods in `PluginHelper` class for plugin display:

```php
public function getPluginName() {
    // Use plugin's display name from manifest, or plugin directory name
    return $this->get('name', $this->name);
}

public function getPluginDescription() {
    // Use plugin description if available
    return $this->get('description', '');
}
```

### 3. Theme Type Tracking

Since themes and plugins are combined at the point of use, track their types during combination rather than detecting later. No additional helper methods needed in ThemeHelper.

### 4. Theme Switching Updates

Update `ajax/theme_switch_ajax.php`:

```php
// Validate theme exists - try directory theme first, then plugin
$valid_theme = false;

if (ThemeHelper::themeExists($theme)) {
    // It's a valid directory theme
    $valid_theme = true;
} elseif (PluginHelper::isPluginActive($theme)) {
    // It's an active plugin that can act as theme
    $valid_theme = true;
} 

if (!$valid_theme) {
    echo json_encode(array('success' => false, 'message' => 'Theme not found'));
    exit;
}

// Save theme selection (works for both types)
// Directory themes and plugins saved by their name directly
```

### 5. Theme Loading Updates

Update `PublicPageBase.php` and other theme loading locations:

```php
$theme_template = $settings->get_setting('theme_template', true, true) ?: 'default';

// Try directory theme first, then plugin
if (ThemeHelper::themeExists($theme_template)) {
    // Existing directory-based theme logic
    $themePath = PathHelper::getThemePath($theme_template);
    // Use regular theme loading
} elseif (PluginHelper::isPluginActive($theme_template)) {
    // This is a plugin acting as theme
    $plugin = PluginHelper::getInstance($theme_template);
    
    // Use plugin's serve.php for routing
    $themePath = $plugin->getBasePath();
    // Plugin handles its own view resolution
} else {
    // Fallback to default theme
    $themePath = PathHelper::getThemePath('default');
}
```

### 6. Asset Handling

Plugin themes serve assets through existing plugin routing:

- **CSS/JS**: `/plugins/[name]/assets/css/`, `/plugins/[name]/assets/js/`
- **Images**: `/plugins/[name]/assets/img/`
- **Views**: `/plugins/[name]/views/`

The existing `serve.php` in each plugin already handles routing. No changes needed.

### 7. View Resolution

When a plugin theme is active:

1. Check plugin's `/views/` directory first
2. Fall back to plugin's `/logic/` for logic files
3. Use plugin's custom `PublicPage.php` if provided
4. Fall back to base system views if not found in plugin

### 8. UI Updates

Update theme switcher dropdown in `PublicPageBase.php`:

```php
// Get themes from both sources
$directory_themes = ThemeHelper::getAvailableThemes();
$plugins = PluginHelper::getActivePlugins();

// Display directory themes
foreach ($directory_themes as $theme_key => $theme_obj): 
    if (method_exists($theme_obj, 'get')) {
        $display_name = $theme_obj->get('display_name', $theme_key);
    } else {
        $display_name = $theme_key;
    }
    ?>
    <a href="#" onclick="joineryAdminBarSwitchTheme('<?php echo htmlspecialchars($theme_key); ?>'); return false;" 
       <?php echo ($theme_key == $theme_template) ? 'style="font-weight: bold !important;"' : ''; ?>>
        <?php echo htmlspecialchars($display_name); ?>
        <?php echo ($theme_key == $theme_template) ? ' ✓' : ''; ?>
    </a>
<?php endforeach; ?>

<?php 
// Display plugins as themes
foreach ($plugins as $plugin_name => $plugin): 
    $display_name = $plugin->getPluginName();
    ?>
    <a href="#" onclick="joineryAdminBarSwitchTheme('<?php echo htmlspecialchars($plugin_name); ?>'); return false;" 
       <?php echo ($plugin_name == $theme_template) ? 'style="font-weight: bold !important;"' : ''; ?>>
        <?php echo htmlspecialchars($display_name); ?>
        <span style="font-size: 0.8em; opacity: 0.7;">(Plugin)</span>
        <?php echo ($plugin_name == $theme_template) ? ' ✓' : ''; ?>
    </a>
<?php endforeach;
```

## Migration Path

### Phase 1: Core Support (Immediate)
1. Update theme validation in ajax handler
2. Update theme validation in ajax handler
3. Update PublicPageBase to combine themes and plugins
4. Test with existing themes to ensure no breaking changes

### Phase 2: Full Integration (Future)
1. Update all theme-dependent code to handle both types
2. Add plugin theme support to admin interface
3. Document plugin theme development
4. Consider allowing multiple themes per plugin

## Example: ControlD Plugin

Once implemented, the ControlD plugin would:

1. Automatically appear in theme dropdown as "ControlD Integration (Plugin)" using the plugin name "controld"

2. When selected:
   - Use `/plugins/controld/views/` for view templates
   - Serve assets from `/plugins/controld/assets/`
   - Use `/plugins/controld/includes/PublicPage.php` for page rendering
   - Route through `/plugins/controld/serve.php`

## Implementation Checklist

- [ ] Update theme validation to check both directory themes and plugins
- [ ] Update theme validation in `ajax/theme_switch_ajax.php`
- [ ] Update theme dropdown UI in `PublicPageBase.php` to combine themes and plugins
- [ ] Test with existing directory themes
- [ ] Test plugin theme switching with ControlD
- [ ] Document plugin theme behavior
- [ ] Update CLAUDE.md with plugin theme patterns

## Notes

- Plugins use their plugin name directly as theme name (no prefixing)
- All active plugins automatically appear as theme options
- Inactive plugins cannot have their themes selected
- No additional manifest requirements - existing plugin.json is sufficient
- System falls back gracefully if plugin theme files are missing
- ThemeHelper and PluginHelper remain independent - no coupling between them