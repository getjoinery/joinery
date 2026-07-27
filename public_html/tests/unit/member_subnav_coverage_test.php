<?php
/** @joinery-test
 * name: member_subnav_coverage
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The member section nav — the menu a signed-in member uses to move between
 * the account pages — is rendered by PublicPageBase::render_member_subnav().
 * It only appears if a header renderer actually calls it, so a theme that
 * writes its own public_header() silently drops the nav by omission. That is
 * exactly how jeremytunnell.com ended up with a /profile page that had no way
 * to reach /profile/calendar or any other member page.
 *
 * This test asserts every renderer that owns a public_header() also emits the
 * nav — by calling render_member_subnav() or by overriding it.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = rtrim(PathHelper::getIncludePath(''), '/');

/**
 * Every PublicPage renderer in the tree: the core base renderers plus each
 * theme's own class. Globbed rather than listed so a newly added theme is
 * covered the moment it lands.
 */
$candidates = array_merge(
	glob($root . '/includes/PublicPage*.php') ?: [],
	glob($root . '/theme/*/includes/PublicPage.php') ?: []
);

// ---------------------------------------------------------------------------
section('The renderer inventory is intact');
// ---------------------------------------------------------------------------

check(count($candidates) >= 15,
	'found the PublicPage renderers to check',
	'only ' . count($candidates) . ' found — has the theme layout moved?');

// ---------------------------------------------------------------------------
section('render_member_subnav() is defined on the base');
// ---------------------------------------------------------------------------

$base_src = file_get_contents($root . '/includes/PublicPageBase.php');

check(strpos($base_src, 'function render_member_subnav') !== false,
	'PublicPageBase declares render_member_subnav()');
check(strpos($base_src, 'function member_subnav_items') !== false,
	'PublicPageBase declares member_subnav_items()');

// ---------------------------------------------------------------------------
section('Every header renderer emits the member section nav');
// ---------------------------------------------------------------------------

foreach ($candidates as $file) {
	$rel = ltrim(str_replace($root, '', $file), '/');
	$src = file_get_contents($file);

	// PublicPageBase owns the method; nothing to call there. A renderer with no
	// public_header() of its own inherits a parent's markup, call included.
	if ($rel === 'includes/PublicPageBase.php') {
		continue;
	}
	if (!preg_match('/function\s+public_header\s*\(/', $src)) {
		continue;
	}

	$calls    = (bool)preg_match('/\$this->render_member_subnav\s*\(/', $src);
	$overrides = (bool)preg_match('/function\s+render_member_subnav\s*\(/', $src);

	check($calls || $overrides,
		$rel . ' emits the member section nav',
		'defines public_header() but never calls $this->render_member_subnav() — '
		. 'members on this theme cannot reach the other account pages. Call it '
		. 'right after the site header, or override render_member_subnav() to '
		. 'supply theme markup.');
}

// ---------------------------------------------------------------------------
section('A theme overriding the renderer still sources items from the base');
// ---------------------------------------------------------------------------

foreach ($candidates as $file) {
	$rel = ltrim(str_replace($root, '', $file), '/');
	$src = file_get_contents($file);

	if (!preg_match('/function\s+render_member_subnav\s*\(/', $src)) {
		continue;
	}

	// An override styles the nav; it must not re-derive the list, or the
	// permission/setting gates on the seeded profile menu drift per theme.
	check(strpos($src, 'member_subnav_items(') !== false,
		$rel . ' takes its item list from member_subnav_items()',
		'overrides render_member_subnav() but builds its own item list — the '
		. 'seeded profile menu and its gates must stay in PublicPageBase.');
}

harness_finish();
