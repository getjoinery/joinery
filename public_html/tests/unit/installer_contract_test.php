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
$handoff     = $site_root . '/maintenance_scripts/install_tools/linode_stackscript.sh';
$wrapper     = $site_root . '/maintenance_scripts/install_tools/linode_stackscript_wrapper.sh';
$reset_tool  = $site_root . '/maintenance_scripts/sysadmin_tools/reset_admin_password.php';
$bundle_tool = $site_root . '/maintenance_scripts/sysadmin_tools/install_bundle.php';
$license     = $site_root . '/LICENSE.md';
$publish     = PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php');
$quickstart  = PathHelper::getIncludePath('docs/quickstart.md');
$upgrade     = PathHelper::getIncludePath('utils/upgrade.php');

$install_src   = is_file($install_sh) ? file_get_contents($install_sh) : '';
$site_init_src = is_file($site_init) ? file_get_contents($site_init) : '';
$handoff_src   = is_file($handoff) ? file_get_contents($handoff) : '';
$wrapper_src   = is_file($wrapper) ? file_get_contents($wrapper) : '';
$publish_src   = is_file($publish) ? file_get_contents($publish) : '';
$quickstart_md = is_file($quickstart) ? file_get_contents($quickstart) : '';
$upgrade_src   = is_file($upgrade) ? file_get_contents($upgrade) : '';


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

// The backup key is re-pinned for the same reason, but it stops at 640 rather
// than 600. Backups run under more than one account here — the web user on the
// scheduled run, the deploy account from a shell — so an owner-only key locks
// every caller but one out of its own backups.
check(strpos($fix_perms_src, 'backup_site_key') !== false,
	'the permissions sweep re-pins the backup key',
	'the dev-mode 777 would otherwise expose every backup this site has made');
check(strpos($fix_perms_src, 'chmod 640 "$BACKUP_KEY"') !== false,
	'the backup key is left group-readable, not owner-only',
	'600 locks the deploy account out of a backup it is running');

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

section('A published install fetches from the release site, and knows it');

// The default is what every stranger following the published one-liner gets.
// It pointed at dev for as long as the one-liner existed, so the audience the
// docs were written for was handed the working tree of a development box.
check(preg_match('/UPGRADE_SERVER="\$\{UPGRADE_SERVER:-https:\/\/getjoinery\.com\}"/', $install_src) === 1,
    'install.sh defaults UPGRADE_SERVER to the release site');
check(strpos($quickstart_md, 'https://getjoinery.com/utils/latest_release') !== false,
    'the published one-liner fetches from the same place');

// Two unconnected knobs until this landed: --upgrade-server chose where the
// installer fetched from, upgrade_source told the finished site where to fetch
// from ever after, and nothing made them agree. A fresh install could come up
// already ahead of the upstream it believed in.
check(strpos($site_init_src, 'upgrade_source') !== false,
    '_site_init.sh records where this install came from');
check(strpos($site_init_src, 'UPGRADE_SOURCE_VALUE="${UPGRADE_SERVER%/}"') !== false,
    'and records the endpoint actually installed from, not a hardcoded one');
check(strpos($install_src, 'export UPGRADE_SERVER') !== false,
    'install.sh exports it so _site_init.sh can see it');


section('The OS pin is a stop, not a warning');

// PHP 8.3 paths are hardcoded from server setup down, so continuing on another
// release produced a box that looked installed and was not.
check(strpos($install_src, '--allow-unsupported-os') !== false,
    'there is a documented way past the check',
    'a check with no override gets deleted the first time someone needs past it');
check(preg_match('/Unsupported OS.*?exit 1/s', $install_src) === 1,
    'and without it the run stops');
// One check, one place. `site` presupposes `server` ran and nothing persists
// the override, so a second copy would only fire on a box prepared by other
// means and would demand the flag twice.
check(substr_count($install_src, '--allow-unsupported-os) ALLOW_UNSUPPORTED_OS=1') === 1,
    'the guard lives in exactly one subcommand');


section('A deferred certificate finishes on its own');

// The install no longer aborts when DNS is not ready — but nothing on the node
// ever retried either. That logic lived only in the control plane, and this
// path has no control plane.
check(strpos($install_src, 'install_ssl_retry_timer') !== false,
    'a deferred certificate installs a retry timer');
check(strpos($install_src, 'joinery-ssl-retry@.timer') !== false,
    'the timer is templated per domain, so a multi-site box gets one each');

// The DNS lookup before certbot is what makes an indefinite retry safe: Let's
// Encrypt counts five failed validations per hostname per hour, and a failed
// lookup counts for nothing.
$retry_block = '';
if (preg_match('/install_ssl_retry_timer\(\) \{.*?\nRETRY_EOF/s', $install_src, $m)) {
    $retry_block = $m[0];
}
check($retry_block !== '', 'the retry script is findable');
check($retry_block !== '' && strpos($retry_block, 'dig +short') !== false,
    'it resolves the domain before spending a validation attempt');
check($retry_block !== '' && strpos($retry_block, 'have_real_cert') !== false,
    'it disables itself on a CA-issued certificate, not on any file at the cert path',
    'provision_origin_cert falls back to self-signed, so file-exists would end the retries at once');


section('An upgrade proves the code it just installed');

// A publish captures whatever was on the publisher's disk at that moment.
// Nothing between there and a node reads a line of it; this is the first thing
// that does.
check(strpos($upgrade_src, "'/tests/run.php'") !== false
    || strpos($upgrade_src, '/tests/run.php') !== false,
    'upgrade.php runs the test suite after the swap');

// The tier matters more than it looks. `safe` is the development gate and its
// tests are entitled to assert things about a checkout — the full first-party
// plugin set, the components manifest, the maintenance_scripts layout. A
// deployed node has none of that, so pointing safe at one fails eleven suites
// for reasons that say nothing about the release, and rolls back every upgrade
// in the fleet. That happened once, on getjoinery, which is why this is pinned.
check(strpos($upgrade_src, "' deploy 2>&1'") !== false,
    'it runs the deploy tier',
    'safe asserts the shape of a repository and a node is not one');
