# Theme Asset Manifest Loading Specification

## Overview

This specification defines a standardized system for declaring, validating, and automatically loading CSS and JavaScript assets through theme manifests, with automatic integration into PublicPage classes for consistent asset management.

## Current State Analysis

### Existing Asset Loading Pattern
Currently, theme assets are loaded manually in PublicPage classes using `PathHelper::getThemeFilePath()`:

```php
// Example from PublicPageFalcon.php
<script src="<?php echo PathHelper::getThemeFilePath('simplebar.min.js', 'assets/vendors/simplebar', 'web', 'falcon'); ?>"></script>
<link rel="stylesheet" href="<?php echo PathHelper::getThemeFilePath('theme.css', 'assets/css', 'web', 'falcon'); ?>">
```

### Current Theme Manifest Structure
Theme manifests (`theme.json`) currently contain basic metadata:

```json
{
  "name": "falcon",
  "displayName": "Falcon Theme",
  "version": "2.0.0",
  "description": "Bootstrap 5 based responsive theme",
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterMasterBootstrap",
  "publicPageBase": "PublicPageFalcon"
}
```

## Proposed Enhancement

### 1. Extended Theme Manifest Schema

The enhanced manifest schema supports multiple asset organization approaches for maximum flexibility:

#### Basic Asset Structure
```json
{
  "name": "falcon",
  "displayName": "Falcon Theme", 
  "version": "2.0.0",
  "description": "Bootstrap 5 based responsive theme",
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterMasterBootstrap",
  "publicPageBase": "PublicPageFalcon",
  "assets": {
    "global": {
      "css": [
        {
          "file": "assets/vendors/simplebar/simplebar.min.css",
          "id": "stylesheet",
          "priority": 10,
          "media": "all",
          "tags": ["global", "core"]
        },
        {
          "file": "assets/css/theme.css",
          "id": "style-default",
          "priority": 20,
          "media": "all", 
          "tags": ["global", "theme"]
        }
      ],
      "js": [
        {
          "file": "assets/vendors/simplebar/simplebar.min.js",
          "location": "head",
          "priority": 10,
          "defer": false,
          "async": false,
          "tags": ["global", "core"]
        }
      ]
    },
    "conditional": {
      "css": [
        {
          "file": "assets/css/admin.css",
          "priority": 20,
          "condition": "strpos($_SERVER['REQUEST_URI'], '/admin') === 0",
          "tags": ["admin", "dashboard"],
          "media": "all"
        },
        {
          "file": "assets/css/shop.css",
          "priority": 20,
          "condition": "strpos($_SERVER['REQUEST_URI'], '/shop') !== false",
          "tags": ["shop", "commerce"],
          "media": "all"
        },
        {
          "file": "assets/css/print.css",
          "priority": 90,
          "tags": ["global"],
          "media": "print"
        }
      ],
      "js": [
        {
          "file": "assets/js/admin-dashboard.js",
          "location": "footer",
          "condition": "$_SERVER['REQUEST_URI'] === '/admin/admin_users'",
          "tags": ["admin", "dashboard"],
          "priority": 30
        }
      ]
    },
    "routes": {
      "/admin/*": {
        "css": [
          {
            "file": "assets/css/admin-base.css",
            "priority": 25,
            "tags": ["admin"]
          }
        ],
        "js": [
          {
            "file": "assets/js/admin-core.js",
            "location": "footer",
            "priority": 25,
            "tags": ["admin"]
          }
        ]
      },
      "/shop/*": {
        "css": [
          {
            "file": "assets/css/shop-theme.css",
            "priority": 25,
            "tags": ["shop"]
          }
        ]
      },
      "/events/{slug}": {
        "css": [
          {
            "file": "assets/css/events.css",
            "priority": 25,
            "tags": ["events"]
          }
        ],
        "js": [
          {
            "file": "assets/js/calendar.js",
            "location": "footer",
            "priority": 30,
            "tags": ["events", "calendar"]
          }
        ]
      }
    },
    "external": {
      "css": [
        {
          "url": "https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700",
          "id": "google-fonts",
          "priority": 5,
          "media": "all",
          "tags": ["global", "fonts"]
        }
      ],
      "js": [
        {
          "url": "https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js",
          "location": "head",
          "priority": 1,
          "defer": false,
          "async": false,
          "integrity": "sha256-xxx",
          "crossorigin": "anonymous",
          "tags": ["global", "core"]
        }
      ]
    }
  }
}
```

