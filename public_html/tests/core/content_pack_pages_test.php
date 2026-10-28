<?php
/** @joinery-test
 * name: content_pack_pages
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */

/**
 * Content packs, phase 2 (content_pack_feature.md §7): pages and blocks.
 * Covered:
 *   - a "find" entry names a component type on the site; a missing one refuses;
 *   - blocks and a page whose layout is a list of refs, all new, apply in order;
 *     a second check is all same;
 *   - {"merge"} sets the keys it names, one plan line each, and leaves the rest;
 *   - a key edited on the site is "edited here" and survives; a pack change to
 *     a key the pack established is "change";
 *   - a layout line pointing at a new block waits for it: unticking the block
 *     leaves the layout alone;
 *   - a block found by its old slug (match_also) takes the new one, ticked;
 *   - the page and block form rules in the models, judged on changed values only,
 *     and met at check: a pack the models would refuse never shows as landing;
 *   - the shapes a pack may not use for a JSON field, and a pack writing a
 *     component type, refuse by name;
 *   - a block's save clears the cached copy of each page showing it (B3).
 *
 * Runs on the test database.
 *
 * Run: php tests/core/content_pack_pages_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('logic/content_pack_check_logic.php'));
require_once(PathHelper::getIncludePath('logic/content_pack_apply_logic.php'));

$run = substr(md5(uniqid('cpp', true)), 0, 8);
$db = DbConnector::get_instance()->get_db_link();

$admin = make_user('CppAdmin' . $run, 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;

/** A component type for this run; the test database carries none. */
function cpp_type($key, $active = true) {
	$type = new Component(NULL);
	$type->set('com_type_key', $key);
	$type->set('com_title', 'Pack test ' . $key);
	$type->set('com_is_active', $active);
	$type->save();
	$type->load();
	return $type;
}

$html_type = cpp_type('cpp_html_' . $run);
$hero_type = cpp_type('cpp_hero_' . $run);
$off_type = cpp_type('cpp_off_' . $run, false);

harness_defer(function () use ($db, $run) {
	$pages = $db->query("SELECT pag_page_id FROM pag_pages WHERE pag_link LIKE 'cpp-%-" . $run . "'")->fetchAll(PDO::FETCH_COLUMN);
	$blocks = $db->query("SELECT pac_page_content_id FROM pac_page_contents WHERE pac_location_name LIKE 'cpp-%" . $run . "'")->fetchAll(PDO::FETCH_COLUMN);
	$targets = array();
	foreach ($pages as $id) {
		$targets[] = 'Page:' . (int)$id;
		$db->prepare('DELETE FROM cvn_content_versions WHERE cvn_type = ? AND cvn_foreign_key_id = ?')->execute(array(ContentVersion::TYPE_PAGE, (int)$id));
		$db->prepare('DELETE FROM pag_pages WHERE pag_page_id = ?')->execute(array((int)$id));
	}
	foreach ($blocks as $id) {
		$targets[] = 'PageContent:' . (int)$id;
		$db->prepare('DELETE FROM cvn_content_versions WHERE cvn_type = ? AND cvn_foreign_key_id = ?')->execute(array(ContentVersion::TYPE_PAGE_CONTENT, (int)$id));
		$db->prepare('DELETE FROM pac_page_contents WHERE pac_page_content_id = ?')->execute(array((int)$id));
	}
	if ($targets) {
		$in = implode(',', array_fill(0, count($targets), '?'));
		$db->prepare("DELETE FROM cpv_content_pack_values WHERE cpv_target IN ($in)")->execute($targets);
	}
	$db->prepare("DELETE FROM com_components WHERE com_type_key LIKE ?")->execute(array('cpp_%' . $run));
	$db->prepare("DELETE FROM cpa_content_pack_applies WHERE cpa_pack_name LIKE ?")->execute(array('cpp-%' . $run));
});