check(strpos($upgrade_src, "' safe 2>&1'") === false,
    'and not the safe tier');
check(strpos($upgrade_src, "' db 2>&1'") === false,
    'and not db, which is minutes long and writes to a database');

// Whatever the deploy tier holds must be runnable on a node. A test that needs
// a checkout is the exact failure above, reintroduced.
$deploy_dir = PathHelper::getIncludePath('tests/deploy');
$deploy_tests = is_dir($deploy_dir) ? glob($deploy_dir . '/*_test.php') : array();
check(count($deploy_tests) > 0, 'the deploy tier has tests in it', $deploy_dir);
$mistiered = array();
foreach ($deploy_tests as $deploy_test) {
    $head = (string)file_get_contents($deploy_test, false, null, 0, 2048);
    if (!preg_match('/^\s*\*\s*tier:\s*deploy\s*$/m', $head)) {
        $mistiered[] = basename($deploy_test);
    }
}
check(empty($mistiered), 'every test in tests/deploy declares tier: deploy',
    $mistiered ? implode(', ', $mistiered) : '');
check(preg_match('/failed its own tests.*?performRollback/s', $upgrade_src) === 1,
    'a failure rolls back to public_html_last');
check(strpos($upgrade_src, 'The database was NOT rolled back') !== false,
    'and says plainly that the schema did not come back with it',
    'migrations run before the tests, so this is a recovery rather than a clean undo');


section('The platform runs on one clock');

// Every stored time is UTC and display conversion is per user, so a web request
// and a scheduled task on the same box have to agree. They did not: the sed
// touched only the Apache ini, leaving CLI and Docker on UTC and web requests
// on New York.
check(strpos($install_src, 'date.timezone = UTC') !== false,
    'install.sh sets php.ini to UTC');
check(strpos($install_src, 'America\\/New_York') === false,
    'and no longer writes a local zone into php.ini');

// The seeded user's own display timezone is a different question and stays put.
$install_sql = PathHelper::getIncludePath('utils/create_install_sql.php');
$install_sql_src = is_file($install_sql) ? file_get_contents($install_sql) : '';
check(strpos($install_sql_src, 'America/New_York') !== false,
    'the seeded account still displays in America/New_York',
    'platform timezone and a user\'s display timezone are separate; only the first changed');


section('A fresh site installs the product, not the bare platform');

$bundles_path = PathHelper::getIncludePath('install_bundles.json');
check(is_file($bundles_path), 'install_bundles.json exists at the public_html root', $bundles_path);

$bundles = is_file($bundles_path) ? json_decode((string)file_get_contents($bundles_path), true) : null;
check(is_array($bundles), 'and is valid JSON');

$declared = [];
foreach ((array)$bundles as $name => $definition) {
    if (strpos((string)$name, '_') === 0 || !is_array($definition)) {
        continue;
    }
    $declared[$name] = $definition;
}
check(isset($declared['personal']), 'the default bundle is defined');

// A bundle naming a plugin that is not shipped installs a shorter set than the
// deployment promised, and reports success doing it.
$missing = [];
foreach ($declared as $name => $definition) {
    foreach ((array)($definition['plugins'] ?? []) as $plugin) {
        if (!is_dir(PathHelper::getIncludePath('plugins/' . $plugin))) {
            $missing[] = $name . ':' . $plugin;
        }
    }
}
check(empty($missing), 'every plugin named in a bundle exists on disk',
    $missing ? implode(', ', $missing) : '');

check(is_file($bundle_tool), 'the bundle installer exists', $bundle_tool);
$bundle_src = is_file($bundle_tool) ? file_get_contents($bundle_tool) : '';
check(strpos($bundle_src, "PHP_SAPI !== 'cli'") !== false,
    'it refuses to run outside the CLI');
check(strpos($bundle_tool, '/maintenance_scripts/sysadmin_tools/') !== false,
    'and lives outside the web root',
    '/utils/<name> is routable with no router-level permission check');
// Installing nothing and exiting 0 would leave a site missing the product it
// was meant to be, with a status that said everything went fine.
check(strpos($bundle_src, "no bundle named") !== false && strpos($bundle_src, 'exit(1)') !== false,
    'an unknown bundle name is an error, not a silent no-op');
check(strpos($site_init_src, 'install_bundle.php') !== false,
    '_site_init.sh installs the bundle on a fresh site');
check(strpos($site_init_src, 'JOINERY_INSTALL_BUNDLE') !== false,
    'and the bundle name can be supplied by an unattended installer');


section('The admin account is reachable by email from the start');

// The address was hardcoded as admin@example.com everywhere, so a one-click
// owner ended up with the only account on the site pointing at a mailbox
// nobody can receive at — and password reset is the way back in.
$reset_src_full = is_file($reset_tool) ? file_get_contents($reset_tool) : '';
check(strpos($reset_src_full, '--set-email') !== false,
    'the reset tool can change the address');
check(preg_match('/set\(\'usr_email\', \$new_email\).*?\$user->save\(\)/s', $reset_src_full) === 1,
    'in the same save as the password',
    'otherwise there is a window where a fresh credential sits on an unreachable address');
check(strpos($site_init_src, 'JOINERY_ADMIN_EMAIL') !== false,
    '_site_init.sh honours an installer-supplied address');
check(strpos($install_src, '--admin-email=') !== false,
    'install.sh site takes it as a flag');


section('An unconfigured mailer says so');

// email_service defaulted to smtp, so a site that had never been configured
// looked configured and simply could not deliver. Worse, EmailSender fell back
// to mailgun in five places, so an empty setting silently meant Mailgun — and a
// failed send reported a credential error for a provider nobody chose.
$sender_src = file_get_contents(PathHelper::getIncludePath('includes/EmailSender.php'));
check(strpos($sender_src, "?: 'mailgun'") === false,
    'EmailSender never substitutes a provider nobody selected');
