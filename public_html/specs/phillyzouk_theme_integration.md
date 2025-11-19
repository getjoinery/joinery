# Phillyzouk Modern Blog Theme Integration Specification

## Overview

Integrate the Linka Modern Blog & Magazine HTML template into the Joinery platform as a new theme named Phillyzouk. The Phillyzouk theme is a modern, responsive Bootstrap-based template designed for blog and magazine-style content with multiple homepage layouts and comprehensive page types.

**Status:** Pending Implementation
**Theme Name:** phillyzouk
**Source:** `/home/user1/joinery/linka-modern-blog-template/linka/`
**Framework:** Bootstrap 5
**Template Type:** Modern Blog & Magazine

## Source Template Analysis

### Theme Characteristics
- **Framework:** Bootstrap 5
- **CSS Preprocessor:** SCSS (source files available)
- **Primary Styling:** Custom CSS with Bootstrap foundation
- **JavaScript Dependencies:** jQuery, OWL Carousel, Magnific Popup, WOW.js, MeanMenu
- **Layout Variants:** 6 homepage layouts (lifestyle, technology, business, newspaper, fashion, travel)
- **Page Types:** 30+ HTML templates covering blogs, gallery, shop, authentication, contact, etc.

### Asset Structure
```
linka/
├── assets/
│   ├── css/          (13 CSS files including framework libraries)
│   ├── js/           (11 JS files including libraries and custom scripts)
│   ├── img/          (14 subdirectories with organized image categories)
│   ├── fonts/        (Font files)
│   └── php/          (Contains form processing scripts)
├── 35 HTML template files (index, posts, pages, e-commerce, auth pages)
```

### Key CSS Files
- `bootstrap.min.css` - Bootstrap framework
- `owl.carousel.min.css`, `owl.theme.default.min.css` - Carousel library
- `magnific-popup.min.css` - Lightbox/modal library
- `animate.min.css` - Animation library
- `boxicons.min.css` - Icon library
- `flaticon.css` - Flaticon library
- `meanmenu.min.css` - Mobile menu library
- `nice-select.min.css` - Select dropdown styling
- `style.css` (main theme styles)
- `responsive.css` (responsive breakpoints)

### Key JavaScript Files
- `bootstrap.bundle.min.js` - Bootstrap functionality
- `jquery.min.js` - jQuery library
- `owl.carousel.min.js` - Carousel functionality
- `magnific-popup.min.js` - Lightbox functionality
- `wow.min.js` - Scroll animation library
- `meanmenu.min.js` - Mobile menu functionality
- `nice-select.min.js` - Select dropdown enhancement
- `contact-form-script.js` - Form submission handler
- `custom.js` - Custom theme JavaScript

### Image Categories
- `blog-img/` - Blog post images
- `home-one/`, `home-two/`, etc. - Homepage layout variants
- Icon and supplementary images for various sections

## Single-Phase Integration Plan

All integration tasks will be completed in one comprehensive phase. The following section outlines the complete directory structure and all files that will be created.

### Complete Theme Directory Structure

Create the theme directory structure in `theme/phillyzouk/` (relative to public_html root):

```
theme/phillyzouk/
├── assets/
│   ├── css/
│   │   ├── bootstrap.min.css
│   │   ├── owl.carousel.min.css
│   │   ├── owl.theme.default.min.css
│   │   ├── magnific-popup.min.css
│   │   ├── animate.min.css
│   │   ├── boxicons.min.css
│   │   ├── flaticon.css
│   │   ├── meanmenu.min.css
│   │   ├── nice-select.min.css
│   │   ├── style.css
│   │   └── responsive.css
│   ├── js/
│   │   ├── bootstrap.bundle.min.js
│   │   ├── jquery.min.js
│   │   ├── owl.carousel.min.js
│   │   ├── magnific-popup.min.js
│   │   ├── wow.min.js
│   │   ├── meanmenu.min.js
│   │   ├── nice-select.min.js
│   │   ├── ajaxchimp.min.js
│   │   ├── form-validator.min.js
│   │   ├── contact-form-script.js
│   │   └── custom.js
│   ├── images/
│   │   ├── blog-img/
│   │   ├── home-one/
│   │   ├── home-two/
│   │   ├── home-three/
│   │   ├── home-four/
│   │   ├── home-five/
│   │   ├── home-six/
│   │   ├── logo.png
│   │   ├── favicon.png
│   │   └── [all other images]
│   └── fonts/
│       └── [font files]
├── includes/
│   ├── FormWriter.php
│   └── PublicPage.php
├── views/
│   └── index.php
└── theme.json
```

