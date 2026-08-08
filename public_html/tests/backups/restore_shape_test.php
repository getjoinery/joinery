<?php
/** @joinery-test
 * name: restore_shape
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A backup is supposed to rebuild a site anywhere. It could not: put a backup
 * taken inside a container onto a plain server and the site came back serving
 * plain HTTP under an internal hostname, believing it was still in a container,
 * at its old address, against a database it could not open — and the restore
 * reported success at every step.
 *
 * Three things stop that recurring, and this holds all three:
 *
 *   1. The backup RECORDS its shape (reconcile_site.sh --print-shape), so a
 *      restore can say what it is landing on versus what it came from rather
 *      than guessing.
 *   2. The restore RECONCILES to the machine (the same script's default mode):
 *      the domain,
 *      the deployment shape, the paths — and it REFUSES when the restored
 *      database will not open with this machine's credentials, which is the
 *      failure that otherwise reaches production as SQLSTATE[08006] on every
 *      page.
 *   3. Neither restore path installs the virtualhost the backup carries. That
 *      one file is the thing the installer has just written correctly for this
 *      box, and overwriting it is how the drill lost a certificate that was
 *      already on disk.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
$reconcile_sh = $tools . '/reconcile_site.sh';

if (!is_file($reconcile_sh)) {
	harness_skip('shape/reconcile tooling not present', $tools);
	harness_finish();
}

$work = sys_get_temp_dir() . '/jy_restore_shape_' . getmypid();
register_shutdown_function(function () use ($work) {
	@shell_exec('rm -rf ' . escapeshellarg($work));
});

/**
 * A stand-in for a site root: just the config, which is all either script reads.
 * $overrides replaces individual settings.
 */
function shapetest_site($dir, array $overrides = []) {
	$settings = array_merge([
		'baseDir'                => dirname($dir) . '/',
		'site_template'          => basename($dir),
		'webDir'                 => 'old.example.com',
		'deployment_environment' => 'docker',
		'dbusername'             => 'postgres',
		'dbname'                 => '',
		'dbpassword'             => 'not-the-real-one',
	], $overrides);

	@mkdir($dir . '/config', 0777, true);
	$php = "<?php\n";
	foreach ($settings as $k => $v) {
		$php .= "\$this->settings['{$k}'] = '{$v}';\n";
	}
	file_put_contents($dir . '/config/Globalvars_site.php', $php);
	return $dir . '/config/Globalvars_site.php';
}

function shapetest_setting($config, $key) {
	$src = (string)@file_get_contents($config);
	if (preg_match("/\\\$this->settings\\['" . preg_quote($key, '/') . "'\\]\s*=\s*'([^']*)'/", $src, $m)) {
		return $m[1];
	}
	return null;
}

// ─────────────────────────────────────────────────────────────────────────────
section('A backup records the shape it was taken on');

$src_dir = $work . '/sourcesite';
shapetest_site($src_dir, ['deployment_environment' => 'docker', 'webDir' => 'demo.getjoinery.com']);

$out = [];
exec('bash ' . escapeshellarg($reconcile_sh) . ' sourcesite --print-shape --site-dir ' . escapeshellarg($src_dir)
	. ' --vhost-captured yes 2>/dev/null', $out, $rc);
check($rc === 0, '--print-shape succeeds against a site config', implode("\n", $out));

$shape = json_decode(implode("\n", $out), true);
check(is_array($shape), 'it emits valid JSON', implode("\n", $out));
check(($shape['deployment_environment'] ?? null) === 'docker',
	'the shape is READ from the site config, not probed for at runtime');
check(($shape['domain'] ?? null) === 'demo.getjoinery.com',
	'it records the domain the site answered to');
check(($shape['vhost_role'] ?? null) === 'internal',
	'a container capture is marked internal — the one kind that must never be installed as-is');
check(($shape['web_root'] ?? '') === $src_dir . '/public_html',
	'it records where the web root was');

// A bare-metal capture is the public face, and says so.
$bare_dir = $work . '/baresite';
shapetest_site($bare_dir, ['deployment_environment' => 'baremetal']);
$out = [];
exec('bash ' . escapeshellarg($reconcile_sh) . ' baresite --print-shape --site-dir ' . escapeshellarg($bare_dir)
	. ' --vhost-captured yes 2>/dev/null', $out);
$bare_shape = json_decode(implode("\n", $out), true);
check(($bare_shape['vhost_role'] ?? null) === 'public',
	'a bare-metal capture is marked public');

// ─────────────────────────────────────────────────────────────────────────────
section('The domain is required, never inferred');

$tgt_dir = $work . '/targetsite';
$tgt_cfg = shapetest_site($tgt_dir, ['deployment_environment' => 'baremetal']);

$out = []; $rc = 0;
exec('bash ' . escapeshellarg($reconcile_sh) . ' targetsite --site-dir ' . escapeshellarg($tgt_dir)
	. ' --skip-web-config --skip-ssl 2>&1', $out, $rc);
check($rc !== 0, 'reconcile_site.sh refuses to run without --domain');
check(strpos(implode("\n", $out), 'rehearsal') !== false,
	'and says why: a rebuild and a rehearsal want opposite answers from the same backup',
	implode("\n", $out));

// ─────────────────────────────────────────────────────────────────────────────
section('Reconciliation settles the identity, in the config');

$meta = $work . '/meta';
@mkdir($meta, 0777, true);
file_put_contents($meta . '/shape.json', json_encode([
	'version' => 1, 'deployment_environment' => 'docker', 'domain' => 'demo.getjoinery.com',
]));