### 2. Enhanced Asset Definition Schema

#### CSS Assets
- **file** (string, required): Path to CSS file relative to theme directory
- **url** (string, required for external): Full URL to external CSS
- **id** (string, optional): Element ID for the link tag
- **priority** (integer, default: 50): Loading order (lower numbers load first)
- **condition** (string, optional): PHP condition to evaluate for conditional loading
- **media** (string, default: "all"): Media query for responsive/print styles
- **tags** (array, optional): Tags for grouping and filtering assets

#### JavaScript Assets
- **file** (string, required): Path to JS file relative to theme directory  
- **url** (string, required for external): Full URL to external JS
- **location** (string, default: "footer"): Where to load ("head" or "footer")
- **priority** (integer, default: 50): Loading order (lower numbers load first)
- **condition** (string, optional): PHP condition to evaluate for conditional loading
- **defer** (boolean, default: false): Add defer attribute
- **async** (boolean, default: false): Add async attribute
- **integrity** (string, optional): Subresource Integrity hash
- **crossorigin** (string, optional): CORS setting ("anonymous", "use-credentials")
- **tags** (array, optional): Tags for grouping and filtering assets

### 3. Asset Organization Approaches

The enhanced schema supports multiple approaches for organizing assets based on page requirements:

#### Global Assets
Always loaded on every page:
```json
"global": {
  "css": [{"file": "assets/css/base.css", "tags": ["global", "core"]}],
  "js": [{"file": "assets/js/core.js", "tags": ["global", "core"]}]
}
```

#### Conditional Assets  
Loaded based on PHP conditions:
```json
"conditional": {
  "css": [
    {
      "file": "assets/css/admin.css",
      "condition": "strpos($_SERVER['REQUEST_URI'], '/admin') === 0",
      "tags": ["admin"]
    }
  ]
}
```

#### Route-Based Assets
Loaded based on URL patterns:
```json
"routes": {
  "/admin/*": {
    "css": [{"file": "assets/css/admin-base.css"}]
  },
  "/shop/*": {
    "css": [{"file": "assets/css/shop.css"}]
  }
}
```

#### Tag-Based Loading
Assets grouped by functionality:
```json
"css": [
  {"file": "base.css", "tags": ["global"]},
  {"file": "admin.css", "tags": ["admin", "dashboard"]},
  {"file": "shop.css", "tags": ["shop", "commerce"]}
]
```

### 4. Page-Specific Asset Loading Strategies

#### Strategy 1: Enhanced Conditions
Use detailed PHP conditions for precise control:

```json
{
  "file": "assets/css/user-profile.css",
  "condition": "$_SERVER['REQUEST_URI'] === '/profile' || $_GET['page'] === 'profile'",
  "priority": 25
}
```

#### Strategy 2: Tag-Based Filtering
Load assets by specifying required tags:

```php
// In admin pages
echo $this->renderPageAssets(['tags' => ['global', 'admin']]);

// In shop pages  
echo $this->renderPageAssets(['tags' => ['global', 'shop', 'commerce']]);
```

#### Strategy 3: Route Matching
Automatic loading based on URL patterns:

```json
"routes": {
  "/admin/users*": {"css": [{"file": "assets/css/user-management.css"}]},
  "/admin/settings*": {"css": [{"file": "assets/css/settings.css"}]},
  "/events/{id}": {"js": [{"file": "assets/js/event-details.js"}]}
}
```

#### Strategy 4: Context-Based Loading
Pass page context for dynamic filtering:

```php
public function public_header($options=array()) {
    $context = [
        'page_type' => $options['page_type'] ?? 'default',
        'user_role' => $session->get_permission_name(),
        'section' => $options['section'] ?? null
    ];
    
    echo $this->renderContextAssets($context);
}
```

### 5. Enhanced ThemeHelper Asset Management

#### Core Asset Retrieval Methods