### Complete theme.json Configuration

```json
{
    "name": "phillyzouk",
    "display_name": "Phillyzouk Modern Blog",
    "version": "1.0.0",
    "description": "Modern and responsive blog & magazine theme",
    "author": "Joinery Team",
    "cssFramework": "bootstrap",
    "formWriterBase": "FormWriterBootstrap",
    "publicPageBase": "PublicPageBase"
}
```

## Complete Implementation Steps

All tasks below will be executed in a single implementation phase to integrate the complete theme.

### Step 1: Asset Preparation and Copying

Copy all assets from source template:

```bash
# Create directory structure
mkdir -p /var/www/html/joinerytest/public_html/theme/phillyzouk/{assets/{css,js,images,fonts},includes,views}

# Copy CSS files
cp -r /home/user1/joinery/linka-modern-blog-template/linka/assets/css/* \
  /var/www/html/joinerytest/public_html/theme/phillyzouk/assets/css/

# Copy JavaScript files
cp -r /home/user1/joinery/linka-modern-blog-template/linka/assets/js/* \
  /var/www/html/joinerytest/public_html/theme/phillyzouk/assets/js/

# Copy images (reorganized under images/ instead of img/)
# For index.php using home-three style, copy home-three and essential images
cp -r /home/user1/joinery/linka-modern-blog-template/linka/assets/img/home-three \
  /var/www/html/joinerytest/public_html/theme/phillyzouk/assets/images/

# Copy essential root-level images (logo, favicon, etc.)
cp /home/user1/joinery/linka-modern-blog-template/linka/assets/img/logo.png \
  /home/user1/joinery/linka-modern-blog-template/linka/assets/img/favicon.png \
  /home/user1/joinery/linka-modern-blog-template/linka/assets/img/black-logo.png \
  /var/www/html/joinerytest/public_html/theme/phillyzouk/assets/images/

# Optional: Copy other image directories if needed for future expansion
# cp -r /home/user1/joinery/linka-modern-blog-template/linka/assets/img/blog-img \
#   /var/www/html/joinerytest/public_html/theme/phillyzouk/assets/images/

# Copy fonts if present
cp -r /home/user1/joinery/linka-modern-blog-template/linka/assets/fonts/* \
  /var/www/html/joinerytest/public_html/theme/phillyzouk/assets/fonts/ 2>/dev/null || true
```

### Step 2: Create FormWriter.php

**File:** `theme/phillyzouk/includes/FormWriter.php`

FormWriter extending FormWriterV2Bootstrap to provide Bootstrap-compatible form styling:

```php
<?php
// FormWriter for Linka theme - extends Bootstrap form writer
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

class FormWriter extends FormWriterV2Bootstrap {
    // Inherits all form styling from FormWriterV2Bootstrap base class
    // Linka theme uses Bootstrap 5, so default Bootstrap styling applies
    // Can override specific methods here if Linka-specific form styling needed
}
?>
```

### Step 3: Create PublicPage.php

**File:** `theme/phillyzouk/includes/PublicPage.php`

PublicPage handling header/footer with Linka template layout:

