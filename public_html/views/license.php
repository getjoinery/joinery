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

// The business license sits beside the core one, so a buyer can read the terms
// they are purchasing without asking for them.
$business_path = dirname($license_path) . '/LICENSE-BUSINESS.md';
$business_html = '';
if (file_exists($business_path)) {
	$business_html = MarkdownRenderer::render(file_get_contents($business_path));
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
			<?php if ($business_html): ?>
				<hr>
				<div class="markdown-content"><?php echo $business_html; ?></div>
			<?php endif; ?>
		<?php else: ?>
			<p>The license file is not available on this install. The platform is licensed under the
			<a href="https://polyformproject.org/licenses/noncommercial/1.0.0">PolyForm Noncommercial License 1.0.0</a>
			for noncommercial use. Business use is licensed separately under the
			Joinery Business License, available at <a href="https://getjoinery.com">getjoinery.com</a>.</p>
		<?php endif; ?>
	</div>
</section>

</div>

<?php
$page->public_footer();
?>
