<?php
/** @joinery-test
 * name: host_restrictions
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * What the machine refuses its sites (specs/host_restrictions.md D1, D2, D3).
 *
 * HostRestrictions reads the host's file and fails closed: a file read cleanly
 * means exactly what it says, no file means nothing is restricted, and a file
 * that cannot be read as written - a bad line, a value its name does not take,
 * one someone else could have written, a symlink - means every restriction is
 * on. The shell's list of what may be written (_host_restrictions.sh) is the
 * same as the reader's, the writer writes only that, and the shell asks the
 * same question the same way. The refusals themselves: the acknowledgement is
 * never minted on a restricted host.
 *
 * Unprivileged, the reader is pointed at a fixture (useFile, or the
 * environment for a subprocess); as root it always reads the host's own file,
 * so this runs as the developer. A file owned by the kernel's overflow uid (the
 * host's root, seen from a container that remaps ids) is a box check.
 *
 * Run: php tests/run.php --only=tests/unit/host_restrictions_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	harness_skip('run unprivileged: as root the reader ignores any fixture and reads the host\'s own file');
	harness_finish();
}

$root = dirname(__DIR__, 3);
$tools = $root . '/maintenance_scripts/install_tools';
$work = harness_scratch_dir('host_restrictions') . '/run-' . getmypid();
@mkdir($work . '/d', 0755, true);
chmod($work . '/d', 0755);
$file = $work . '/d/restrictions';
harness_defer(function () use ($work) {
	exec('rm -rf ' . escapeshellarg($work));
	HostRestrictions::useFile(null);
});
$write = function (string $body, int $mode = 0644) use ($file) {
	@unlink($file);
	file_put_contents($file, $body);
	chmod($file, $mode);
	HostRestrictions::useFile($file);
};

section('No file: nothing is restricted');

HostRestrictions::useFile($work . '/absent/restrictions');
check(!HostRestrictions::has('unsigned_packages') && HostRestrictions::all() === array() && HostRestrictions::refusal() === '',
	'no file and no directory: nothing on, nothing refused');

section('A file read cleanly means exactly what it says');

$write("# joinery-managed\n\nunsigned_packages=refuse\n");
check(HostRestrictions::has('unsigned_packages') && HostRestrictions::all() === array('unsigned_packages' => 'refuse')
	&& HostRestrictions::refusal() === '', 'unsigned_packages=refuse is on, and nothing else');
$write("# nothing yet\n");
check(!HostRestrictions::has('unsigned_packages') && HostRestrictions::refusal() === '', 'a file with only comments restricts nothing');
$write("some_future_rule=on\n");
check(!HostRestrictions::has('unsigned_packages') && HostRestrictions::refusal() === '',
	'a name this release does not know limits nothing here (a newer release may write it)');
check(HostRestrictions::unsignedRefusal() === 'This host installs only signed plugins and themes (' . $file . ').',
	'the refusal names the sentence and the file', HostRestrictions::unsignedRefusal());

section('A file that cannot be read as written: every restriction is on');

foreach (array(
	'a line that is not name=value' => array("unsigned_packages=refuse\nnot a line\n", 0644),
	'a value its name does not take' => array("unsigned_packages=allow\n", 0644),
	'a file others can write' => array("# empty\n", 0666),
	'a file its group can write' => array("# empty\n", 0664),
) as $label => $case) {
	$write($case[0], $case[1]);
	check(HostRestrictions::has('unsigned_packages') && strpos(HostRestrictions::refusal(), 'every restriction is on') !== false,
		$label . ': on, and refusal() says why', HostRestrictions::refusal());
}
@unlink($file);
symlink($work . '/elsewhere', $file);
file_put_contents($work . '/elsewhere', "# empty\n");
chmod($work . '/elsewhere', 0644);
HostRestrictions::useFile($file);
check(HostRestrictions::has('unsigned_packages'), 'a symlink in place of the file: on', HostRestrictions::refusal());
@unlink($file);
chmod($work . '/d', 0777);
$write("# empty\n");
check(HostRestrictions::has('unsigned_packages'), 'a directory others can write: on (the file could be swapped)', HostRestrictions::refusal());
chmod($work . '/d', 0755);

section('The shell writes only what the reader knows, and asks the same question');

exec('bash ' . escapeshellarg($tools . '/host_restrictions.sh') . ' known', $known, $rc);
$php_known = array();
foreach (HostRestrictions::KNOWN as $name => $values) {
	foreach ($values as $v) { $php_known[] = $name . '=' . $v; }
}
sort($known);
sort($php_known);
check($rc === 0 && $known === $php_known, 'the shell\'s known pairs are HostRestrictions::KNOWN', json_encode($known));

@unlink($file);
$env = 'JOINERY_HOST_RESTRICTIONS_FILE=' . escapeshellarg($file);
exec($env . ' bash ' . escapeshellarg($tools . '/host_restrictions.sh') . ' multi-tenant 2>&1', $out, $rc);
HostRestrictions::useFile($file);
check($rc === 0 && HostRestrictions::has('unsigned_packages') && HostRestrictions::refusal() === ''
	&& substr(sprintf('%o', fileperms($file)), -3) === '644', 'multi-tenant writes the set, 644, read cleanly', implode(' ', $out));
$before = file_get_contents($file);
exec($env . ' bash ' . escapeshellarg($tools . '/host_restrictions.sh') . ' multi-tenant 2>&1', $out, $rc);
check(file_get_contents($file) === $before, 'run again, it writes the same file');
exec($env . ' bash ' . escapeshellarg($tools . '/host_restrictions.sh') . ' init 2>&1', $out, $rc);
check($rc === 0 && file_get_contents($file) === $before, 'init over a file already there keeps it as it is');

// A single-site Docker host remaps user ids too (multi_tenant_host_hardening D11):
// init gives it the directory, with a file that refuses nothing.
@unlink($file);
exec($env . ' bash ' . escapeshellarg($tools . '/host_restrictions.sh') . ' init 2>&1', $out, $rc);
HostRestrictions::useFile($file);
check($rc === 0 && is_file($file) && HostRestrictions::all() === array() && HostRestrictions::refusal() === ''
	&& substr(sprintf('%o', fileperms($file)), -3) === '644', 'init writes a file that refuses nothing, 644, read cleanly', implode(' ', $out));
exec($env . ' bash ' . escapeshellarg($tools . '/host_restrictions.sh') . ' multi-tenant 2>&1', $out, $rc);
HostRestrictions::useFile($file);
check($rc === 0 && HostRestrictions::has('unsigned_packages'), 'multi-tenant after init adds the set');

$ask = function (string $file) use ($tools, $root) {
	exec('JOINERY_HOST_RESTRICTIONS_FILE=' . escapeshellarg($file) . ' bash -c '
		. escapeshellarg('. ' . escapeshellarg($tools . '/_host_restrictions.sh') . '; host_restriction_on '
			. escapeshellarg($root . '/public_html') . ' unsigned_packages') . ' 2>/dev/null', $o, $rc);
	return $rc;
};
check($ask($file) === 0, 'the shell asks the PHP reader: on');
file_put_contents($file, "not a line\n");
check($ask($file) === 0, 'a broken file: the shell says on too');
check($ask($work . '/absent/restrictions') === 1, 'no file and no directory: the shell says off without asking');
file_put_contents($file, "# empty\n");
check($ask($file) === 1, 'a file restricting nothing: off');

section('On a restricted host the acknowledgement is never minted');

$write("unsigned_packages=refuse\n");
$threw = '';
try {
	PackageAcknowledgement::mint(SessionControl::get_instance());
} catch (Exception $e) {
	$threw = $e->getMessage();
}
check($threw === HostRestrictions::UNSIGNED_SENTENCE, 'mint() refuses with the sentence, before asking for any second factor', $threw);
check(PackageSignature::publisherBox() === false, 'and this box is not the publishing box, whatever its config/ holds');

$page = new class {
	public function getFormWriter($id) {
		return new class { public function getCSRFToken() { return 'token'; } };
	}
};
$refused = array('request_id' => 'r1', 'type' => 'plugin', 'name' => 'evil', 'version' => '1.0', 'author' => 'x',
	'file_count' => 3, 'verdict' => 'unsigned: no manifest', 'staged_dir' => 'a/evil', 'replace' => false, 'warning' => 'w');
$html = PackageInstallPage::warning_html($refused + array('restricted' => true), '/admin/admin_plugins', $page);
check(strpos($html, HostRestrictions::UNSIGNED_SENTENCE) !== false && strpos($html, 'Install anyway') === false
	&& strpos($html, 'install_anyway') === false, 'the page says the host refuses it, and offers no Install anyway', $html);
$html = PackageInstallPage::warning_html($refused + array('restricted' => false), '/admin/admin_plugins', $page);
check(strpos($html, 'Install anyway') !== false, 'on any other host the warning and its button are as they were');

harness_finish();
