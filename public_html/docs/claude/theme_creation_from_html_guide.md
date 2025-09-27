# Theme Creation from HTML Template - Step-by-Step Guide

This guide provides specific instructions for creating a new Joinery theme from any HTML template.

## Prerequisites

1. **Verify the HTML template location** - Usually in `/home/user1/` directories
2. **Check which themes exist** - Look in BOTH locations:
   - `/var/www/html/joinerytest/public_html/theme/` (active deployment)
   - `/home/user1/joinery/joinery/theme/` (development repository)

## Critical Understanding Points

### ⚠️ Theme Locations
**IMPORTANT:** Themes must exist in `/var/www/html/joinerytest/public_html/theme/` to be active. This is NOT a symlink location - themes are actual directories here.

### ⚠️ CRITICAL: Core Files Availability
**NEVER REQUIRE THESE FILES - THEY ARE ALWAYS PRE-LOADED:**
- **PathHelper** - ALWAYS available, NEVER require it
- **Globalvars** - ALWAYS available, NEVER require it  
- **SessionControl** - ALWAYS available, NEVER require it

```php
// ❌ WRONG - NEVER DO THIS IN ANY FILE
require_once('PathHelper.php');
require_once(__DIR__ . '/../includes/PathHelper.php');

// ✅ CORRECT - Just use them directly
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
$settings = Globalvars::get_instance();
```

### ⚠️ FormWriter Base Classes
Available FormWriter base classes to inherit from:
- `FormWriterBootstrap` - For Bootstrap-based themes
- `FormWriterTailwind` - For Tailwind CSS themes
- `FormWriterUIKit` - For UIKit themes

## Step-by-Step Theme Creation

### Step 1: Create Theme Directory Structure

```bash
# Create in the PUBLIC_HTML location (NOT in /home/user1/joinery/)
mkdir -p /var/www/html/joinerytest/public_html/theme/THEMENAME/{assets/{css,js,images,fonts},includes,views}
```

### Step 2: Copy Template Assets

```bash
# Copy CSS files
cp -r "/path/to/template/css/"* /var/www/html/joinerytest/public_html/theme/THEMENAME/assets/css/

# Copy JS files  
cp -r "/path/to/template/js/"* /var/www/html/joinerytest/public_html/theme/THEMENAME/assets/js/

# Copy images
cp -r "/path/to/template/images/"* /var/www/html/joinerytest/public_html/theme/THEMENAME/assets/images/

# Don't forget the main style.css if it's in root
cp "/path/to/template/style.css" /var/www/html/joinerytest/public_html/theme/THEMENAME/assets/css/
```

### Step 3: Create FormWriter.php

**Location:** `/var/www/html/joinerytest/public_html/theme/THEMENAME/includes/FormWriter.php`

```php
<?php
// PathHelper is always available - no need to require it
// Choose the correct base class based on your theme's framework
require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php')); // Change based on framework

class FormWriter extends FormWriterBootstrap {
    // Inherits all form styling from base class
    // Can override specific methods here if needed
}
?>
```

### Step 4: Create PublicPage.php

**Location:** `/var/www/html/joinerytest/public_html/theme/THEMENAME/includes/PublicPage.php`

```php
<?php
// PathHelper is always available - no need to require it
require_once(PathHelper::getIncludePath('includes/PublicPageFalcon.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

class PublicPage extends PublicPageFalcon {
    
    public function public_header($options = array()) {
        $session = SessionControl::get_instance();
        $settings = Globalvars::get_instance();
        
        // Set defaults
        $title = isset($options['title']) ? $options['title'] : 'Site Title';
        $showheader = isset($options['showheader']) ? $options['showheader'] : true;
        $description = isset($options['description']) ? $options['description'] : 'Site Description';
        
        ?>
<!DOCTYPE html>
<html>
<head>
    <!-- Copy <head> content from your HTML template -->
    <title><?php echo htmlspecialchars($title); ?></title>
    
    <!-- Update asset paths to use theme directory -->
    <link rel="stylesheet" href="/theme/THEMENAME/assets/css/style.css">
</head>
<body>
    <!-- Copy header HTML from template -->
    
    <!-- IMPORTANT: Use dynamic menu from database -->
    <nav>
        <?php
        $menus = PublicPage::get_public_menu();
        foreach ($menus as $menu) {
            if ($menu['parent'] === true) {
                $submenus = $menu['submenu'];
                if (empty($submenus)) {
                    echo '<li><a href="' . htmlspecialchars($menu['link']) . '">' . 
                         htmlspecialchars($menu['name']) . '</a></li>';
                } else {
                    // Render menu with submenus
                }
            }
        }
        ?>
    </nav>
    
    <!-- DO NOT automatically open content sections here -->
    <?php
    }
    
    public function public_footer($options = array()) {
        ?>
        <!-- Copy footer HTML from template -->
        
        <!-- Update JS paths -->
        <script src="/theme/THEMENAME/assets/js/main.js"></script>
    </body>
    </html>
        <?php
    }
}
?>
```

