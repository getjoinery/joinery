<?php
/** @joinery-test
 * name: file_source_declarations
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * File origin tags declared by plugins.
 *
 * Core's own tags live in File::core_source_catalog(); a plugin declares its
 * tags under "fileSources" in plugin.json, and File::source_catalog() merges
 * every plugin on disk over core. Proven against the real tree (every declared
 * tag appears, under its label) and against a stand-in plugins directory of
 * hand-made manifests: an inactive plugin's internal tag stays internal, a tag
 * core owns or another plugin owns is refused by the manifest check and loses
 * at runtime, a malformed declaration is reported and left out, and a tag
 * nobody declares reads as its raw name and stays listable.
 *
 * Run: php tests/run.php --filter=file_source_declarations
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/files_class.php'));

// ---------------------------------------------------------------------------
section('every tag a plugin on disk declares is in the catalog, under its label');

FileSourceDeclarations::resetForTests();
$catalog = File::source_catalog();
$declared_count = 0;
foreach ((array)glob(PathHelper::getIncludePath('plugins') . '/*/plugin.json') as $path) {
	$data = json_decode((string)file_get_contents($path), true);
	foreach ((array)($data['fileSources'] ?? array()) as $tag => $spec) {
		$declared_count++;
		check(isset($catalog[$tag]) && $catalog[$tag]['label'] === $spec['label'],
			basename(dirname($path)) . " declares '{$tag}' and the catalog carries it as '{$spec['label']}'",
			json_encode($catalog[$tag] ?? null));
		check(FileSourceDeclarations::errorsFor(basename(dirname($path))) === array(),
			basename(dirname($path)) . ' passes the manifest check',
			json_encode(FileSourceDeclarations::errorsFor(basename(dirname($path)))));
	}
}
check($declared_count >= 7, 'the tree declares plugin tags (mailbox, messenger, joinery_ai, bug_reports, boards)', "found $declared_count");
foreach (array_keys(File::core_source_catalog()) as $tag) {
	check(isset($catalog[$tag]), "core's own tag '{$tag}' is in the catalog");
}
check(in_array('mailbox_search_index', File::internal_sources(), true),
	"mailbox's search index is internal, so no browse surface lists it");

// ---------------------------------------------------------------------------
section('a stand-in plugins directory: inactive, colliding and malformed manifests');

$dir = harness_scratch_dir('file_source_declarations') . '/' . bin2hex(random_bytes(4));
mkdir($dir, 0770, true);
$manifests = array(
	// Never installed, so never active: its tags must still resolve.
	'aaa_dormant'   => array('fileSources' => array(
		'dormant_upload' => array('label' => 'Dormant uploads'),
		'dormant_index'  => array('label' => 'Dormant index', 'internal' => true),
	)),
	'bbb_core_clash' => array('fileSources' => array(
		'drive' => array('label' => 'Not Drive', 'internal' => true),
	)),
	'ccc_first'     => array('fileSources' => array(
		'shared_tag' => array('label' => 'First claim'),
	)),
	'ddd_second'    => array('fileSources' => array(
		'shared_tag' => array('label' => 'Second claim'),
	)),
	'eee_badshape'  => array('fileSources' => array(
		'Bad-Tag'   => array('label' => 'Bad tag'),
		'no_label'  => array('internal' => false),
		'flag_str'  => array('label' => 'Flag', 'internal' => 'yes'),
		'extra_key' => array('label' => 'Extra', 'colour' => 'red'),
		'secret_idx' => array('label' => 'Secret index', 'internal' => true, 'note' => 'a stray key'),
	)),
	'fff_list'      => array('fileSources' => array(array('label' => 'A list, not an object'))),
);
foreach ($manifests as $plugin => $manifest) {
	mkdir("$dir/$plugin");
	file_put_contents("$dir/$plugin/plugin.json", json_encode(array('name' => $plugin) + $manifest));
}
FileSourceDeclarations::usePluginDirForTest($dir);
$catalog = File::source_catalog();

check(isset($catalog['dormant_upload']) && $catalog['dormant_upload']['label'] === 'Dormant uploads',
	"an inactive plugin's tag keeps its name");
check(File::source_label('dormant_upload') === 'Dormant uploads', 'source_label() reads the declared label');
check(isset($catalog['dormant_index']) && $catalog['dormant_index']['internal'] === true
	&& in_array('dormant_index', File::internal_sources(), true) && !File::source_is_listable('dormant_index'),
	"an inactive plugin's internal tag stays internal and unlisted");
