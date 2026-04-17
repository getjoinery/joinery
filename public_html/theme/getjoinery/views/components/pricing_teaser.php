<?php
$heading = $component_config['heading'] ?? 'Simple, honest pricing';
$subheading = $component_config['subheading'] ?? '';
$link_text = $component_config['link_text'] ?? 'See full pricing';
$link_url = $component_config['link_url'] ?? '/pricing';

$tiers = [
    ['name' => $component_config['tier1_name'] ?? 'Starter', 'price' => $component_config['tier1_price'] ?? '$29', 'note' => $component_config['tier1_note'] ?? 'Up to 250 members', 'featured' => false],
    ['name' => $component_config['tier2_name'] ?? 'Organization', 'price' => $component_config['tier2_price'] ?? '$59', 'note' => $component_config['tier2_note'] ?? 'Up to 2,000 members', 'featured' => true],
    ['name' => $component_config['tier3_name'] ?? 'Network', 'price' => $component_config['tier3_price'] ?? '$99', 'note' => $component_config['tier3_note'] ?? 'Up to 10,000 members', 'featured' => false],
];
?>

<section class="section section-white text-center">
    <div class="container">
        <h2 class="section-title"><?= htmlspecialchars($heading) ?></h2>
        <?php if ($subheading): ?>
            <p class="section-subtitle"><?= htmlspecialchars($subheading) ?></p>
        <?php endif; ?>
        <div class="pricing-cards">
            <?php foreach ($tiers as $tier): ?>
                <div class="pricing-card<?= $tier['featured'] ? ' featured' : '' ?>">
                    <div class="tier-name"><?= htmlspecialchars($tier['name']) ?></div>
                    <div class="price"><?= htmlspecialchars($tier['price']) ?><span>/mo</span></div>
                    <div class="note"><?= htmlspecialchars($tier['note']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($link_text): ?>
            <a href="<?= htmlspecialchars($link_url) ?>" class="link-arrow"><?= htmlspecialchars($link_text) ?> &rarr;</a>
        <?php endif; ?>
    </div>
</section>
