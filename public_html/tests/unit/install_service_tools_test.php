<?php
/** @joinery-test
 * name: install_service_tools
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * The two tools a first-boot installer runs when the deploy form names a
 * sending key or a backup bucket (utils/install_mail_provider.php,
 * utils/install_backup_target.php). Their happy paths need a real provider
 * and a real bucket and are proven by deploying; what is pinned here is the
 * contract the StackScript relies on — the verdict line, the reason line, the
 * exit code — and that a refusal writes nothing, because a tool that half-
 * configured a provider on bad input would leave the wizard showing a
 * service that cannot work.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$utils = PathHelper::getIncludePath('utils');
$php = PHP_BINARY;

/** Run a tool with exactly this environment; returns [exit code, stdout lines]. */
function install_tool_run(string $tool, array $env): array {
	$prefix = '';
	foreach ($env as $k => $v) {
		$prefix .= $k . '=' . escapeshellarg($v) . ' ';
	}
	// env -i so nothing from this shell leaks in as an input.
	$cmd = 'env -i ' . $prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' 2>&1';
	exec($cmd, $lines, $code);
	return array($code, $lines);
}

function install_tool_line(array $lines, string $key): string {
	foreach ($lines as $line) {
		if (strpos($line, $key . '=') === 0) {
			return substr($line, strlen($key) + 1);
		}
	}
	return '';
}

$db = DbConnector::get_instance()->get_db_link();
$read = function (string $name) use ($db): string {
	$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	$v = $q->fetchColumn();
	return ($v === false || $v === null) ? '' : (string)$v;
};
$count_targets = function () use ($db): int {
	return (int)$db->query('SELECT count(*) FROM bkt_backup_targets')->fetchColumn();
};

$before = array(
	'email_service' => $read('email_service'),
	'defaultemail' => $read('defaultemail'),
	'smtp2go_api_key' => $read('smtp2go_api_key'),
	'backup_target_id' => $read('backup_target_id'),
	'targets' => $count_targets(),
);

section('install_mail_provider refuses unusable input and writes nothing');
list($code, $out) = install_tool_run($utils . '/install_mail_provider.php', array());
check($code === 2, 'no key: exit 2', 'exit ' . $code);
check(($out[0] ?? '') === 'INSTALL_MAIL_PROVIDER=error', 'the verdict is the first line', $out[0] ?? '(none)');
check(install_tool_line($out, 'reason') !== '', 'and a reason line follows');

list($code, $out) = install_tool_run($utils . '/install_mail_provider.php',
	array('JOINERY_MAIL_API_KEY' => 'not-a-real-key', 'JOINERY_MAIL_PROVIDER' => 'no-such-provider'));
check($code === 2 && ($out[0] ?? '') === 'INSTALL_MAIL_PROVIDER=error', 'an unknown provider: exit 2 with the error verdict');
check(stripos(install_tool_line($out, 'reason'), 'no-such-provider') !== false, 'the reason names the provider');

// SMTP needs a host, a port and a password — no single key configures it.
list($code, $out) = install_tool_run($utils . '/install_mail_provider.php',
	array('JOINERY_MAIL_API_KEY' => 'not-a-real-key', 'JOINERY_MAIL_PROVIDER' => 'smtp'));
check($code === 2, 'a provider a single key cannot configure: exit 2', 'exit ' . $code);
check(stripos(install_tool_line($out, 'reason'), 'single key') !== false, 'the reason says so');

foreach (array('email_service', 'defaultemail', 'smtp2go_api_key') as $name) {
	check($read($name) === $before[$name], 'refusal left ' . $name . ' untouched');
}

section('install_backup_target refuses unusable input and writes nothing');
list($code, $out) = install_tool_run($utils . '/install_backup_target.php', array());
check($code === 2, 'no bucket: exit 2', 'exit ' . $code);
check(($out[0] ?? '') === 'INSTALL_BACKUP_TARGET=error', 'the verdict is the first line', $out[0] ?? '(none)');
check(install_tool_line($out, 'reason') !== '', 'and a reason line follows');

list($code, $out) = install_tool_run($utils . '/install_backup_target.php', array(
	'JOINERY_BACKUP_BUCKET' => 'b', 'JOINERY_BACKUP_KEY_ID' => 'k', 'JOINERY_BACKUP_KEY' => 's',
	'JOINERY_BACKUP_PROVIDER' => 'dropbox'));
check($code === 2 && stripos(install_tool_line($out, 'reason'), 'dropbox') !== false,
	'an unknown provider: exit 2, reason names it');

list($code, $out) = install_tool_run($utils . '/install_backup_target.php', array(
	'JOINERY_BACKUP_BUCKET' => 'b', 'JOINERY_BACKUP_KEY_ID' => 'k', 'JOINERY_BACKUP_KEY' => 's',
	'JOINERY_BACKUP_PROVIDER' => 'linode'));
check($code === 2 && stripos(install_tool_line($out, 'reason'), 'REGION') !== false,
	'a linode bucket without a region: exit 2, reason names the region');

check($count_targets() === $before['targets'], 'no target row was created by a refusal');
check($read('backup_target_id') === $before['backup_target_id'], 'backup_target_id untouched');

section('The tools take no argv and answer only from the CLI');
foreach (array('install_mail_provider.php', 'install_backup_target.php') as $tool) {
	$src = (string)file_get_contents($utils . '/' . $tool);
	check(strpos($src, '$argv') === false, $tool . ' reads nothing from argv');
	check(strpos($src, "php_sapi_name() !== 'cli'") !== false, $tool . ' refuses the web');
}

harness_finish();