check($catalog['dormant_upload']['internal'] === false && $catalog['dormant_upload']['default_view'] === false,
	'internal and default_view default to false');

check($catalog['drive']['label'] === 'Drive' && $catalog['drive']['internal'] === false,
	"a plugin claiming core's tag loses at runtime: core's entry wins", json_encode($catalog['drive']));
$clash = FileSourceDeclarations::errorsFor('bbb_core_clash');
check(count($clash) === 1 && strpos($clash[0], 'core already owns') !== false,
	"the manifest check refuses a tag core owns", json_encode($clash));

check($catalog['shared_tag']['label'] === 'First claim',
	'two plugins claiming one tag, neither active: the first by name keeps it', json_encode($catalog['shared_tag']));
FileSourceDeclarations::usePluginRankForTest(array('ccc_first' => '2026-10-01 00:00:00', 'ddd_second' => '2026-01-01 00:00:00'));
check(File::source_catalog()['shared_tag']['label'] === 'Second claim',
	'both active: the plugin installed first keeps the tag, whatever its name');
FileSourceDeclarations::usePluginRankForTest(array('ccc_first' => '2026-10-01 00:00:00'));
check(File::source_catalog()['shared_tag']['label'] === 'First claim',
	'only one active: the active plugin keeps it');
FileSourceDeclarations::usePluginRankForTest(array());
$catalog = File::source_catalog();
$first = FileSourceDeclarations::errorsFor('ccc_first');
$second = FileSourceDeclarations::errorsFor('ddd_second');
check(count($first) === 1 && strpos($first[0], "also declared by plugin 'ddd_second'") !== false
	&& count($second) === 1 && strpos($second[0], "also declared by plugin 'ccc_first'") !== false,
	'the manifest check names the other plugin, for both', json_encode(array($first, $second)));

$bad = FileSourceDeclarations::errorsFor('eee_badshape');
check(count($bad) === 5, 'each malformed entry is reported once', json_encode($bad));
foreach (array('Bad-Tag', 'no_label') as $tag) {
	check(!isset($catalog[$tag]), "an unusable tag or label ('{$tag}') is left out of the catalog");
}
foreach (array('flag_str', 'extra_key', 'secret_idx') as $tag) {
	check(isset($catalog[$tag]), "a usable tag with another mistake ('{$tag}') stays in the catalog, and is still reported");
}
check($catalog['secret_idx']['internal'] === true && !File::source_is_listable('secret_idx'),
	'an internal tag with a stray key stays internal: a typo never makes hidden files listable');
check(count(FileSourceDeclarations::errorsFor('ddd_second', array('aaa_dormant'))) === 0
	&& count(FileSourceDeclarations::errorsFor('ddd_second', array('ccc_first'))) === 1,
	'a clash is judged against the plugins named (activation names the active ones)');
$list = FileSourceDeclarations::errorsFor('fff_list');
check(count($list) === 1 && strpos($list[0], 'must be an object') !== false,
	'a fileSources list (not an object) is reported', json_encode($list));
check(FileSourceDeclarations::errorsFor('aaa_dormant') === array(), 'a well-formed manifest passes');

// ---------------------------------------------------------------------------
section('an undeclared tag reads as itself and stays listable');

check(!isset($catalog['left_by_a_removed_plugin']), 'nobody declares it');
check(File::source_label('left_by_a_removed_plugin') === 'left_by_a_removed_plugin', 'its label is its raw name');
check(File::source_is_listable('left_by_a_removed_plugin') === true, 'it is listable, so it is noticed rather than lost');

// ---------------------------------------------------------------------------
section('a test declaration');

FileSourceDeclarations::declareForTest('test_only_tag', array('label' => 'Test only'));
check(File::source_label('test_only_tag') === 'Test only', 'a test may declare a throwaway tag');
$refused = false;
try { FileSourceDeclarations::declareForTest('drive', array('label' => 'x')); } catch (LogicException $e) { $refused = true; }
check($refused, 'a test may not redeclare a tag already in the catalog');
$refused = false;
try { FileSourceDeclarations::declareForTest('Bad Tag', array('label' => 'x')); } catch (LogicException $e) { $refused = true; }
check($refused, 'a test declaration is shape-checked like a manifest one');

FileSourceDeclarations::resetForTests();
check(!isset(File::source_catalog()['test_only_tag']) && !isset(File::source_catalog()['dormant_upload']),
	'reset() returns to the real tree with no test declarations');

foreach (array_keys($manifests) as $plugin) {
	@unlink("$dir/$plugin/plugin.json");
	@rmdir("$dir/$plugin");
}
@rmdir($dir);

harness_finish();
