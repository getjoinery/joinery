<?php
/** @joinery-test
 * name: seo_public_paths
 * tier: db
 * env: any
 * needs: []
 */
/**
 * The sitemap and the SEO inventory list only declared public pages
 * (SeoPageMetadata::enumerate_public_paths()): core's own list, the active
 * theme's theme.json publicPaths and each active plugin's plugin.json
 * publicPaths. A view file is not a public page: a site's sitemap once listed
 * /copy-key, /sm_ssl_probe, /oauth_callback and the sign-in steps because
 * every file in views/ was taken for one.
 *
 * Run: php tests/run.php db --filter=seo_public_paths
 *
 * @version 1.1 - a publicPaths entry may name a setting; pages list only published, products only active
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$static = array();
foreach (SeoPageMetadata::enumerate_public_paths() as $rec) {
	if ($rec['entity_type'] === null) $static[] = $rec['path'];
}

section('core lists its declared pages and no utility views');
foreach (SeoPageMetadata::CORE_PUBLIC_PATHS as $path) {
	check(in_array($path, $static, true), "$path is listed");
}
foreach (array('/copy-key', '/sm_ssl_probe', '/oauth_callback', '/verify-totp', '/setup', '/app_bridge', '/drive', '/password-set', '/manifest') as $path) {
	check(!in_array($path, $static, true), "$path is not listed", implode(' ', $static));
}

section('a theme declares its own public pages in theme.json');
check(ThemeHelper::config('publicPaths', array(), 'scrolldaddy') === array('/pricing'), 'scrolldaddy declares /pricing');
$active = (string)Globalvars::get_instance()->get_setting('theme_template', false, true);
foreach (SeoPageMetadata::theme_public_paths() as $path) {
	check(in_array($path, $static, true), "the active theme's $path is listed ($active)");
}

section('a path that depends on a setting, and entities listed only when their page shows them');
$entry = new ReflectionMethod('SeoPageMetadata', 'public_path_entry');
check($entry->invoke(null, '/forum') === '/forum', 'a plain entry is its path');
check($entry->invoke(null, array('path' => '/x', 'setting' => 'no_such_setting_' . getmypid())) === false,
	'an entry whose setting is off (or absent) is left out');
check($entry->invoke(null, array('path' => '/x')) === '/x', 'an entry naming no setting is listed');
$widget_on = (string)Globalvars::get_instance()->get_setting('support_desk_widget_enabled', true, true) === '1';
if (PluginHelper::isPluginActive('support_desk')) {
	check(in_array('/support_desk/ask', $static, true) === $widget_on, '/support_desk/ask is listed exactly while the widget is on');
}
$db = DbConnector::get_instance()->get_db_link();
$by_type = array();
foreach (SeoPageMetadata::enumerate_public_paths() as $rec) {
	if ($rec['entity_type'] !== null) $by_type[$rec['entity_type']][] = (int)$rec['entity_id'];
}
if (!empty($by_type['page'])) {
	$ids = implode(',', array_map('intval', $by_type['page']));
	check((int)$db->query("SELECT count(*) FROM pag_pages WHERE pag_page_id IN ($ids) AND pag_published_time IS NULL")->fetchColumn() === 0,
		'no unpublished page is listed (page_logic shows them a 404)');
}
if (!empty($by_type['product'])) {
	$ids = implode(',', array_map('intval', $by_type['product']));
	check((int)$db->query("SELECT count(*) FROM pro_products WHERE pro_product_id IN ($ids) AND pro_is_active IS NOT TRUE")->fetchColumn() === 0,
		'no inactive product is listed (product_logic shows them a 404)');
}

harness_finish();
