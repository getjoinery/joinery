<?php
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('page_logic.php', 'logic'));

	$page_vars = process_logic(page_logic($_GET, $_POST, $page, $params));
	$page = $page_vars['page'];

	$paget = new PublicPage();
	$page_header_options = array(
		'is_valid_page' => $is_valid_page ?? false,
		'title' => $page->get('pag_title')
	);
	if ($page->get('pag_body')) {
		$pag_desc = trim(strip_tags($page->get('pag_body')));
		if (mb_strlen($pag_desc) > 160) {
			$pag_desc = mb_substr($pag_desc, 0, 157) . '...';
		}
		if ($pag_desc) {
			$page_header_options['meta_description'] = $pag_desc;
		}
	}
	$og_img = $page->get_picture_link('og_image') ?: $page->get_picture_link('hero');
	if ($og_img) {
		$page_header_options['preview_image_url'] = $og_img;
	}
	$paget->public_header($page_header_options);
?>

	<div class="breadcumb-wrapper" data-bg-src="/plugins/scrolldaddy/assets/img/bg/breadcumb-bg.jpg">
		<div class="container">
			<div class="breadcumb-content">
				<h1 class="breadcumb-title"><?php echo htmlspecialchars($page->get('pag_title')); ?></h1>
			</div>
		</div>
	</div>

	<section class="space">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-10">
					<article class="sd-page-content">
						<?php
						require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
						echo ComponentRenderer::render(null, 'image_gallery', [
							'photos' => $page->get_photos(),
							'primary_file_id' => $page->get('pag_fil_file_id'),
							'alt_text' => $page->get('pag_title'),
						]);

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
					</article>
				</div>
			</div>
		</div>
	</section>

<?php
	$paget->public_footer($foptions=array('track'=>TRUE));
?>
