<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Philosophy — Joinery',
    'description' => 'Why Joinery exists. Privacy, data ownership, transparency, and building software that respects the people who use it.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-philosophy');

$page->public_footer();
?>