/** A pack zip's bytes from a manifest and its text files. */
function cpp_zip(array $manifest, array $files = array()) {
	$path = tempnam(sys_get_temp_dir(), 'cpp');
	$zip = new ZipArchive();
	$zip->open($path, ZipArchive::OVERWRITE);
	$zip->addFromString('pack.json', json_encode($manifest));
	foreach ($files as $name => $contents) {
		$zip->addFromString($name, $contents);
	}
	$zip->close();
	$bytes = file_get_contents($path);
	unlink($path);
	return $bytes;
}

/** Check a pack: [apply_id, plan] or [null, error]. */
function cpp_check($bytes) {
	$result = content_pack_check_bytes($bytes, SessionControl::get_instance());
	return $result->error ? array(null, $result->error) : array($result->data['apply_id'], $result->data['plan']);
}

/** Check and apply with the plan's default ticks, or $ticks($plan). Returns [plan, error]. */
function cpp_apply($bytes, $ticks = null) {
	list($apply_id, $plan) = cpp_check($bytes);
	if (!$apply_id) return array(null, $plan);
	$chosen = $ticks === null ? ContentPack::default_ticks($plan['items']) : $ticks($plan);
	$result = content_pack_apply_logic(array('apply_id' => $apply_id, 'plan_hash' => $plan['hash'], 'ticks' => $chosen));
	return array($plan, $result->error);
}

/** field => state for every line of the record labelled with $slug. */
function cpp_lines(array $plan, $slug) {
	foreach ($plan['items'] as $item) {
		if ($item['kind'] === 'record' && strpos($item['label'], '"' . $slug . '"') !== false) {
			$out = array();
			foreach ($item['lines'] as $line) $out[$line['field']] = $line;
			return $out;
		}
	}
	return null;
}

function cpp_block($slug) {
	foreach (new MultiPageContent(array('slug' => $slug)) as $block) return $block;
	return null;
}

function cpp_page($link) {
	foreach (new MultiPage(array('link' => $link)) as $page) return $page;
	return null;
}

$link = 'cpp-page-' . $run;
$intro = 'cpp-intro-' . $run;
$hero = 'cpp-hero-' . $run;

/** The pack: two blocks and the page that shows them, in that order. */
$manifest = function (array $changes = array()) use ($run, $link, $intro, $hero, $html_type, $hero_type) {
	$pack = array(
		'pack' => 'cpp-pages-' . $run, 'version' => '1',
		'entries' => array(
			array('model' => 'Page', 'match' => array('pag_link' => $link),
				'set' => array('pag_title' => 'Pack page', 'pag_component_layout' => array(array('ref' => 'hero'), array('ref' => 'intro')))),
			array('id' => 'html', 'model' => 'Component', 'find' => array('com_type_key' => $html_type->get('com_type_key'))),
			array('id' => 'herotype', 'model' => 'Component', 'find' => array('com_type_key' => $hero_type->get('com_type_key'))),
			array('id' => 'intro', 'model' => 'PageContent', 'match' => array('pac_location_name' => $intro),
				'set' => array('pac_com_component_id' => array('ref' => 'html'), 'pac_config' => array('merge' => array('html' => array('file' => 'intro.html')))),
				'on_create' => array('pac_title' => 'Intro')),
			array('id' => 'hero', 'model' => 'PageContent', 'match' => array('pac_location_name' => $hero),
				'set' => array('pac_com_component_id' => array('ref' => 'herotype'),
					'pac_config' => array('merge' => array('heading' => 'Own your mail', 'badges' => array(array('icon' => 'mail', 'text' => 'Yours')))))),
		),
	);
	foreach ($changes as $fn) $pack = $fn($pack);
	return $pack;
};
$intro_html = "<section>\r\n  <h2>Hello</h2>\r\n</section>";

// ---------------------------------------------------------------------------
section('A page and its blocks, all new');
// ---------------------------------------------------------------------------

list($plan, $error) = cpp_apply(cpp_zip($manifest(), array('intro.html' => $intro_html)));
check($error === null, 'the pack applies', (string)$error);
$finds = array_values(array_filter($plan['items'], function ($i) { return $i['kind'] === 'find'; }));
check(count($finds) === 2 && $finds[0]['key'] === (int)$html_type->key, 'each find names the type on this site');
$lines = cpp_lines($plan, $hero);
check(isset($lines['pac_config.heading'], $lines['pac_config.badges']) && $lines['pac_config.heading']['state'] === 'new',
	'a merge is one line per key', json_encode(array_keys((array)$lines)));

