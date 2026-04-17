<?php
$heading = $component_config['heading'] ?? 'Built for real organizations';
$audiences = $component_config['audiences'] ?? [];

if (empty($audiences)) {
    $audiences = [
        ['icon' => 'members', 'title' => 'Clubs & Associations', 'description' => 'Manage members, collect dues, and organize events for your community.'],
        ['icon' => 'heart', 'title' => 'Nonprofits', 'description' => 'Track supporters, run fundraisers, and communicate with donors and volunteers.'],
        ['icon' => 'globe', 'title' => 'Community Groups', 'description' => 'Build your community on your terms, not someone else\'s platform.'],
        ['icon' => 'briefcase', 'title' => 'Small Businesses', 'description' => 'Membership programs, subscriptions, and customer management without enterprise pricing.'],
    ];
}

$icons = [
    'members' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
    'globe' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
    'briefcase' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
];
?>

<section class="section">
    <div class="container">
        <h2 class="section-title"><?= htmlspecialchars($heading) ?></h2>
        <div class="audience-grid">
            <?php foreach ($audiences as $aud): ?>
                <div class="audience-card">
                    <div class="audience-icon">
                        <?php
                        $icon_key = $aud['icon'] ?? '';
                        if (isset($icons[$icon_key])) {
                            echo $icons[$icon_key];
                        }
                        ?>
                    </div>
                    <h3><?= htmlspecialchars($aud['title'] ?? '') ?></h3>
                    <p><?= htmlspecialchars($aud['description'] ?? '') ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