```php
<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

class PublicPage extends PublicPageBase {

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
        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <meta name="description" content="<?php echo htmlspecialchars($description); ?>">

        <!-- Bootstrap CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/bootstrap.min.css">
        <!-- Owl Carousel CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/owl.theme.default.min.css">
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/owl.carousel.min.css">
        <!-- Magnific Popup CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/magnific-popup.min.css">
        <!-- Animate CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/animate.min.css">
        <!-- Boxicons CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/boxicons.min.css">
        <!-- Flaticon CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/flaticon.css">
        <!-- MeanMenu CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/meanmenu.min.css">
        <!-- Nice Select CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/nice-select.min.css">
        <!-- Linka Theme CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/style.css">
        <!-- Responsive CSS -->
        <link rel="stylesheet" href="/theme/phillyzouk/assets/css/responsive.css">

        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/theme/phillyzouk/assets/images/favicon.png">

        <title><?php echo htmlspecialchars($title); ?></title>
    </head>
    <body>

        <!-- Start Navbar Area -->
        <div class="nav-area">
            <div class="navbar-area">
                <!-- Menu For Mobile Device -->
                <div class="mobile-nav">
                    <a href="/" class="logo">
                        <img src="/theme/phillyzouk/assets/images/logo.png" alt="<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>">
                    </a>
                </div>

                <!-- Menu For Desktop Device -->
                <div class="main-nav">
                    <nav class="navbar navbar-expand-md">
                        <div class="container-fluid">
                            <a class="navbar-brand" href="/">
                                <img src="/theme/phillyzouk/assets/images/logo.png" alt="<?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>">
                            </a>

                            <div class="collapse navbar-collapse mean-menu">
                                <ul class="navbar-nav m-auto">
                                    <?php
                                    // Get menu data from public menu model
                                    $menu_data = $this->get_menu_data();
                                    $menus = isset($menu_data['main_menu']) ? $menu_data['main_menu'] : array();

                                    foreach ($menus as $menu) {
                                        $is_active = isset($menu['is_active']) && $menu['is_active'] ? 'active' : '';
                                        $has_submenu = isset($menu['submenu']) && !empty($menu['submenu']);

                                        echo '<li class="nav-item ' . htmlspecialchars($is_active) . '">';
                                        echo '<a href="' . htmlspecialchars($menu['link']) . '" class="nav-link ' . htmlspecialchars($is_active) . '">' .
                                             htmlspecialchars($menu['name']);

                                        if ($has_submenu) {
                                            echo '<i class="bx bx-chevron-down"></i>';
                                        }
                                        echo '</a>';

                                        if ($has_submenu) {
                                            echo '<ul class="dropdown-menu">';
                                            foreach ($menu['submenu'] as $submenu) {
                                                $submenu_active = isset($submenu['is_active']) && $submenu['is_active'] ? 'active' : '';
                                                echo '<li class="nav-item ' . htmlspecialchars($submenu_active) . '">';
                                                echo '<a href="' . htmlspecialchars($submenu['link']) . '" class="nav-link ' . htmlspecialchars($submenu_active) . '">' .
                                                     htmlspecialchars($submenu['name']) . '</a>';
                                                echo '</li>';
                                            }
                                            echo '</ul>';
                                        }
                                        echo '</li>';
                                    }
                                    ?>
                                </ul>
                            </div>
                        </div>
                    </nav>
                </div>
            </div>
        </div>
        <!-- End Navbar Area -->

        <?php
    }

    public function public_footer($options = array()) {
        $settings = Globalvars::get_instance();
        ?>

        <!-- Start Footer Area -->
        <footer class="footer-area">
            <div class="container">
                <div class="row">
                    <div class="col-lg-4 col-sm-6">
                        <div class="footer-widget">
                            <h3><?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></h3>
                            <p><?php echo htmlspecialchars($settings->get_setting('site_description', true, true)); ?></p>
                        </div>
                    </div>
                    <div class="col-lg-4 col-sm-6">
                        <div class="footer-widget">
                            <h3>Quick Links</h3>
                            <ul>
                                <li><a href="/">Home</a></li>
                                <li><a href="/about">About</a></li>
                                <li><a href="/contact">Contact</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-4 col-sm-6">
                        <div class="footer-widget">
                            <h3>Follow Us</h3>
                            <ul class="social-links">
                                <li><a href="#" target="_blank"><i class="bx bxl-facebook"></i></a></li>
                                <li><a href="#" target="_blank"><i class="bx bxl-twitter"></i></a></li>
                                <li><a href="#" target="_blank"><i class="bx bxl-instagram"></i></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="footer-bottom">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?>. All rights reserved.</p>
                </div>
            </div>
        </footer>
        <!-- End Footer Area -->

        <!-- jQuery -->
        <script src="/theme/phillyzouk/assets/js/jquery.min.js"></script>
        <!-- Bootstrap JS -->
        <script src="/theme/phillyzouk/assets/js/bootstrap.bundle.min.js"></script>
        <!-- OWL Carousel JS -->
        <script src="/theme/phillyzouk/assets/js/owl.carousel.min.js"></script>
        <!-- Magnific Popup JS -->
        <script src="/theme/phillyzouk/assets/js/magnific-popup.min.js"></script>
        <!-- WOW JS -->
        <script src="/theme/phillyzouk/assets/js/wow.min.js"></script>
        <!-- MeanMenu JS -->
        <script src="/theme/phillyzouk/assets/js/meanmenu.min.js"></script>
        <!-- Nice Select JS -->
        <script src="/theme/phillyzouk/assets/js/nice-select.min.js"></script>
        <!-- Form Validator JS -->
        <script src="/theme/phillyzouk/assets/js/form-validator.min.js"></script>
        <!-- AjaxChimp JS -->
        <script src="/theme/phillyzouk/assets/js/ajaxchimp.min.js"></script>
        <!-- Contact Form JS -->
        <script src="/theme/phillyzouk/assets/js/contact-form-script.js"></script>
        <!-- Custom JS -->
        <script src="/theme/phillyzouk/assets/js/custom.js"></script>
    </body>
</html>
        <?php
    }
}
?>
```