```php
/**
 * Get all assets from theme manifest
 * @return array Array of assets organized by type
 */
public function getAssets() {
    return $this->manifestData['assets'] ?? [];
}

/**
 * Get all assets for current route with fallbacks
 * @param string $currentRoute Current request path
 * @return array Combined assets from global, conditional, and route-specific sources
 */
public function getRouteAssets($currentRoute) {
    $assets = $this->getAssets();
    $result = ['css' => [], 'js' => []];
    
    // Always include global assets
    if (isset($assets['global'])) {
        $result['css'] = array_merge($result['css'], $assets['global']['css'] ?? []);
        $result['js'] = array_merge($result['js'], $assets['global']['js'] ?? []);
    }
    
    // Add conditional assets that match
    if (isset($assets['conditional'])) {
        foreach ($assets['conditional']['css'] ?? [] as $asset) {
            if ($this->evaluateCondition($asset['condition'] ?? null)) {
                $result['css'][] = $asset;
            }
        }
        foreach ($assets['conditional']['js'] ?? [] as $asset) {
            if ($this->evaluateCondition($asset['condition'] ?? null)) {
                $result['js'][] = $asset;
            }
        }
    }
    
    // Add route-specific assets
    if (isset($assets['routes'])) {
        foreach ($assets['routes'] as $pattern => $routeAssets) {
            if ($this->matchesRoute($currentRoute, $pattern)) {
                $result['css'] = array_merge($result['css'], $routeAssets['css'] ?? []);
                $result['js'] = array_merge($result['js'], $routeAssets['js'] ?? []);
            }
        }
    }
    
    // Add external assets
    if (isset($assets['external'])) {
        $result['css'] = array_merge($result['css'], $assets['external']['css'] ?? []);
        $result['js'] = array_merge($result['js'], $assets['external']['js'] ?? []);
    }
    
    return $result;
}

/**
 * Get CSS assets filtered by tags and conditions
 * @param array $tags Tags to include (empty = all)
 * @param array $excludeTags Tags to exclude
 * @param string|null $condition Context condition
 * @return array Filtered CSS assets
 */
public function getCssAssetsByTags($tags = [], $excludeTags = [], $condition = null) {
    $routeAssets = $this->getRouteAssets($_SERVER['REQUEST_URI']);
    $css = $routeAssets['css'];
    
    // Filter by tags if specified
    if (!empty($tags) || !empty($excludeTags)) {
        $css = array_filter($css, function($asset) use ($tags, $excludeTags) {
            $assetTags = $asset['tags'] ?? ['global'];
            
            // Exclude if contains excluded tags
            if (!empty($excludeTags) && array_intersect($assetTags, $excludeTags)) {
                return false;
            }
            
            // Include if no specific tags required, or if contains any required tags
            return empty($tags) || array_intersect($assetTags, $tags);
        });
    }
    
    // Filter by additional condition
    if ($condition !== null) {
        $css = array_filter($css, function($asset) use ($condition) {
            return $this->evaluateCondition($asset['condition'] ?? null, $condition);
        });
    }
    
    // Sort by priority
    usort($css, function($a, $b) {
        return ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50);
    });
    
    return $css;
}

/**
 * Get JavaScript assets filtered by location, tags and conditions
 * @param string $location "head" or "footer"
 * @param array $tags Tags to include
 * @param array $excludeTags Tags to exclude
 * @param string|null $condition Context condition
 * @return array Filtered JS assets
 */
public function getJsAssetsByTags($location = 'footer', $tags = [], $excludeTags = [], $condition = null) {
    $routeAssets = $this->getRouteAssets($_SERVER['REQUEST_URI']);
    $js = $routeAssets['js'];
    
    // Filter by location first
    $js = array_filter($js, function($asset) use ($location) {
        return ($asset['location'] ?? 'footer') === $location;
    });
    
    // Filter by tags if specified
    if (!empty($tags) || !empty($excludeTags)) {
        $js = array_filter($js, function($asset) use ($tags, $excludeTags) {
            $assetTags = $asset['tags'] ?? ['global'];
            
            // Exclude if contains excluded tags
            if (!empty($excludeTags) && array_intersect($assetTags, $excludeTags)) {
                return false;
            }
            
            // Include if no specific tags required, or if contains any required tags
            return empty($tags) || array_intersect($assetTags, $tags);
        });
    }
    
    // Filter by additional condition
    if ($condition !== null) {
        $js = array_filter($js, function($asset) use ($condition) {
            return $this->evaluateCondition($asset['condition'] ?? null, $condition);
        });
    }
    
    // Sort by priority
    usort($js, function($a, $b) {
        return ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50);
    });
    
    return $js;
}

#### Dynamic Asset Registration

```php
private $dynamicAssets = ['css' => [], 'js' => []];

