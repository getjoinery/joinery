<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));

// Installed sites carry LICENSE.md inside public_html (the release archive puts
// it there); source checkouts keep it at the site root alongside config/.
$license_path = PathHelper::getAbsolutePath('LICENSE.md');
if (!file_exists($license_path)) {
	$license_path = PathHelper::getSiteRoot() . '/LICENSE.md';
}

$license_html = '';
if (file_exists($license_path)) {
	$license_html = MarkdownRenderer::render(file_get_contents($license_path));
}

$page = new PublicPage();
$page->public_header(array(
	'title' => 'License',
	'meta_description' => 'Software license terms for this platform.',
));
?>

<div class="jy-ui">

<style>
	<?php echo MarkdownRenderer::get_css(); ?>
</style>

<section class="jy-docs-section">
	<div class="container">
		<?php if ($license_html): ?>
			<div class="markdown-content"><?php echo $license_html; ?></div>
		<?php else: ?>
			<p>The license file is not available on this install. The platform is licensed under the
			<a href="https://polyformproject.org/licenses/shield/1.0.0">PolyForm Shield License 1.0.0</a>.</p>
		<?php endif; ?>
	</div>
</section>

</div>

<?php
$page->public_footer();
?>
