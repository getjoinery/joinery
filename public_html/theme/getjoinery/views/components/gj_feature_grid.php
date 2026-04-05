<?php
$label = $component_config['label'] ?? 'Everything You Need';
$heading = $component_config['heading'] ?? 'One platform, no duct tape';
$subheading = $component_config['subheading'] ?? '';
$features = $component_config['features'] ?? [];
$link_text = $component_config['link_text'] ?? '';
$link_url = $component_config['link_url'] ?? '/features';

// Default features if none configured
if (empty($features)) {
    $features = [
        ['icon' => 'members', 'title' => 'Member Management', 'description' => 'Profiles, permissions, subscription tiers, and groups. Everything you need to organize your people.'],
        ['icon' => 'calendar', 'title' => 'Events & Registration', 'description' => 'Create events, manage signups, handle waitlists. Supports recurring schedules and custom questions.'],
        ['icon' => 'payments', 'title' => 'Payments & E-Commerce', 'description' => 'Stripe and PayPal built in. Sell memberships, products, and event tickets with zero platform fees.'],
        ['icon' => 'email', 'title' => 'Email & Communications', 'description' => 'Newsletters, mailing lists, and notifications. Works with Mailgun or self-hosted — your choice.'],
        ['icon' => 'plugins', 'title' => 'Plugins & Themes', 'description' => 'Extend with plugins, customize with themes. 20+ themes included, from Bootstrap to zero-dependency HTML5.'],
        ['icon' => 'shield', 'title' => 'Your Data, Your Rules', 'description' => 'Self-host anytime. Export everything. No lock-in, no data selling. Source available for full transparency.'],
    ];
}

$icons = [
    'members' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'payments' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'email' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
    'plugins' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
    'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
];
?>

<section class="section">
    <div class="container">
        <?php if ($label): ?>
            <div class="section-label"><?= htmlspecialchars($label) ?></div>
        <?php endif; ?>
        <h2 class="section-title"><?= htmlspecialchars($heading) ?></h2>
        <?php if ($subheading): ?>
            <p class="section-subtitle"><?= htmlspecialchars($subheading) ?></p>
        <?php endif; ?>
        <div class="feature-grid">
            <?php foreach ($features as $feature): ?>
                <div class="feature-card">
                    <div class="feature-icon">
                        <?php
                        $icon_key = $feature['icon'] ?? '';
                        if (isset($icons[$icon_key])) {
                            echo $icons[$icon_key];
                        } elseif (strpos($icon_key, '<svg') !== false) {
                            echo $icon_key;
                        }
                        ?>
                    </div>
                    <h3><?= htmlspecialchars($feature['title'] ?? '') ?></h3>
                    <p><?= htmlspecialchars($feature['description'] ?? '') ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($link_text): ?>
            <p style="text-align: center; margin-top: 2rem;">
                <a href="<?= htmlspecialchars($link_url) ?>" class="link-arrow"><?= htmlspecialchars($link_text) ?> &rarr;</a>
            </p>
        <?php endif; ?>
    </div>
</section>