/**
 * Add asset at runtime
 * @param string $type 'css' or 'js'
 * @param array $asset Asset definition
 */
public function addPageAsset($type, $asset) {
    if (!isset($this->dynamicAssets[$type])) {
        $this->dynamicAssets[$type] = [];
    }
    $this->dynamicAssets[$type][] = $asset;
}

/**
 * Get combined static and dynamic assets
 * @param string $type 'css' or 'js'
 * @return array Combined asset list
 */
public function getCombinedAssets($type) {
    $routeAssets = $this->getRouteAssets($_SERVER['REQUEST_URI']);
    $staticAssets = $routeAssets[$type] ?? [];
    $dynamicAssets = $this->dynamicAssets[$type] ?? [];
    
    return array_merge($staticAssets, $dynamicAssets);
}

#### Utility Methods

```php
/**
 * Check if current route matches pattern
 * @param string $route Current request path
 * @param string $pattern Pattern to match (supports *, {slug}, {id})
 * @return bool Whether route matches pattern
 */
private function matchesRoute($route, $pattern) {
    // Convert pattern to regex
    $regex = str_replace(
        ['*', '{slug}', '{id}', '{param}'],
        ['.*', '[^/]+', '\d+', '[^/]+'],
        preg_quote($pattern, '/')
    );
    
    return preg_match("/^{$regex}$/", $route);
}

/**
 * Evaluate asset condition safely
 * @param string|null $condition PHP condition to evaluate
 * @return bool Whether condition is met
 */
private function evaluateCondition($condition) {
    if ($condition === null) {
        return true; // No condition means always load
    }
    
    // Evaluate PHP condition safely
    try {
        return eval("return {$condition};");
    } catch (Exception $e) {
        error_log("Asset condition evaluation failed: {$condition}");
        return false;
    }
}

/**
 * Validate enhanced assets in theme manifest
 * @return bool|array True if valid, array of errors if invalid
 */
public function validateAssets() {
    $errors = [];
    $assets = $this->getAssets();
    
    if (empty($assets)) {
        return true; // No assets defined is valid
    }
    
    // Validate global assets
    $this->validateAssetSection($assets['global'] ?? [], 'global', $errors);
    
    // Validate conditional assets  
    $this->validateAssetSection($assets['conditional'] ?? [], 'conditional', $errors);
    
    // Validate route-based assets
    foreach ($assets['routes'] ?? [] as $route => $routeAssets) {
        $this->validateAssetSection($routeAssets, "route '{$route}'", $errors);
    }
    
    // Validate external assets
    $this->validateExternalAssets($assets['external'] ?? [], $errors);
    
    return empty($errors) ? true : $errors;
}

/**
 * Validate a section of assets
 * @param array $section Asset section to validate
 * @param string $sectionName Name for error reporting
 * @param array &$errors Error array to append to
 */
private function validateAssetSection($section, $sectionName, &$errors) {
    foreach ($section['css'] ?? [] as $index => $asset) {
        if (empty($asset['file'])) {
            $errors[] = "CSS asset at index {$index} in {$sectionName} missing required 'file' field";
            continue;
        }
        
        $filePath = $this->getIncludePath($asset['file']);
        if (!file_exists($filePath)) {
            $errors[] = "CSS file not found in {$sectionName}: {$asset['file']}";
        }
    }
    
    foreach ($section['js'] ?? [] as $index => $asset) {
        if (empty($asset['file'])) {
            $errors[] = "JS asset at index {$index} in {$sectionName} missing required 'file' field";
            continue;
        }
        
        $filePath = $this->getIncludePath($asset['file']);
        if (!file_exists($filePath)) {
            $errors[] = "JS file not found in {$sectionName}: {$asset['file']}";
        }
        
        $location = $asset['location'] ?? 'footer';
        if (!in_array($location, ['head', 'footer'])) {
            $errors[] = "Invalid JS location '{$location}' in {$sectionName} for {$asset['file']}. Must be 'head' or 'footer'";
        }
    }
}

/**
 * Validate external assets
 * @param array $external External assets section
 * @param array &$errors Error array to append to
 */
private function validateExternalAssets($external, &$errors) {
    foreach ($external['css'] ?? [] as $index => $asset) {
        if (empty($asset['url'])) {
            $errors[] = "External CSS asset at index {$index} missing required 'url' field";
        }
    }
    
    foreach ($external['js'] ?? [] as $index => $asset) {
        if (empty($asset['url'])) {
            $errors[] = "External JS asset at index {$index} missing required 'url' field";
        }
    }
}

#### High-Level Rendering Methods

```php
/**
 * Render page assets with context filtering
 * @param array $context Page context for filtering
 * @return array HTML for head and footer assets
 */
