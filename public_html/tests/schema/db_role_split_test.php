<?php
/** @joinery-test
 * name: db_role_split
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [maintenance_scripts/install_tools/site_db_role.sh, maintenance_scripts/install_tools/_db_ownership_sql.sh, maintenance_scripts/install_tools/_set_site_db_role.php, maintenance_scripts/install_tools/_site_init.sh, maintenance_scripts/install_tools/fix_permissions.sh, maintenance_scripts/install_tools/build_dev_from_source.sh, maintenance_scripts/install_tools/default_Globalvars_site.php, maintenance_scripts/sysadmin_tools/_dump_without_owners.sh, maintenance_scripts/sysadmin_tools/_backup_root_only_files.sh, maintenance_scripts/sysadmin_tools/restore_database.sh, maintenance_scripts/sysadmin_tools/restore_chain.sh, maintenance_scripts/sysadmin_tools/backup_database.sh, maintenance_scripts/sysadmin_tools/copy_database.sh, maintenance_scripts/sysadmin_tools/backup_project.sh, maintenance_scripts/sysadmin_tools/remove_account.sh, utils/upgrade.php]
 */
/**
 * The site's own database role, as the tools build it (mail_access_hardening WP4).
 *
 * Nothing here touches a database or a site: it reads what the scripts would
 * do. site_db_role.sh --print-sql names the role with the right attributes and
 * never REASSIGN; _db_ownership_sql.sh covers tables, sequences, views,
 * functions and types and leaves what the role already owns; _set_site_db_role.php
 * rewrites the four config literals and nothing else; _dump_without_owners.sh
 * takes the OWNER TO statements out of a dump and leaves a COPY block's rows
 * alone; the shell tools read the postgres pair from db_admin.php first; the
 * root-only backup list carries db_admin.php.
 *
 * Run: php tests/run.php --filter=db_role_split
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tools = dirname(PathHelper::getBasePath()) . '/maintenance_scripts';
$install = $tools . '/install_tools';
$sysadmin = $tools . '/sysadmin_tools';
$scratch = harness_scratch_dir('db_role_split');

$run = function (string $cmd, ?string &$out = null): int {
	$lines = array();
	exec($cmd . ' 2>&1', $lines, $rc);
	$out = implode("\n", $lines);
	return (int)$rc;
};

section('the scripts parse');
foreach (array('install_tools/site_db_role.sh', 'install_tools/_db_ownership_sql.sh', 'sysadmin_tools/_dump_without_owners.sh',
		'sysadmin_tools/restore_database.sh', 'sysadmin_tools/backup_database.sh', 'sysadmin_tools/copy_database.sh',
		'sysadmin_tools/backup_project.sh', 'sysadmin_tools/remove_account.sh', 'install_tools/_site_init.sh') as $rel) {
	check($run('bash -n ' . escapeshellarg($tools . '/' . $rel)) === 0, $rel . ' parses');
}
check($run('php -l ' . escapeshellarg($install . '/_set_site_db_role.php')) === 0, '_set_site_db_role.php parses');

section('the role, as site_db_role.sh would make it');
$rc = $run('bash ' . escapeshellarg($install . '/site_db_role.sh') . ' demo_site --print-sql', $sql);
check($rc === 0, '--print-sql connects to nothing and succeeds for a site that does not exist', $sql);
check(strpos($sql, 'ALTER ROLE "joinery_demo_site" LOGIN NOSUPERUSER CREATEDB NOCREATEROLE CONNECTION LIMIT 100 PASSWORD') !== false,
	'joinery_{site}: LOGIN, NOSUPERUSER, CREATEDB, NOCREATEROLE, CONNECTION LIMIT 100', $sql);
check(strpos($sql, "PASSWORD '(generated, 32 characters)'") !== false,
	'the dry run generated a real password (the generator runs under pipefail) and printed a placeholder for it', $sql);
check(strpos($sql, 'ALTER DATABASE "demo_site" OWNER TO "joinery_demo_site"') !== false
		&& strpos($sql, 'ALTER DATABASE "test_demo_site" OWNER TO "joinery_demo_site"') !== false,
	'the site database and its test database are handed to the role');
check(stripos($sql, 'REASSIGN') === false, 'never REASSIGN OWNED BY (it would move every database on the server)');
check($run('bash ' . escapeshellarg($install . '/site_db_role.sh') . ' "Bad;Name" --print-sql') !== 0, 'a name that is not a site name is refused');

section('the ownership block');
$rc = $run('bash ' . escapeshellarg($install . '/_db_ownership_sql.sh') . ' joinery_demo', $block);
check($rc === 0 && strpos($block, 'DO $joinery_ownership$') === 0, 'one DO block', substr($block, 0, 60));
foreach (array("'ALTER SEQUENCE %s OWNER TO %I'", "'ALTER VIEW %s OWNER TO %I'", "'ALTER MATERIALIZED VIEW %s OWNER TO %I'",
		"'ALTER FOREIGN TABLE %s OWNER TO %I'", "'ALTER TABLE %s OWNER TO %I'", "'ALTER TYPE %s OWNER TO %I'",
		"THEN 'PROCEDURE' WHEN r.prokind = 'a' THEN 'AGGREGATE' ELSE 'FUNCTION' END",
		"'ALTER SCHEMA public OWNER TO %I'", "'GRANT USAGE, CREATE ON SCHEMA public TO %I'") as $needle) {
	check(strpos($block, $needle) !== false, 'covers ' . trim($needle, "'"));
}
check(substr_count($block, "<> 'joinery_demo'") === 3 && strpos($block, "NOT IN ('joinery_demo', 'pg_database_owner')") !== false,
	'what the role already owns is left alone (idempotent)');
check(strpos($block, "d.deptype IN ('a', 'i')") !== false, 'a sequence a column owns follows its table (reviewer2 B2)');
check($run('bash ' . escapeshellarg($install . '/_db_ownership_sql.sh') . ' "x; drop"') !== 0, 'a role name that is not a name is refused');

section('the config rewrite changes the four literals and nothing else');
$config = $scratch . '/Globalvars_site.php';
copy($install . '/default_Globalvars_site.php', $config);
$before = file_get_contents($config);
$rc = $run('JOINERY_CFG_DB_ROLE=joinery_demo JOINERY_CFG_DB_PASSWORD=' . escapeshellarg("p'a\\ss") . ' php ' . escapeshellarg($install . '/_set_site_db_role.php') . ' ' . escapeshellarg($config), $out);
check($rc === 0 && trim($out) === 'updated', 'rewritten', $out);
$after = file_get_contents($config);
check(preg_match("/\\\$this->settings\\['dbusername'\\] = 'joinery_demo';/", $after) === 1
		&& preg_match("/\\\$this->settings\\['dbusername_test'\\] = 'joinery_demo';/", $after) === 1,
	'both usernames name the role');
check(substr_count($after, "'p\\'a\\\\ss'") === 2, 'the password is a correct PHP literal, quotes and backslashes included', $after);
check(strpos($after, "{{SECRET_BOX_KEY}}") !== false && strpos($after, "{{SITE_NAME}}") !== false,
	'every other literal is untouched');
// Everything outside the four literals is byte for byte the template.
$normalize = function (string $s): string {
	return preg_replace("/(\\\$this->settings\\['db(?:username|password)(?:_test)?'\\] = )'[^;]*';/", '$1X;', $s);
};
check($normalize($after) === $normalize($before), 'and nothing outside the four assignments moved');
$rc = $run('JOINERY_CFG_DB_ROLE=joinery_demo JOINERY_CFG_DB_PASSWORD=' . escapeshellarg("p'a\\ss") . ' php ' . escapeshellarg($install . '/_set_site_db_role.php') . ' ' . escapeshellarg($config), $out);
check($rc === 0 && trim($out) === 'same', 'a second run changes nothing');

section('a dump loses its OWNER TO statements and keeps its rows');
$dump = $scratch . '/dump.sql';
file_put_contents($dump, implode("\n", array(
	'SET x = 1;', 'CREATE TABLE t (a int);', 'ALTER TABLE public.t OWNER TO postgres;', 'ALTER SEQUENCE public.s OWNER TO joinery_other;',
	'COPY public.t (a) FROM stdin;', 'ALTER TABLE x OWNER TO postgres;', '\\.', 'ALTER FUNCTION public.f() OWNER TO postgres;',
	'GRANT SELECT ON t TO iemap_x;')) . "\n");
$rc = $run('bash ' . escapeshellarg($sysadmin . '/_dump_without_owners.sh') . ' ' . escapeshellarg($dump), $out);
$kept = file_get_contents($dump);
check($rc === 0 && trim($out) === '3', 'three statements removed, said', $out);
check(strpos($kept, "ALTER TABLE public.t OWNER") === false && strpos($kept, "OWNER TO joinery_other") === false && strpos($kept, "ALTER FUNCTION") === false,
	'the table, sequence and function owners are gone');
check(strpos($kept, "COPY public.t (a) FROM stdin;\nALTER TABLE x OWNER TO postgres;\n\\.") !== false, 'a row inside a COPY block that reads like one stays');
check(strpos($kept, 'GRANT SELECT ON t TO iemap_x;') !== false, 'grants stay (the Postfix role keeps its reads)');

section('the shell tools and the backup know about db_admin.php');
foreach (array('restore_database.sh', 'backup_database.sh', 'copy_database.sh', 'backup_project.sh', 'remove_account.sh') as $tool) {
	$src = file_get_contents($sysadmin . '/' . $tool);
	check(strpos($src, 'dbadminpassword') !== false && strpos($src, 'db_admin.php') !== false, $tool . ' reads the postgres pair from db_admin.php');
}
check(preg_match('/ROOT_ONLY_FILES=\([^)]*db_admin\.php[^)]*\)/', file_get_contents($sysadmin . '/_backup_root_only_files.sh')) === 1,
	'db_admin.php rides in the root-only config files a backup carries');
check(strpos(file_get_contents($sysadmin . '/restore_database.sh'), '_dump_without_owners.sh') !== false
		&& strpos(file_get_contents($sysadmin . '/restore_database.sh'), '_db_ownership_sql.sh') !== false,
	'the restore strips owners before the load and hands the database to the site role after it');
check(strpos(file_get_contents($install . '/_site_init.sh'), 'site_db_role.sh') !== false, 'a new site gets its role from _site_init.sh');
check(preg_match('/for _php_tool in "\$\{SCRIPT_DIR\}\/\.\.\/install_tools\/"\*\.php/', file_get_contents($sysadmin . '/restore_chain.sh')) === 1,
	'a chain restore\'s tools copy carries the config reader the engine hands the database over with');
check(strpos(file_get_contents($sysadmin . '/restore_database.sh'), 'is not beside this engine; the site') !== false,
	'an engine run without its reader still finds the owning site (sed over the identifiers) and says so');
$fixperm = file_get_contents($install . '/fix_permissions.sh');
check(strpos($fixperm, '"$SITE_ROOT/config/db_admin.php"') !== false && substr_count($fixperm, "-not -name 'db_admin.php'") === 2
		&& strpos($fixperm, 'chmod 600 "$DB_ADMIN_FILE"') !== false,
	'fix_permissions.sh pins db_admin.php 600 root:root and leaves it out of the config sweep, so the pool never gets the postgres pair back');
$role_src = file_get_contents($install . '/site_db_role.sh');
check(strpos($role_src, 'SELECT count(*) FROM stg_settings') < strpos($role_src, "# ---- the site's config"),
	'site_db_role.sh proves the role reads before it rewrites the config');
check(strpos(file_get_contents($install . '/build_dev_from_source.sh'), 'site_db_role.sh') !== false, 'a dev build hands the copied database to the target site\'s role');
check(strpos(file_get_contents(PathHelper::getBasePath() . 'utils/upgrade.php'), 'site_db_role.sh') !== false, 'an existing site gets it from upgrade.php');

harness_finish();
