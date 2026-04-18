<?php
	// PathHelper is always available - never require it
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('page_logic.php', 'logic'));

	$page_vars = process_logic(page_logic($_GET, $_POST, $page, $params));
	$page = $page_vars['page'];

	$paget = new PublicPage();
	$page_header_options = array(
		'is_valid_page' => $is_valid_page ?? false,
		'title' => $page->get('pag_title')
	);
	if ($page->get_picture_link('hero')) {
		$page_header_options['preview_image_url'] = $page->get_picture_link('hero');
	}
	$paget->public_header($page_header_options);
?>
<div class="jy-ui">

	<!-- Page Title
	============================================= -->
	<section class="page-title bg-transparent">
		<div class="jy-container">
			<div class="page-title-row">

				<div class="page-title-content">
					<h1><?php echo htmlspecialchars($page->get('pag_title')); ?></h1>
				</div>

				<nav aria-label="breadcrumb">
					<ol class="breadcrumb">
						<li class="breadcrumb-item"><a href="/">Home</a></li>
						<li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($page->get('pag_title')); ?></li>
					</ol>
				</nav>

			</div>
		</div>
	</section><!-- .page-title end -->

	<!-- Content
	============================================= -->
	<section id="content">
		<div class="content-wrap">
			<div class="jy-container">

				<div class="row gx-5">
					<div class="col-lg-12">
						<div class="bg-white rounded-4 shadow-sm p-4">
							<?php
							require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
							echo ComponentRenderer::render(null, 'image_gallery', [
								'photos' => $page->get_photos(),
								'primary_file_id' => $page->get('pag_fil_file_id'),
								'alt_text' => $page->get('pag_title'),
							]);
							?>
							<div class="entry-content">
								<?php
								$session = SessionControl::get_instance();
								$page_tier_access = $page->authenticate_tier($session);
								if ($page_tier_access['allowed']) {
									echo $page->get_filled_content();
								} else {
									require_once(PathHelper::getIncludePath('includes/tier_gate_prompt.php'));
									$preview_html = get_tier_gate_preview_html($page->get('pag_body'));
									render_tier_gate_prompt($page_tier_access, ['preview_html' => $preview_html]);
								}
								?>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>
	</section><!-- #content end -->

</div>
<?php
	$paget->public_footer($foptions=array('track'=>TRUE));
?>