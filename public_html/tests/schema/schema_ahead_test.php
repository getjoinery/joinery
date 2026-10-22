<?php
/** @joinery-test
 * name: schema_ahead
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 * timeout: 180
 * covers: [public_html/utils/update_database.php, public_html/utils/upgrade.php, public_html/includes/DeploymentHelper.php]
 */
/**
 * A release's missing tables and columns are added before its code goes live.
 *
 * upgrade.php swapped the new code in and ran update_database a few seconds
 * later. In between the new code served, and every write that named a column
 * it added failed: on getjoinery each agent check-in in that window failed with
 * 'Undefined column mgn_agent_test_build' (0.8.520 and 0.8.521). Now
 * DeploymentHelper::schemaAhead() runs the STAGED tree's own update_database.php
 * --schema-ahead first, with the site's config/ and vendor/ lent beside the
 * staged tree.
 *
 * Built here the way upgrade.php builds it: a copy of this tree as the staged
 * public_html, plus two model classes the live tree does not have (standing for
 * the release's new schema), and a stand-in site whose config points at the
 * test database and whose vendor/ is this site's. Checked: the new table and column are added; nothing that is not
 * additive happens (a type, a unique constraint, a migration); nothing lent is
 * left behind, and the release's own config/ (the core archive ships
 * default_Globalvars_site.php there, which made 0.8.523 refuse to run) is set
 * aside for the run and put back, and recovered after a run killed mid-way; the run raises no PHP warning (a model file that loads Composer
 * packages finds them); a second run adds nothing; a staged update_database that does
 * not know the flag is never run (it would run in full, migrations and all).
 *
 * Run: php tests/run.php test-db --filter=schema_ahead
 *
 * @version 1.1 - the staging area holds the release's own config/, as a real
 *   archive's does
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

$db = DbConnector::get_instance()->get_db_link();
$work = sys_get_temp_dir() . '/schema_ahead_' . getmypid();
$stage = $work . '/upgrades';
$site = $work . '/site';
$conf = $site . '/config';

$column_type = function ($table, $column) use ($db) {
	$q = $db->prepare("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?");
	$q->execute(array($table, $column));
	return $q->fetchColumn();
};
$cleanup = function () use ($db, $work) {
	$db->exec('DROP TABLE IF EXISTS zz_schema_ahead_probe CASCADE');
	$db->exec('DROP TABLE IF EXISTS zz_schema_ahead_new CASCADE');
	$db->exec('DROP SEQUENCE IF EXISTS zz_schema_ahead_new_zsn_id_seq');
	exec('rm -rf ' . escapeshellarg($work));
};

$cleanup();
try {
	section('A staged tree, a new release\'s schema, and the site config lent beside it');
	mkdir($stage, 0700, true);
	mkdir($conf, 0700, true);
	symlink(PathHelper::getSiteRoot() . '/vendor', $site . '/vendor');
	exec('cp -a ' . escapeshellarg(PathHelper::getRootDir()) . ' ' . escapeshellarg($stage . '/public_html') . ' 2>&1', $out, $rc);
	check($rc === 0, 'the tree is copied as the staged public_html', implode("\n", $out));
	// As the core archive has it: config/ with the template install.sh seeds a site from.
	mkdir($stage . '/config');
	file_put_contents($stage . '/config/default_Globalvars_site.php', "<?php // the release's template\n");
	file_put_contents($stage . '/public_html/data/zz_schema_ahead_probe_class.php', '<?php
class ZzSchemaAheadProbe extends SystemBase {
	public static $prefix = "zsp";
	public static $tablename = "zz_schema_ahead_probe";
	public static $pkey_column = "zsp_id";
	public static $field_specifications = array(
		"zsp_id"    => array("type" => "int8", "serial" => true),
		"zsp_code"  => array("type" => "varchar(16)", "unique" => true),
		"zsp_count" => array("type" => "int4"),
		"zsp_note"  => array("type" => "varchar(64)"),
	);
}
class ZzSchemaAheadNew extends SystemBase {
	public static $prefix = "zsn";
	public static $tablename = "zz_schema_ahead_new";
	public static $pkey_column = "zsn_id";
	public static $field_specifications = array(
		"zsn_id"   => array("type" => "int8", "serial" => true),
		"zsn_name" => array("type" => "varchar(32)"),
	);
}
');
	// The release before: the probe table without zsp_note, zsp_count wider than
	// the new spec, and no unique constraint on zsp_code.
	$db->exec('CREATE TABLE zz_schema_ahead_probe (zsp_id int8 PRIMARY KEY, zsp_code varchar(16), zsp_count int8)');
	$db->exec("INSERT INTO zz_schema_ahead_probe VALUES (1, 'a', 5)");
	// The test database stands in for the site's: the real config, with the
	// test database's name and account in place of the live one's.
	file_put_contents($conf . '/Globalvars_site.php', '<?php
require ' . var_export(PathHelper::getSiteRoot() . '/config/Globalvars_site.php', true) . ';
$this->settings["dbname"] = $this->settings["dbname_test"];
$this->settings["dbusername"] = $this->settings["dbusername_test"];
$this->settings["dbpassword"] = $this->settings["dbpassword_test"];
');

	section('The new table and column are added, and nothing that is not additive');
	$r = DeploymentHelper::schemaAhead($stage, $site);
	$said = implode("\n", $r['changes']);
	check($r['ran'] && $r['ok'], 'the staged update_database ran and succeeded', json_encode($r));
	check($column_type('zz_schema_ahead_new', 'zsn_name') !== false, 'the new release\'s table exists', $said);
	check($column_type('zz_schema_ahead_probe', 'zsp_note') !== false, 'the new release\'s column exists', $said);
	check(strpos($said, 'zz_schema_ahead_new') !== false && strpos($said, 'zsp_note') !== false,
		'both are reported as schema changes', $said);
	check($column_type('zz_schema_ahead_probe', 'zsp_count') === 'bigint', 'a column type is left as it was', (string)$column_type('zz_schema_ahead_probe', 'zsp_count'));
	$unique = (int)$db->query("SELECT count(*) FROM pg_constraint WHERE conrelid = 'zz_schema_ahead_probe'::regclass AND contype = 'u'")->fetchColumn();
	check($unique === 0, 'no unique constraint is added', "unique constraints: $unique");
	$transcript = implode("\n", $r['output']);
	check(strpos($transcript, 'MIGRATIONS') === false && strpos($transcript, 'COMPOSER VALIDATION') === false,
		'no migration runs, and no Composer check (the staged tree has no vendor/)', $transcript);
	check((int)$db->query('SELECT count(*) FROM zz_schema_ahead_probe')->fetchColumn() === 1, 'the row already there is still there');
	check(strpos($transcript, 'PHP Warning') === false && strpos($transcript, 'PHP Fatal') === false,
		'the staged run raises no PHP warning', $transcript);
	check(!is_link($stage . '/config') && !is_link($stage . '/vendor') && !file_exists($stage . '/vendor'),
		'the lent config and vendor are taken back');
	check(is_file($stage . '/config/default_Globalvars_site.php') && !file_exists($stage . '/.config.staged'),
		'the release\'s own config/ is back where it was, and nothing is left aside');

	section('A second run adds nothing');
	$r = DeploymentHelper::schemaAhead($stage, $site);
	check($r['ok'] && $r['changes'] === array(), 'nothing is left to add', json_encode($r['changes']));

	section('What is never run before the swap');
	file_put_contents($stage . '/public_html/utils/update_database.php', "<?php file_put_contents(__DIR__ . '/RAN', 'yes');\n");
	$r = DeploymentHelper::schemaAhead($stage, $site);
	check(!$r['ran'] && !is_file($stage . '/public_html/utils/RAN'),
		'a staged update_database without --schema-ahead is not run', json_encode($r));
	file_put_contents($stage . '/public_html/utils/update_database.php', "<?php // '--schema-ahead'\nfile_put_contents(__DIR__ . '/RAN', 'yes');\n");
	mkdir($stage . '/.config.staged');
	$r = DeploymentHelper::schemaAhead($stage, $site);
	check(!$r['ran'] && is_file($stage . '/config/default_Globalvars_site.php') && is_dir($stage . '/.config.staged'),
		'with the aside name already taken, nothing runs and both are left alone', json_encode($r));
	rmdir($stage . '/.config.staged');

	section('A run killed while the release\'s config/ was aside');
	rename($stage . '/config', $stage . '/.config.staged');
	symlink($conf, $stage . '/config');
	$r = DeploymentHelper::schemaAhead($stage, $site);
	check($r['ran'] && is_file($stage . '/config/default_Globalvars_site.php') && !file_exists($stage . '/.config.staged'),
		'the next run puts it back before anything else', json_encode($r));
} finally {
	$cleanup();
}

section('upgrade.php adds the schema before the swap, and stages a tree the pool can read');
$up = (string)file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
$ahead = strpos($up, 'DeploymentHelper::schemaAhead($stage_location');
// The swap: DeploymentHelper::swapLiveTree() where the release has it, the
// two find-mv moves before it.
$swap = strpos($up, 'DeploymentHelper::swapLiveTree($live_directory');
if ($swap === false) {
	$swap = strpos($up, '// Move CONTENTS of stage → live');
}
check($ahead !== false && $swap !== false && $ahead < $swap, 'schemaAhead runs before the staged tree is moved in');
check(strpos($up, 'chmod -R 770 $stage_location') === false, 'the staged tree is no longer made 770, which the PHP pool cannot read');
$modes = strpos($up, "-type f ! -name '*.sh' -exec chmod 644");
check($modes !== false && $modes < $swap, 'it is given 755/644 before the swap');

harness_finish();