check(strpos($sender_src, 'activeServiceKey') !== false,
    'the configured provider is read through one place that can answer "none"');

$settings_json = json_decode((string)file_get_contents(PathHelper::getIncludePath('settings.json')), true);
$email_default = null;
foreach ((array)($settings_json['settings'] ?? $settings_json) as $declaration) {
    if (is_array($declaration) && ($declaration['name'] ?? '') === 'email_service') {
        $email_default = $declaration['default'];
    }
}
check($email_default === '', 'email_service declares no default',
    'a preselected provider with no credentials is configured-but-useless, and denies an honest unconfigured state');

// Unconditional, in both places an installer's output is read. A detection rule
// that guesses wrong is worse than one extra line for an admin who is already
// set up.
check(strpos($install_src, 'admin_settings_email') !== false,
    'the install summary points at email setup');
check(strpos($site_init_src, 'admin_settings_email') !== false,
    'so does the credentials file');


section('The Linode path delegates and keeps its secrets');

check(is_file($handoff), 'the handoff script ships in the archive', $handoff);
check(is_file($wrapper), 'the pasted StackScript body is kept in the repo', $wrapper);

// A wrapper that contains logic means every fix to that logic waits on a
// Marketplace review. A wrapper that contains a handoff never needs touching
// unless the field set changes.
check(strpos($wrapper_src, 'linode_stackscript.sh') !== false,
    'the wrapper hands off to the archive rather than doing the work');
check(strpos($wrapper_src, 'getjoinery.com/utils/latest_release') !== false,
    'and fetches from the release site');
check(strpos($wrapper_src, 'version=') === false,
    'with no pinned version',
    'a pin needs a bump every publish, and a stale pin is worse than none');

// A field is masked in the Linode UI, and kept out of the deployment log, only
// if its name contains "password".
check(preg_match('/UDF name="JOINERY_ADMIN_PASSWORD"/', $wrapper_src) === 1,
    'the admin password field is named so Linode masks it');
check(preg_match('/UDF name="JOINERY_LINODE_TOKEN_PASSWORD"/', $wrapper_src) === 1,
    'and so is the API token');

// The deployment log is readable by the deployer and outlives the install.
check(!preg_match('/echo[^\n]*\$\{?JOINERY_ADMIN_PASSWORD/', $handoff_src),
    'the handoff script never echoes the password');
check(!preg_match('/echo[^\n]*\$\{?(JOINERY_)?LINODE_TOKEN/', $handoff_src),
    'and never echoes the API token');
check(strpos($handoff_src, 'export JOINERY_ADMIN_PASSWORD') !== false
    && !preg_match('/install\.sh[^\n]*\$ADMIN_PASSWORD/', $handoff_src),
    'the password reaches install.sh through the environment, not an argument',
    'arguments are visible in ps to every user on the box');

// install.sh already hard-fails on the wrong OS a few lines into the handoff, so
// a second check here would just be a second place to update.
check(strpos($handoff_src, '/etc/os-release') === false,
    'the handoff script carries no OS check of its own');
check(strpos($handoff_src, '--allow-unsupported-os') === false,
    'and never passes the override');


section('A site rebuild cannot move the site backward');

// A site's PHP code lives in the container's writable layer, so removing and
// rebuilding the container replaces the running code with the archive's. The
// guard refuses unless it can prove the archive is not older. It is text-only
// here for the same reason as everything above: install.sh cannot be run.
check(strpos($install_src, 'assert_rebuild_moves_code_forward()') !== false,
    'the rebuild guard exists');
check(substr_count($install_src, 'assert_rebuild_moves_code_forward') >= 2,
    'and do_site_docker calls it');

// Ordering is the whole safety property. do_site_docker stops the container
// early as a port-check preflight; a guard after that point would leave a
// refused site sitting down.
$guard_call = strpos($install_src, "\n    assert_rebuild_moves_code_forward \"\$SITENAME\"");
$preflight  = strpos($install_src, 'Existing container \'${SITENAME}\' is running');
check($guard_call !== false, 'the call site is findable');
check($preflight !== false, 'the preflight stop is findable');
check($guard_call !== false && $preflight !== false && $guard_call < $preflight,
    'the guard runs before anything stops the container',
    'a refusal must cost the running site nothing');

// Isolate the function body so an unrelated match elsewhere cannot stand in
// for a rule that was dropped.
$guard_body = '';
if (preg_match('/assert_rebuild_moves_code_forward\(\) \{.*?\n\}\n/s', $install_src, $m)) {
    $guard_body = $m[0];
}
check($guard_body !== '', 'the guard body is findable');
check(strpos($guard_body, 'sort -V') !== false,
    'versions compare with sort -V',
    'string comparison puts 0.8.221 below 0.8.24, which is the pair in the field');
check(strpos($guard_body, 'exit 1') !== false,
    'the guard refuses rather than warning');
check(preg_match('/WIPE_DATA["\']? -eq 1/', $guard_body) === 1,
    '--wipe-data skips the check',
    'a wipe deletes the database that made the running code load-bearing');
check(preg_match('/ALLOW_DOWNGRADE["\']? -eq 1/', $guard_body) === 1,
    '--allow-downgrade is honoured inside the guard');
check(strpos($install_src, '--allow-downgrade)') !== false,
    'and --allow-downgrade is a parsed flag');
check(substr_count($install_src, 'ALLOW_DOWNGRADE=1') === 1,
    'it is the only bypass',
    'a second thing setting it would be a silent override path');

// An archive that cannot say what it is, is an older archive with less
// information — the April tree that started this had no VERSION at all.
check(strpos($guard_body, 'VERDICT="unknown"') !== false,
    'an unreadable version is a verdict of its own, not a pass');

// docker exec cannot read a container a previous failed run left stopped.
check(strpos($guard_body, 'docker cp') !== false,
    'the running version is read in a way that works on a stopped container');

