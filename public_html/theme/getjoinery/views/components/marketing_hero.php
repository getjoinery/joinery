<?php
$heading = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$primary_text = $component_config['primary_button_text'] ?? '';
$primary_url = $component_config['primary_button_url'] ?? '';
$secondary_text = $component_config['secondary_button_text'] ?? '';
$secondary_url = $component_config['secondary_button_url'] ?? '';
$btn_size = ($component_config['button_size'] ?? '') === 'large' ? ' btn-lg' : '';
?>

<section class="hero">
    <?php if ($heading): ?><h1><?= htmlspecialchars($heading) ?></h1><?php endif; ?>
    <?php if ($subheading): ?>
        <p><?= htmlspecialchars($subheading) ?></p>
    <?php endif; ?>
    <div class="btn-group btn-group-center">
        <?php if ($primary_text): ?>
            <a href="<?= htmlspecialchars($primary_url) ?>" class="btn btn-primary<?= $btn_size ?>"><?= htmlspecialchars($primary_text) ?></a>
        <?php endif; ?>
        <?php if ($secondary_text): ?>
            <a href="<?= htmlspecialchars($secondary_url) ?>" class="btn btn-secondary<?= $btn_size ?>"><?= htmlspecialchars($secondary_text) ?></a>
        <?php endif; ?>
    </div>
</section>