### Step 4: Create Homepage Template

**File:** `theme/phillyzouk/views/index.php`

Primary homepage integrating PublicPage header/footer with HTML placeholder content:

```php
<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Welcome to Our Blog',
    'showheader' => true,
    'description' => 'Discover the latest articles and stories'
));
?>

<!-- Main Content Area -->
<main class="main-content">
    <div class="container my-5">
        <div class="row">
            <div class="col-lg-8">
                <!-- Hero Section -->
                <section class="hero-section mb-5">
                    <div class="hero-content">
                        <h1 class="hero-title">Welcome to Our Blog</h1>
                        <p class="hero-subtitle">Discover the latest articles, stories, and insights</p>
                    </div>
                </section>

                <!-- Blog Posts Section -->
                <section class="blog-section">
                    <h2 class="section-title mb-4">Latest Posts</h2>
                    <div class="blog-posts">
                        <!-- Blog post cards will be dynamically loaded here -->
                        <div class="post-card mb-4">
                            <div class="post-image">
                                <img src="/theme/phillyzouk/assets/images/home-three/placeholder.jpg" alt="Post" class="img-fluid">
                            </div>
                            <div class="post-content">
                                <h3 class="post-title">Post Title Here</h3>
                                <p class="post-excerpt">Post excerpt placeholder content goes here...</p>
                                <a href="#" class="read-more">Read More</a>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <!-- Sidebar -->
                <aside class="sidebar">
                    <div class="sidebar-widget mb-4">
                        <h4 class="widget-title">Categories</h4>
                        <ul class="category-list">
                            <li><a href="#">Category Placeholder</a></li>
                        </ul>
                    </div>

                    <div class="sidebar-widget mb-4">
                        <h4 class="widget-title">Recent Posts</h4>
                        <ul class="recent-posts-list">
                            <li><a href="#">Recent Post Placeholder</a></li>
                        </ul>
                    </div>

                    <div class="sidebar-widget">
                        <h4 class="widget-title">Newsletter</h4>
                        <p>Subscribe to our newsletter for updates</p>
                        <!-- Subscription form placeholder -->
                    </div>
                </aside>
            </div>
        </div>
    </div>
</main>

<?php
$page->public_footer();
?>
```

### Step 5: Create theme.json Configuration

**File:** `theme/phillyzouk/theme.json`

Theme metadata and asset configuration.

### Step 6: Validation and Testing