// The operator hitting this needs the next action, not a diagnosis.
check(strpos($guard_body, 'Publish a current release') !== false,
    'the refusal says how to fix it');


section('Site code survives the container it runs in');

$dockerfile     = $site_root . '/maintenance_scripts/install_tools/Dockerfile.template';
$dockerfile_src = is_file($dockerfile) ? file_get_contents($dockerfile) : '';
check($dockerfile_src !== '', 'Dockerfile.template is readable', $dockerfile);

// Everything utils/upgrade.php writes has to be on a volume, or a rebuild
// discards it. These three cover public_html, vendor and maintenance_scripts.
// Two occurrences each: install.sh has a quiet and a verbose docker run.
foreach (['code' => 'public_html', 'vendor' => 'vendor', 'scripts' => 'maintenance_scripts'] as $vol => $path) {
    check(substr_count($install_src,
        '-v "${SITENAME}_' . $vol . '":/var/www/html/"${SITENAME}"/' . $path) === 2,
        "the {$vol} volume is mounted at {$path} in both docker run blocks");
}

// Mounting one volume inside another works but is hard to reason about, and
// the data volumes are all siblings of public_html already. Nothing should
// claim the site root itself.
check(preg_match('/-v "\$\{SITENAME\}_[a-z_]+":\/var\/www\/html\/"\$\{SITENAME\}" /', $install_src) === 0,
    'no volume is mounted at the site root, so none nests inside another');

// A wipe that leaves the code volume behind would reinstall onto old code.
check(strpos($install_src, 'ALL_SITE_VOLUMES=(') !== false,
    'the set of volumes a wipe deletes is written down once');

// Docker fills an empty volume from the image and ignores the image once the
// volume has content. That is what makes a stale image inert, so the COPY has
// to stay: without it a new site has nothing to seed from.
check(strpos($dockerfile_src, 'COPY ${SITENAME}/ /var/www/html/${SITENAME}/') !== false,
    'the image still carries a release to seed a new volume from');

// An empty code volume must stop the container, not serve an empty site.
check(strpos($dockerfile_src, 'FATAL: no site code at') !== false,
    'the container refuses to start with no code on the volume');

// Once code is on a volume a rebuild cannot touch it, so the 2.29 guard has
// nothing to protect and would only refuse safe rebuilds.
check(strpos($install_src, 'code_volume_is_populated()') !== false,
    'the guard can tell whether a site is on a code volume');
check(isset($guard_body) && strpos($guard_body, 'code_volume_is_populated') !== false,
    'and skips the version check when it is');

// Fail closed: an unreadable or missing volume must send the caller to the
// version comparison, never past it.
$vol_body = '';
if (preg_match('/code_volume_is_populated\(\) \{.*?\n\}\n/s', $install_src, $m)) {
    $vol_body = $m[0];
}
check($vol_body !== '', 'the volume probe body is findable');
check(strpos($vol_body, '|| return 1') !== false,
    'a missing volume answers no rather than erroring');
check(strpos($vol_body, '/VERSION') !== false,
    'an existing but unseeded volume answers no too',
    'a volume created by a half-finished migration is not a populated one');


section('Only --wipe-data deletes a site\'s data');

// A rebuild removes the container; that is routine and reversible. Deleting the
// volumes takes the database, the uploads, the config that holds secret_box_key
// and the backups, and nothing brings those back. Saying yes to a rebuild prompt
// is not consent to that, so the flag is the only thing that authorises it.
// Left ungated this also cancelled the guard above: it prints "code is on a
// volume - a rebuild cannot replace it" and then the removal deletes the volume.

// The wipe list has to cover every volume the site actually mounts, or a wipe
// silently leaves state behind for the next install to inherit. Derived from
// the docker run blocks rather than restated, so a new volume fails here.
$declared_wipe = [];
if (preg_match('/ALL_SITE_VOLUMES=\((.*?)\n\)/s', $install_src, $m)) {
    $declared_wipe = preg_split('/\s+/', trim(preg_replace('/#.*/', '', $m[1])), -1, PREG_SPLIT_NO_EMPTY);
}
check($declared_wipe !== [], 'the wipe list is findable');

preg_match_all('/-v "\$\{SITENAME\}_([a-z_]+)"/', $install_src, $mounted);
$mounted_vols = array_values(array_unique($mounted[1]));
$unwiped = array_diff($mounted_vols, $declared_wipe);
check($unwiped === [], 'every volume the site mounts is in the wipe list',
    $unwiped ? 'missing from ALL_SITE_VOLUMES: ' . implode(', ', $unwiped) : '');

// One list, one deletion helper — the two removal paths previously spelled the
// volumes out separately, which is how the two copies drift apart.
check(substr_count($install_src, 'remove_site_volumes "$SITENAME"') === 2,
    'both removal paths go through the one helper');
check(preg_match('/for vol in code vendor scripts postgres/', $install_src) === 0,
    'and neither still carries its own copy of the list');

// The container-exists block is where the decision is made. Every deletion in
// it must sit under a WIPE_DATA test — one for the -y path, one for the prompt.
$exists_block = '';
if (preg_match('/Checking for existing container.*?No existing container found/s', $install_src, $m)) {
    $exists_block = $m[0];
}
check($exists_block !== '', 'the container-exists block is findable');
check(substr_count($exists_block, 'remove_site_volumes') === 2,
    'volumes are deleted in exactly two places');
check(substr_count($exists_block, '"$WIPE_DATA" -eq 1') === 2,
    'and each is behind its own --wipe-data test');

// The prompt an operator reads has to match what the run will actually do.
check(strpos($exists_block, 'Data volumes are kept') !== false,
    'the rebuild prompt says the data survives');
check(strpos($exists_block, 'Remove the container AND every data volume?') !== false,
    'the wipe prompt says it does not');


section('A bare-metal install cannot move a site backward either');