$page = cpp_page($link);
$intro_block = cpp_block($intro);
$hero_block = cpp_block($hero);
check($page && $intro_block && $hero_block, 'the page and both blocks exist');
check(array_map('intval', $page->get_component_layout()) === array((int)$hero_block->key, (int)$intro_block->key),
	'the layout holds the blocks\' keys, in the pack\'s order', json_encode($page->get_component_layout()));
check((int)$intro_block->get('pac_com_component_id') === (int)$html_type->key && $intro_block->get_config()['html'] === $intro_html,
	'the block is of the found type, with its file as config', json_encode($intro_block->get_config()));
check($hero_block->get_config()['badges'] === array(array('icon' => 'mail', 'text' => 'Yours')), 'a nested config value is written as JSON');
check($intro_block->get('pac_title') === 'Intro', 'on_create sets the title');

list($apply_id, $plan) = cpp_check(cpp_zip($manifest(), array('intro.html' => $intro_html)));
$states = array();
foreach ($plan['items'] as $item) foreach ($item['lines'] ?? array() as $line) $states[$line['state']] = true;
check(array_keys($states) === array('same'), 'a second check is all same, CRLF in the file and all', json_encode(array_keys($states)));

// ---------------------------------------------------------------------------
section('Merge leaves other keys alone; edits are kept');
// ---------------------------------------------------------------------------

$hero_block->set_config(array_merge($hero_block->get_config(), array('heading' => 'Edited in the editor', 'button' => 'Kept')));
$hero_block->save();

$v2 = $manifest(array(function ($p) { $p['entries'][4]['set']['pac_config']['merge']['badges'][0]['text'] = 'All yours'; return $p; }));
list($plan, $error) = cpp_apply(cpp_zip($v2, array('intro.html' => $intro_html)));
$lines = cpp_lines($plan, $hero);
check($lines['pac_config.heading']['state'] === 'edited' && empty($lines['pac_config.heading']['default']),
	'a key edited on the site is edited here, unticked');
check($lines['pac_config.badges']['state'] === 'change' && !empty($lines['pac_config.badges']['default']),
	'a pack change to a key it established is a change');
$config = cpp_block($hero)->get_config();
check($error === null && $config['heading'] === 'Edited in the editor' && $config['button'] === 'Kept'
	&& $config['badges'][0]['text'] === 'All yours', 'the change lands; the edit and the key no pack names stay', json_encode($config));

// ---------------------------------------------------------------------------
section('A layout line waits for the new block it points at');
// ---------------------------------------------------------------------------

$extra = 'cpp-extra-' . $run;
$v3 = $manifest(array(function ($p) use ($extra) {
	$p['entries'][] = array('id' => 'extra', 'model' => 'PageContent', 'match' => array('pac_location_name' => $extra),
		'set' => array('pac_com_component_id' => array('ref' => 'html'), 'pac_config' => array('merge' => array('html' => '<p>More</p>'))));
	$p['entries'][0]['set']['pag_component_layout'][] = array('ref' => 'extra');
	return $p;
}));
$extra_tick = null;
list($plan, $error) = cpp_apply(cpp_zip($v3, array('intro.html' => $intro_html)), function ($plan) use (&$extra_tick) {
	$ticks = ContentPack::default_ticks($plan['items']);
	foreach ($plan['items'] as $item) {
		if ($item['kind'] === 'record' && $item['key'] === null) $extra_tick = $item['tick'];
	}
	return array_values(array_diff($ticks, array($extra_tick)));
});
$layout_line = cpp_lines($plan, $link)['pag_component_layout'];
check($layout_line['state'] === 'change' && $layout_line['needs'] === array($extra_tick),
	'the layout line is a change that needs the new block', json_encode($layout_line));
check($error === null && !cpp_block($extra) && count(cpp_page($link)->get_component_layout()) === 2,
	'with the block unticked, neither it nor the layout change is written', (string)$error);

