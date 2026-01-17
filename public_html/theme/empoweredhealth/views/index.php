<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$page = new PublicPage();
$page->public_header(array(
    'title' => 'Home - ' . $settings->get_setting('site_name', true, true),
    'showheader' => true
));

/**
 * Helper function to render a theme component with default or custom config
 */
function render_theme_component($component_name, $config = []) {
    $component_config = $config;
    $component_data = [];
    $component = null;
    $component_type_record = null;
    $component_slug = $component_name;

    $template_path = PathHelper::getThemeFilePath($component_name . '.php', 'views/components');
    if (file_exists($template_path)) {
        include($template_path);
    }
}

// Render Hero Banner
render_theme_component('hero_banner');

// Render About Section with Letter
render_theme_component('about_section');

// Render Pricing Section
render_theme_component('pricing_section');

// Render Specialties Section
render_theme_component('specialties_section');

// Render Testimonials Carousel
render_theme_component('testimonials_carousel');

$page->public_footer();
?>