// The same defect as the container one, on the deployment type with no volume
// to hide behind. deploy_application_code rsyncs the archive onto the live tree
// with no --delete, so an older archive does not replace it, it merges into it:
// files in both roll back, files only the newer release shipped stay, and
// VERSION names the older one. A tree no release ever shipped, misreporting
// itself, against a database that migrated forward. With -y there is not even a
// prompt — and -y is what Server Manager always passes.
check(substr_count($install_src, 'assert_rebuild_moves_code_forward "$SITENAME" "$ARCHIVE_ROOT" baremetal') === 1,
    'the bare-metal path calls the guard');

// Before the overwrite prompt, not after: there is no point asking a question
// the next check overrides, and a refusal at that point has touched nothing.
$bm_guard = strpos($install_src, '"$ARCHIVE_ROOT" baremetal');
$bm_prompt = strpos($install_src, 'Site $SITENAME already exists. Overwrite?');
$bm_deploy = strpos($install_src, 'deploy_application_code "$SITENAME"');
check($bm_guard !== false && $bm_prompt !== false && $bm_guard < $bm_prompt,
    'it runs before the overwrite prompt');
check($bm_guard !== false && $bm_deploy !== false && $bm_guard < $bm_deploy,
    'and before any code is copied over the live tree');

// Executed, not asserted. The version comparison is the part that has to be
// right, and text matching cannot tell sort -V from string comparison — which
// is the difference between catching 0.8.221 -> 0.8.24 and shipping it.
$gr = sys_get_temp_dir() . '/joinery_guard_' . getmypid();
$harness = $gr . '/run.sh';
@mkdir($gr, 0700, true);
file_put_contents($harness, <<<'SH'
#!/usr/bin/env bash
SRC="$1"; ROOT="$2"; SITE="$3"; ARCH="$4"; WIPE_DATA="${5:-0}"; ALLOW_DOWNGRADE="${6:-0}"
print_step() { :; }; print_info() { :; }; print_success() { :; }
print_warning() { :; }; print_error() { :; }
code_volume_is_populated() { return 1; }
eval "$(awk '/^assert_rebuild_moves_code_forward\(\) \{/,/^}$/' "$SRC" \
        | sed "s#/var/www/html/#${ROOT}/#g")"
assert_rebuild_moves_code_forward "$SITE" "$ARCH" baremetal
echo PROCEED
SH
);

$mk_site = function ($name, $version) use ($gr) {
    @mkdir("$gr/$name/public_html", 0700, true);
    @mkdir("$gr/$name/config", 0700, true);
    touch("$gr/$name/config/Globalvars_site.php");
    if ($version !== null) file_put_contents("$gr/$name/public_html/VERSION", $version . "\n");
};
$mk_arch = function ($name, $version) use ($gr) {
    @mkdir("$gr/$name/public_html", 0700, true);
    if ($version !== null) file_put_contents("$gr/$name/public_html/VERSION", $version . "\n");
};

$mk_site('live', '0.8.221');
$mk_site('live9', '0.9.0');
$mk_site('noversion', null);
@mkdir("$gr/nocfg/public_html", 0700, true);          // code but never initialised
$mk_arch('a_newer', '0.8.222');
$mk_arch('a_same',  '0.8.221');
$mk_arch('a_older', '0.8.24');
$mk_arch('a_ten',   '0.10.0');
$mk_arch('a_none',  null);

$guard_run = function ($site, $arch, $wipe = 0, $allow = 0) use ($harness, $install_sh, $gr) {
    $cmd = 'bash ' . escapeshellarg($harness) . ' ' . escapeshellarg($install_sh) . ' '
         . escapeshellarg($gr) . ' ' . escapeshellarg($site) . ' '
         . escapeshellarg($gr . '/' . $arch) . " {$wipe} {$allow} 2>&1";
    return strpos((string)shell_exec($cmd), 'PROCEED') !== false;
};

// Nothing installed yet is not a downgrade — a fresh install must not be blocked.
check($guard_run('absent', 'a_newer') === true, 'an absent site installs normally');
check($guard_run('nocfg', 'a_newer') === true,
    'a directory that was never initialised installs normally');

check($guard_run('live', 'a_newer') === true, '0.8.221 to 0.8.222 proceeds');
check($guard_run('live', 'a_same') === true, 'the same version proceeds');

// The pair string comparison gets backwards, and the pair that was in the field.
check($guard_run('live', 'a_older') === false, '0.8.221 to 0.8.24 is refused');
check($guard_run('live9', 'a_ten') === true, '0.9.0 to 0.10.0 proceeds',
    'the other ordering string comparison inverts');

// Unreadable is the same signal as older, with less information.
check($guard_run('live', 'a_none') === false, 'an archive with no VERSION is refused');
check($guard_run('noversion', 'a_newer') === false, 'a site with no VERSION is refused');

check($guard_run('live', 'a_older', 0, 1) === true, '--allow-downgrade overrides it');

// --wipe-data means "delete the volumes" and there are none here, so it must not
// read as consent to overwrite a bare-metal site with older code.
check($guard_run('live', 'a_older', 1, 0) === false,
    '--wipe-data is not a bare-metal bypass');

exec('rm -rf ' . escapeshellarg($gr));


section('A site image carries no live configuration');

// A release archive's config/ holds one file: the template. A live site's holds
// Globalvars_site.php — database password, secret_box_key — and, on a control
// plane, the agent signing key, provisioning and relay keys, and the DNS token.
// Copying the directory wholesale put whichever of those existed into an image
// layer. It also broke the install: a Globalvars_site.php in the image makes the
// container skip _site_init.sh, so the site never gets a database.
check(strpos($install_src, 'cp -r "$ARCHIVE_ROOT/config"/*') === false,
    'the config directory is not copied wholesale into the build context');
check(strpos($install_src, 'cp "$ARCHIVE_ROOT/config/default_Globalvars_site.php"') !== false,
    'only the template is copied');

