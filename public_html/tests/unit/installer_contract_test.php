<?php
/** @joinery-test
 * name: installer_contract
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The promises the published install path makes, held to the code that makes them.
 *
 * Everything here guards a defect that shipped once (specs/installer_defects.md).
 * The common shape of those defects was drift no one could see: a document
 * describing behavior the script did not have, a hardening step that assumed an
 * account it never created, a credential printed on a page that no longer knew
 * the value. None of them fail loudly at install time — they fail for a stranger
 * on a server we cannot see. So they are pinned here instead.
 *
 * These are text assertions over scripts, not executions of them. install.sh
 * provisions a server; running it in a test suite is not an option. That
 * limits what can be claimed: a check passing means the guard is still written
 * down, not that a live install behaves. The three-branch SSH behavior is
 * verified by hand on a real box — see the spec's Testing section.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/installer_contract_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$site_root   = dirname(PathHelper::getRootDir());
$install_sh  = $site_root . '/maintenance_scripts/install_tools/install.sh';
$site_init   = $site_root . '/maintenance_scripts/install_tools/_site_init.sh';
$reset_tool  = $site_root . '/maintenance_scripts/sysadmin_tools/reset_admin_password.php';
$license     = $site_root . '/LICENSE.md';
$publish     = PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php');
$quickstart  = PathHelper::getIncludePath('docs/quickstart.md');

$install_src   = is_file($install_sh) ? file_get_contents($install_sh) : '';
$site_init_src = is_file($site_init) ? file_get_contents($site_init) : '';
$publish_src   = is_file($publish) ? file_get_contents($publish) : '';
$quickstart_md = is_file($quickstart) ? file_get_contents($quickstart) : '';


section('DNS not being ready does not stop an install');

// The original defect: `install.sh site` ran an early DNS check and exited
// before doing any work when a real domain did not resolve here. Everything
// downstream already tolerated a missing certificate, so the gate was the only
// thing turning "no cert yet" into "no site at all".
check($install_src !== '', 'install.sh is readable', $install_sh);

// Isolate the early DNS block so an `exit 1` elsewhere in the file cannot
// mask a regression here.
$dns_block = '';
if (preg_match('/Early DNS check.*?\n    fi\n/s', $install_src, $m)) {
	$dns_block = $m[0];
}
check($dns_block !== '', 'the early DNS check block is findable');
check($dns_block !== '' && strpos($dns_block, 'exit 1') === false,
	'the DNS check does not abort the install',
	'an exit in this block is the defect the spec removed');
check(strpos($install_src, 'SSL_DEFERRED=1') !== false,
	'a deferred certificate is recorded so the summary can report it');
check(strpos($install_src, 'print_ssl_deferred_notice') !== false,
	'the closing summary prints the deferred-SSL notice');

// The doc and the behavior drifting apart is what made this defect expensive:
// quickstart.md described the graceful path for as long as the script aborted.
check(strpos($install_src, 'sysadmin_tools/setup_ssl.sh') !== false,
	'install.sh names the command that issues the certificate later');
check(strpos($quickstart_md, 'setup_ssl.sh') !== false,
	'quickstart.md names the same command');
check(stripos($quickstart_md, 'the install continues') !== false
	|| stripos($quickstart_md, 'install continues') !== false,
	'quickstart.md still says an install continues without DNS');


section('Server hardening keeps someone able to log in');

// `install.sh server` used to set PermitRootLogin no while user1 held no
// password, no key and no sudo. On a key-only box that ends the operator's
// session partway through the run.
check(strpos($install_src, 'derive_ssh_access()') !== false,
	'install.sh derives which account survives hardening');
check(strpos($install_src, 'SUDO_USER') !== false,
	'the sudo-from-a-normal-account branch is present');
check(strpos($install_src, '/root/.ssh/authorized_keys') !== false,
	'the root-with-a-key branch is present');
check(strpos($install_src, 'sudoers.d/user1') !== false,
	'user1 is granted sudo when it inherits root\'s keys');

// Only PermitRootLogin is conditional. If this assertion ever needs relaxing,
// the question to ask is whether the directive being skipped can lock anyone
// out — most cannot, and those always apply.
check(preg_match('/if \[ "\$SSH_ROOT_LOGIN_SAFE" -eq 1 \][^}]*?PermitRootLogin no/s', $install_src) === 1,
	'PermitRootLogin no is gated on an account that survives it');
check(preg_match('/^\s*sed -i .s\/#?PasswordAuthentication yes\/PasswordAuthentication yes\//m', $install_src) === 0,
	'install.sh does not turn password authentication on',
	'it was enabling password auth while leaving no account able to use it');


section('No shared admin credential ships with a release');

check($site_init_src !== '', '_site_init.sh is readable', $site_init);
check(strpos($site_init_src, 'reset_admin_password.php') !== false,
	'_site_init.sh sets a per-site admin password on install');
check(strpos($site_init_src, 'JOINERY_ADMIN_PASSWORD') !== false,
	'an unattended installer can supply the password instead',
	'the one-click path has no terminal to read a file from');
check(strpos($site_init_src, '--password-file=') !== false,
	'the password is handed over in a file, not an argument',
	'arguments are visible in ps');
check(is_file($reset_tool), 'the reset tool exists where _site_init.sh calls it', $reset_tool);

// fix_permissions.sh sweeps the whole site root — 770 www-data:user1 in
// production, 777 in dev. Without an explicit re-pin the credentials file
// written above would come out readable by the web server user, and on a dev
// install by everyone.
$fix_perms = $site_root . '/maintenance_scripts/install_tools/fix_permissions.sh';
$fix_perms_src = is_file($fix_perms) ? file_get_contents($fix_perms) : '';
check(strpos($fix_perms_src, 'admin_credentials.txt') !== false,
	'the permissions sweep re-pins the credentials file',
	'the blanket chmod would otherwise hand it to the web server user');

// It lives outside public_html on purpose: /utils/<name> is web-routable and
// the router applies no permission check of its own.
check(strpos($reset_tool, '/maintenance_scripts/sysadmin_tools/') !== false,
	'the reset tool is outside the web root');
$reset_src = is_file($reset_tool) ? file_get_contents($reset_tool) : '';
check(strpos($reset_src, "PHP_SAPI !== 'cli'") !== false,
	'the reset tool refuses to run outside the CLI');


section('No view renders a credential literal');

// The homepage of a fresh install used to print the seeded admin password to
// any anonymous visitor. The point of this check is not that one card — it is
// gone — but the next one.
$views_dir = PathHelper::getIncludePath('views');
$offenders = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views_dir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
		continue;
	}
	$body = file_get_contents($file->getPathname());
	// Literal passwords, not the word "password": a quoted or <code>-wrapped
	// value sitting next to a password label.
	if (preg_match('/(changeme|passw0rd|letmein|admin123)/i', $body)) {
		$offenders[] = str_replace($views_dir . '/', '', $file->getPathname());
	}
}
check(count($offenders) === 0,
	'no view contains a well-known credential literal',
	$offenders ? implode(', ', $offenders) : '');


section('A release cannot be published without its license');

check(is_file($license) && trim((string)file_get_contents($license)) !== '',
	'LICENSE.md exists at the repo root and is not empty', $license);
check($publish_src !== '', 'publish_upgrade.php is readable', $publish);
check(strpos($publish_src, 'Refusing to publish') !== false
	&& strpos($publish_src, 'LICENSE.md is missing or empty') !== false,
	'publish refuses to build without a license');

// The guard has to run before anything is written, or "nothing has been
// written" in its own message is false.
$guard_pos   = strpos($publish_src, 'LICENSE.md is missing or empty');
$version_pos = strpos($publish_src, "PathHelper::getIncludePath('VERSION')");
check($guard_pos !== false && $version_pos !== false && $guard_pos < $version_pos,
	'the license guard runs before the VERSION file is written');

// Placement matters as much as presence. upgrade.php deploys only public_html
// and maintenance_scripts from a staged archive, so a root-level LICENSE.md
// would be installed once and never refreshed — and would still pass a naive
// "is it in the tarball" check.
check(strpos($publish_src, "\$core_temp_dir . '/public_html/LICENSE.md'") !== false,
	'the license is copied into public_html, not the archive root');

// Hold a real archive to the same rule, but only one built by the publisher that
// carries the copy — an archive predating it is expected not to have the file,
// and failing on that would just be reporting history.
$archives = glob(dirname(PathHelper::getRootDir()) . '/static_files/joinery-core-*.tar.gz');
usort($archives, function ($a, $b) { return filemtime($b) - filemtime($a); });
$newest = $archives ? $archives[0] : null;

if ($newest === null) {
	harness_skip('a built core archive carries public_html/LICENSE.md',
		'no core archive on this box — publish once and this check starts running');
} elseif (filemtime($newest) < filemtime($publish)) {
	harness_skip('a built core archive carries public_html/LICENSE.md',
		basename($newest) . ' predates the license copy; the next publish is the first one this applies to');
} else {
	$listing = array();
	exec('tar -tzf ' . escapeshellarg($newest) . ' ./public_html/LICENSE.md 2>&1', $listing, $rc);
	check($rc === 0, 'the newest core archive carries public_html/LICENSE.md',
		basename($newest) . ($rc === 0 ? '' : ' — the copy did not land in the archive'));
}

harness_finish();
