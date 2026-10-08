<?php
/** @joinery-test
 * name: updates_page
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The Updates page's pieces (/admin/admin_updates, spec release_transparency D7).
 *
 * ReleaseProvenance reads the running release from its statement: the version,
 * the public commits and the log entry, and nothing from a file that is not a
 * statement. The page's lists read only: who installed what with Install anyway,
 * and which local copies are kept out of updates. The install history the
 * upgrade writes is a model, round-tripped by the model suites.
 *
 * Run: php tests/run.php db --filter=updates_page
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('adm/logic/admin_updates_logic.php'));

section('The running release, read from its statement');

$commit = str_repeat('ab12', 10);
$agent  = str_repeat('cd34', 10);
$statement = json_encode(array(
	'format'   => 1,
	'envelope' => array(
		'payloadType' => 'application/vnd.joinery.release-statement+json',
		'payload'     => base64_encode(json_encode(array(
			'version' => '0.8.470', 'core_commit' => $commit, 'agent_commit' => $agent,
			'published_at' => '2026-10-08T00:28:14Z'))),
		'signatures'  => array(),
	),
	'entry' => array('log_origin' => 'log2025-1.rekor.sigstore.dev', 'log_index' => 142820611),
));
$read = ReleaseProvenance::read($statement);
check(is_array($read) && $read['version'] === '0.8.470', 'the version comes from the signed payload', json_encode($read));
check(($read['core_commit'] ?? '') === $commit && ($read['agent_commit'] ?? '') === $agent, 'both commits are read');
check(($read['log_origin'] ?? '') === 'log2025-1.rekor.sigstore.dev' && ($read['log_index'] ?? null) === 142820611,
	'the log entry is read');
check(ReleaseProvenance::commit_url(ReleaseProvenance::CORE_REPO, $commit) === 'https://github.com/getjoinery/joinery/commit/' . $commit,
	'a commit links to the public repository');

check(ReleaseProvenance::read('not json') === null, 'a file that is not JSON is not a statement');
check(ReleaseProvenance::read(json_encode(array('envelope' => array('payload' => base64_encode('{}'))))) === null,
	'a statement with no version is not read');
$odd = json_decode($statement, true);
unset($odd['entry']);
$odd['envelope']['payload'] = base64_encode(json_encode(array('version' => '0.8.470', 'core_commit' => 'main; rm -rf /')));
$odd_read = ReleaseProvenance::read(json_encode($odd));
check(is_array($odd_read) && $odd_read['core_commit'] === '' && $odd_read['log_index'] === null,
	'anything but a full commit hash is dropped, and a missing entry reads as none');
check(ReleaseProvenance::commit_url(ReleaseProvenance::CORE_REPO, 'main') === '', 'a branch name gets no link');

$dir = sys_get_temp_dir() . '/updates_page_test_' . getmypid();
@mkdir($dir);
check(ReleaseProvenance::running($dir) === null, 'a tree with no statement runs no logged release');
file_put_contents($dir . '/' . PackageSignature::STATEMENT_NAME, $statement);
check((ReleaseProvenance::running($dir)['version'] ?? '') === '0.8.470', 'a tree\'s statement is found at its top');
@unlink($dir . '/' . PackageSignature::STATEMENT_NAME);
@rmdir($dir);

section('What the upgrade records for each run');

// Requiring upgrade.php runs an upgrade, so the two functions are taken from
// its source and defined here: this exercises the code that ships.
$upgrade_src = file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
foreach (array('upgrade_plain_text', 'upgrade_install_fields') as $fn) {
	$start = strpos($upgrade_src, "function $fn(");
	$open = strpos($upgrade_src, '{', $start);
	for ($depth = 0, $i = $open; $i < strlen($upgrade_src); $i++) {
		$depth += ($upgrade_src[$i] === '{') - ($upgrade_src[$i] === '}');
		if ($depth === 0) { break; }
	}
	eval(substr($upgrade_src, $start, $i - $start + 1));
}
$result = array('version_before' => '0.8.466', 'version_after' => '0.8.466', 'outcome' => 'failed',
	'rolled_back' => array('rolled_back' => false, 'step' => null));
$running = ReleaseProvenance::read($statement);

$installed = upgrade_install_fields(array_merge($result, array('outcome' => 'completed', 'version_after' => '0.8.470')), null, '0.8.470', $running);
check($installed['rin_outcome'] === ReleaseInstall::INSTALLED && $installed['rin_core_commit'] === $commit
	&& $installed['rin_log_index'] === 142820611 && $installed['rin_to_version'] === '0.8.470',
	'an installed release records its commits and log entry', json_encode($installed));

$refused = upgrade_install_fields($result, array('outcome' => 'refused',
	'detail' => 'Upgrade refused: the core archive did not verify: verdict: <code>unlogged</code><br>Nothing has been deployed.'), '0.8.470', null);
check($refused['rin_outcome'] === ReleaseInstall::REFUSED && strpos($refused['rin_detail'], 'unlogged') !== false
	&& strpos($refused['rin_detail'], '<') === false, 'a refusal records why, as plain text', $refused['rin_detail']);
check(strpos($upgrade_src, "strpos(\$title, 'Upgrade refused') === 0 ? 'refused'") !== false
	&& strpos($upgrade_src, "upgrade_abort('Upgrade refused: the ' . \$label . ' did not verify'") !== false,
	'a verification refusal is told apart by its title');

$rolled = upgrade_install_fields(array_merge($result, array('rolled_back' => array('rolled_back' => true, 'step' => 'deploy_tier'))), null, '0.8.470', null);
check($rolled['rin_outcome'] === ReleaseInstall::ROLLED_BACK && strpos($rolled['rin_detail'], 'deploy_tier') !== false,
	'a rollback records the step');

$stopped = upgrade_install_fields($result, array('outcome' => 'stopped', 'detail' => 'Core Download Failed: HTTP 404'), '0.8.470', null);
check($stopped['rin_outcome'] === ReleaseInstall::STOPPED && $stopped['rin_detail'] === 'Core Download Failed: HTTP 404',
	'any other stop records its reason');
$silent = upgrade_install_fields($result, null, null, null);
check($silent['rin_outcome'] === ReleaseInstall::STOPPED && $silent['rin_to_version'] === null && $silent['rin_detail'] !== '',
	'a run that stopped without saying why still gets a row, and no version it never learned');
foreach (array($installed, $refused, $rolled, $stopped) as $row) {
	foreach (array_keys($row) as $field) {
		check(isset(ReleaseInstall::$field_specifications[$field]), "$field is a column of the install history");
	}
}

section('Only a POST asks for an update');

// A link is a GET, and a browser follows one from any site with the admin's
// session cookie attached; the page must not queue a root upgrade for it.
$admin = null;
foreach (new MultiUser(array('usr_permission' => 10, 'deleted' => false), array('user_id' => 'ASC'), 1) as $u) { $admin = $u; }
if ($admin === null) {
	check(false, 'a superadmin exists to load the page as');
} else {
	$saved_session = $_SESSION ?? array();
	$_SESSION['usr_user_id'] = $admin->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = 10;
	$queued_before = count(RootRequest::pending());
	$saved_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$result = admin_updates_logic(array('action' => 'queue_upgrade'));
	if ($saved_method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $saved_method; }
	check($result->redirect === null && count(RootRequest::pending()) === $queued_before,
		'a GET carrying action=queue_upgrade renders the page and queues nothing');
	$_SESSION = $saved_session;
}

section('The page\'s lists');

$sideloaded = admin_updates_sideloaded();
check(is_array($sideloaded), 'Installed with Install anyway lists', count($sideloaded) . ' item(s)');
foreach ($sideloaded as $item) {
	check(in_array($item['trust'], array('unsigned', 'unlogged'), true), $item['type'] . ' ' . $item['name'] . ' is listed for its trust');
}
$forks = admin_updates_forks(true);
check(is_array($forks), 'Kept out of updates lists', count($forks) . ' item(s)');

harness_finish();
