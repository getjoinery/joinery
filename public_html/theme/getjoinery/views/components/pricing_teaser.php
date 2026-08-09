<?php
$heading = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$link_text = $component_config['link_text'] ?? '';
$link_url = $component_config['link_url'] ?? '';

// Which tier gets the accent border, and what follows each price. Both default
// to the recurring-plan shape this started as, so existing instances are
// unchanged; a one-time or free offer sets its own suffix and highlight.
$featured_tier = (string)($component_config['featured_tier'] ?? '2');
$default_suffix = $component_config['price_suffix'] ?? '/mo';

$tiers = [
    ['name' => $component_config['tier1_name'] ?? '', 'price' => $component_config['tier1_price'] ?? '', 'note' => $component_config['tier1_note'] ?? '', 'suffix' => $component_config['tier1_suffix'] ?? $default_suffix, 'featured' => ($featured_tier === '1')],
    ['name' => $component_config['tier2_name'] ?? '', 'price' => $component_config['tier2_price'] ?? '', 'note' => $component_config['tier2_note'] ?? '', 'suffix' => $component_config['tier2_suffix'] ?? $default_suffix, 'featured' => ($featured_tier === '2')],
    ['name' => $component_config['tier3_name'] ?? '', 'price' => $component_config['tier3_price'] ?? '', 'note' => $component_config['tier3_note'] ?? '', 'suffix' => $component_config['tier3_suffix'] ?? $default_suffix, 'featured' => ($featured_tier === '3')],
];
?>

<section class="section section-white text-center">
    <div class="container">
        <?php if ($heading): ?><h2 class="section-title"><?= htmlspecialchars($heading) ?></h2><?php endif; ?>
        <?php if ($subheading): ?>
            <p class="section-subtitle"><?= htmlspecialchars($subheading) ?></p>
        <?php endif; ?>
        <div class="pricing-cards">
            <?php foreach ($tiers as $tier): if ($tier['name'] === '' && $tier['price'] === '') continue; ?>
                <div class="pricing-card<?= $tier['featured'] ? ' featured' : '' ?>">
                    <div class="tier-name"><?= htmlspecialchars($tier['name']) ?></div>
                    <div class="price"><?= htmlspecialchars($tier['price']) ?><?php if ($tier['suffix'] !== ''): ?><span><?= htmlspecialchars($tier['suffix']) ?></span><?php endif; ?></div>
                    <div class="note"><?= htmlspecialchars($tier['note']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($link_text): ?>
            <a href="<?= htmlspecialchars($link_url) ?>" class="link-arrow"><?= htmlspecialchars($link_text) ?> &rarr;</a>
        <?php endif; ?>
    </div>
</section>