public function renderPageAssets($context = []) {
    $tags = $context['tags'] ?? [];
    $excludeTags = $context['exclude_tags'] ?? [];
    $condition = $context['condition'] ?? null;
    
    return [
        'css' => $this->renderPageCss($tags, $excludeTags, $condition),
        'js_head' => $this->renderPageJs('head', $tags, $excludeTags, $condition),
        'js_footer' => $this->renderPageJs('footer', $tags, $excludeTags, $condition)
    ];
}

/**
 * Render CSS assets with tag and condition filtering
 * @param array $tags Tags to include
 * @param array $excludeTags Tags to exclude
 * @param string|null $condition Additional condition
 * @return string HTML for CSS assets
 */
public function renderPageCss($tags = [], $excludeTags = [], $condition = null) {
    $assets = $this->getCssAssetsByTags($tags, $excludeTags, $condition);
    return $this->renderCssHtml($assets);
}

/**
 * Render JavaScript assets with tag and condition filtering
 * @param string $location "head" or "footer"
 * @param array $tags Tags to include
 * @param array $excludeTags Tags to exclude  
 * @param string|null $condition Additional condition
 * @return string HTML for JS assets
 */
public function renderPageJs($location = 'footer', $tags = [], $excludeTags = [], $condition = null) {
    $assets = $this->getJsAssetsByTags($location, $tags, $excludeTags, $condition);
    return $this->renderJsHtml($assets);
}

/**
 * Render CSS assets as HTML
 * @param array $assets CSS asset definitions
 * @return string HTML for CSS link tags
 */
private function renderCssHtml($assets) {
    $html = '';
    
    foreach ($assets as $asset) {
        $href = isset($asset['url']) 
            ? $asset['url'] 
            : ThemeHelper::asset($asset['file'], $this->name);
        
        $id = isset($asset['id']) ? ' id="' . htmlspecialchars($asset['id']) . '"' : '';
        $media = isset($asset['media']) ? ' media="' . htmlspecialchars($asset['media']) . '"' : ' media="all"';
        
        $html .= "<link rel=\"stylesheet\" type=\"text/css\"{$id} href=\"{$href}\"{$media}>\n";
    }
    
    return $html;
}

/**
 * Render JavaScript assets as HTML
 * @param array $assets JS asset definitions
 * @return string HTML for script tags
 */
private function renderJsHtml($assets) {
    $html = '';
    
    foreach ($assets as $asset) {
        $src = isset($asset['url']) 
            ? $asset['url']
            : ThemeHelper::asset($asset['file'], $this->name);
        
        $attributes = '';
        if ($asset['defer'] ?? false) $attributes .= ' defer';
        if ($asset['async'] ?? false) $attributes .= ' async';
        if (isset($asset['integrity'])) {
            $attributes .= ' integrity="' . htmlspecialchars($asset['integrity']) . '"';
        }
        if (isset($asset['crossorigin'])) {
            $attributes .= ' crossorigin="' . htmlspecialchars($asset['crossorigin']) . '"';
        }
        
        $html .= "<script src=\"{$src}\"{$attributes}></script>\n";
    }
    
    return $html;
}
```

### 6. Enhanced PublicPageBase Integration

#### Automatic Asset Loading in Constructor

Modify `PublicPageBase::__construct()` to automatically load theme assets with page context awareness:

```php
public function __construct($secure=FALSE) {
    // ... existing constructor code ...
    
    // Initialize page context for asset loading
    $this->pageContext = $this->initializePageContext();
    
    // Load theme assets automatically
    $this->loadThemeAssets();
}

