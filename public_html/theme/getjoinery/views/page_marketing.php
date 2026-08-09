<?php
/**
 * Full-bleed page template.
 *
 * Selected per page by setting the page's Template field to "page_marketing".
 * The default page template wraps content in a title bar, breadcrumbs and a
 * centred container, which is right for an ordinary content page and wrong for
 * a landing page whose blocks paint their own full-width bands. This one emits
 * the page's components and nothing else.
 *
 * Reached via views/page.php, which hands off here with $page already loaded.
 * Carries no page content of its own — every word on the rendered page comes
 * from the component blocks attached to the page record.
 *
 * @version 1.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$marketing_page = $page;
$marketing_body = $marketing_page->get_filled_content();

$marketing_options = array(
	'is_valid_page'    => $is_valid_page ?? TRUE,
	'showheader'       => TRUE,
	'title'            => $marketing_page->get('pag_title'),
	'entity_type'      => 'page',
	'entity_body_html' => $marketing_body,
);

// Same canonical rule as the default template: a published page answers at both
// /page/{link} and /{link}, and the short one is the real address.
if ($marketing_page->get('pag_link') && $marketing_page->get('pag_published_time') && !$marketing_page->get('pag_delete_time')) {
	$marketing_options['canonical_path'] = '/' . $marketing_page->get('pag_link');
}

$marketing_og = $marketing_page->get_picture_link('og_image') ?: $marketing_page->get_picture_link('hero');
if ($marketing_og) {
	$marketing_options['preview_image_url'] = $marketing_og;
}

$marketing_shell = new PublicPage();
$marketing_shell->public_header($marketing_options);

$marketing_session = SessionControl::get_instance();
$marketing_access = $marketing_page->authenticate_tier($marketing_session);
if ($marketing_access['allowed']) {
	echo $marketing_body;
} else {
	require_once(PathHelper::getIncludePath('includes/tier_gate_prompt.php'));
	render_tier_gate_prompt($marketing_access, ['preview_html' => get_tier_gate_preview_html($marketing_body)]);
}

$marketing_shell->public_footer(array('track' => TRUE));
?>