$out = []; $rc = 0;
exec('bash ' . escapeshellarg($reconcile_sh) . ' targetsite --site-dir ' . escapeshellarg($tgt_dir)
	. ' --domain new.example.com --backup-meta ' . escapeshellarg($meta)
	. ' --skip-web-config --skip-ssl 2>/dev/null', $out, $rc);
$report = implode("\n", $out);

check($rc === 0, 'it succeeds against an installed site', $report);
check(strpos($report, 'RECONCILE_OK') !== false, 'it reports completion', $report);
check(shapetest_setting($tgt_cfg, 'webDir') === 'new.example.com',
	'the site now calls itself by the domain it was given');
check(shapetest_setting($tgt_cfg, 'deployment_environment') === 'baremetal',
	'the site now believes the shape of the machine it is on, not the one it came from');
check(shapetest_setting($tgt_cfg, 'site_template') === 'targetsite',
	'the site knows which directory it lives in');
check(shapetest_setting($tgt_cfg, 'baseDir') === dirname($tgt_dir) . '/',
	'and where that directory is');

// The report is the point: a silent fixup is as hard to trust as a silent
// breakage, so every changed value is named.
check(strpos($report, 'RECONCILE_SHAPE_CHANGE docker -> baremetal') !== false,
	'it states the shape change it detected', $report);
check(strpos($report, 'RECONCILE_DOMAIN_CHANGE demo.getjoinery.com -> new.example.com') !== false,
	'it states the domain change', $report);
check(preg_match('/RECONCILE_SET webDir /', $report) === 1,
	'it names each setting it rewrote', $report);

// Running it twice must be a no-op, not a second round of changes: a restore
// that is retried after a transport failure is the normal case.
$out = []; $rc = 0;
exec('bash ' . escapeshellarg($reconcile_sh) . ' targetsite --site-dir ' . escapeshellarg($tgt_dir)
	. ' --domain new.example.com --skip-web-config --skip-ssl 2>/dev/null', $out, $rc);
check($rc === 0 && strpos(implode("\n", $out), 'RECONCILE_CHANGES 0') !== false,
	'a second run changes nothing', implode("\n", $out));

// ─────────────────────────────────────────────────────────────────────────────
section('A backup with no shape is still restorable');

$out = []; $rc = 0;
exec('bash ' . escapeshellarg($reconcile_sh) . ' targetsite --site-dir ' . escapeshellarg($tgt_dir)
	. ' --domain new.example.com --skip-web-config --skip-ssl 2>/dev/null', $out, $rc);
check($rc === 0, 'an archive taken before shape.json existed does not fail the restore');
check(strpos(implode("\n", $out), 'RECONCILE_SOURCE_SHAPE unknown') !== false,
	'the source shape reads as unknown rather than being guessed at', implode("\n", $out));

// ─────────────────────────────────────────────────────────────────────────────
section('A database that will not open is a failure, not a warning');

// The exact drill failure: the restored config carried the SOURCE machine's
// database password, every page logged SQLSTATE[08006], and an operator had to
// change a role password by hand. Reconciliation is the gate that catches it.
if (trim((string)@shell_exec('command -v psql 2>/dev/null')) === '') {
	harness_skip('psql not available — the credential gate cannot be exercised here');
} else {
	$bad_dir = $work . '/badcreds';
	shapetest_site($bad_dir, [
		'deployment_environment' => 'baremetal',
		'dbname'                 => 'jy_no_such_database_' . getmypid(),
		'dbpassword'             => 'definitely-not-the-role-password',
	]);

	$out = []; $rc = 0;
	exec('bash ' . escapeshellarg($reconcile_sh) . ' badcreds --site-dir ' . escapeshellarg($bad_dir)
		. ' --domain new.example.com --skip-web-config --skip-ssl 2>&1', $out, $rc);
	$bad_report = implode("\n", $out);

	check($rc !== 0, 'the reconcile fails when the database will not open', $bad_report);
	check(strpos($bad_report, 'RECONCILE_FAILED database_credentials') !== false,
		'and names the reason, rather than leaving it to show up as SQLSTATE on every page',
		$bad_report);
}

// ─────────────────────────────────────────────────────────────────────────────
section('No restore path installs the virtualhost it carries');

// This is a contract between three files, and the failure it guards against is
// invisible: the site keeps answering on :80, so every HTTP check stays green
// while HTTPS is simply gone.
$rp = (string)@file_get_contents($tools . '/restore_project.sh');
$rc_sh = (string)@file_get_contents($tools . '/restore_chain.sh');

check(strpos($rp, 'reconcile_site.sh') !== false,
	'restore_project.sh ends by reconciling to this machine');
check(strpos($rc_sh, 'reconcile_site.sh') !== false,
	'restore_chain.sh ends by reconciling to this machine');
check(strpos($rc_sh, 'cp "$VHOST" /etc/apache2/sites-available/') === false,
	'restore_chain.sh no longer copies the captured virtualhost over the live one');
check(strpos($rp, 'sudo cp "$apache_conf" "$target_conf"') === false,
	'restore_project.sh no longer copies the captured virtualhost over the live one');

// The two files that belong to the MACHINE and never to the backup.
foreach (['config/Globalvars_site.php', 'config/backup_site_key'] as $mine) {
	check(strpos($rp, $mine) !== false,
		"restore_project.sh keeps this machine's {$mine}");
	check(strpos($rc_sh, $mine) !== false,
		"restore_chain.sh keeps this machine's {$mine}");
}

harness_finish();
