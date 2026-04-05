<?php
$heading = $component_config['heading'] ?? 'How Joinery is different';
$comparisons = $component_config['comparisons'] ?? [];

if (empty($comparisons)) {
    $comparisons = [
        ['ours' => 'Your data is yours — export, self-host, or delete anytime', 'theirs' => 'Others keep your data on their servers, governed by their terms'],
        ['ours' => 'Source available — read every line that touches your data', 'theirs' => 'Others use opaque code you can never see or audit'],
        ['ours' => 'No lock-in — we earn your business every month', 'theirs' => 'Others design switching costs to keep you trapped'],
        ['ours' => 'Zero platform transaction fees — keep what you earn', 'theirs' => 'Others charge 2-5% platform fees on every transaction'],
    ];
}

$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
?>

<section class="section section-alt">
    <div class="container">
        <h2 class="section-title"><?= htmlspecialchars($heading) ?></h2>
        <div class="diff-cards">
            <?php foreach ($comparisons as $comp): ?>
                <div class="diff-card">
                    <div class="diff-ours"><?= $check_svg ?> <?= htmlspecialchars($comp['ours'] ?? '') ?></div>
                    <div class="diff-theirs"><?= htmlspecialchars($comp['theirs'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
