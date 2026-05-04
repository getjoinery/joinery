<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Showcase — Joinery',
    'description' => 'Applications and services built on the Joinery platform. See what you can build.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-showcase');

$page->public_footer();
?>
