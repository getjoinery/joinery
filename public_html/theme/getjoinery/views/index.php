<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Joinery — Membership software you can trust with your data',
    'description' => 'All-in-one platform for managing members, events, payments, and communications. Hosted for you or self-hosted — your choice, your data.',
    'showheader' => true,
]);

echo ComponentRenderer::render('gj-home');

$page->public_footer();
?>
