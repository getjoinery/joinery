<?php
/** @joinery-test
 * name: theme_look
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The look slot (specs/style_themes.md WP1, WP2).
 *
 * A style theme registers as 'style' from its contents, is offered as a
 * look and never as the active theme, applies and removes through the look
 * slot, refuses Activate with the sentence, and is dropped from the slot by
 * the sync once it carries pages. Against the live dev database: a fixture
 * style theme is written under theme/ for the run and removed, its row is
 * deleted, and theme_look is restored.
 *
 * Run: php tests/run.php --only=tests/models/theme_look_test.php
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

const TLT_FIXTURE = 'zzlookmodel';
$fixture_dir = PathHelper::getAbsolutePath('theme') . '/' . TLT_FIXTURE;
$rmtree = function ($p) use (&$rmtree) {
	foreach (glob(rtrim($p, '/') . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
		if (basename($f) === '.' || basename($f) === '..') continue;
		is_dir($f) && !is_link($f) ? $rmtree($f) : @unlink($f);
	}
	@rmdir($p);
};
$manager = ThemeManager::getInstance();
// SettingsWriter validates through a FormWriter, which starts a session;
// under the CLI, after the harness has printed, that raises a "headers
// already sent" warning that is the CLI's and not the writer's.
error_reporting(E_ALL & ~E_WARNING);
$previous_look = (string)get_setting_raw('theme_look');
$previous_template = (string)get_setting_raw('theme_template');

$rmtree($fixture_dir);
@mkdir($fixture_dir . '/assets/css', 0755, true);
file_put_contents($fixture_dir . '/theme.json', json_encode(array(
	'name' => TLT_FIXTURE, 'display_name' => 'Look model fixture', 'version' => '1.0.0',
	'styles' => array('assets/css/look.css'),
)));
file_put_contents($fixture_dir . '/assets/css/look.css', ".jy-ui { --jy-radius: 2px }\n");
$stale = Theme::get_by_theme_name(TLT_FIXTURE);
if ($stale) { $stale->permanent_delete(); }

harness_defer(function () use ($fixture_dir, $rmtree, $previous_look, $previous_template) {
	Setting::put('theme_look', $previous_look);
	if ((string)get_setting_raw('theme_template') !== $previous_template) {
		Setting::put('theme_template', $previous_template);
	}
	$row = Theme::get_by_theme_name(TLT_FIXTURE);
	if ($row) { try { $row->permanent_delete(); } catch (Throwable $e) {} }
	$rmtree($fixture_dir);
	ThemeHelper::forget(TLT_FIXTURE);
});

section('Registration records the kind from the contents');
$result = $manager->sync();
check(in_array(TLT_FIXTURE, $result['added'] ?? array(), true), 'the sync registered the fixture', json_encode($result['added'] ?? null));
$row = Theme::get_by_theme_name(TLT_FIXTURE);
check($row && $row->is_style(), 'as a style theme', $row ? (string)$row->get('thm_kind') : 'no row');
foreach (new MultiTheme(array('thm_kind' => 'page')) as $t) {
	$why = ThemeHelper::styleThemeRefusal(PathHelper::getAbsolutePath('theme/' . $t->get('thm_name')));
	if (!is_dir(PathHelper::getAbsolutePath('theme/' . $t->get('thm_name')))) continue;
	check($why !== null, 'every page theme on disk has a reason: ' . $t->get('thm_name'), (string)$why);
}

section('Offered as a look, never as the active theme');
$themes = CoreSettingOptions::themes();
$looks = CoreSettingOptions::looks();
check(isset($looks[TLT_FIXTURE]) && $looks[TLT_FIXTURE] === (string)$row->get('thm_display_name'),
	'looks() lists it under the registered display name', json_encode($looks));
check(!isset($themes[TLT_FIXTURE]), 'themes() does not');
check(array_intersect_key($themes, $looks) === array(), 'themes() and looks() are disjoint');
check(isset($themes['default']), 'and themes() still lists the page themes');

section('Apply and Remove write the look slot');
Setting::put('theme_look', '');
$manager->applyLook(TLT_FIXTURE);
check((string)get_setting_raw('theme_look') === TLT_FIXTURE, 'Apply stored the name');
check($manager->activeLook() === TLT_FIXTURE, 'and activeLook() reads it');
$manager->removeLook();
check((string)get_setting_raw('theme_look') === '', 'Remove cleared it');
check($manager->activeLook() === '', 'and activeLook() reads none');

$caught = '';
try { $manager->applyLook('default'); } catch (Exception $e) { $caught = $e->getMessage(); }
check(strpos($caught, 'carries pages') !== false, 'Apply on a page theme is refused', $caught);
$caught = '';
try { $manager->applyLook('no_such_theme_xyz'); } catch (Exception $e) { $caught = $e->getMessage(); }
check(strpos($caught, 'not found') !== false, 'Apply on an unknown name is refused', $caught);

section('Activate on a style theme is refused with the sentence');
$caught = '';
try { $manager->activate(TLT_FIXTURE); } catch (Exception $e) { $caught = $e->getMessage(); }
check(strpos($caught, ThemeManager::STYLE_THEME_ACTIVATE_REFUSAL) !== false, 'the sentence', $caught);
check((string)get_setting_raw('theme_template') === $previous_template, 'and the active theme is unchanged');
$row = Theme::get_by_theme_name(TLT_FIXTURE);
check($row && !$row->get('thm_is_active'), 'and the row is not active');

section('A look that gains pages is dropped from the slot by the sync');
$manager->applyLook(TLT_FIXTURE);
@mkdir($fixture_dir . '/includes', 0755);
file_put_contents($fixture_dir . '/includes/PublicPage.php', "<?php // now a page theme\n");
$result = $manager->sync();
$row = Theme::get_by_theme_name(TLT_FIXTURE);
check($row && !$row->is_style(), 'the sync re-read the kind as page', $row ? (string)$row->get('thm_kind') : 'no row');
check(is_string($result['look_cleared'] ?? null) && strpos($result['look_cleared'], 'now carries pages') !== false,
	'and reported the clear with the sentence', json_encode($result['look_cleared'] ?? null));
check((string)get_setting_raw('theme_look') === '', 'theme_look reads none');
check(!isset(CoreSettingOptions::looks()[TLT_FIXTURE]) && isset(CoreSettingOptions::themes()[TLT_FIXTURE]),
	'and it moved from looks() to themes()');

section('A look whose theme is gone reads as none');
Setting::put('theme_look', 'no_such_theme_xyz');
check($manager->activeLook() === '', 'activeLook() on a missing theme is none');
$why = $manager->clearStaleLook();
check(is_string($why) && (string)get_setting_raw('theme_look') === '', 'and the clear empties the slot', (string)$why);
check($manager->clearStaleLook() === null, 'a second clear has nothing to do');

harness_finish();
?>
