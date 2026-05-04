<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Developers — Joinery',
    'description' => 'PostgreSQL. PHP 8.x. REST API. Plugin system. Theme engine. Readable code, no lock-in.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-developers');

$page->public_footer();
?>