list($plan, $error) = cpp_apply(cpp_zip($v3, array('intro.html' => $intro_html)));
$layout = array_map('intval', cpp_page($link)->get_component_layout());
check($error === null && cpp_block($extra) && end($layout) === (int)cpp_block($extra)->key,
	'ticked, the block is created and the layout points at it', json_encode($layout));

// The site reorders the page: the layout is edited here.
$page = cpp_page($link);
$page->set('pag_component_layout', array_reverse($layout));
$page->save();
list($apply_id, $plan) = cpp_check(cpp_zip($v3, array('intro.html' => $intro_html)));
check(cpp_lines($plan, $link)['pag_component_layout']['state'] === 'edited', 'a layout reordered on the site is edited here');

// ---------------------------------------------------------------------------
section('A block found by its old slug takes the new one');
// ---------------------------------------------------------------------------

$old = 'cpp-old--' . $run;
$new = 'cpp-old-' . $run;
// The seed wrote slugs the editor would not: only a raw row holds one.
$db->prepare("INSERT INTO pac_page_contents (pac_com_component_id, pac_location_name, pac_title, pac_config) VALUES (?, ?, 'Old', ?)")
	->execute(array((int)$html_type->key, $old, json_encode(array('html' => '<p>Old</p>'))));
$legacy = cpp_block($old);
$legacy->set('pac_title', 'Old, retitled');
try { $legacy->prepare(); $legacy->save(); $saved = true; } catch (Throwable $e) { $saved = $e->getMessage(); }
check($saved === true, 'a row whose slug predates the rule still saves untouched', (string)$saved);

$rename = array('pack' => 'cpp-rename-' . $run, 'entries' => array(
	array('id' => 'html', 'model' => 'Component', 'find' => array('com_type_key' => $html_type->get('com_type_key'))),
	array('model' => 'PageContent', 'match' => array('pac_location_name' => $new), 'match_also' => array(array('pac_location_name' => $old)),
		'set' => array('pac_config' => array('merge' => array('html' => '<p>Old</p>')))),
));
list($plan, $error) = cpp_apply(cpp_zip($rename));
$lines = cpp_lines($plan, $new);
check($lines['pac_location_name']['state'] === 'change' && !empty($lines['pac_location_name']['default']),
	'the old slug is a change, ticked', json_encode($lines['pac_location_name']));
check($error === null && cpp_block($new) && (int)cpp_block($new)->key === (int)$legacy->key, 'the block takes the new slug', (string)$error);

// ---------------------------------------------------------------------------
section('The form rules live in the models, and the check meets them');
// ---------------------------------------------------------------------------

$refused = function ($manifest_fn, $needle) {
	list($apply_id, $error) = cpp_check(cpp_zip($manifest_fn()));
	return $apply_id === null && stripos($error, 'would be refused') !== false && stripos($error, $needle) !== false ? true : (string)$error;
};
$base = array('pack' => 'cpp-rules-' . $run, 'entries' => array());
check($refused(function () use ($base, $run) {
	$base['entries'][] = array('model' => 'Page', 'match' => array('pag_link' => 'cpp-Bad Link-' . $run), 'set' => array('pag_title' => 'x'));
	return $base;
}, 'page link may hold only') === true, 'a page link not in slug form is refused');
check($refused(function () use ($base, $run) {
	$base['entries'][] = array('model' => 'Page', 'match' => array('pag_link' => 'cpp-tpl-' . $run), 'set' => array('pag_template' => 'login'));
	return $base;
}, 'no page template called') === true, 'a template that is not a page_ view is refused');
check($refused(function () use ($base, $run) {
	$base['entries'][] = array('model' => 'PageContent', 'match' => array('pac_location_name' => 'cpp-bad--' . $run), 'set' => array('pac_title' => 'x'));
	return $base;
}, 'component slug may hold only') === true, 'a block slug the editor would rewrite is refused');
check($refused(function () use ($base, $run, $off_type) {
	$base['entries'][] = array('id' => 'off', 'model' => 'Component', 'find' => array('com_type_key' => $off_type->get('com_type_key')));
	$base['entries'][] = array('model' => 'PageContent', 'match' => array('pac_location_name' => 'cpp-off-' . $run),
		'set' => array('pac_com_component_id' => array('ref' => 'off')));
	return $base;
}, 'not an active component type') === true, 'a block of an inactive type is refused');
check(!cpp_page('cpp-tpl-' . $run) && !cpp_block('cpp-off-' . $run), 'and nothing of a refused pack is written');
check($refused(function () use ($base, $intro, $html_type) {
	$base['entries'][] = array('id' => 'off', 'model' => 'Component', 'find' => array('com_type_key' => 'cpp_off_' . substr($intro, -8)));
	$base['entries'][] = array('model' => 'PageContent', 'match' => array('pac_location_name' => $intro),
		'set' => array('pac_com_component_id' => array('ref' => 'off')));
	return $base;
}, 'not an active component type') === true, 'an existing block changed to an inactive type is refused at check too');

