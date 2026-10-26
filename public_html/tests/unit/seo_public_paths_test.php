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

harness_finish();
