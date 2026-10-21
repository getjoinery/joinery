<?php
	// The site's pages as the Tailwind theme laid them out: the title centered
	// over a panel, the text in a readable prose column.
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('page_logic.php', 'logic'));

	$page_vars = process_logic(page_logic(array_merge($_GET, $_POST, $params ?? [])));
	$page = $page_vars['page'];

	require_once(PathHelper::getIncludePath('data/ab_tests_class.php'));
	AbTest::apply_variant($page);

	if ($template = $page->get('pag_template')) {
		$template_path = PathHelper::getThemeFilePath($template . '.php', 'views', 'system', NULL, NULL, false, false);
		if ($template_path) {
			require($template_path);
			return;
		}
	}

	$paget = new PublicPage();
	$page_header_options = array(
		'is_valid_page'    => $is_valid_page ?? false,
		'title'            => $page->get('pag_title'),
		'entity_type'      => 'page',
		'entity_body_html' => $page->get_filled_content(),
	);
	$og_img = $page->get_picture_link('og_image') ?: $page->get_picture_link('hero');
	if ($og_img) {
		$page_header_options['preview_image_url'] = $og_img;
	}
	$paget->public_header($page_header_options);

	echo PublicPage::BeginPage($page->get('pag_title'));
	echo PublicPage::BeginPanel();
	echo '<div class="text-lg prose max-w-prose mx-auto">';

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
		$preview_html = get_tier_gate_preview_html($page->get_filled_content());
		render_tier_gate_prompt($page_tier_access, ['preview_html' => $preview_html]);
	}

	echo '</div>';
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();

	$paget->public_footer($foptions=array('track'=>TRUE));
?>