/**
 * Initialize page context for asset filtering
 * @return array Page context information
 */
protected function initializePageContext() {
    $context = [
        'route' => $_SERVER['REQUEST_URI'],
        'tags' => ['global'], // Default tags
        'exclude_tags' => [],
        'page_type' => 'default',
        'user_role' => null,
        'section' => null
    ];
    
    // Add user role if available
    $session = SessionControl::get_instance();
    if ($session->is_logged_in()) {
        $context['user_role'] = $session->get_permission_name();
        if ($session->get_permission() >= 5) {
            $context['tags'][] = 'admin';
        }
    }
    
    // Detect page type from route
    $route = $context['route'];
    if (strpos($route, '/admin') === 0) {
        $context['page_type'] = 'admin';
        $context['tags'][] = 'admin';
        $context['tags'][] = 'dashboard';
    } elseif (strpos($route, '/shop') !== false) {
        $context['page_type'] = 'shop';
        $context['tags'][] = 'shop';
        $context['tags'][] = 'commerce';
    } elseif (strpos($route, '/events') !== false) {
        $context['page_type'] = 'events';
        $context['tags'][] = 'events';
    }
    
    return $context;
}

/**
 * Load theme assets based on manifest and page context
 */
protected function loadThemeAssets() {
    try {
        $themeHelper = ThemeHelper::getInstance();
        
        // Store assets with page context filtering
        $this->themeAssets = $themeHelper->renderPageAssets($this->pageContext);
        
        // Validate assets
        $validation = $themeHelper->validateAssets();
        if ($validation !== true) {
            error_log("Theme asset validation errors: " . implode(', ', $validation));
        }
        
    } catch (Exception $e) {
        error_log("Failed to load theme assets: " . $e->getMessage());
        $this->themeAssets = ['css' => '', 'js_head' => '', 'js_footer' => ''];
    }
}

#### Enhanced Asset Rendering Methods

```php
/**
 * Render theme CSS assets with optional context overrides
 * @param array $contextOverrides Optional context modifications
 * @return string HTML for CSS assets
 */
protected function renderThemeCss($contextOverrides = []) {
    try {
        $themeHelper = ThemeHelper::getInstance();
        $context = array_merge($this->pageContext, $contextOverrides);
        
        return $themeHelper->renderPageCss(
            $context['tags'] ?? [],
            $context['exclude_tags'] ?? [],
            $context['condition'] ?? null
        );
    } catch (Exception $e) {
        error_log("Failed to render theme CSS: " . $e->getMessage());
        return '';
    }
}

/**
 * Render theme JavaScript assets with optional context overrides
 * @param string $location "head" or "footer"
 * @param array $contextOverrides Optional context modifications
 * @return string HTML for JS assets
 */
protected function renderThemeJs($location = 'footer', $contextOverrides = []) {
    try {
        $themeHelper = ThemeHelper::getInstance();
        $context = array_merge($this->pageContext, $contextOverrides);
        
        return $themeHelper->renderPageJs(
            $location,
            $context['tags'] ?? [],
            $context['exclude_tags'] ?? [],
            $context['condition'] ?? null
        );
    } catch (Exception $e) {
        error_log("Failed to render theme JS: " . $e->getMessage());
        return '';
    }
}

/**
 * Add runtime assets for specific page needs
 * @param string $type 'css' or 'js'
 * @param array $asset Asset definition
 */
protected function addPageAsset($type, $asset) {
    try {
        $themeHelper = ThemeHelper::getInstance();
        $themeHelper->addPageAsset($type, $asset);
    } catch (Exception $e) {
        error_log("Failed to add page asset: " . $e->getMessage());
    }
}

/**
 * Set page context for asset filtering
 * @param array $context Context to merge with current context
 */
protected function setPageContext($context) {
    $this->pageContext = array_merge($this->pageContext, $context);
}

#### Page-Specific Context Methods

```php
/**
 * Set context for admin pages
 * @param string $section Optional admin section
 */
