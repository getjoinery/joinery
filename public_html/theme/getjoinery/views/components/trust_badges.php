<?php
$badges = $component_config['badges'] ?? [];

// Default badges if none configured
if (empty($badges)) {
    $badges = [
        ['icon' => 'shield', 'text' => 'Source Available'],
        ['icon' => 'check', 'text' => '0% Transaction Fees'],
        ['icon' => 'lock', 'text' => 'Self-Host Option'],
        ['icon' => 'download', 'text' => 'Full Data Export'],
        ['icon' => 'heart', 'text' => 'Free for Personal Use'],
    ];
}

$icons = [
    'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    'lock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    'heart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
];
?>

<div class="trust-bar">
    <div class="trust-items">
        <?php foreach ($badges as $badge): ?>
            <div class="trust-item">
                <?php
                $icon_key = $badge['icon'] ?? '';
                if (isset($icons[$icon_key])) {
                    echo $icons[$icon_key];
                } elseif (strpos($icon_key, '<svg') !== false) {
                    echo $icon_key;
                }
                ?>
                <?= htmlspecialchars($badge['text'] ?? '') ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
