<?php
$heading = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$link_text = $component_config['link_text'] ?? '';
$link_url = $component_config['link_url'] ?? '';
$background = $component_config['background'] ?? 'alt';
$show_tick = !isset($component_config['show_tick']) || $component_config['show_tick'];
$comparisons = $component_config['comparisons'] ?? [];

// No sample content: an unconfigured block renders nothing.

$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
?>

<section class="section<?= $background === 'alt' ? ' section-alt' : '' ?>">
    <div class="container">
        <?php if ($heading): ?><h2 class="section-title"><?= htmlspecialchars($heading) ?></h2><?php endif; ?>
        <?php if ($subheading): ?><p class="section-subtitle"><?= htmlspecialchars($subheading) ?></p><?php endif; ?>
        <div class="diff-cards">
            <?php foreach ($comparisons as $comp): ?>
                <div class="diff-card">
                    <div class="diff-ours"><?php if ($show_tick) echo $check_svg; ?> <?= htmlspecialchars($comp['ours'] ?? '') ?></div>
                    <div class="diff-theirs"><?= htmlspecialchars($comp['theirs'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($link_text && $link_url): ?>
        <p style="text-align: center; margin-top: 2rem;">
            <a href="<?= htmlspecialchars($link_url) ?>" class="link-arrow"><?= htmlspecialchars($link_text) ?> &rarr;</a>
        </p>
        <?php endif; ?>
    </div>
</section>
