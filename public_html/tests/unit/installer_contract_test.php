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
check(substr_count($install_src, 'for vol in code vendor scripts postgres') === 2,
    '--wipe-data removes the code volumes too');

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


harness_finish();
