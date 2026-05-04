<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Privacy Policy — Joinery',
    'description' => 'How Joinery handles your data: what we collect, what we never do, and your rights.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-privacy');

$page->public_footer();
?>