// Second line of defence, and the one that also covers a build directory left
// over from an earlier run. Denylisting would mean guessing secret filenames.
$ignore = '';
if (preg_match('/cat > "\$BUILD_DIR\/\.dockerignore".*?\nEOF/s', $install_src, $m)) {
    $ignore = $m[0];
}
check($ignore !== '', 'the dockerignore block is findable');
$excl_at = strpos($ignore, '*/config/*');
$negate_at = strpos($ignore, '!*/config/default_Globalvars_site.php');
check($excl_at !== false, 'dockerignore excludes config/');
check($negate_at !== false, 'and re-admits the template');
check($excl_at !== false && $negate_at !== false && $excl_at < $negate_at,
    'in that order, since the last matching dockerignore rule wins');

// The warning names what it skipped. Executed against a fixture shaped like a
// live control-plane config, because a find predicate that quietly misses a file
// is a file that ships.
$fixture = sys_get_temp_dir() . '/joinery_cfgfix_' . getmypid();
@mkdir($fixture, 0700, true);
$live_files = ['Globalvars_site.php', 'agent_signing_key', 'backup_site_key',
               'cloudflare_dns_token', 'provisioning_key', 'relay_pull_key.pub'];
foreach (array_merge($live_files, ['default_Globalvars_site.php']) as $f) {
    file_put_contents($fixture . '/' . $f, 'x');
}

$find_cmd = '';
if (preg_match('/find "\$ARCHIVE_ROOT\/config".*?-printf \'%f \' 2>\/dev\/null/s', $install_src, $m)) {
    $find_cmd = str_replace('"$ARCHIVE_ROOT/config"', escapeshellarg($fixture), $m[0]);
    $find_cmd = preg_replace('/\s*\\\\\n\s*/', ' ', $find_cmd);
}
check($find_cmd !== '', 'the skipped-file scan is findable');

$skipped = $find_cmd === '' ? '' : shell_exec($find_cmd);
$skipped_list = preg_split('/\s+/', trim((string)$skipped), -1, PREG_SPLIT_NO_EMPTY);
sort($skipped_list);
$expect = $live_files; sort($expect);
check($skipped_list === $expect, 'every live config file is named as skipped',
    'got: ' . implode(', ', $skipped_list));
check(!in_array('default_Globalvars_site.php', $skipped_list, true),
    'and the template is not, because it is the one file that travels');

array_map('unlink', glob($fixture . '/*'));
@rmdir($fixture);


section('A reinstall never rotates a live site\'s encryption key');

// create_config_file mints a fresh secret_box_key. Run over a site that already
// has one, it leaves the database untouched and orphans everything encrypted at
// rest in it — sealed vault wrappings, stored credentials, DKIM keys. The site
// comes up looking fine; the failure surfaces whenever something decrypts.
$init_src = is_file($site_init) ? file_get_contents($site_init) : '';
check($init_src !== '', '_site_init.sh is readable', $site_init);

$mk_config = '';
if (preg_match('/create_config_file\(\) \{.*?\n\}/s', $init_src, $m)) {
    $mk_config = $m[0];
}
check($mk_config !== '', 'the config writer is findable');

$guard_at = strpos($mk_config, 'Globalvars_site.php" ]; then');
$keygen_at = strpos($mk_config, 'openssl rand -base64 32');
check($guard_at !== false, 'it checks whether a config is already there');
check($keygen_at !== false, 'and it is still the thing that generates the key');
check($guard_at !== false && $keygen_at !== false && $guard_at < $keygen_at,
    'the check comes first, so an existing key is never regenerated');
check(preg_match('/Globalvars_site\.php" \]; then.*?\n\s*return 0/s', $mk_config) === 1,
    'and an existing config returns rather than falling through');

// The guard belongs inside the writer, not at one call site: clone mode calls
// it from a second place after the clone completes.
check(substr_count($init_src, 'create_config_file') >= 3,
    'both call sites are covered because the guard is in the function');


section('A rebuilt container gets its declared extensions back');

// These are apt packages that utils/upgrade.php installs into the writable
// layer, so `docker rm` takes them with it. Without them composer validation
// fails, update_database never runs, and the site serves an unmigrated schema
// with nothing in the log to say why. It has to be re-asserted at every start,
// not only at install, because a rebuild is exactly when they go missing.
$deps_sh = $site_root . '/maintenance_scripts/install_tools/_install_declared_dependencies.sh';
$deps_src = is_file($deps_sh) ? file_get_contents($deps_sh) : '';
check($deps_src !== '', 'the dependency installer exists', $deps_sh);

$cmd_call = strpos($dockerfile_src, '_install_declared_dependencies.sh');
$cmd_updb = strpos($dockerfile_src, 'utils/update_database.php');
check($cmd_call !== false, 'the container start runs it');
check($cmd_call !== false && $cmd_updb !== false && $cmd_call < $cmd_updb,
    'before update_database, which is what depends on the extensions');

// One implementation. install.sh had its own copy, which is how the bare-metal
// path stayed correct while the container path had no equivalent at all.
check(strpos($install_src, '_install_declared_dependencies.sh') !== false,
    'install.sh runs the same script rather than its own copy');
check(preg_match('/specs=\$\(php "\$resolver" --apt/', $install_src) === 0,
    'and no longer carries a second implementation');

// Never fatal: a site that cannot reach apt, or is handed nothing, must still
// start. Executed, because "exits 0" is a claim worth running.
foreach ([
    ''                        => 'no argument',
    '/nonexistent-public-html' => 'a path that is not there',
] as $arg => $label) {
    exec('bash ' . escapeshellarg($deps_sh) . ' ' . escapeshellarg($arg) . ' 2>&1', $o, $rc);
    check($rc === 0, "given {$label} it exits 0 rather than stopping the container",
        'exit ' . $rc . ': ' . implode(' / ', $o));
}

