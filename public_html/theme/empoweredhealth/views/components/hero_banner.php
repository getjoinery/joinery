<?php
/**
 * Hero Banner Component
 *
 * Full-width hero section with background image overlay, heading, subheading, and CTA button.
 * Copied from empoweredhealthtn.com
 */

$heading = $component_config['heading'] ?? 'Empowering You to Be Healthy';
$subheading = $component_config['subheading'] ?? '';
$button_text = $component_config['button_text'] ?? 'Learn More';
$button_url = $component_config['button_url'] ?? '/about';
$height = $component_config['height'] ?? '493';
?>

<section class="banner-area relative" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row fullscreen d-flex align-items-center justify-content-center" style="height: <?= htmlspecialchars($height) ?>px;">
            <div class="banner-content col-lg-8 col-md-12">
                <h1>
                    <?= htmlspecialchars($heading) ?>
                </h1>
                <?php if ($subheading): ?>
                <p class="pt-10 pb-10 text-white">
                    <?= htmlspecialchars($subheading) ?>
                </p>
                <?php endif; ?>
                <?php if ($button_text): ?>
                <a href="<?= htmlspecialchars($button_url) ?>" class="primary-btn text-uppercase"><?= htmlspecialchars($button_text) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
