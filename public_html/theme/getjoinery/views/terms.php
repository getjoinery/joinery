<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Terms of Service — Joinery',
    'description' => 'Terms of service for Joinery. Plain language, fair terms, no surprises.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-terms');

$page->public_footer();
?>
