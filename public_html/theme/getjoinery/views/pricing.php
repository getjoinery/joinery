<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Pricing — Joinery',
    'description' => 'Simple, honest pricing. All features included on every plan. No transaction fees. Self-hosting is free.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-pricing');

$page->public_footer();
?>
