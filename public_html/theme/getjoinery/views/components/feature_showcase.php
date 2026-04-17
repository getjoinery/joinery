<?php
$title = $component_config['title'] ?? '';
$description = $component_config['description'] ?? '';
$bullets = $component_config['bullets'] ?? [];
$image_url = $component_config['image_url'] ?? '';
$placeholder_text = $component_config['placeholder_text'] ?? 'Screenshot coming soon';
$reverse = ($component_config['reverse'] ?? 'no') === 'yes';

$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
?>

<div class="feature-showcase<?= $reverse ? ' reverse' : '' ?>">
    <div class="feature-showcase-content">
        <?php if ($title): ?>
            <h3><?= htmlspecialchars($title) ?></h3>
        <?php endif; ?>
        <?php if ($description): ?>
            <p><?= htmlspecialchars($description) ?></p>
        <?php endif; ?>
        <?php if (!empty($bullets)): ?>
            <ul>
                <?php foreach ($bullets as $bullet): ?>
                    <li><?= $check_svg ?> <?= htmlspecialchars($bullet['text'] ?? '') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="feature-showcase-image">
        <?php if ($image_url): ?>
            <img src="<?= htmlspecialchars($image_url) ?>" alt="<?= htmlspecialchars($title) ?>">
        <?php else: ?>
            <span class="placeholder-text"><?= htmlspecialchars($placeholder_text) ?></span>
        <?php endif; ?>
    </div>
</div>
