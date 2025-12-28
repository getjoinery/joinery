<?php
/**
 * Component Preview Utility
 *
 * Renders component types with auto-generated placeholder data for rapid testing
 * and validation without database setup. Displays within the active theme.
 *
 * Usage:
 *   /utils/component_preview              - All components
 *   /utils/component_preview?type=hero    - Single component type
 *   /utils/component_preview?category=hero - Filter by category
 *   /utils/component_preview?theme=flavor - Override active theme
 *
 * @version 1.1.0
 */

require_once(PathHelper::getIncludePath('data/components_class.php'));

/**
 * ComponentPreviewer - Generates placeholder data and renders component types
 */
class ComponentPreviewer {

    private $lorem_words = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit',
        'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore',
        'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam', 'quis', 'nostrud',
        'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'ex', 'ea', 'commodo'
    ];

    private $icons = [
        'bx bx-check', 'bx bx-star', 'bx bx-heart', 'bx bx-user', 'bx bx-cog',
        'bx bx-home', 'bx bx-envelope', 'bx bx-phone', 'bx bx-calendar', 'bx bx-chart'
    ];

    /**
     * Generate placeholder data for a component type based on its config schema
     *
     * @param Component $componentType
     * @return array
     */
    public function generatePlaceholderData($componentType) {
        $schema = $componentType->get('com_config_schema');
        $schema = is_string($schema) ? json_decode($schema, true) : $schema;

        if (empty($schema) || empty($schema['fields'])) {
            return [];
        }

        $data = [];
        foreach ($schema['fields'] as $field) {
            $name = $field['name'] ?? '';
            if ($name) {
                $data[$name] = $this->generateFieldPlaceholder($field);
            }
        }

        return $data;
    }

    /**
     * Generate placeholder value for a single field based on its type
     *
     * @param array $field Field definition from config_schema
     * @return mixed
     */
    public function generateFieldPlaceholder($field) {
        $type = $field['type'] ?? 'textinput';
        $name = $field['name'] ?? '';

        switch ($type) {
            case 'textinput':
                // Check for common field name patterns
                if (stripos($name, 'url') !== false || stripos($name, 'link') !== false) {
                    return '#';
                }
                if (stripos($name, 'color') !== false) {
                    return '#007bff';
                }
                if (stripos($name, 'icon') !== false) {
                    return $this->icons[array_rand($this->icons)];
                }
                if (stripos($name, 'button') !== false && stripos($name, 'text') !== false) {
                    return 'Learn More';
                }
                if (stripos($name, 'heading') !== false || stripos($name, 'title') !== false) {
                    return $this->generateLoremPhrase(4, 8);
                }
                // Default: short lorem phrase
                return $this->generateLoremPhrase(3, 6);

            case 'textarea':
                return $this->generateLoremParagraph(2, 3);

            case 'checkboxinput':
                return (bool) rand(0, 1);

            case 'dropinput':
                // Return first option key
                if (!empty($field['options']) && is_array($field['options'])) {
                    $keys = array_keys($field['options']);
                    return $keys[0] ?? '';
                }
                return '';

            case 'repeater':
                return $this->generateRepeaterPlaceholder($field, 3);

            case 'numberinput':
                return rand(1, 100);

            case 'fileinput':
                return 'https://via.placeholder.com/800x400?text=Placeholder+Image';

            default:
                return $this->generateLoremPhrase(2, 4);
        }
    }

    /**
     * Generate placeholder data for a repeater field
     *
     * @param array $field Repeater field definition
     * @param int $count Number of items to generate
     * @return array
     */
    private function generateRepeaterPlaceholder($field, $count = 3) {
        $items = [];
        $subfields = $field['fields'] ?? [];

        for ($i = 0; $i < $count; $i++) {
            $item = [];
            foreach ($subfields as $subfield) {
                $name = $subfield['name'] ?? '';
                if ($name) {
                    // Add variety to repeated items
                    if (stripos($name, 'title') !== false) {
                        $item[$name] = 'Feature ' . ($i + 1);
                    } elseif (stripos($name, 'icon') !== false) {
                        $item[$name] = $this->icons[$i % count($this->icons)];
                    } else {
                        $item[$name] = $this->generateFieldPlaceholder($subfield);
                    }
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Generate a lorem ipsum phrase
     *
     * @param int $min Minimum word count
     * @param int $max Maximum word count
     * @return string
     */
    private function generateLoremPhrase($min, $max) {
        $count = rand($min, $max);
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->lorem_words[array_rand($this->lorem_words)];
        }
        return ucfirst(implode(' ', $words));
    }

    /**
     * Generate a lorem ipsum paragraph
     *
     * @param int $minSentences Minimum sentences
     * @param int $maxSentences Maximum sentences
     * @return string
     */
    private function generateLoremParagraph($minSentences, $maxSentences) {
        $count = rand($minSentences, $maxSentences);
        $sentences = [];
        for ($i = 0; $i < $count; $i++) {
            $sentences[] = $this->generateLoremPhrase(8, 15) . '.';
        }
        return implode(' ', $sentences);
    }

    /**
     * Render a component type with provided data
     *
     * @param Component $componentType
     * @param array $data Config data for the template
     * @param string|null $theme_override Theme name to use instead of active theme
     * @return array ['html' => string, 'error' => string|null, 'template_path' => string]
     */
    public function renderComponent($componentType, $data, $theme_override = null) {
        $template_file = $componentType->get('com_template_file');
        $result = [
            'html' => '',
            'error' => null,
            'template_path' => ''
        ];

        if (!$template_file) {
            $result['error'] = 'No template file configured';
            return $result;
        }

        // Handle both full relative paths and filename-only (legacy)
        // Full path: 'theme/linka-reference/views/components/component.php' or 'views/components/component.php'
        // Filename only: 'component.php'
        if (strpos($template_file, '/') !== false) {
            // Full relative path - use directly
            $template_path = PathHelper::getIncludePath($template_file);
        } else {
            // Filename only - look in views/components with theme override support
            try {
                $template_path = PathHelper::getThemeFilePath(
                    $template_file,
                    'views/components',
                    'system',
                    $theme_override
                );
            } catch (Exception $e) {
                $result['error'] = 'Template resolution failed: ' . $e->getMessage();
                return $result;
            }
        }
        $result['template_path'] = $template_path;

        if (!file_exists($template_path)) {
            $result['error'] = 'Template file not found: ' . $template_file;
            return $result;
        }

        // Render template with output buffering
        ob_start();

        // Make variables available to template (matching ComponentRenderer conventions)
        $component_config = $data;
        $component_data = [];  // No dynamic data in preview
        $component_slug = 'preview-' . $componentType->get('com_type_key');

        try {
            require($template_path);
            $result['html'] = ob_get_clean();
        } catch (Exception $e) {
            ob_end_clean();
            $result['error'] = 'Template error: ' . $e->getMessage();
        } catch (Error $e) {
            ob_end_clean();
            $result['error'] = 'Template error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Get all active component types, optionally filtered
     *
     * @param array $filters ['type' => 'hero_static', 'category' => 'hero']
     * @return array Array of Component objects
     */
    public function getComponentTypes($filters = []) {
        $options = ['active' => true, 'deleted' => false];

        if (!empty($filters['category'])) {
            $options['category'] = $filters['category'];
        }

        $components = new MultiComponent($options, ['com_category' => 'ASC', 'com_title' => 'ASC']);
        $components->load();

        $result = [];
        foreach ($components as $component) {
            // Filter by specific type if requested
            if (!empty($filters['type'])) {
                if ($component->get('com_type_key') !== $filters['type']) {
                    continue;
                }
            }
            $result[] = $component;
        }

        return $result;
    }

    /**
     * Get list of available themes
     *
     * @return array Theme names
     */
    public function getAvailableThemes() {
        $theme_dir = PathHelper::getIncludePath('theme');
        $themes = [];

        if (is_dir($theme_dir)) {
            $dirs = scandir($theme_dir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                if (is_dir($theme_dir . '/' . $dir)) {
                    $themes[] = $dir;
                }
            }
        }

        return $themes;
    }

    /**
     * Get unique categories from active components
     *
     * @return array Category names
     */
    public function getCategories() {
        $components = new MultiComponent(['active' => true, 'deleted' => false], []);
        $components->load();

        $categories = [];
        foreach ($components as $component) {
            $cat = $component->get('com_category');
            if ($cat && !in_array($cat, $categories)) {
                $categories[] = $cat;
            }
        }
        sort($categories);
        return $categories;
    }
}

// Initialize
$previewer = new ComponentPreviewer();

// Get filter parameters (convert empty strings to appropriate defaults)
$type_filter = $_GET['type'] ?? '';
$category_filter = $_GET['category'] ?? '';
$theme_override = !empty($_GET['theme']) ? $_GET['theme'] : null;
$show_config = isset($_GET['config']);
$show_paths = isset($_GET['paths']);

// Get available data for filters
$themes = $previewer->getAvailableThemes();
$categories = $previewer->getCategories();

// Get components to preview
$filters = [];
if ($type_filter) $filters['type'] = $type_filter;
if ($category_filter) $filters['category'] = $category_filter;

$component_types = $previewer->getComponentTypes($filters);

// Get current theme for display
$settings = Globalvars::get_instance();
$active_theme = $settings->get_setting('theme_template');
$display_theme = $theme_override ?: $active_theme;

// Load PublicPage from the appropriate theme
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes', 'system', $theme_override));

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Component Preview',
    'is_valid_page' => true,
    'showheader' => true
));

// Build dropdown options for FormWriter (format: value => label)
$type_options = ['' => 'All Types'];
$all_types = $previewer->getComponentTypes([]);
foreach ($all_types as $ct) {
    $type_options[$ct->get('com_type_key')] = $ct->get('com_title');
}

$category_options = ['' => 'All'];
foreach ($categories as $cat) {
    $category_options[$cat] = ucfirst($cat);
}

$theme_options = ['' => 'Active (' . $active_theme . ')'];
foreach ($themes as $theme) {
    $theme_options[$theme] = $theme;
}

// Get FormWriter for themed form elements
$formwriter = $page->getFormWriter('filter_form', [
    'method' => 'GET',
    'action' => ''
]);
?>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1>Component Preview</h1>
            <p class="text-muted">
                Renders component types with placeholder data for testing.
                Theme: <strong><?php echo htmlspecialchars($display_theme); ?></strong>
                <?php if ($theme_override): ?>
                    <span class="badge bg-info text-white ms-2">Override</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Filter Bar (z-index to stay above stretched-link overlays in component previews) -->
    <div class="card mb-4" style="position: relative; z-index: 100;">
        <div class="card-body">
            <?php
            $formwriter->begin_form();
            ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <?php $formwriter->dropinput('type', 'Component Type', [
                        'options' => $type_options,
                        'value' => $type_filter
                    ]); ?>
                </div>

                <div class="col-md-2">
                    <?php $formwriter->dropinput('category', 'Category', [
                        'options' => $category_options,
                        'value' => $category_filter
                    ]); ?>
                </div>

                <div class="col-md-2">
                    <?php $formwriter->dropinput('theme', 'Theme', [
                        'options' => $theme_options,
                        'value' => $theme_override
                    ]); ?>
                </div>

                <div class="col-md-2">
                    <?php
                    $formwriter->checkboxinput('config', 'Show Config', [
                        'checked' => $show_config
                    ]);
                    $formwriter->checkboxinput('paths', 'Show Paths', [
                        'checked' => $show_paths
                    ]);
                    ?>
                </div>

                <div class="col-md-3">
                    <?php $formwriter->submitbutton('apply', 'Apply'); ?>
                    <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </div>
            <?php $formwriter->end_form(); ?>
        </div>
    </div>

    <!-- Components -->
    <?php if (empty($component_types)): ?>
        <div class="alert alert-info">
            No component types found matching your filters.
        </div>
    <?php else: ?>
        <p class="text-muted mb-4">Showing <?php echo count($component_types); ?> component type(s)</p>

        <?php foreach ($component_types as $componentType):
            $type_key = $componentType->get('com_type_key');
            $placeholder_data = $previewer->generatePlaceholderData($componentType);
            $render_result = $previewer->renderComponent($componentType, $placeholder_data, $theme_override);
            $framework = $componentType->get('com_css_framework');
        ?>
            <div class="card mb-4" id="component-<?php echo htmlspecialchars($type_key); ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            <?php echo htmlspecialchars($componentType->get('com_title')); ?>
                            <small class="text-muted ms-2"><?php echo htmlspecialchars($type_key); ?></small>
                        </h5>
                        <small class="text-muted">
                            Category: <?php echo htmlspecialchars($componentType->get('com_category') ?: 'none'); ?>
                            <?php if ($framework): ?>
                                | Framework: <?php echo htmlspecialchars($framework); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <a href="?type=<?php echo urlencode($type_key); ?><?php echo $theme_override ? '&theme=' . urlencode($theme_override) : ''; ?>"
                       class="btn btn-sm btn-outline-primary">
                        Solo
                    </a>
                </div>

                <?php if ($render_result['error']): ?>
                    <div class="card-body bg-danger text-white">
                        <strong>Error:</strong> <?php echo htmlspecialchars($render_result['error']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($show_paths && $render_result['template_path']): ?>
                    <div class="card-body border-bottom py-2 bg-light">
                        <small><strong>Template:</strong> <code><?php echo htmlspecialchars($render_result['template_path']); ?></code></small>
                    </div>
                <?php endif; ?>

                <?php if ($show_config && !empty($placeholder_data)): ?>
                    <div class="card-body border-bottom py-2 bg-light">
                        <details>
                            <summary><strong>Config Data</strong></summary>
                            <pre class="mt-2 mb-0" style="font-size: 0.8rem;"><?php echo htmlspecialchars(json_encode($placeholder_data, JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Rendered component (outside card for full-width display) -->
            <?php if (!$render_result['error']): ?>
                <div class="component-preview mb-4" style="position: relative; border: 2px dashed #dee2e6; background: #fff;">
                    <?php echo $render_result['html']; ?>
                </div>
            <?php endif; ?>

        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
$page->public_footer();
?>