// ---------------------------------------------------------------------------
section('Refusals at check');
// ---------------------------------------------------------------------------

$check_refused = function (array $pack, $needle) {
	list($apply_id, $error) = cpp_check(cpp_zip($pack));
	return $apply_id === null && stripos($error, $needle) !== false ? true : (string)$error;
};
check($check_refused(array('pack' => 'cpp-x-' . $run, 'entries' => array(
	array('id' => 'gone', 'model' => 'Component', 'find' => array('com_type_key' => 'cpp_missing_' . $run)))), 'which is not on this site') === true,
	'a find that matches nothing refuses up front');
check($check_refused(array('pack' => 'cpp-x-' . $run, 'entries' => array(
	array('model' => 'Component', 'match' => array('com_type_key' => 'cpp_new_' . $run)))), 'Use a "find" entry') === true,
	'a pack never writes a component type');
check($check_refused(array('pack' => 'cpp-x-' . $run, 'entries' => array(
	array('model' => 'PageContent', 'match' => array('pac_location_name' => 'cpp-j-' . $run), 'set' => array('pac_config' => array('html' => 'x'))))),
	'is a JSON field') === true, 'a JSON field written as a bare object refuses (merge or a list)');
check($check_refused(array('pack' => 'cpp-x-' . $run, 'entries' => array(
	array('model' => 'PageContent', 'match' => array('pac_location_name' => 'cpp-j-' . $run), 'set' => array('pac_config' => array('merge' => array()))))),
	'merge') === true, 'an empty merge refuses');
check($check_refused(array('pack' => 'cpp-x-' . $run, 'entries' => array(
	array('model' => 'PageContent', 'match' => array('pac_location_name' => 'cpp-j-' . $run),
		'set' => array('pac_config' => array('merge' => array(str_repeat('k', 95) => 'x')))))),
	'at most 100 characters') === true, 'a merge key too long to track refuses');
check($check_refused(array('pack' => 'cpp-x-' . $run, 'entries' => array(
	array('model' => 'Page', 'match' => array('pag_link' => 'cpp-j-' . $run), 'set' => array('pag_component_layout' => array(array('ref' => 'nowhere')))))),
	'no record entry is called') === true, 'a ref inside a JSON value is checked like any other');

// ---------------------------------------------------------------------------
section('A block\'s save clears the pages that show it (B3)');
// ---------------------------------------------------------------------------

$url = cpp_page($link)->get_url();
if (!StaticPageCache::createCache($url, array(), '<html>cached ' . $run . '</html>')) {
	harness_skip('the page cache cannot be written here', 'StaticPageCache::createCache returned false');
} else {
	harness_defer(function () use ($url) { StaticPageCache::invalidateUrl($url); });
	$cached = StaticPageCache::checkCache($url);
	$block = cpp_block($intro);
	$block->set('pac_title', 'Intro, retitled');
	$block->save();
	$after = StaticPageCache::checkCache($url);
	check(is_string($cached) && $cached !== 'nostatic' ? $after === false : $after === 'nostatic',
		'the cached copy of the page is gone', json_encode(array($cached, $after)));
}

harness_finish();
