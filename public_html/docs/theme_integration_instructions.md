# Theme Integration Instructions for Claude Code

This document provides step-by-step instructions for integrating HTML/CSS templates into the Joinery platform as themes. Follow these instructions exactly to ensure successful theme integration.

## How Theme Activation Works

**CRITICAL:** The active visual theme is controlled by a **database setting**, not by any config file.

- **To switch themes:** Update `theme_template` in `stg_settings` via Admin > Settings, or directly:
  ```sql
  UPDATE stg_settings SET stg_value = 'my-theme-name' WHERE stg_name = 'theme_template';
  ```
- Theme directories live in `public_html/theme/{theme-name}/`
- The `site_template` setting in `config/Globalvars_site.php` is **not the visual theme** — it is the site installation directory identifier (e.g., `phillyzouk`, `joinerytest`). Almost never needs changing.

## Default Theme CSS Kit & `.jy-ui` Namespace

The `default` theme ships a scoped CSS component kit in `assets/css/joinery-styles.css`. This file is loaded by **every** theme (via `PublicPageBase::render_base_assets()`), making it safe to use the kit's classes in base views (`/views/*.php`) regardless of which theme is active.

### How Scoping Works

`joinery-styles.css` uses two complementary gates so it is safe to load unconditionally on every page:

**1. Component rules — scoped to `.jy-ui`**

All component classes require a `.jy-ui` ancestor:

```css
/* lives in joinery-styles.css */
.jy-ui .btn { ... }
.jy-ui .card { ... }
.jy-ui .alert { ... }
```

A branded theme's own `.btn` / `.card` etc. are unaffected because they sit outside `.jy-ui`.

**Every base view that uses the kit must wrap its HTML in `.jy-ui`:**

```php
<?php $page->public_header([...]); ?>

<div class="jy-ui">
    <!-- page content using kit classes -->
</div>

<?php $page->public_footer([...]); ?>
```

Theme-specific views (homepage, blog, events) that manage their own full-width HTML do **not** need the `.jy-ui` wrapper.

**2. Global type rules — gated to `body.jy-default`**

The default theme's `body`, `h1–h6`, `a`, `p`, `code`, and `blockquote` resets are scoped to `body.jy-default`. The default theme's `PublicPage.php` outputs `<body class="jy-default">`; all other themes leave the body classless, so the type rules are completely inert for them. **Branded themes do not need to do anything special** — isolation is automatic.

### `--jy-*` Token Vocabulary

All design tokens use the `--jy-` prefix to prevent collision with any other CSS variable:

| Token | Default value | Purpose |
|-------|--------------|---------|
| `--jy-color-bg` | `#ffffff` | Page background |
| `--jy-color-surface` | `#f7f8fa` | Card / panel background |
| `--jy-color-surface-alt` | `#eff1f5` | Alternate surface (table rows, etc.) |
| `--jy-color-border` | `#e1e4ea` | Default border |
| `--jy-color-border-strong` | `#c8ccd4` | Emphasized border |
| `--jy-color-text` | `#1a1d23` | Body text |
| `--jy-color-text-muted` | `#5a6170` | Secondary / muted text |
| `--jy-color-text-subtle` | `#8990a0` | Placeholder / disabled text |
| `--jy-color-primary` | `#5b7a99` | Primary action color |
| `--jy-color-primary-hover` | `#4a6886` | Primary hover state |
| `--jy-color-primary-text` | `#ffffff` | Text on primary background |
| `--jy-color-link` | `#4a6886` | Hyperlink color |
| `--jy-color-success` | `#2e7d32` | Success / positive |
| `--jy-color-warning` | `#b45309` | Warning / caution |
| `--jy-color-error` | `#c62828` | Error / danger |
| `--jy-color-info` | `#0277bd` | Informational |
| `--jy-font-sans` | `'Inter', system-ui, …` | Body / UI font stack |
| `--jy-font-display` | `'Playfair Display', serif` | Heading display font |
| `--jy-space-1` … `--jy-space-8` | `0.25rem` … `2rem` | Spacing scale |
| `--jy-radius-sm/md/lg/xl/full` | `4px … 9999px` | Border radius scale |
| `--jy-shadow-sm/md/lg` | — | Box shadow scale |
| `--jy-control-height-sm/md/lg` | `32px / 40px / 48px` | Form control heights |

### Re-skinning with a Branded Theme

To give a branded theme different colors/typography while keeping all base-view components working, override tokens at `:root` in your theme's CSS:

```css
/* theme/mybrand/assets/css/style.css */
:root {
    --jy-color-primary:       #c0392b;   /* brand red */
    --jy-color-primary-hover: #a93226;
    --jy-color-link:          #c0392b;
    --jy-font-sans:           'Lato', sans-serif;
    --jy-font-display:        'Montserrat', sans-serif;
}
```

Because the kit rules reference `var(--jy-color-primary)`, overriding the variable at `:root` is all that is needed — no CSS selectors to duplicate.

## Prerequisites

Before starting, ensure you have:
1. Access to the source HTML template files
2. Write permissions in the `/theme/` directory
3. Access to maintenance scripts for validation

## Development Resources

### Theme Source Files

Raw HTML theme templates are available for reference at `/theme-sources/`:
- **URL:** `https://[yoursite]/theme-sources/`
- **Available themes:** canvas, falcon, linka, sassa
- Browse rendered HTML pages and view source structure

### Component Preview Utility

After creating components, test them instantly without database setup:
```
/utils/component_preview              - All components
/utils/component_preview?type=hero    - Single component type
/utils/component_preview?theme=falcon - Override theme for testing
/utils/component_preview?config&paths - Show config data and file paths
```

See [Creating Components from Themes](/docs/creating_components_from_themes.md) for component extraction workflow.

## Step-by-Step Integration Process

### Step 1: Analyze Source Template

First, examine the source template structure:

```bash
# List template files
ls -la /path/to/source/template/

# Check for key directories
ls -la /path/to/source/template/assets/
ls -la /path/to/source/template/assets/css/
ls -la /path/to/source/template/assets/js/
ls -la /path/to/source/template/assets/img/

# Identify main CSS framework
grep -l "bootstrap" /path/to/source/template/assets/css/*.css
```

**Key things to identify:**
- CSS framework (Bootstrap, Tailwind, etc.)
- JavaScript dependencies (jQuery, React, etc.)
- Homepage layout files (index.html, index-2.html, etc.)
- Image organization structure
- Footer and header structure

### Step 2: Create Theme Directory Structure

Create the complete theme directory with proper permissions:

```bash
# Set theme name (no spaces, lowercase)
THEME_NAME="mytheme"

# Create directory structure
mkdir -p theme/$THEME_NAME/{assets/{css,js,images,fonts},includes,views}

# Set permissions immediately
chmod 755 theme/$THEME_NAME
find theme/$THEME_NAME -type d -exec chmod 755 {} \;
```

### Step 3: Copy and Organize Assets

Copy assets from source template, reorganizing as needed:

```bash
# Copy CSS files
cp /path/to/source/template/assets/css/*.css theme/$THEME_NAME/assets/css/

# Copy JavaScript files
cp /path/to/source/template/assets/js/*.js theme/$THEME_NAME/assets/js/

# Copy fonts if present
cp -r /path/to/source/template/assets/fonts/* theme/$THEME_NAME/assets/fonts/ 2>/dev/null || true

# Copy only needed images (be selective to save space)
# Example: For a specific homepage layout
cp -r /path/to/source/template/assets/img/home-three theme/$THEME_NAME/assets/images/
cp /path/to/source/template/assets/img/logo.png theme/$THEME_NAME/assets/images/
cp /path/to/source/template/assets/img/favicon.png theme/$THEME_NAME/assets/images/

# Set file permissions
find theme/$THEME_NAME -type f -exec chmod 644 {} \;
```

### Step 4: Create theme.json Configuration

Create `theme/$THEME_NAME/theme.json` with minimal, accurate configuration:

```json
{
    "name": "mytheme",
    "display_name": "My Theme Display Name",
    "version": "1.0.0",
    "description": "Brief theme description",
    "author": "Joinery Team",
    "cssFramework": "bootstrap",
    "formWriterBase": "FormWriterBootstrap",
    "publicPageBase": "PublicPageBase"
}
```

**For HTML5 zero-dependency themes** (no Bootstrap, no jQuery):
```json
{
    "name": "mytheme-html5",
    "display_name": "My Theme HTML5",
    "version": "1.0.0",
    "description": "Clean HTML5 theme based on [source] design, zero dependencies",
    "author": "Joinery Team",
    "receives_upgrades": true,
    "included_in_publish": true,
    "cssFramework": "html5",
    "formWriterBase": "FormWriterV2HTML5",
    "publicPageBase": "PublicPageBase"
}
```

**Important field notes:**
- `cssFramework`: Use `"bootstrap"` for Bootstrap themes, `"html5"` for zero-dependency themes, `"tailwind"` for Tailwind
- `formWriterBase`: Use `"FormWriterV2Bootstrap"` for Bootstrap, `"FormWriterV2HTML5"` for HTML5, `"FormWriterV2Tailwind"` for Tailwind
- `publicPageBase`: Always use `"PublicPageBase"` (NOT PublicPageFalcon)

### Step 5: Create FormWriter.php

Create `theme/$THEME_NAME/includes/FormWriter.php`:

```php
<?php
// FormWriter for theme - extends appropriate base for CSS framework
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

class FormWriter extends FormWriterV2Bootstrap {
    // Inherits all form styling from base class
    // Add theme-specific overrides here if needed
}
?>
```

**Framework mappings:**
- Bootstrap themes → `FormWriterV2Bootstrap`
- HTML5 zero-dependency themes → `FormWriterV2HTML5`
- Tailwind themes → `FormWriterV2Tailwind`