protected function setAdminContext($section = null) {
    $this->setPageContext([
        'page_type' => 'admin',
        'tags' => ['global', 'admin', 'dashboard'],
        'section' => $section
    ]);
    
    if ($section) {
        $this->pageContext['tags'][] = $section;
    }
}

/**
 * Set context for shop pages
 * @param string $section Optional shop section
 */
protected function setShopContext($section = null) {
    $this->setPageContext([
        'page_type' => 'shop',
        'tags' => ['global', 'shop', 'commerce'],
        'section' => $section
    ]);
    
    if ($section) {
        $this->pageContext['tags'][] = $section;
    }
}

/**
 * Set context for event pages
 * @param string $eventType Type of event page
 */
protected function setEventContext($eventType = null) {
    $this->setPageContext([
        'page_type' => 'events',
        'tags' => ['global', 'events'],
        'section' => $eventType
    ]);
    
    if ($eventType) {
        $this->pageContext['tags'][] = $eventType;
    }
}
```

#### Updated Header/Footer Methods

Modify theme-specific PublicPage classes to use automatic asset loading:

```php
// In PublicPageFalcon.php
public function public_header($options=array()) {
    // Set page context if provided
    if (isset($options['page_context'])) {
        $this->setPageContext($options['page_context']);
    }
    
    // ... existing header setup ...
    ?>
    <head>
        <!-- ... existing head content ... -->
        
        <!-- Theme CSS Assets (automatic) -->
        <?php echo $this->renderThemeCss(); ?>
        
        <!-- Theme JavaScript Assets (Head) -->
        <?php echo $this->renderThemeJs('head'); ?>
    </head>
    <body>
        <!-- ... body content ... -->
    <?php
}

public function public_footer($options=array()) {
    ?>
        <!-- Theme JavaScript Assets (Footer) -->
        <?php echo $this->renderThemeJs('footer'); ?>
    </body>
    </html>
    <?php
    
    // ... existing footer code ...
}
```

### 7. Usage Examples

#### Basic Usage (Automatic)
Assets load automatically based on route detection:

```php
// Admin page - automatically loads admin assets
class AdminUsersPage extends PublicPageFalcon {
    public function __construct() {
        parent::__construct();
        // Admin assets loaded automatically based on route
    }
}
```

#### Manual Context Setting
Override automatic context detection:

```php
// Shop product page with specific requirements
class ShopProductPage extends PublicPageFalcon {
    public function __construct() {
        parent::__construct();
        
        // Add product-specific assets
        $this->setShopContext('product');
        $this->addPageAsset('js', [
            'file' => 'assets/js/product-gallery.js',
            'location' => 'footer',
            'priority' => 40
        ]);
    }
}
```

#### Dynamic Asset Loading
Add assets based on runtime conditions:

```php
// Event detail page
class EventDetailPage extends PublicPageFalcon {
    public function __construct($eventId) {
        parent::__construct();
        
        $this->setEventContext('detail');
        
        // Add calendar widget if event has multiple dates
        $event = new Event($eventId, true);
        if ($event->has_multiple_dates()) {
            $this->addPageAsset('css', [
                'file' => 'assets/css/calendar-widget.css',
                'priority' => 30
            ]);
            $this->addPageAsset('js', [
                'file' => 'assets/js/calendar-widget.js',
                'location' => 'footer',
                'priority' => 35
            ]);
        }
    }
}
```

#### Conditional Context Loading  
Use context overrides for specific needs:

```php
// Page with print-specific requirements
public function public_header($options=array()) {
    // Standard assets
    echo $this->renderThemeCss();
    
    // Print-specific assets
    echo $this->renderThemeCss(['tags' => ['print'], 'exclude_tags' => ['interactive']]);
    
    // Mobile-only assets
    echo $this->renderThemeCss(['condition' => 'wp_is_mobile()']);
}
```

### 8. Asset Caching and Performance

#### Enhanced Cache Busting
Assets include comprehensive version-based cache busting:

```php
/**
 * Get versioned asset URL with enhanced cache busting
 * @param string $path Asset path
 * @param string|null $themeName Theme name
 * @param bool $bustCache Whether to add cache busting
 * @return string Asset URL with cache busting
 */
