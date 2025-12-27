<?php
/**
 * Linka Newsletter Component
 *
 * Newsletter subscription form with email input.
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
$form_action = $component_config['form_action'] ?? '/ajax/newsletter';
$button_text = $component_config['button_text'] ?? 'Subscribe';
$placeholder = $component_config['placeholder'] ?? 'Enter Your Email';
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
            <div class="col-lg-6">
                <form class="newsletter-form" action="<?php echo htmlspecialchars($form_action); ?>" method="POST">
                    <div class="input-group">
                        <input type="email" name="email" class="form-control" placeholder="<?php echo htmlspecialchars($placeholder); ?>" required>
                        <button class="default-btn" type="submit">
                            <?php echo htmlspecialchars($button_text); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
