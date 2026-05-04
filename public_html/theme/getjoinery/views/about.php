<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'About — Joinery',
    'description' => 'About the Joinery project, its creator, and the philosophy behind building membership software differently.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-about');

$page->public_footer();
?>