public static function asset($path, $themeName = null, $bustCache = true) {
    // ... existing asset logic ...
    
    if ($bustCache) {
        $themeHelper = self::getInstance($themeName);
        $version = $themeHelper->get('version', '1.0.0');
        $fileTime = file_exists($filePath) ? filemtime($filePath) : time();
        
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= "{$separator}v={$version}&t={$fileTime}";
    }
    
    return $url;
}
```

#### Asset Minification Support
Support for minified assets in production:

```json
{
  "file": "assets/css/theme.css",
  "minified": "assets/css/theme.min.css",
  "priority": 20,
  "tags": ["global"]
}
```

```php
/**
 * Get asset file path with minification support
 */
private function getAssetFile($asset) {
    $settings = Globalvars::get_instance();
    $useMinified = $settings->get_setting('use_minified_assets', false);
    
    if ($useMinified && isset($asset['minified'])) {
        $minPath = $this->getIncludePath($asset['minified']);
        if (file_exists($minPath)) {
            return $asset['minified'];
        }
    }
    
    return $asset['file'];
}
```

### 9. Implementation Plan

#### Phase 1: Enhanced Infrastructure (Weeks 1-2)
1. **Enhanced ThemeHelper Methods**
   - Route matching functionality
   - Tag-based asset filtering  
   - Validation for all asset organization types
   - Dynamic asset registration
   
2. **Extended Manifest Schema**
   - Update schema documentation
   - Add migration tools for existing themes
   - Comprehensive validation

#### Phase 2: PublicPage Integration (Weeks 3-4)  
1. **Enhanced PublicPageBase**
   - Page context initialization
   - Automatic asset loading with context awareness
   - Runtime asset modification methods
   - Context-specific helper methods

2. **Theme-Specific Updates**
   - Update PublicPageFalcon with new asset loading
   - Update other PublicPage classes (Tailwind, etc.)
   - Remove manual asset loading

#### Phase 3: Theme Migration (Weeks 5-6)
1. **Create Enhanced Asset Manifests**
   - Falcon theme with complete asset organization
   - Tailwind theme migration
   - Default theme updates
   - Custom theme templates

2. **Testing and Validation**
   - Comprehensive theme testing
   - Performance impact assessment
   - Cross-browser compatibility testing

#### Phase 4: Advanced Features (Weeks 7-8)
1. **Performance Optimizations**
   - Enhanced cache busting
   - Asset minification support
   - Lazy loading for non-critical assets

2. **Development Tools**
   - Asset manifest validation utilities
   - Theme development helpers
   - Documentation and examples

### 10. Enhanced Benefits

#### Developer Experience
- **Declarative asset management** through comprehensive JSON structure
- **Flexible organization approaches** (global, conditional, route-based, tag-based)
- **Automatic context detection** reduces manual configuration
- **Runtime asset modification** for dynamic requirements
- **Comprehensive validation** catches configuration errors early

#### Performance & User Experience  
- **Intelligent asset loading** based on page requirements
- **Priority-based loading order** for optimal performance
- **Conditional loading** reduces unnecessary requests
- **Enhanced cache busting** ensures fresh assets
- **Support for modern loading attributes** (defer, async, integrity)

#### Maintainability & Organization
- **Multiple organization strategies** accommodate different needs
- **Clear separation** of global vs. page-specific assets
- **Tag-based grouping** enables flexible combinations
- **Route-based organization** provides clean structure
- **Centralized configuration** with distributed control

#### Security & Reliability
- **Enhanced asset validation** across all organization types
- **Safe condition evaluation** with error handling
- **SRI support** for external resources
- **Path validation** prevents security issues
- **Graceful degradation** for missing assets

This enhanced specification provides a complete foundation for sophisticated, automatic theme asset loading while maintaining full backward compatibility and security best practices. The flexible organization approaches accommodate simple themes with basic needs as well as complex applications with sophisticated asset requirements.