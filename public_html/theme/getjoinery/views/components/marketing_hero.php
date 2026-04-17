<?php
$heading = $component_config['heading'] ?? 'Membership software you can trust with your data';
$subheading = $component_config['subheading'] ?? '';
$primary_text = $component_config['primary_button_text'] ?? 'Start Free Trial';
$primary_url = $component_config['primary_button_url'] ?? '#';
$secondary_text = $component_config['secondary_button_text'] ?? '';
$secondary_url = $component_config['secondary_button_url'] ?? '/features';
?>

<section class="hero">
    <h1><?= htmlspecialchars($heading) ?></h1>
    <?php if ($subheading): ?>
        <p><?= htmlspecialchars($subheading) ?></p>
    <?php endif; ?>
    <div class="btn-group btn-group-center">
        <?php if ($primary_text): ?>
            <a href="<?= htmlspecialchars($primary_url) ?>" class="btn btn-primary"><?= htmlspecialchars($primary_text) ?></a>
        <?php endif; ?>
        <?php if ($secondary_text): ?>
            <a href="<?= htmlspecialchars($secondary_url) ?>" class="btn btn-secondary"><?= htmlspecialchars($secondary_text) ?></a>
        <?php endif; ?>
    </div>
</section>