// Missing packages are computed before apt is touched, so the common case (all
// present) costs no network — this runs on every container start.
$update_at = strpos($deps_src, "\napt-get update");   // the call, not the comment about it
$missing_at = strpos($deps_src, 'if [ -z "$MISSING" ]');
check($missing_at !== false && $update_at !== false && $missing_at < $update_at,
    'nothing missing means apt is never called');


section('The installer asks for no extension the distro cannot supply');

// ext/imap left the PHP distribution in 8.4, so from 8.5 on there is no
// php{version}-imap to install. Naming it in the apt list does not degrade
// gracefully: apt refuses the whole batch, so every extension listed beside it
// goes missing too and the install stops there. Matched at the start of a line
// so the version history above can keep naming the package it dropped.
check(preg_match('/^\s*php[0-9.]*-imap\b/m', $install_src) === 0,
    'install.sh does not apt-install php-imap');

// The check above is only right while nothing needs the extension. If a future
// require adds it, the declared-dependency resolver installs it on its own and
// this section becomes the thing that is wrong.
$declares_imap = strpos(file_get_contents($site_root . '/public_html/composer.json'), 'ext-imap') !== false;
foreach (glob($site_root . '/public_html/plugins/*/plugin.json') ?: array() as $manifest) {
    if (strpos(file_get_contents($manifest), '"imap"') !== false) { $declares_imap = true; }
}
check(!$declares_imap, 'and nothing in the tree declares it as a dependency');

// The one caller reads a delivered message back over IMAP. It has to say so
// before Step 1: the steps between send a real email and sleep out the delivery
// wait, and a run that cannot finish should spend neither.
$auth_src = file_get_contents($site_root . '/public_html/tests/email/auth_analysis.php');
// The brace form is the run-block guard; the form page carries an alternate-
// syntax one that would otherwise match first and prove nothing about the run.
$guard_at = strpos($auth_src, "if (!function_exists('imap_open')) {");
$send_at  = strpos($auth_src, 'Step 1:</strong> Sending test email');
check($guard_at !== false, 'the email auth tool checks for the extension');
check($guard_at !== false && $send_at !== false && $guard_at < $send_at,
    'and checks before it sends anything');
check(strpos($auth_src, 'Please install php-imap') === false,
    'without telling the operator to install a package that no longer exists');


section('Runtime paths name no PHP or PostgreSQL version');

// A pinned version in a path is drift with a delay on it: correct until the
// host moves, then wrong everywhere at once. These all run on every deployed
// node, so each is checked for the literal rather than for the fix — a future
// edit that reintroduces one fails here.

check($upgrade_src !== '', 'utils/upgrade.php is readable', $upgrade);

// The original bug: `service php8.3-fpm reload 2>/dev/null` on a host running
// anything else. stderr discarded, exit code unread, so the reload silently did
// not happen and opcache kept serving pre-upgrade code under a green upgrade.
check(preg_match('/php\d+\.\d+-fpm/', $upgrade_src) === 0,
    'upgrade.php names no PHP version when reloading FPM');
check(strpos($upgrade_src, 'function upgrade_find_fpm_service') !== false,
    'it resolves the service against what is installed instead');

// Resolving the name is only half of it. The failure mode was silence, so the
// reload has to be checked, and a host with no FPM at all has to be told apart
// from one where the reload failed.
if (preg_match('/\$fpm_service = upgrade_find_fpm_service\(\);.*?\n\t{3}\}\n/s', $upgrade_src, $m)) {
    $reload_body = $m[0];
} else {
    $reload_body = '';
}
check($reload_body !== '', 'the reload block is findable');
check(strpos($reload_body, '$fpm_return') !== false
    && strpos($reload_body, "if (\$fpm_return !== 0)") !== false,
    'the reload exit code is read rather than discarded');
check(strpos($reload_body, "out_alert('warning'") !== false,
    'and a failed reload warns instead of passing silently');
check(strpos($reload_body, "\$fpm_service === ''") !== false,
    'a host with no FPM is distinguished from a reload that failed');

// Both Dockerfile sites sit in the start command's && chain, so a path that
// does not resolve stops the container before Apache — the container never
// came up at all, with no message saying which path was wrong.
// Comment lines are excluded: the version log at the top records what each
// revision changed and naming the old literal there is the point of it.
$dockerfile_exec = implode("\n", array_filter(
    explode("\n", $dockerfile_src),
    function ($line) { return strpos(ltrim($line), '#') !== 0; }
));
check(preg_match('#/etc/postgresql/\d+/#', $dockerfile_exec) === 0,
    'Dockerfile.template names no PostgreSQL version in a config path');
check(preg_match('/php\d+\.\d+-fpm/', $dockerfile_exec) === 0,
    'and no PHP version in the fpm service it starts');
foreach ([
    'FATAL: no pg_hba.conf under /etc/postgresql' => 'an unresolvable pg_hba.conf',
    'FATAL: no php-fpm init script under /etc/init.d' => 'a missing fpm service',
] as $needle => $label) {
    check(strpos($dockerfile_src, $needle) !== false,
        "{$label} stops the container with a message naming it");
}

// prepare and swap run the same command, so a glob matching nothing on both
// sides makes the swap report that no packages were lost while every PHP
// extension the site declared is in fact gone.
$migrate_sh  = $site_root . '/maintenance_scripts/sysadmin_tools/migrate_site_to_code_volumes.sh';
$migrate_src = is_file($migrate_sh) ? file_get_contents($migrate_sh) : '';
check($migrate_src !== '', 'migrate_site_to_code_volumes.sh is readable', $migrate_sh);
check(preg_match("/dpkg -l 'php\d+\.\d+-/", $migrate_src) === 0,
    'the installed-package probe is not pinned to one PHP version');

// The server setup is the largest of these: package names, the Apache module
// and conf, the fpm service, and the ini path all named one version. Comment
// lines are excluded for the same reason as the Dockerfile — the version log
// has to be able to say which version it stopped naming.
$install_exec = implode("\n", array_filter(
    explode("\n", $install_src),
    function ($line) { return strpos(ltrim($line), '#') !== 0; }
));
check(preg_match('/php\d+\.\d+/', $install_exec) === 0,
    'install.sh names no PHP version in a package, service, conf or path');