**HTML5 FormWriter example:**
```php
<?php
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

class FormWriter extends FormWriterV2HTML5 {
    // Inherits all form methods from FormWriterV2HTML5
}
?>
```

**Important:** `FormWriterV2HTML5` generates semantic HTML form elements with CSS classes like `.form-group`, `.form-control`, `.form-label`, `.btn`, and `.form-check`. Your theme CSS must style these classes — see the [HTML5 Zero-Dependency Themes](#html5-zero-dependency-themes) section for required form CSS.

### Step 6: Create PublicPage.php (Most Critical File)

Create `theme/$THEME_NAME/includes/PublicPage.php`:

```php
<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

class PublicPage extends PublicPageBase {

    // CRITICAL: Must implement this abstract method
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table table-striped',
            'header' => 'table-light'
        ];
    }

    public function public_header($options = array()) {
        $session = SessionControl::get_instance();
        $settings = Globalvars::get_instance();

        $title = isset($options['title']) ? $options['title'] : $settings->get_setting('site_name', true, true);
        $showheader = isset($options['showheader']) ? $options['showheader'] : true;
        $description = isset($options['description']) ? $options['description'] : $settings->get_setting('site_description', true, true);

        ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <meta name="description" content="<?php echo htmlspecialchars($description); ?>">

        <!-- CSS files - use absolute paths -->
        <link rel="stylesheet" href="/theme/<?php echo $THEME_NAME; ?>/assets/css/bootstrap.min.css">
        <!-- Add all theme CSS files here -->

        <title><?php echo htmlspecialchars($title); ?></title>
    </head>
    <body>

        <!-- Navigation -->
        <nav>
            <!-- CRITICAL: Use dynamic menu system -->
            <?php
            $menu_data = $this->get_menu_data();
            $menus = isset($menu_data['main_menu']) ? $menu_data['main_menu'] : array();

            foreach ($menus as $menu) {
                // Render menu items
                echo '<a href="' . htmlspecialchars($menu['link']) . '">' .
                     htmlspecialchars($menu['name']) . '</a>';

                // Handle submenus if present
                if (isset($menu['submenu']) && !empty($menu['submenu'])) {
                    // Render submenu
                }
            }
            ?>
        </nav>

        <!-- Notification bell icon (provided by base class, override if needed) -->
        <?php $this->render_notification_icon($menu_data); ?>

        <!-- CRITICAL: User menu (login/logout/profile) - ALWAYS include in header -->
        <div class="user-menu">
            <?php if ($session->is_logged_in()): ?>
                <a href="/profile">Profile</a>
                <a href="/logout">Logout</a>
            <?php else: ?>
                <a href="/login">Login</a>
            <?php endif; ?>
        </div>
        <?php
    }

    // CRITICAL: Override BeginPage/EndPage for content containers
    // See "Content Container Pattern" section below for details
    public static function BeginPage($title = '', $options = array()) {
        // Use your theme's container classes here
        $output = '<section class="content-area"><div class="container">';
        if ($title) {
            $output .= '<h2>' . $title . '</h2>';
            if (isset($options['subtitle']) && $options['subtitle']) {
                $output .= '<p>' . $options['subtitle'] . '</p>';
            }
        }
        return $output;
    }

    public static function EndPage($options = array()) {
        return '</div></section>';
    }

    public function public_footer($options = array()) {
        $settings = Globalvars::get_instance();
        ?>

        <!-- Footer structure from source template -->
        <footer>
            <!-- Use settings for dynamic content -->
            <?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>
            <?php echo htmlspecialchars($settings->get_setting('site_description', true, true)); ?>
        </footer>

        <!-- JavaScript files - use absolute paths -->
        <script src="/theme/<?php echo $THEME_NAME; ?>/assets/js/jquery.min.js"></script>
        <!-- Add all theme JS files here -->
    </body>
</html>
        <?php
    }
}
?>
```

#### Content Container Pattern (BeginPage / EndPage)

Base views (in `/views/`) call `PublicPage::BeginPage()` and `PublicPage::EndPage()` to wrap their content. These methods provide the content container — margins, max-width, padding — that gives standard pages a reasonable layout. Theme-specific views (in `/theme/*/views/`) manage their own full-width sections and do **not** call `BeginPage`/`EndPage`.

**How it works:**
- `public_header()` / `public_footer()` — Output the HTML skeleton, navbar, and footer. No content container.
- `BeginPage()` / `EndPage()` — Output the content container wrapper. Called by base views only.

**Why this separation matters:**
- Base views (profile pages, list pages, cart, etc.) automatically get proper margins from `BeginPage`/`EndPage` without needing theme-specific overrides for each page.
- Theme-specific views (homepage, blog, events) use their own `<section>` and `<div class="container">` elements for full-width layouts, hero images, etc.

**What to override:**
Your theme's `BeginPage`/`EndPage` should use your CSS framework's container classes. Examples:

| Framework | BeginPage output | EndPage output |
|-----------|-----------------|----------------|
| Bootstrap | `<section class="pt-100 pb-70"><div class="container">` | `</div></section>` |
| UIKit | `<div class="uk-section"><div class="uk-container">` | `</div></div>` |
| Tailwind | `<div class="max-w-7xl mx-auto px-4 py-16">` | `</div>` |

**Fallback:** `PublicPageBase` provides a basic default container (`max-width: 1140px; margin: 0 auto; padding: 2rem 1rem`) so pages are never completely unstyled, but themes should always override with framework-appropriate markup.

### Step 7: Extract and Create Homepage Template

**CRITICAL:** Use actual HTML from source template, not placeholder content!

1. Open the desired homepage layout (e.g., `index-3.html`)
2. Extract the main content area (between header and footer)
3. Update all asset paths to absolute theme paths

Create `theme/$THEME_NAME/views/index.php`:

```php
<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Home - Theme Name',
    'showheader' => true
));
?>

<!-- PASTE ACTUAL HTML FROM SOURCE TEMPLATE HERE -->
<!-- Example from index-3.html lines 271-700 -->
<!-- Theme views manage their own <section> and container markup -->
<!-- Do NOT call BeginPage/EndPage here — those are for base views only -->

<!-- CRITICAL: Update all image paths -->
<!-- FROM: assets/img/home-three/blog-item/1.jpg -->
<!-- TO:   /theme/mytheme/assets/images/home-three/blog-item/1.jpg -->

<?php
$page->public_footer();
?>
```

**Note:** Theme-specific views (homepage, blog, events, posts) use their own `<section>` wrappers with full-width layouts. They call `public_header()` / `public_footer()` but do **not** call `BeginPage()` / `EndPage()`. The `BeginPage`/`EndPage` container is only used by base views in `/views/` (profile pages, cart, lists, etc.) that don't have theme-specific overrides. See the "Content Container Pattern" section in Step 6.

### Step 8: Set File Permissions (CRITICAL!)

**This step is often missed but is absolutely required:**

```bash
# Set all file permissions to 644 (readable by web server)
find theme/$THEME_NAME -type f -exec chmod 644 {} \;

# Set all directory permissions to 755 (executable by web server)
find theme/$THEME_NAME -type d -exec chmod 755 {} \;

# Verify permissions
ls -la theme/$THEME_NAME/includes/PublicPage.php  # Should show -rw-r--r--
ls -la theme/$THEME_NAME/                          # Should show drwxr-xr-x
```

### Step 9: Validate PHP Files

Run syntax validation on all PHP files:

```bash
# Check PHP syntax
php -l theme/$THEME_NAME/includes/FormWriter.php
php -l theme/$THEME_NAME/includes/PublicPage.php
php -l theme/$THEME_NAME/views/index.php

# Run PHP file validation
php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php \
    theme/$THEME_NAME/includes/PublicPage.php
```

### Step 10: Test Theme

1. Open browser to your site
2. Check browser console for JavaScript errors
3. Check network tab for 404 errors (missing assets)
4. Verify responsive design works
5. Check server error logs: `tail /var/www/html/joinerytest/logs/error.log`

## Common Issues and Solutions

### Issue 1: "Failed opening required" Error

**Symptom:** PHP error "Failed opening required '/path/to/file.php'"

**Cause:** File permissions are incorrect (usually 0600 instead of 0644)

**Solution:**
```bash
chmod 644 theme/$THEME_NAME/includes/PublicPage.php
chmod 755 theme/$THEME_NAME/includes/
```

### Issue 2: Abstract Method Error

**Symptom:** "Class PublicPage contains 1 abstract method"

**Cause:** Missing `getTableClasses()` method implementation

**Solution:** Add to PublicPage class:
```php
protected function getTableClasses() {
    return [
        'wrapper' => 'table-responsive',
        'table' => 'table table-striped',
        'header' => 'table-light'
    ];
}
```

### Issue 3: Images Not Loading

**Symptom:** Broken image icons, 404 errors in network tab

**Cause:** Incorrect image paths or missing image files

**Solution:**
1. Verify paths are absolute: `/theme/mytheme/assets/images/...`
2. Check images exist: `ls theme/mytheme/assets/images/`
3. Verify permissions: `ls -la theme/mytheme/assets/images/`

### Issue 4: Footer Not Styled Correctly

**Symptom:** Footer appears unstyled or broken

**Cause:** CSS classes don't match theme expectations

**Solution:**
1. Check source template footer HTML structure
2. Match exact CSS classes from source (e.g., `footer-top-area` not `footer-area`)
3. Include all wrapper divs and structure from source

### Issue 5: CSS/JavaScript Not Loading

**Symptom:** Page appears unstyled, console errors

**Cause:** Incorrect asset paths in PublicPage.php

**Solution:**
1. Use absolute paths: `/theme/mytheme/assets/css/style.css`
2. Verify files exist at those paths
3. Check file permissions (644)

## Path Update Checklist

When integrating a theme, update these paths:

- [ ] CSS links in PublicPage.php header
- [ ] JavaScript sources in PublicPage.php footer
- [ ] Image sources in views/index.php
- [ ] Logo path in header/footer
- [ ] Favicon path in header
- [ ] Font paths in CSS files (if using custom fonts)

## Asset Optimization Tips

1. **Be selective with images:** Only copy image directories for layouts you're implementing
   - One layout = ~400KB vs all layouts = ~2.4MB

2. **Remove unused CSS/JS:** Delete framework files you're not using

3. **Consolidate similar images:** Use one set of placeholder images

## Production-Only Files Rule

**CRITICAL:** Only add files to a theme that are needed in production deployment. Theme directories are included in release archives, so unnecessary files waste space and bandwidth.

### Files That MUST Be Excluded

**Never include these file types in themes:**

| File Type | Extension | Reason |
|-----------|-----------|--------|
| Photoshop files | `.psd` | Source files, not needed for rendering |
| Illustrator files | `.ai` | Source files, not needed for rendering |
| EPS files | `.eps` | Vector source files |
| InDesign files | `.indd` | Source files |
| Video files | `.mp4`, `.webm`, `.mov`, `.avi` | Demo content, too large |
| Raw images | `.raw`, `.cr2`, `.nef` | Unprocessed camera files |
| Archive files | `.zip`, `.tar`, `.gz` | Compressed source bundles |

### Files That ARE Allowed

| File Type | Extension | Notes |
|-----------|-----------|-------|
| Images | `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.svg` | Optimized for web |
| Stylesheets | `.css` | Required for styling |
| JavaScript | `.js` | Required for functionality |
| Fonts | `.woff`, `.woff2`, `.ttf`, `.eot` | Web fonts |
| JSON | `.json` | Configuration files |
| PHP | `.php` | Theme logic files |

### When In Doubt, Ask

If you're unsure whether a file should be included:
1. **Ask before adding** - It's easier to add files later than to remove them from deployed sites
2. **Check file size** - Files over 1MB should be questioned
3. **Check file purpose** - Is it needed to render pages, or is it source/demo content?

### Cleaning Up Purchased Themes

Many purchased HTML templates include source files and demo content. Before integrating:

```bash
# Remove source/design files
find theme/$THEME_NAME -type f \( -name "*.psd" -o -name "*.ai" -o -name "*.eps" -o -name "*.indd" \) -delete

# Remove video files
find theme/$THEME_NAME -type f \( -name "*.mp4" -o -name "*.webm" -o -name "*.mov" \) -delete

# Check what's left and how much space
du -sh theme/$THEME_NAME
find theme/$THEME_NAME -type f -size +1M -exec ls -lh {} \;
```

## Validation Checklist

Before declaring theme complete:

- [ ] **No source files present** (.psd, .ai, .eps, .indd, .mp4, .webm, .mov)
- [ ] All directories created with 755 permissions
- [ ] All files have 644 permissions
- [ ] theme.json exists and is valid JSON
- [ ] FormWriter.php extends correct base class
- [ ] PublicPage.php extends PublicPageBase
- [ ] PublicPage implements getTableClasses() method
- [ ] PublicPage overrides BeginPage()/EndPage() with theme-appropriate container markup
- [ ] public_header() uses get_menu_data() for navigation
- [ ] **public_header() includes user menu (login/logout/profile links)**
- [ ] **public_header() includes shopping cart icon with item count**
- [ ] **top_right_menu() calls `$this->render_notification_icon($menu_data)`** for notification bell
- [ ] public_footer() uses Globalvars for site info
- [ ] index.php uses actual HTML from source (not placeholders)
- [ ] All image paths updated to `/theme/[name]/assets/images/`
- [ ] PHP syntax validation passes on all files
- [ ] validate_php_file.php passes on all files
- [ ] No 404 errors in browser network tab
- [ ] No JavaScript errors in browser console

## Quick Reference Commands

```bash
# Create theme structure
THEME_NAME="mytheme"
mkdir -p theme/$THEME_NAME/{assets/{css,js,images,fonts},includes,views}

# Copy assets
cp -r /source/assets/css/* theme/$THEME_NAME/assets/css/
cp -r /source/assets/js/* theme/$THEME_NAME/assets/js/

# Fix permissions
find theme/$THEME_NAME -type f -exec chmod 644 {} \;
find theme/$THEME_NAME -type d -exec chmod 755 {} \;

# Validate PHP
php -l theme/$THEME_NAME/includes/PublicPage.php

# Test theme
tail -f /var/www/html/joinerytest/logs/error.log
```

## Important Notes

1. **Always extend PublicPageBase directly** — never extend another theme's class (e.g. PublicPageFalcon). Each theme must be self-contained and inherit only from the framework base class. Inheriting from a sibling theme creates a hidden dependency on that theme's frontend framework (Bootstrap, etc.) and makes both themes harder to maintain independently.
2. **Always implement getTableClasses()** in PublicPage class
3. **Always fix permissions** before testing (644 files, 755 directories)
4. **Always use actual HTML** from source template, not placeholders
5. **Always use absolute paths** for assets (/theme/name/assets/...)
6. **Always validate PHP syntax** before testing in browser
7. **Always check error logs** when debugging issues
8. **Always include user menu (login/logout/profile)** in the header's utility/option area
9. **Always include shopping cart icon** in the header's utility/option area (uses get_menu_data() cart data)
10. **Always call `$this->render_notification_icon($menu_data)`** in `top_right_menu()` — this base class method renders the notification bell icon automatically; override it only if your theme needs different markup

## Footer Structure Pattern

Most themes use a multi-column footer. Match the source structure:

```html
<!-- Wrong: Simple footer -->
<footer class="footer-area">
    <p>Copyright...</p>
</footer>

<!-- Right: Multi-section footer matching source -->
<footer class="footer-top-area pt-100 pb-70">
    <div class="container">
        <div class="row">
            <div class="col-lg-3"><!-- Widget 1 --></div>
            <div class="col-lg-3"><!-- Widget 2 --></div>
            <div class="col-lg-3"><!-- Widget 3 --></div>
            <div class="col-lg-3"><!-- Widget 4 --></div>
        </div>
    </div>
</footer>
<footer class="footer-bottom-area">
    <div class="container">
        <p>Copyright...</p>
    </div>
</footer>
```

## Dynamic Content Integration

Replace static content with Joinery dynamic features:

### Basic Site Information

| Static Content | Replace With | Setting Name | Location |
|---------------|--------------|--------------|----------|
| Site name | `$settings->get_setting('site_name')` | `site_name` | Admin → Settings |
| Site description | `$settings->get_setting('site_description')` | `site_description` | Admin → Settings |
| Logo URL | `$settings->get_setting('logo_link')` | `logo_link` | Admin → Settings |
| Copyright year | `<?php echo date('Y'); ?>` | N/A | PHP function |

### Email & Contact Settings

| Static Content | Replace With | Setting Name | Default Value |
|---------------|--------------|--------------|---------------|
| Default email | `$settings->get_setting('defaultemail')` | `defaultemail` | N/A |
| Email sender name | `$settings->get_setting('defaultemailname')` | `defaultemailname` | N/A |
| Webmaster email | `$settings->get_setting('webmaster_email')` | `webmaster_email` | N/A |

**Note:** Contact phone and address settings do not exist by default. The Phillyzouk theme uses placeholders with graceful fallback:
```php
<?php echo htmlspecialchars($settings->get_setting('contact_phone', true, true) ?: 'N/A'); ?>
<?php echo htmlspecialchars($settings->get_setting('contact_email', true, true) ?: 'N/A'); ?>
<?php echo htmlspecialchars($settings->get_setting('contact_address', true, true) ?: 'N/A'); ?>
```

### Social Media Links

All social media settings are optional and stored with naming pattern `social_[platform]_link`:

| Platform | Setting Name | Admin Toggle |
|----------|--------------|--------------|
| Facebook | `social_facebook_link` | `social_settings_active` |
| Twitter | `social_twitter_link` | `social_settings_active` |
| Instagram | `social_instagram_link` | `social_settings_active` |
| LinkedIn | `social_linkedin_link` | `social_settings_active` |
| YouTube | `social_youtube_link` | `social_settings_active` |
| Discord | `social_discord_link` | `social_settings_active` |
| GitHub | `social_github_link` | `social_settings_active` |
| Reddit | `social_reddit_link` | `social_settings_active` |
| TikTok | `social_tiktok_link` | `social_settings_active` |
| Spotify | `social_spotify_link` | `social_settings_active` |
| SoundCloud | `social_soundcloud_link` | `social_settings_active` |
| Mixcloud | `social_mixcloud_link` | `social_settings_active` |
| Pinterest | `social_pinterest_link` | `social_settings_active` |
| Telegram | `social_telegram_link` | `social_settings_active` |
| WhatsApp | `social_whatsapp_link` | `social_settings_active` |
| Snapchat | `social_snapchat_link` | `social_settings_active` |
| Twitch | `social_twitch_link` | `social_settings_active` |
| Slack | `social_slack_link` | `social_settings_active` |
| Stack Overflow | `social_stack_link` | `social_settings_active` |
| Google+ | `social_google_link` | `social_settings_active` |
| Messenger | `social_messenger_link` | `social_settings_active` |

**Usage example:**
```php
<?php if ($settings->get_setting('social_facebook_link')): ?>
    <a href="<?php echo htmlspecialchars($settings->get_setting('social_facebook_link')); ?>" target="_blank">
        <i class="bx bxl-facebook"></i>
    </a>
<?php endif; ?>
```

### Navigation & Menus

| Static Content | Replace With | Notes |
|---------------|--------------|-------|
| Hardcoded menu items | `$this->get_menu_data()` | Returns array with `main_menu` key |
| Active page detection | `$menu['is_active']` | Automatically detected |
| Submenu items | `$menu['submenu']` | Array of submenu items |

**Menu structure:**
```php
$menu_data = $this->get_menu_data();
$menus = isset($menu_data['main_menu']) ? $menu_data['main_menu'] : array();

foreach ($menus as $menu) {
    // $menu['name'] - Display name
    // $menu['link'] - URL
    // $menu['is_active'] - Boolean, true if current page
    // $menu['submenu'] - Array of submenu items (same structure)
}
```

### User Menu (Login/Logout/Profile) - CRITICAL

**Every theme MUST include a user menu in the header.** This provides login access for non-authenticated users and profile/logout links for authenticated users.

**Where to place:** In the header's "utility" or "options" area, typically in the top-right corner near the search icon or phone number.

**Required pattern:**
```php
<div class="user-menu">
    <?php if ($session->is_logged_in()): ?>
        <a href="/profile" class="user-link">
            <i class="bx bx-user"></i>
            <span>Profile</span>
        </a>
        <a href="/logout" class="user-link">
            <i class="bx bx-log-out"></i>
            <span>Logout</span>
        </a>
    <?php else: ?>
        <a href="/login" class="user-link">
            <i class="bx bx-log-in"></i>
            <span>Login</span>
        </a>
    <?php endif; ?>
</div>
```

**Styling example (add to joinery-custom.css):**
```css
/* User menu (login/logout/profile) styling */
.user-menu {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-right: 15px;
}

.user-menu .user-link {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #ffffff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: color 0.3s ease;
}

.user-menu .user-link:hover {
    color: #0d6efd;
}

.user-menu .user-link i {
    font-size: 18px;
}

/* Mobile user menu adjustments */
@media (max-width: 991px) {
    .user-menu {
        margin-right: 0;
        margin-bottom: 15px;
    }
}
```

**DO NOT** use the header utility area for social media "Follow" links - those belong in the footer. The header utility area should be reserved for user authentication and account access.

### Shopping Cart Icon - CRITICAL

**Every theme MUST include a shopping cart icon in the header.** This provides easy access to the cart for users shopping on the site.

**Where to place:** In the header's "utility" or "options" area, typically near the user menu links. Place it between the phone number (if present) and the user menu.

**Data source:** Use `get_menu_data()` which provides cart data automatically:
```php
$menu_data = $this->get_menu_data();
$cart_data = isset($menu_data['cart']) ? $menu_data['cart'] : array('count' => 0, 'link' => '/cart');
// Available fields:
// $cart_data['count'] - Number of items in cart
// $cart_data['link'] - Link to cart page (/cart)
// $cart_data['has_items'] - Boolean, true if cart has items
```

**Required pattern:**
```php
<?php
// Get cart data from menu_data (already retrieved earlier in header)
$cart_data = isset($menu_data['cart']) ? $menu_data['cart'] : array('count' => 0, 'link' => '/cart');
?>
<div class="cart">
    <a href="<?php echo htmlspecialchars($cart_data['link']); ?>">
        <i class="bx bx-cart"></i>
        <?php if ($cart_data['count'] > 0): ?>
        <span class="cart-count"><?php echo intval($cart_data['count']); ?></span>
        <?php endif; ?>
    </a>
</div>
```

**CSS considerations:** Most themes include cart styles. Check for existing `.cart` or `.cart-count` classes in the theme's CSS (e.g., `.navbar-area .others-option .cart`). If not present, add styles to `joinery-custom.css`:

```css
/* Cart icon styling */
.others-option .cart {
    display: inline-block;
    color: #ffffff;
    margin-left: 20px;
    position: relative;
}

.others-option .cart a {
    color: #ffffff;
}

.others-option .cart a i {
    font-size: 20px;
}

.others-option .cart a:hover {
    color: #0d6efd;
}

.others-option .cart .cart-count {
    position: absolute;
    top: -8px;
    left: 11px;
    color: #d80650;
    background-color: #ffffff;
    width: 15px;
    height: 15px;
    line-height: 16px;
    text-align: center;
    border-radius: 50%;
    font-size: 10px;
    font-weight: bold;
}
```

### Settings That Do NOT Exist by Default

These settings are commonly needed but don't exist in the database by default. Use fallback values:

- `contact_phone` - Use: `$settings->get_setting('contact_phone', true, true) ?: 'N/A'`
- `contact_address` - Use: `$settings->get_setting('contact_address', true, true) ?: 'N/A'`
- `contact_email` - Use: `$settings->get_setting('defaultemail')` as fallback
- `copyright_text` - Build dynamically: `&copy; <?php echo date('Y'); ?> <?php echo $settings->get_setting('site_name'); ?>`
- `footer_text` - Use: `$settings->get_setting('blog_footer_text', true, true)` (exists but often empty)

## Integrating Settings Into Theme Templates

When integrating a new theme, replace static content with these dynamic settings in appropriate locations:

### Where to Use Settings

**Header/Navigation (`public_header()`):**
- Site name in logo alt text: `<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>`
- Logo with fallback to site name (see Logo Integration Pattern below)
- Dynamic menu system (replace hardcoded nav items)

**Footer (`public_footer()`):**
- Site name and description in footer widget
- Social media links (all platforms)
- Contact information (email, phone, address with fallbacks)
- Copyright with dynamic year and site name

**Meta Tags (`public_header()` `<head>` section):**
- Meta description: `<?php echo htmlspecialchars($description); ?>` (already passed in $options)
- Title tag: `<?php echo htmlspecialchars($title); ?>` (already passed in $options)

### Logo Integration Pattern

**CRITICAL:** Never use theme-bundled logo files. Always integrate the site's logo from settings with fallback to site name.

Replace static logo images in header and footer:

```php
<!-- Header/Navbar Logo -->
<a href="/" class="navbar-brand">
    <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>">
    <?php else: ?>
        <span class="site-name-logo"><?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></span>
    <?php endif; ?>
</a>

<!-- Footer Logo -->
<div class="footer-logo">
    <?php if ($logo_url = $settings->get_setting('logo_link', true, true)): ?>
        <a href="/">
            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>">
        </a>
    <?php else: ?>
        <h3><?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></h3>
    <?php endif; ?>
</div>
```

**Why this pattern:**
- The site owner's logo is in `logo_link` setting (Admin → Settings)
- Template logos are never used in production
- Fallback to site name ensures graceful display
- Works for both header (navbar) and footer
- Header fallback: `<span>` for inline display
- Footer fallback: `<h3>` for heading hierarchy

**Setting the logo:**
- Admin → Settings → Logo Link
- Enter full URL path (e.g., `/uploads/mylogo.png` or `https://example.com/logo.png`)
- Can be relative or absolute URL

**IMPORTANT: Logo Visibility Considerations**

After integrating logos, always verify visibility:

1. **Check header/navbar background color** - Dark navbar with dark logo = invisible
2. **Check footer background color** - Light footer with light logo = invisible
3. **Test with actual logo file** - Don't rely on theme's bundled logos

**Common issues:**
- Black logo on black navbar background (invisible)
- White logo on white footer background (invisible)

**Solutions:**
- Use CSS filters to invert logo colors for dark backgrounds
- Provide separate logo settings (`logo_link_light`, `logo_link_dark`)
- Add CSS class to logo img and style per section
- Use SVG logos with CSS fill colors
- Recommend transparent PNG logos that work on any background

**Quick fix for Phillyzouk-style themes:**

Create `theme/[themename]/assets/css/joinery-custom.css` with theme-specific overrides:

```css
/* Joinery Custom Styles */

/* Fix logo visibility on dark navbar background - use high specificity */
.navbar-area .main-nav .navbar-brand img,
.navbar-area .mobile-nav .logo img,
.nav-area .navbar-brand img,
.nav-area .mobile-nav img {
    filter: brightness(0) invert(1) !important;
}

/* Ensure footer logo stays normal (light background) */
.footer-top-area img {
    filter: none !important;
}

/* Site name logo styling when no logo image is set */
.site-name-logo {
    font-size: 1.5rem;
    font-weight: 700;
    color: #ffffff;
    text-decoration: none;
}
```

**Note:** Use `!important` and high specificity selectors to ensure the filter overrides theme CSS.

Then include in PublicPage.php header (after main theme CSS):
```php
<!-- Joinery Custom CSS -->
<link rel="stylesheet" href="/theme/[themename]/assets/css/joinery-custom.css">
```

**Benefits of separate custom CSS file:**
- Keeps Joinery-specific overrides separate from theme CSS
- Easier to maintain and update
- Doesn't modify original theme files
- Can be version controlled independently

### Social Media Integration Pattern

When you see static social media links in source template, replace with this pattern:

```php
<div class="social-area">
    <ul>
        <?php if ($settings->get_setting('social_facebook_link')): ?>
        <li>
            <a href="<?php echo htmlspecialchars($settings->get_setting('social_facebook_link')); ?>" target="_blank">
                <i class="bx bxl-facebook"></i>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($settings->get_setting('social_twitter_link')): ?>
        <li>
            <a href="<?php echo htmlspecialchars($settings->get_setting('social_twitter_link')); ?>" target="_blank">
                <i class="bx bxl-twitter"></i>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($settings->get_setting('social_linkedin_link')): ?>
        <li>
            <a href="<?php echo htmlspecialchars($settings->get_setting('social_linkedin_link')); ?>" target="_blank">
                <i class="bx bxl-linkedin"></i>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($settings->get_setting('social_youtube_link')): ?>
        <li>
            <a href="<?php echo htmlspecialchars($settings->get_setting('social_youtube_link')); ?>" target="_blank">
                <i class="bx bxl-youtube"></i>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($settings->get_setting('social_instagram_link')): ?>
        <li>
            <a href="<?php echo htmlspecialchars($settings->get_setting('social_instagram_link')); ?>" target="_blank">
                <i class="bx bxl-instagram"></i>
            </a>
        </li>
        <?php endif; ?>

        <!-- Add other platforms as needed -->
    </ul>
</div>
```

**Note:** Only include platforms that make sense for the theme design. Don't force all 21 platforms if the design only has space for 5.

### Contact Information Pattern

Replace static contact information:

```php
<div class="contact-info">
    <?php if ($phone = $settings->get_setting('contact_phone', true, true)): ?>
    <div class="contact-item">
        <i class="bx bx-phone-call"></i>
        <span>Phone:</span>
        <?php echo htmlspecialchars($phone); ?>
    </div>
    <?php endif; ?>

    <?php if ($email = $settings->get_setting('defaultemail', true, true)): ?>
    <div class="contact-item">
        <i class="bx bx-envelope"></i>
        <span>Email:</span>
        <a href="mailto:<?php echo htmlspecialchars($email); ?>">
            <?php echo htmlspecialchars($email); ?>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($address = $settings->get_setting('contact_address', true, true)): ?>
    <div class="contact-item">
        <i class="bx bx-location-plus"></i>
        <span>Address:</span>
        <?php echo htmlspecialchars($address); ?>
    </div>
    <?php endif; ?>
</div>
```

### Quick Integration Checklist

When integrating settings into a new theme:

- [ ] Replace header logo with `logo_link` setting + site name fallback
- [ ] Replace footer logo with `logo_link` setting + site name fallback (as h3)
- [ ] Replace site name in logo alt text
- [ ] Replace site description in footer
- [ ] Add dynamic menu system (replace hardcoded nav)
- [ ] **Add shopping cart icon with item count badge**
- [ ] **Add user menu (login/logout/profile links)**
- [ ] Replace static social media links with conditional rendering
- [ ] Replace static contact info with settings + fallbacks
- [ ] Replace static copyright with dynamic year + site name
- [ ] Verify all settings use `htmlspecialchars()` for security
- [ ] Test with missing/empty settings to verify fallbacks work
- [ ] Test logo display with and without `logo_link` set
- [ ] **Check logo visibility on header background** (dark logo on dark bg = invisible)
- [ ] **Check logo visibility on footer background** (light logo on light bg = invisible)
- [ ] Add CSS filters if needed to ensure logo visibility

## Integrating Blog and Post Pages

If the source template includes blog listing and single post pages, integrate them into the theme.

### Step 1: Identify Blog Pages in Source Template

Look for these HTML files in the source template:
- Blog listing pages: `blog.html`, `full-width-blog.html`, `author.html`, `category.html`
- Single post pages: `post.html`, `post-style-*.html`, `blog-single.html`, `article.html`
- Note: `left-sidebar.html` and `right-sidebar.html` are usually single post pages with sidebars, NOT blog listings

```bash
# Find blog-related pages
find /path/to/source/template/ -name "*.html" | grep -iE "blog|post|article|author"
```

**CRITICAL: If multiple blog listing styles are found, ALWAYS ask the user which style to use before implementing.**

Common blog listing styles:
- **Full-width grid**: 3-4 column grid with vertical blog cards (e.g., `full-width-blog.html`)
- **Sidebar layout**: 8-column content + 4-column sidebar with horizontal blog cards (e.g., `author.html`)
- **List layout**: Single column with large horizontal cards
- **Masonry layout**: Variable height cards in grid

Example question to ask:
> "I found multiple blog listing styles in the template:
> 1. `full-width-blog.html` - Full-width 3-column grid with vertical cards
> 2. `author.html` - 8-column content + 4-column sidebar with horizontal cards
>
> Which layout would you like to use for the blog listing page?"

### Step 2: Review Core Blog Files

Joinery provides two core blog view files as references:

**`/views/blog.php`** - Blog listing page structure:
```php
<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

$page_vars = blog_logic($_GET, $_POST);
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

$page = new PublicPage();
$page->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $page_vars['title']
));

// Loop through $page_vars['posts'] and display blog listing

$page->public_footer();
?>
```

**`/views/post.php`** - Single post page structure:
```php
<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

$page_vars = post_logic($_GET, $_POST, $post);
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
$post = $page_vars['post'];

$page = new PublicPage();
$page->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $post->get('pst_title')
));

// Display single post content using $post object

$page->public_footer();
?>
```

### Step 3: Create Theme Blog Listing Page

Create `theme/[themename]/views/blog.php`:

1. **Start with core structure from `/views/blog.php`**
2. **Extract HTML from source template** (e.g., `full-width-blog.html`)
3. **Replace static blog items with PHP loop**

```php
<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('blog_logic.php', 'logic'));

$page_vars = blog_logic($_GET, $_POST);
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

$page = new PublicPage();
$page->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $page_vars['title']
));
?>

<!-- Extract blog listing HTML from source template -->
<section class="blog-area">
    <div class="container">
        <div class="row">
            <?php
            if (!$page_vars['posts']) {
                echo '<p>No posts found.</p>';
            } else {
                foreach ($page_vars['posts'] as $post) {
                    $author = new User($post->get('pst_usr_user_id'), TRUE);
                    $post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
                    ?>

                    <!-- Blog post card from source template -->
                    <div class="col-lg-4 col-md-6">
                        <div class="blog-card">
                            <div class="blog-image">
                                <a href="<?php echo $post->get_url(); ?>">
                                    <img src="https://via.placeholder.com/400x300"
                                         alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                </a>
                            </div>
                            <div class="blog-content">
                                <h3>
                                    <a href="<?php echo $post->get_url(); ?>">
                                        <?php echo htmlspecialchars($post->get('pst_title')); ?>
                                    </a>
                                </h3>
                                <div class="blog-meta">
                                    <span><?php echo date('F j, Y', strtotime($post->get('pst_published_time'))); ?></span>
                                    <span>By <?php echo htmlspecialchars($author->display_name()); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars(substr(strip_tags($post->get('pst_body')), 0, 150)) . '...'; ?></p>
                                <a href="<?php echo $post->get_url(); ?>" class="read-more">Read More</a>
                            </div>
                        </div>
                    </div>

                    <?php
                }
            }
            ?>
        </div>
    </div>
</section>

<?php
$page->public_footer();
?>
```

### Step 4: Create Theme Single Post Page

Create `theme/[themename]/views/post.php`:

1. **Start with core structure from `/views/post.php`**
2. **Extract HTML from source template** (e.g., `post-style-one.html`)
3. **Replace static content with dynamic post data**

```php
<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

$page_vars = post_logic($_GET, $_POST, $post);
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
$post = $page_vars['post'];

$page = new PublicPage();
$page->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $post->get('pst_title')
));
?>

<!-- Extract single post HTML from source template -->
<article class="post-single">
    <div class="container">
        <div class="post-header">
            <h1><?php echo htmlspecialchars($post->get('pst_title')); ?></h1>

            <div class="post-meta">
                <span class="author">
                    By <?php echo htmlspecialchars($page_vars['author']->display_name()); ?>
                </span>
                <span class="date">
                    <?php echo date('F j, Y', strtotime($post->get('pst_published_time'))); ?>
                </span>
            </div>

            <!-- Tags -->
            <div class="post-tags">
                <?php foreach ($page_vars['tags'] as $tag): ?>
                    <a href="/blog/tag/<?php echo urlencode($tag); ?>" class="tag">
                        <?php echo htmlspecialchars($tag); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="post-content">
            <?php echo $post->get('pst_body'); ?>
        </div>

        <?php if ($page_vars['settings']->get_setting('blog_footer_text')): ?>
        <div class="post-footer">
            <?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
        </div>
        <?php endif; ?>
    </div>
</article>

<?php
$page->public_footer();
?>
```

### Available Blog Variables

**In `blog.php` (from `blog_logic()`):**
- `$page_vars['posts']` - Array of Post objects
- `$page_vars['title']` - Page title
- `$page_vars['tag']` - Current tag filter (if any)
- `$page_vars['settings']` - Globalvars instance

**In `post.php` (from `post_logic()`):**
- `$page_vars['post']` - Post object (also available as `$post`)
- `$page_vars['author']` - User object for post author
- `$page_vars['tags']` - Array of tag names for this post
- `$page_vars['settings']` - Globalvars instance

### Post Object Methods

```php
// Post data
$post->get('pst_title')          // Post title
$post->get('pst_body')           // Post content (HTML)
$post->get('pst_published_time') // Published timestamp
$post->get('pst_usr_user_id')    // Author user ID
$post->get_url()                 // Post URL (/post/slug)

// Author data
$author = new User($post->get('pst_usr_user_id'), TRUE);
$author->display_name()          // Author display name
$author->get('usr_email')        // Author email

// Tags
$post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
```

### Blog Integration Checklist

When adding blog pages to a theme:

- [ ] Check if source template has blog listing page(s)
- [ ] Check if source template has single post page(s)
- [ ] Read core `/views/blog.php` for structure reference
- [ ] Read core `/views/post.php` for structure reference
- [ ] Create `theme/[themename]/views/blog.php` with theme HTML
- [ ] Create `theme/[themename]/views/post.php` with theme HTML
- [ ] **CRITICAL: Set file permissions to 644** (`chmod 644 theme/[themename]/views/*.php`)
- [ ] Replace static blog items with `foreach ($page_vars['posts'] as $post)` loop
- [ ] Replace static post content with `$post->get()` methods
- [ ] Update all asset paths to `/theme/[themename]/assets/`
- [ ] Validate PHP syntax with `php -l` on both files
- [ ] Run method existence test on both files
- [ ] Test blog listing at `/blog` URL
- [ ] Test single post at `/post/[slug]` URL
- [ ] Verify pagination works (if implemented)
- [ ] Verify tag filtering works at `/blog/tag/[tagname]`

### Common Blog Patterns

**Excerpt generation:**
```php
<?php echo htmlspecialchars(substr(strip_tags($post->get('pst_body')), 0, 150)) . '...'; ?>
```

**Date formatting:**
```php
<?php echo date('F j, Y', strtotime($post->get('pst_published_time'))); ?>
```

**Tag links:**
```php
<?php foreach ($page_vars['tags'] as $tag): ?>
    <a href="/blog/tag/<?php echo urlencode($tag); ?>">
        <?php echo htmlspecialchars($tag); ?>
    </a>
<?php endforeach; ?>
```

**Author display:**
```php
<?php
$author = new User($post->get('pst_usr_user_id'), TRUE);
echo htmlspecialchars($author->display_name());
?>
```

### Note on Blog Routes

Blog and post pages are already configured in `/serve.php`:
```php
'/blog/*' => function(...) // Handles /blog and /blog/tag/[tagname]
'/post/{slug}' => ['model' => 'Post', ...] // Handles /post/[slug]
```

No routing changes needed - just create the view files.

## Utility Pages (Login, 404, etc.)

**Important:** You do NOT need to create theme-specific versions of utility pages. These pages will automatically inherit the base PublicPageBase styles from your theme's `PublicPage.php` file.

The following pages already have implementations in the core system and will use your theme's header/footer/styling automatically:
- Login/Sign-in pages (`/login`, `/signup`)
- Logout (`/logout`)
- 404 error page (page not found)
- Password recovery (`/recover-password`)
- Coming soon page
- Privacy policy, Terms & conditions, etc.
- **Shopping cart pages** (`/cart`, `/checkout`) - Uses reference cart page designs by default

**Why?** These utility pages use a different routing pattern in `/serve.php` that doesn't look for theme-specific view files. They render using the core views with your theme's CSS and header/footer, which is usually sufficient.

### Cart Pages (Default Behavior)

By default, shopping cart pages (`/cart`, `/checkout`) will use the reference cart page implementations in the core system. These pages are styled with your theme's CSS framework and use your theme's header/footer.

**When to create custom cart versions:** Only create `theme/[themename]/views/` versions of cart pages if:
- You need a completely different cart layout or checkout flow
- The default cart styling doesn't match your theme's design requirements
- You want to customize the shopping experience significantly

For most themes, using the default cart pages with your theme's styling is recommended to maintain consistency with the Joinery platform's e-commerce functionality.

**When to create custom versions of other utility pages:** Only create `theme/[themename]/views/` versions if:
- You need completely different HTML structure than the default
- The default styling doesn't match your theme's design
- You want to customize the layout significantly

For most themes, the default utility pages styled with your theme's CSS framework are perfectly adequate.

## HTML5 Zero-Dependency Themes

This section covers the specific workflow for creating Joinery themes that use **zero external dependencies** — no Bootstrap, no jQuery, no icon fonts. Everything is vanilla CSS + vanilla JS.

This approach was proven with the `jeremytunnell-html5` (based on Typology) and `phillyzouk-html5` (based on Linka) themes.

### When to Use HTML5 vs Bootstrap

| Criteria | HTML5 Theme | Bootstrap Theme |
|----------|-------------|-----------------|
| Source template | Static HTML/CSS conversion (no framework) | Commercial Bootstrap template |
| CSS framework | None — theme provides all CSS | Bootstrap loaded in header |
| JavaScript | Vanilla JS only | jQuery + Bootstrap JS available |
| Icon system | Unicode characters or inline SVG | Boxicons, Font Awesome, etc. |
| Form styling | Theme must style `.form-group`, `.form-control`, etc. | Bootstrap provides form styles |
| Grid system | Theme CSS provides own grid rules | Bootstrap grid available |
| File size | Very small (one CSS + one JS file) | Larger (framework + plugins) |

### HTML5 Theme Prerequisites

Before creating an HTML5 Joinery theme, you need **static HTML/CSS source files** — a clean-room conversion of a commercial template into zero-dependency HTML5. These are stored in `/home/user1/theme-sources/` (e.g., `canvas-html5/`, `linka-html5/`).

The source files typically include:
- `index.html` — Homepage with navbar, hero, content sections, footer
- `style.css` — All base styles (grid, navbar, footer, typography, etc.)
- `script.js` — Vanilla JS for interactive elements (menu toggle, scroll-to-top, etc.)
- Additional page HTMLs (e.g., `right-sidebar.html` for blog, `post-style-one.html` for single posts) — **these often have page-specific CSS as inline `<style>` blocks**

### Step-by-Step: HTML5 Theme Creation

Follow the same 10-step process from the main guide above, with these specific differences:

#### 1. Configuration Files

**theme.json** — Use `"html5"` framework and `"FormWriterV2HTML5"` base:
```json
{
    "name": "mytheme-html5",
    "display_name": "My Theme HTML5",
    "version": "1.0.0",
    "description": "Clean HTML5 theme based on [source] design, zero dependencies",
    "author": "Joinery Team",
    "receives_upgrades": true,
    "included_in_publish": true,
    "cssFramework": "html5",
    "formWriterBase": "FormWriterV2HTML5",
    "publicPageBase": "PublicPageBase"
}
```

**FormWriter.php** — Extend `FormWriterV2HTML5`:
```php
<?php
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

class FormWriter extends FormWriterV2HTML5 {
}
?>
```

**serve.php** — HTML5 themes should have their own serve.php for custom routes:
```php
<?php
$routes = [
    'dynamic' => [
        '/blog' => ['view' => 'views/blog'],
        '/blog/tag/{tag}' => ['view' => 'views/blog'],
        '/post/{slug}' => ['model' => 'Post', 'model_file' => 'data/posts_class'],
        '/events' => ['view' => 'views/events'],
    ],
    'custom' => [],
];
?>
```

#### 2. CSS Consolidation (Critical)

The static HTML5 source files typically have page-specific styles as inline `<style>` blocks in each HTML file. You must **extract and merge all inline CSS into a single `style.css`** file.

**Process:**
1. Start with the base `style.css` from the source (contains grid, navbar, footer, typography)
2. Open each additional HTML file (blog listing, single post, etc.)
3. Copy each `<style>` block's contents into your theme's `style.css`
4. Add comments to separate sections (e.g., `/* ===== BLOG LISTING ===== */`)

**Example — extracting from `right-sidebar.html`:**
```css
/* ===== BLOG LISTING (from right-sidebar.html) ===== */
.single-blog-post { ... }
.blog-image { ... }
.blog-content { ... }
.blog-category { ... }
.blog-meta { ... }
```

#### 3. Required Utility CSS Classes

Bootstrap themes get utility classes for free. HTML5 themes must define them in CSS. These are used throughout Joinery views (comments, forms, event cards, etc.):

```css
/* ===== UTILITY CLASSES (required for Joinery views) ===== */

/* Spacing */
.mb-1 { margin-bottom: 0.25rem; }
.mb-2 { margin-bottom: 0.5rem; }
.mb-3 { margin-bottom: 1rem; }
.mb-4 { margin-bottom: 1.5rem; }
.mb-5 { margin-bottom: 3rem; }
.mt-3 { margin-top: 1rem; }
.mt-4 { margin-top: 1.5rem; }
.p-5 { padding: 3rem; }
.pt-100 { padding-top: 100px; }
.pb-70 { padding-bottom: 70px; }
.ptb-100 { padding-top: 100px; padding-bottom: 100px; }

/* Text */
.text-center { text-align: center; }
.text-muted { color: #6c757d; }
.small { font-size: 0.875em; }

/* Badges */
.badge { display: inline-block; padding: 3px 8px; font-size: 12px; font-weight: 600; border-radius: 3px; }
.bg-danger { background-color: #dc3545; color: #fff; }

/* Grid (provide columns your views use) */
.row { display: flex; flex-wrap: wrap; margin: 0 -15px; }
.row > [class*="col-"] { padding: 0 15px; }
.col-lg-3 { width: 25%; }
.col-lg-4 { width: 33.333%; }
.col-lg-6 { width: 50%; }
.col-lg-8 { width: 66.666%; }
.col-md-6 { width: 50%; }

@media (max-width: 991px) {
    .col-lg-3, .col-lg-4, .col-lg-6, .col-lg-8 { width: 100%; }
}
@media (max-width: 767px) {
    .col-md-6 { width: 100%; }
}
```

#### 4. Form Styling CSS

`FormWriterV2HTML5` generates forms with these CSS classes that your theme must style:

```css
/* ===== FORM STYLES (for FormWriterV2HTML5) ===== */
.form-group { margin-bottom: 1rem; }

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    font-size: 14px;
}

.form-control {
    display: block;
    width: 100%;
    padding: 10px 15px;
    font-size: 14px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
    transition: border-color 0.3s;
}
.form-control:focus {
    border-color: #d80650;  /* Use your theme's primary color */
    outline: none;
}

textarea.form-control { min-height: 100px; resize: vertical; }

.form-check { margin-bottom: 0.5rem; }
.form-check label { display: flex; align-items: center; gap: 8px; cursor: pointer; }

/* Submit buttons */
.btn { display: inline-block; padding: 10px 25px; font-size: 14px; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; transition: all 0.3s; }
.btn-submit, .btn-primary { background: #d80650; color: #fff; }
.btn-submit:hover, .btn-primary:hover { background: #b8053f; }

/* Validation states */
.is-invalid { border-color: #dc3545; }
.invalid-feedback { color: #dc3545; font-size: 13px; margin-top: 4px; }
```

#### 5. Icon Replacement (Icon Fonts → Unicode)

Bootstrap themes use icon fonts (Boxicons, Font Awesome). HTML5 themes use Unicode characters instead.

**Common replacements:**

| Purpose | Boxicons | Unicode | Code |
|---------|----------|---------|------|
| Calendar | `bx bx-calendar` | &#128197; | `&#128197;` |
| Clock | `bx bx-time` | &#128336; | `&#128336;` |
| User/Person | `bx bx-user` | &#128100; | `&#128100;` |
| People | `bx bx-group` | &#128101; | `&#128101;` |
| Heart | `bx bx-heart` | &#10084; | `&#10084;` |
| Music | `bx bx-music` | &#127925; | `&#127925;` |
| Phone | `bx bx-phone` | &#128222; | `&#128222;` |
| Email | `bx bx-envelope` | &#9993; | `&#9993;` |
| Location | `bx bx-map` | &#128205; | `&#128205;` |
| Arrow left | `bx bx-left-arrow` | &#8592; | `&#8592;` |
| Arrow right | `bx bx-right-arrow` | &#8594; | `&#8594;` |
| Arrow up | `bx bx-up-arrow` | &#8679; | `&#8679;` |
| Dropdown | `bx bx-chevron-down` | &#9660; | `&#9660;` |
| Close | `bx bx-x` | &times; | `&times;` |
| Menu/hamburger | `bx bx-menu` | &#9776; | `&#9776;` |

**Social media in footer** — Use text abbreviations instead of icon fonts:
```php
<!-- Facebook -->
<li><a href="..." target="_blank">f</a></li>
<!-- Twitter -->
<li><a href="..." target="_blank">t</a></li>
<!-- LinkedIn -->
<li><a href="..." target="_blank">in</a></li>
<!-- Instagram -->
<li><a href="..." target="_blank">ig</a></li>
<!-- YouTube -->
<li><a href="..." target="_blank">&#9654;</a></li>
```

To prevent color in emoji icons, use: `style="filter: grayscale(1);"`

#### 6. jQuery → Vanilla JS Patterns

Any interactive behavior from the Bootstrap theme must be rewritten in vanilla JS.

**Mobile menu toggle:**
```html
<script>
    const menuToggle = document.querySelector('.menu-toggle');
    const mainNav = document.querySelector('.main-nav');
    if (menuToggle && mainNav) {
        menuToggle.addEventListener('click', function() {
            mainNav.classList.toggle('open');
        });
    }
</script>
```

With CSS:
```css
/* Mobile: hide desktop nav, show toggle */
@media (max-width: 991px) {
    .main-nav { display: none; }
    .main-nav.open { display: block; }
    .mobile-nav { display: flex; }
}

/* Desktop: show nav, hide toggle */
@media (min-width: 992px) {
    .main-nav { display: block; }
    .mobile-nav { display: none; }
}
```

**Comment reply toggle** (replaces jQuery `.slideToggle()`):
```html
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.comment-reply-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var commentId = this.getAttribute('data-comment-id');
            var formContainer = document.getElementById('reply-form-' + commentId);
            if (formContainer) {
                formContainer.style.display =
                    formContainer.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
});
</script>
```

**Sidebar panel toggle** (for sidebar-based navigation designs):
```javascript
const toggle = document.getElementById('sidebarToggle');
const overlay = document.getElementById('sidebarOverlay');
const panel = document.getElementById('sidebarPanel');
const close = document.getElementById('sidebarClose');
function openSidebar() { overlay.classList.add('active'); panel.classList.add('active'); }
function closeSidebar() { overlay.classList.remove('active'); panel.classList.remove('active'); }
if (toggle) toggle.addEventListener('click', openSidebar);
if (overlay) overlay.addEventListener('click', closeSidebar);
if (close) close.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
```

#### 7. PublicPage.php Structure

HTML5 PublicPage.php follows the same structure as Bootstrap themes — the only difference is:
- Load **one CSS file** (your consolidated `style.css`) instead of multiple framework files
- Load **one JS file** (your `script.js`) instead of jQuery + Bootstrap + plugins
- Use `$this->global_includes_top($options)` in `<head>` for system meta tags, tracking, and base assets
- Include mobile menu toggle JS inline in the footer (after `script.js`)

**Base Asset Loading:**

`PublicPageBase::global_includes_top()` calls `$this->render_base_assets()` which loads `base.css`, `joinery-styles.css`, and `base.js`. These are safe to load on every page — component rules are scoped to `.jy-ui` and global type rules are scoped to `body.jy-default`, so they do not conflict with branded theme CSS. **Do not override `render_base_assets()` to suppress them.**

Immediately after `render_base_assets()`, `global_includes_top()` calls `render_brand_token_overrides()`, which outputs a `<style id="jy-brand-tokens">` block if the admin has configured any `jy_color_*` settings (Brand & Appearance section in admin settings). This block overrides the `:root` token defaults from `joinery-styles.css` with site-wide brand colors. Themes that want to enforce their own palette ahead of the admin settings should load their own token overrides **after** `global_includes_top()` — source order guarantees they win.

**Key pattern — always include:**
```php
public function public_header($options=array()) {
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();
    $menu_data = $this->get_menu_data();
    // ... prepare variables, then output HTML:
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $options = parent::public_header_common($options); // CRITICAL: call inside <head> ?>
    <meta charset="utf-8">
    <!-- ... rest of head -->
</head>
    <?php
}
```

**BeginPage/EndPage** — Use your theme's content wrapper classes:
```php
public static function BeginPage($title='', $options=array()) {
    $output = '<section class="post-detail-area"><div class="container"><div class="post-content">';
    if ($title) {
        $output .= '<div class="post-header"><h1>' . htmlspecialchars($title) . '</h1></div>';
    }
    $output .= '<div class="post-body">';
    return $output;
}

public static function EndPage($options=array()) {
    return '</div></div></div></section>';
}
```

#### 8. Views — What to Copy vs Rewrite

When creating HTML5 theme views from an existing Bootstrap theme:

| File | Approach |
|------|----------|
| **Logic files** (`index_logic.php`, etc.) | Copy verbatim — no framework dependency |
| **Views** (`index.php`, `blog.php`, etc.) | Rewrite HTML structure using source template classes, keep all PHP logic |
| **PublicPage.php** | Full rewrite — HTML structure from source, PHP patterns from existing theme |
| **FormWriter.php** | Trivial — just change the extends |
| **theme.json** | New file — change 3 values |
| **serve.php** | Copy from existing theme or create new |

**When rewriting views**, the PHP logic (loops, conditionals, model access) stays the same — only the HTML wrapper classes change:

```php
<!-- Bootstrap theme -->
<div class="col-lg-4 col-md-6">
    <div class="single-blog-post card">
        <img class="card-img-top" ...>

<!-- HTML5 theme — same PHP, different CSS classes -->
<div class="col-lg-4 col-md-6">
    <div class="single-blog-post">
        <div class="blog-image"><img ...></div>
```

#### 9. Events View — Virtual Recurring Instances

The events page requires special handling for **virtual recurring event instances** — these are generated at runtime and use property access instead of `get()` methods. This pattern is identical in Bootstrap and HTML5 themes:

```php
$is_virtual = (is_object($event) && isset($event->is_virtual) && $event->is_virtual);

// Dual accessor pattern — required for both real and virtual events
$evt_name = $is_virtual ? $event->evt_name : $event->get('evt_name');
$evt_start_time = $is_virtual ? $event->evt_start_time : $event->get('evt_start_time');
$evt_link = $is_virtual ? $event->evt_link : $event->get('evt_link');
$evt_tz = $is_virtual ? ($event->evt_timezone ?: 'America/New_York') : ($event->get('evt_timezone') ?: 'America/New_York');

// Virtual instances have instance_date for URL construction
if ($is_virtual) {
    $event_url = '/event/' . $evt_link . '/' . $event->instance_date;
} else {
    $event_url = $event->get_url();
}
```

#### 10. Comment System CSS

The post page's comment system requires these styles:

```css
/* ===== COMMENTS ===== */
.comments-section { margin-top: 40px; padding-top: 30px; border-top: 1px solid #eee; }
.comments-title { margin-bottom: 20px; }

.comment { padding: 20px 0; border-bottom: 1px solid #f0f0f0; }
.comment-author { font-weight: 600; font-size: 15px; }
.comment-date { font-size: 13px; color: #999; margin-bottom: 8px; }
.comment-text { font-size: 14px; line-height: 1.6; }

.comment-reply { margin-left: 30px; border-left: 2px solid #f0f0f0; padding-left: 20px; }
.comment-reply-link { font-size: 13px; color: #d80650; cursor: pointer; }

.reply-form-container { margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 5px; }

.comment-form { margin-top: 40px; padding-top: 30px; border-top: 1px solid #eee; }
.comment-form h3 { margin-bottom: 20px; }
```

### HTML5 Theme Checklist (Additions to Main Checklist)

Beyond the standard validation checklist, HTML5 themes must verify:

- [ ] `theme.json` has `cssFramework: "html5"` and `formWriterBase: "FormWriterV2HTML5"`
- [ ] `FormWriter.php` extends `FormWriterV2HTML5` (not `FormWriterV2Bootstrap`)
- [ ] All inline CSS from source HTML files extracted into single `style.css`
- [ ] Utility CSS classes defined (`.mb-*`, `.mt-*`, `.text-center`, `.text-muted`, `.badge`, etc.)
- [ ] Grid CSS defined (`.row`, `.col-lg-*`, `.col-md-*` with responsive breakpoints)
- [ ] Form CSS defined (`.form-group`, `.form-control`, `.form-label`, `.btn`, `.btn-submit`)
- [ ] Comment CSS defined (`.comment`, `.comment-reply`, `.reply-form-container`, `.comment-form`)
- [ ] No jQuery references anywhere — all JS is vanilla
- [ ] No icon font references — all icons are Unicode or inline SVG
- [ ] Mobile menu works with vanilla JS toggle (no Bootstrap collapse)
- [ ] `global_includes_top()` called in `<head>` section of PublicPage.php (loads meta tags + base assets)
- [ ] `$options = parent::public_header_common($options)` called inside `<head>` in `public_header()` (injects admin bar, tracking, settings defaults)
- [ ] Comment reply toggle uses vanilla JS `addEventListener` (not jQuery)
- [ ] Single consolidated CSS file loads in header (not multiple framework files)

### Existing HTML5 Theme References

Use these as working examples when creating new HTML5 themes:

| Theme | Source Design | Key Features |
|-------|--------------|--------------|
| `jeremytunnell-html5` | Typology | Sidebar panel navigation, minimal blog design, drop-cap letters |
| `phillyzouk-html5` | Linka | Traditional navbar, event listings, full comment system, newsletter signup |

## Lessons Learned from Phillyzouk Implementation

### What Went Wrong Initially

1. **Wrong parent class:** Initially tried extending PublicPageFalcon instead of PublicPageBase
2. **Missing abstract method:** Forgot to implement getTableClasses() causing fatal error
3. **Permission issues:** Files created with 0600 permissions, web server couldn't read them
4. **Placeholder content:** Used generic HTML instead of actual theme layout
5. **Wrong footer structure:** Used simple footer instead of multi-section theme footer

### What Fixed It

1. **Correct inheritance:** Extended PublicPageBase and implemented all abstract methods
2. **Permission fix:** Applied 644 to files and 755 to directories before testing
3. **Actual HTML:** Extracted real homepage layout from source template (index-3.html)
4. **Matching CSS structure:** Used exact CSS classes from theme (footer-top-area, single-widget, etc.)

### Time Savers

1. **Copy only what you need:** home-three images only = 400KB vs all images = 2.4MB
2. **Fix permissions immediately:** Don't wait until errors occur
3. **Use theme's exact structure:** Don't simplify or "improve" the HTML
4. **Test with browser console open:** Catch 404s and JS errors immediately

## Canonical URLs (SEO)

The system automatically adds canonical URL tags to all public pages. It uses your `webDir` setting as configured and strips pagination parameters (`offset`, `page`, `page_offset`, `p`). No additional configuration needed. See the implementation in `PublicPageBase::get_canonical_url()` (includes/PublicPageBase.php:429-459).

## Converting Bootstrap/jQuery Themes to Zero-Dependency HTML5

These lessons apply to any theme conversion where you are stripping Bootstrap, jQuery, or other
framework dependencies from an existing theme to produce a zero-dependency HTML5 version.

### Visual Verification Strategy

**Full-page screenshot comparison with ImageMagick is the most accurate way to verify visual parity.**
Don't rely on spot-checking individual sections — take full-page screenshots of both versions and
diff them:

```bash
# Take full-page screenshots of both themes (via Playwright, browser, etc.)
# Then compare with ImageMagick:
compare original.png converted.png diff.png
# Or get a numeric similarity score:
magick compare -metric RMSE original.png converted.png /dev/null 2>&1
```

File size of full-page PNGs is also a quick proxy — if the original is 427,976 bytes and the
conversion is 427,975 bytes, the pages are nearly pixel-identical.

**Do section-by-section verification at a fixed viewport width (e.g. 1280px)** by scrolling to
specific pixel offsets and screenshotting. This catches spacing/padding issues that full-page
thumbnails compress away.

### The "Invisible Dependency" Problem

The most dangerous category of bugs is **classes that silently do nothing** when their CSS definition
is missing. No error appears — the page just looks slightly wrong.

Commercial theme CSS files typically depend on Bootstrap (or Tailwind) for utility classes but
don't document that dependency. When you strip Bootstrap, things like `.text-center`, `.d-flex`,
`.container`, `.btn`, `.mb-4` silently stop working because the theme CSS never defined them
— Bootstrap did.

**How to find these systematically:**
1. Grep all view templates for `class="..."` and extract every class name used
2. Grep the theme's own CSS for each class name
3. Any class that appears in templates but NOT in theme CSS was being provided by Bootstrap
4. Build a `responsive-utils.css` (or equivalent) defining only the missing classes

**Common categories of missing classes:**

| Category | Examples | Impact when missing |
|----------|---------|---------------------|
| Container/grid | `.container`, `.row`, `.col-*` | Content touches edges, columns stack |
| Display | `.d-flex`, `.d-none`, `.d-block` | Layout breaks |
| Flex | `.align-items-center`, `.justify-content-between` | Alignment off |
| Spacing | `.mb-4`, `.mt-3`, `.px-4`, `.py-2` | Spacing wrong throughout |
| Text | `.text-center`, `.text-sm` | Centering and sizing lost |
| Buttons | `.btn`, `.btn-primary` | Submit buttons are unstyled browser defaults |
| Cards | `.border`, `.rounded`, `.shadow` | Card appearance missing |

**Watch for CSS variables too.** Bootstrap defines variables like `--bs-gutter-x` and `--bs-gutter-y`
that theme CSS references in `calc()` expressions. Without the variable definition, the calc
evaluates to 0 and gutters disappear.

### Tailwind/Bootstrap Class Name Mixing

View templates often use a mix of Bootstrap and Tailwind class names (e.g. Bootstrap's
`align-items-center` alongside Tailwind's `items-center`, or `d-flex` alongside `flex`).
Always scan templates for BOTH naming conventions and define aliases:

```css
/* Bootstrap name */
.d-flex          { display: flex !important; }
/* Tailwind name — same effect, different class */
.flex            { display: flex !important; }
```

### jQuery → Vanilla JS: What Actually Changes

A ~1,100-line jQuery `main.js` typically collapses to ~220 lines of vanilla JS. The reduction
comes from removing jQuery wrapper overhead, not from removing features.

**Things that have direct 1:1 vanilla equivalents** (just translate):
- DOM selection, class toggling, event binding, attribute access, style changes

**Things that need a different approach entirely:**
- **Scroll-triggered animations:** Replace jQuery scroll-position math with `IntersectionObserver`
- **Popup video (Magnific Popup, lightbox, etc.):** Replace with native `<dialog>` + `showModal()`
- **Counter animations:** `IntersectionObserver` + `requestAnimationFrame`
- **Form validation (jQuery Validate):** The Joinery `JoineryValidation` system handles this

**Things that silently break without jQuery (no console error):**
- `$(document).ready()` calls — the function just never runs, no error thrown
- Any `$('.selector')` usage — `$` is undefined but may be swallowed by try/catch in other scripts
- These are the hardest to find because there's zero visible error. Search ALL view files for `$(`
  and `jQuery(` after removing jQuery.

### Cache Busting Is Non-Negotiable

CSS/JS files are often served with aggressive `max-age` headers (12+ hours). After ANY change to
a CSS or JS file, bump its `?v=N` query string in the `<link>` or `<script>` tag — otherwise the
old cached version will persist and you'll think your fix didn't work. This has caused multiple
hours of debugging changes that "didn't take effect."

### CSS Dead Section Removal

Large commercial theme CSS files (20,000+ lines) contain huge sections for features you'll
never use (WooCommerce, products, wishlists, etc.). These are safe to remove:

**How to identify dead sections:** Search the CSS for section headers (usually `/*--- Section Name ---*/`),
then grep your templates for any class defined in that section. If no template uses any class from
a section, the whole section is dead.

**Python line-range removal** is cleaner than sed for multi-thousand-line deletions:
```bash
python3 -c "
lines = open('style.css').readlines()
keep = lines[:start] + lines[end:]  # 0-indexed line numbers
open('style.css', 'w').writelines(keep)
"
```

### FormWriter API Gotcha

When converting views from Bootstrap-era FormWriter, the `checkboxinput()` argument order changed:
- **Old API:** `(label, name, value, type, checked, unchecked, extra)` — 7 args, label first
- **Current V2 API:** `(name, label, options)` — 3 args, name first

If a checkbox shows its field name (e.g. "setcookie") instead of its label, the args are swapped.

### Plugin Database Tables

`update_database.php` does NOT create plugin tables by default (`include_plugins: false`).
After cloning or creating a new plugin, create its tables with:
```bash
php -r "
require_once('includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
\$u = new DatabaseUpdater();
\$u->runPluginTablesOnly('plugin-name');
"
```

## Final Tips

1. Start with a simple layout first (homepage only)
2. Test frequently during development
3. Keep the browser console open to catch errors early
4. Use working themes as references:
   - **Bootstrap themes:** `/theme/phillyzouk/`
   - **HTML5 themes:** `/theme/phillyzouk-html5/` or `/theme/jeremytunnell-html5/`
5. Document any theme-specific quirks in theme.json description
6. For HTML5 themes, see the [HTML5 Zero-Dependency Themes](#html5-zero-dependency-themes) section

This guide should enable successful theme integration following the proven Phillyzouk pattern.

## See Also

- [Creating Components from Themes](/docs/creating_components_from_themes.md) - Extract theme sections into reusable components
- [Component Preview Utility](/specs/implemented/component_preview_utility.md) - Test components with placeholder data
- [Component System Documentation](/docs/component_system.md) - Full component system reference
- [Plugin Developer Guide](/docs/plugin_developer_guide.md) - Themes, plugins, and routing details