**Key Points:**
- DO NOT wrap content in automatic `<section>` tags in the header
- DO NOT close non-existent tags in the footer
- Use `PublicPage::get_public_menu()` for dynamic menus
- Remove authentication checks if creating public pages

### Step 5: Create Homepage View

**Location:** `/var/www/html/joinerytest/public_html/theme/THEMENAME/views/index.php`

```php
<?php
// Include PublicPage class following best practices
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Page Title from Template',
    'showheader' => true
));
?>

<!-- Copy EXACT HTML content between header and footer from template -->
<!-- Update image paths as needed -->

<?php
$page->public_footer();
?>
```

**Important:** 
- Use `PathHelper::getThemeFilePath()` to include PublicPage
- Copy HTML content EXACTLY as it appears in the template

### Step 6: Create theme.json

**Location:** `/var/www/html/joinerytest/public_html/theme/THEMENAME/theme.json`

```json
{
    "name": "themename",
    "display_name": "Theme Display Name",
    "version": "1.0.0",
    "description": "Theme description",
    "author": "Author Name",
    "parent_theme": "falcon",
    "framework": "bootstrap",
    "assets": {
        "css": [
            "assets/css/style.css"
        ],
        "js": [
            "assets/js/main.js"
        ]
    }
}
```

### Step 7: Testing and Validation

```bash
# Test PHP syntax for all files
php -l /var/www/html/joinerytest/public_html/theme/THEMENAME/includes/PublicPage.php
php -l /var/www/html/joinerytest/public_html/theme/THEMENAME/includes/FormWriter.php
php -l /var/www/html/joinerytest/public_html/theme/THEMENAME/views/index.php

# Verify FormWriter can be loaded
php -r "
require_once('includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));
require_once('theme/THEMENAME/includes/FormWriter.php');
echo 'FormWriter class exists: ' . (class_exists('FormWriter') ? 'YES' : 'NO') . PHP_EOL;
"
```

## Common Issues and Solutions

### Issue: "Class FormWriter not found"
**Cause:** FormWriter.php extending non-existent class
**Solution:** Verify you're extending one of the existing FormWriterHTML5 classes

### Issue: "Theme directory not found"
**Cause:** Theme created in wrong location
**Solution:** Create in `/var/www/html/joinerytest/public_html/theme/`, not in `/home/user1/`

### Issue: Extra space above hero/slider sections
**Cause:** PublicPage header automatically wrapping content in sections
**Solution:** Don't add automatic content wrappers in public_header()

### Issue: Images not showing
**Cause:** Incorrect asset paths
**Solution:** Update paths to `/theme/THEMENAME/assets/images/`

### Issue: Menu not showing correctly
**Cause:** Hardcoded menu instead of database-driven
**Solution:** Use `PublicPage::get_public_menu()` to fetch from database

## Asset Path Conversions

When copying HTML content, update paths as follows:

| Original Path | New Path |
|--------------|----------|
| `href="css/style.css"` | `href="/theme/THEMENAME/assets/css/style.css"` |
| `src="images/logo.png"` | `src="/theme/THEMENAME/assets/images/logo.png"` |
| `src="js/main.js"` | `src="/theme/THEMENAME/assets/js/main.js"` |

## File Structure Summary

```
/var/www/html/joinerytest/public_html/theme/THEMENAME/
├── assets/
│   ├── css/        # All CSS files from template
│   ├── js/         # All JS files from template
│   ├── images/     # All images from template
│   └── fonts/      # Font files if any
├── includes/
│   ├── FormWriter.php   # Extends appropriate FormWriterHTML5 class
│   └── PublicPage.php   # Header/footer from template
├── views/
│   └── index.php        # Homepage content
└── theme.json           # Theme configuration
```

## Final Checklist

- [ ] Theme created in `/var/www/html/joinerytest/public_html/theme/`
- [ ] All assets copied to `assets/` subdirectories
- [ ] FormWriter extends correct FormWriterHTML5 class for framework
- [ ] PublicPage doesn't auto-wrap content in sections
- [ ] Homepage view uses PathHelper::getThemeFilePath()
- [ ] Dynamic menu implemented with get_public_menu()
- [ ] All PHP files pass syntax check
- [ ] theme.json created with correct metadata
- [ ] Asset paths updated to theme directory

## Testing Commands Summary

```bash
# Quick validation of all theme files
find /var/www/html/joinerytest/public_html/theme/THEMENAME -name "*.php" -exec php -l {} \;

# Check if theme is active
php -r "require_once('includes/PathHelper.php'); require_once('includes/Globalvars.php'); \$s = Globalvars::get_instance(); echo 'Active theme: ' . \$s->get_setting('theme_template', true, true) . PHP_EOL;"
```

## Important Notes

- Never use `require_once` for PathHelper, Globalvars, or SessionControl - they're always available
- Always test FormWriter loading separately before testing forms
- Keep original HTML structure intact - only update asset paths
- Database-driven menus are required - don't hardcode navigation
- Test each PHP file for syntax errors before attempting to load the theme