<?php
$heading = $component_config['heading'] ?? 'Ready to own your membership platform?';
$subheading = $component_config['subheading'] ?? 'Start your free trial today. No credit card required.';
$button_text = $component_config['button_text'] ?? 'Start Free Trial';
$button_url = $component_config['button_url'] ?? '#';
$secondary_text = $component_config['secondary_text'] ?? '';
$secondary_url = $component_config['secondary_url'] ?? '';
$style = $component_config['style'] ?? 'dark';

$section_class = $style === 'dark' ? 'section section-dark' : 'section section-alt';
$btn_class = $style === 'dark' ? 'btn btn-primary' : 'btn btn-primary';
?>

<section class="<?= $section_class ?> text-center">
    <div class="container">
        <h2 class="section-title" style="margin-bottom: 1rem;"><?= htmlspecialchars($heading) ?></h2>
        <?php if ($subheading): ?>
            <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;"><?= htmlspecialchars($subheading) ?></p>
        <?php endif; ?>
        <div class="btn-group btn-group-center">
            <?php if ($button_text): ?>
                <a href="<?= htmlspecialchars($button_url) ?>" class="<?= $btn_class ?>"><?= htmlspecialchars($button_text) ?></a>
            <?php endif; ?>
            <?php if ($secondary_text): ?>
                <a href="<?= htmlspecialchars($secondary_url) ?>" class="btn btn-outline" style="<?= $style === 'dark' ? 'color: white; border-color: rgba(255,255,255,0.3);' : '' ?>"><?= htmlspecialchars($secondary_text) ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>
