<?php
/**
 * Linka Newsletter Component
 *
 * Newsletter call-to-action that links to the list signup page.
 * This is a presentation-only component - actual signup is handled
 * by the existing /list/{slug} page.
 *
 * @version 1.1.0
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$title = $component_config['title'] ?? 'Subscribe to Newsletter';
$subtitle = $component_config['subtitle'] ?? 'Get the latest updates and news delivered to your inbox.';
$signup_url = $component_config['signup_url'] ?? '/list/newsletter';
$button_text = $component_config['button_text'] ?? 'Subscribe Now';
$background_color = $component_config['background_color'] ?? '#1a1a1a';
?>

<section class="newsletter-area py-5" style="background-color: <?php echo htmlspecialchars($background_color); ?>;">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="newsletter-content text-white">
                    <?php if ($title): ?>
                        <h3><?php echo htmlspecialchars($title); ?></h3>
                    <?php endif; ?>
                    <?php if ($subtitle): ?>
                        <p class="mb-0"><?php echo htmlspecialchars($subtitle); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6 text-lg-end">
                <a href="<?php echo htmlspecialchars($signup_url); ?>" class="default-btn">
                    <?php echo htmlspecialchars($button_text); ?>
                </a>
            </div>
        </div>
    </div>
</section>