check(preg_match('#/etc/php/\d+\.\d+/#', $install_exec) === 0,
    'and no PHP version in an ini path');
check(strpos($install_src, 'detect_php_version()') !== false,
    'it detects one version and derives the rest from it');

// Both stops exist because both failures are silent. An empty version builds
// /etc/php//fpm/php.ini, which sed will not match and will not complain about;
// a version that installed but laid its ini elsewhere does the same. Either way
// the tuning is skipped and the setup reports success.
check(strpos($install_exec, 'Could not determine which PHP version to install') !== false
    && preg_match('/Could not determine which PHP version to install.*?exit 1/s', $install_exec) === 1,
    'an undetectable version stops the run rather than configuring an empty path');
check(preg_match('/Expected PHP configuration at.*?exit 1/s', $install_exec) === 1,
    'and so does an fpm ini that is not where the tuning writes');

// The gate is the reason the parameterization had to come first: widening it
// while paths were pinned would have admitted a release the script could not
// configure.
check(preg_match('/grep -qE "Ubuntu \(24\|26\)\\\\\.04"/', $install_src) === 1,
    'the OS gate admits both tested releases');

// The mail host provisioner names its own package. It runs on a box that
// already has PHP, so it reads the version off the interpreter it resolved.
$email_sh  = $site_root . '/public_html/plugins/mailbox/provisioning/install_email.sh';
$email_src = is_file($email_sh) ? file_get_contents($email_sh) : '';
check($email_src !== '', 'install_email.sh is readable', $email_sh);
$email_exec = implode("\n", array_filter(
    explode("\n", $email_src),
    function ($line) { return strpos(ltrim($line), '#') !== 0; }
));
check(preg_match('/php\d+\.\d+-/', $email_exec) === 0,
    'install_email.sh names no PHP version in its package list');
check(strpos($email_exec, '${PHP_BIN}" -r') !== false,
    'it reads the version from the interpreter it already resolved');


section('Nothing is left granting trust on the postgres socket');

// Setting the postgres password needs trust on the local socket for the length
// of one ALTER USER, so both installers flip it and flip it back. The flip back
// is the whole risk: sed exits 0 when it matches nothing, so a pattern that no
// longer fits the file leaves trust in place — superuser for anyone with a
// shell — and every later step still reports success.
// Twice: the flip to trust and the flip back. Either one written as a literal
// line is a silent no-op the moment the generated spacing changes.
check(substr_count($install_exec, 'sed -i -E') >= 2
    && substr_count($install_exec, 'local[[:space:]]+all[[:space:]]+postgres[[:space:]]+') >= 3,
    'install.sh matches the postgres rule by field, not as a whitespace-exact line');
check(preg_match('/grep -qE .\^local\[\[:space:\]\]\+all\[\[:space:\]\]\+postgres\[\[:space:\]\]\+trust/', $install_exec) === 1,
    'and confirms the restore took rather than assuming it');
check(preg_match('/Could not restore authenticated access.*?exit 1/s', $install_exec) === 1,
    'a restore that did not take stops the install');

// One variable feeds the generated rules and the restore, so they cannot name
// different methods. md5 is what that variable replaced: password_encryption
// has defaulted to scram-sha-256 since PG 14 and an md5 line accepts a SCRAM
// verifier, so the word was doing nothing — and PG 18 deprecates it.
check(strpos($install_exec, 'PG_AUTH_METHOD="scram-sha-256"') !== false,
    'the auth method is declared once');
check(preg_match('/^\s*(local|host)\s+\S+\s+\S+.*\bmd5\s*$/m', $install_exec) === 0,
    'and no generated pg_hba rule names md5');

// The container repeats the dance at first run against a file install.sh wrote,
// so it reads the method out of that file instead of carrying its own copy of
// the answer. Two scripts that each name a method can disagree; one that asks
// cannot.
check(strpos($dockerfile_exec, 'PG_LOCAL_METHOD=') !== false
    && strpos($dockerfile_exec, '$3=="postgres"') !== false,
    'the container reads the method out of pg_hba rather than naming one');
check(preg_match('/local postgres left on trust.*?exit 1/s', $dockerfile_exec) === 1,
    'and a container whose restore did not take stops instead of running that way');
check(preg_match('/no method to restore.*?exit 1/s', $dockerfile_exec) === 1,
    'as does one with no method to put back');


section('A connection attempt can be attributed to a source');

// PostgreSQL logs failed logins and not successful ones, and the packaged
// log_line_prefix carries no client address. A box found under attack could
// therefore say how many attempts it refused but not whether any had worked —
// which is the only question worth asking. Both halves are set at install.
check(strpos($install_exec, 'log_connections = on') !== false,
    'install.sh turns on connection logging');
check(strpos($install_exec, '%h') !== false
    && preg_match("/log_line_prefix = '[^']*%h/", $install_exec) === 1,
    'and puts the client address in every session line');

// A drop-in, not a sed. log_line_prefix ships uncommented and already set, so a
// sed written against the commented form matches nothing and reports success —
// the same silent no-op this file guards against everywhere else.
check(strpos($install_exec, 'conf.d/10-joinery-logging.conf') !== false,
    'written as a conf.d drop-in rather than an edit of postgresql.conf');
check(preg_match('/sed -i.*log_line_prefix/', $install_exec) === 0,
    'and never by seding a line the package may already have set');

// A drop-in in a directory nothing includes is worse than no drop-in: it reads
// as configured. Ubuntu ships the include, so this only fires on a distro that
// does not — but it fires rather than silently doing nothing.
check(strpos($install_exec, "include_dir = 'conf.d'") !== false
    && preg_match('/if ! grep -qE.*include_dir/', $install_exec) === 1,
    'and the include is added when the packaged config lacks it');


harness_finish();