Execute validation commands (from public_html directory):

```bash
# Validate PHP syntax
php -l theme/phillyzouk/includes/FormWriter.php
php -l theme/phillyzouk/includes/PublicPage.php
php -l theme/phillyzouk/views/index.php

# Run method existence test (from public_html directory)
php ../maintenance_scripts/method_existence_test.php \
  theme/phillyzouk/includes/FormWriter.php
php ../maintenance_scripts/method_existence_test.php \
  theme/phillyzouk/includes/PublicPage.php
php ../maintenance_scripts/method_existence_test.php \
  theme/phillyzouk/views/index.php
```

### Step 7: Integration Verification

```bash
# Verify theme directory structure (from public_html directory)
tree theme/phillyzouk/

# Check asset file counts (from public_html directory)
find theme/phillyzouk/assets -type f | wc -l
```

## Technical Considerations

### jQuery Dependency
The template relies on jQuery for carousel, animation, and form functionality. The following libraries require jQuery:
- OWL Carousel
- Magnific Popup
- WOW.js
- Form Validator
- Contact Form Script
- MeanMenu (mobile menu)

**Decision:** Include jQuery as it's required by multiple template features. This is a Bootstrap theme best practice.

### Asset Path Management
All asset paths must be updated from relative paths (e.g., `assets/css/style.css`) to absolute theme paths (e.g., `/theme/phillyzouk/assets/css/style.css`).

### FormWriter Compatibility
Since Linka is Bootstrap-based, use `FormWriterV2Bootstrap` as the base class. Bootstrap form styling will be consistent with template design.

### Menu System
Linka template includes hardcoded menu items. Must replace with dynamic menu system using:
```php
$menus = PublicPage::get_public_menu();
```

### Responsive Design
Linka includes comprehensive responsive CSS with multiple breakpoints:
- Extra large: 1200px+
- Large: 992px - 1199px
- Medium: 768px - 991px
- Small: 576px - 767px
- Extra small: <576px

Mobile menu functionality uses MeanMenu for hamburger menu toggle.

### Image Organization
Source template organizes images by homepage layout variant. Consider consolidating or maintaining this structure in `/assets/images/` subdirectories for clarity.

## Files to Exclude from Integration

The following files from the source template should NOT be copied to the theme:
- `*.html` files (converted to PHP views)
- `assets/php/` directory (contact form processing - handled by Joinery form system)
- Documentation files (if any)

## Success Criteria

- [ ] Theme directory created with proper structure
- [ ] All assets copied to appropriate subdirectories
- [ ] FormWriter.php loads without errors (`php -l` passes)
- [ ] PublicPage.php loads without errors (`php -l` passes)
- [ ] Method existence test passes on all PHP files
- [ ] Homepage template displays correctly with Joinery header/footer
- [ ] All CSS files load (check browser dev tools)
- [ ] All JavaScript files load (check browser dev tools)
- [ ] Images display correctly with updated asset paths
- [ ] Forms display correctly with FormWriter integration
- [ ] Mobile responsive menu works correctly
- [ ] Carousel functionality works (if featured on homepage)
- [ ] Theme can be selected in admin settings
- [ ] Theme displays correctly when selected as active theme

## Post-Integration Considerations

1. **Custom CSS Overrides:** If specific Joinery features need styling adjustments, create `custom-joinery.css` in the theme CSS directory
2. **Form Styling:** Verify all form types (login, registration, checkout, etc.) display correctly
3. **Blog Integration:** Ensure blog post listings, pagination, and single post views work properly
4. **E-commerce:** If shop features are used, verify product grid, filters, and checkout flow
5. **Plugin Compatibility:** Test that installed plugins render correctly with Linka theme
6. **Admin Interface:** Verify admin pages still function properly (they use different styling)

## Notes

- The Linka theme is feature-rich with carousel, animations, and interactive elements
- Multiple homepage layout variants could eventually be offered as theme options
- SCSS source files are included for potential future customization
- The theme emphasizes blog/magazine content but includes shop functionality
- Mobile-first approach with comprehensive mobile menu support
