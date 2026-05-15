<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-home');

$page->public_footer();
?>
