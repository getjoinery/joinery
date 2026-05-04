<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Features — Joinery',
    'description' => 'Members, events, payments, email, themes, plugins, API, and more — all included in every plan.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-features');

$page->public_footer();
?>
