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
 * These are almost all text assertions over scripts rather than executions of
 * them. install.sh provisions a server; running it in a test suite is not an
 * option, and --help is the sole exception, being the one subcommand whose
 * whole job is to print and exit. That limits what can be claimed: a check
 * passing means the guard is still written down, not that a live install
 * behaves. The three-branch SSH behavior is verified by hand on a real box —
 * see the spec's Testing section.
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
// A development tree keeps LICENSE.md beside public_html; an installed site
// keeps it inside public_html, because that is where publish_upgrade.php copies
// it into the archive. Look in both, so this reads the same on either layout.
$license     = $site_root . '/LICENSE.md';
if (!is_file($license)) {
	$license = PathHelper::getRootDir() . '/LICENSE.md';
}
$publish     = PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php');
$quickstart  = PathHelper::getIncludePath('docs/quickstart.md');
$upgrade     = PathHelper::getIncludePath('utils/upgrade.php');

$install_src   = is_file($install_sh) ? file_get_contents($install_sh) : '';
$site_init_src = is_file($site_init) ? file_get_contents($site_init) : '';
$handoff_src   = is_file($handoff) ? file_get_contents($handoff) : '';
$wrapper_src   = is_file($wrapper) ? file_get_contents($wrapper) : '';
$publish_src   = is_file($publish) ? file_get_contents($publish) : '';
$quickstart_md = is_file($quickstart) ? file_get_contents($quickstart) : '';
$installation  = PathHelper::getIncludePath('docs/installation.md');
$installation_md = is_file($installation) ? file_get_contents($installation) : '';
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
// The one-liner is published in the installation reference; quickstart is the
// StackScript path and points command-line installs there.
check(strpos($installation_md, 'https://getjoinery.com/utils/latest_release') !== false,
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
// ever retried either. That logic lived only in the management node, and this
// path has no management node.
check(strpos($install_src, 'install_ssl_retry_timer') !== false,
    'a deferred certificate installs a retry timer');

// The timer itself is armed by a shared script, because a RESTORE that lands a
// site on a different domain has to arm exactly the same machinery for its new
// name — and two copies of a retry loop that talks to a rate-limited CA is one
// copy too many.
$armer_path = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/arm_ssl_retry.sh';
$armer_src  = is_file($armer_path) ? (string)file_get_contents($armer_path) : '';
check($armer_src !== '', 'the retry timer has one implementation, in arm_ssl_retry.sh', $armer_path);
check(strpos($install_src, 'arm_ssl_retry.sh') !== false,
    'install.sh arms it through that script rather than carrying its own copy');
check(strpos($armer_src, 'joinery-ssl-retry@.timer') !== false,
    'the timer is templated per domain, so a multi-site box gets one each');
check(strpos($armer_src, '--disarm') !== false,
    'it can also stop watching a domain, so a restore that changes the name leaves no orphan timer');

// The DNS lookup before certbot is what makes an indefinite retry safe: Let's
// Encrypt counts five failed validations per hostname per hour, and a failed
// lookup counts for nothing.
$retry_block = '';
if (preg_match('/RETRY_EOF.*?\nRETRY_EOF/s', $armer_src, $m)) {
    $retry_block = $m[0];
}
check($retry_block !== '', 'the retry script is findable');
check($retry_block !== '' && strpos($retry_block, 'dig +short') !== false,
    'it resolves the domain before spending a validation attempt');
check($retry_block !== '' && strpos($retry_block, 'have_real_cert') !== false,
    'it disables itself on a CA-issued certificate, not on any file at the cert path',
    'an operator or an origin-cert flow can place a self-signed cert there, so file-exists is not a finish line');

// Every Linode is dual-stack and prefers IPv6, so a bare `curl ifconfig.me`
// reports an IPv6 address. Compared against an A record it never matches, and
// the box waits for a certificate forever while reporting that it is waiting —
// which is exactly what happened on the first deferred install. Ask per family
// and compare like with like, the same way provision_origin_cert already does.
check($retry_block !== '' && preg_match('/curl -4 [^\n]*ifconfig\.me/', $retry_block) === 1
    && preg_match('/curl -6 [^\n]*ifconfig\.me/', $retry_block) === 1,
    'it asks for its own address per family, not whichever the host prefers');
check($retry_block !== '' && strpos($retry_block, 'dig +short A ') !== false
    && strpos($retry_block, 'dig +short AAAA ') !== false,
    'and resolves both A and AAAA to compare like with like');
check($retry_block !== '' && !preg_match('/\$\(curl -s --max-time 5 ifconfig\.me/', $retry_block),
    'with no bare curl left to reintroduce the mismatch');

// The same comparison exists in install.sh. Two checks that must agree, and
// only one of them was fixed the first time.
check(strpos($install_src, 'curl -4 -s') !== false && strpos($install_src, 'curl -6 -s') !== false,
    'provision_origin_cert makes the same per-family comparison',
    'the retry timer and the installer must agree on whether DNS points here');


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

// A field is required when it declares no default. The domain is required
// because a site with no domain can get no certificate, and every link and
// canonical URL it emits names an IP address. Blank is a state worth passing
// through during setup, not one worth deploying into — and an empty box beside
// "Site domain" reads as optional, which is how a placeholder that never
// resolves ends up naming somebody's site.
$domain_udf = '';
if (preg_match('/<UDF name="JOINERY_DOMAIN"[^>]*>/', $wrapper_src, $dm)) {
    $domain_udf = $dm[0];
}
check($domain_udf !== '', 'the wrapper declares a domain field');
check($domain_udf !== '' && strpos($domain_udf, 'default=') === false
    && strpos($domain_udf, 'optional=') === false,
    'and the domain is required, not optional',
    'a default or an optional flag makes the form accept a blank domain');

// Both of these are Linode-side constraints that reject the upload outright, so
// a wrapper that breaks either one is a file that can never become a
// StackScript. Neither is visible from reading the script or running it here.
check(preg_match('/[^\x00-\x7F]/', $wrapper_src) === 0,
    'the wrapper is plain ASCII',
    'the create API rejects the whole body on the first non-ASCII byte, naming only its offset');

// The platform parses every occurrence of the opening tag, comments included,
// and a mention without a name and label attached is a malformed field.
$udf_mentions = preg_match_all('/<UDF\b[^>]*>/', $wrapper_src, $udf_matches);
$bare_udf = [];
foreach ($udf_matches[0] as $tag) {
    if (strpos($tag, 'name=') === false || strpos($tag, 'label=') === false) {
        $bare_udf[] = $tag;
    }
}
check(empty($bare_udf),
    'every field tag in the wrapper carries a name and a label',
    empty($bare_udf)
        ? $udf_mentions . ' declared, none bare'
        : 'prose mentions the tag literally: ' . implode(', ', $bare_udf));

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
// Globalvars_site.php — database password, secret_box_key — and, on a management
// node, the agent signing key, provisioning and relay keys, and the DNS token.
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
// live management-node config, because a find predicate that quietly misses a file
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

// "Installed" means the Status field says so, not that dpkg has heard of the
// name. `dpkg -s` exits 0 for a package apt removed without purging — it stays
// in the database as "deinstall ok config-files" with its files gone. A node in
// that state was permanently stuck: the extension was never reinstalled,
// ComposerValidator then failed on the missing ext-*, and every upgrade rolled
// back. Observed on a live 24.04 box 2026-08-06.
$upgrade_php  = PathHelper::getIncludePath('utils/upgrade.php');
$plugin_start = $site_root . '/maintenance_scripts/install_tools/_plugin_installers_start.sh';
foreach ([
    'the container/bare-metal extension installer' => $deps_sh,
    'the plugin-start extension installer'         => $plugin_start,
    'the upgrade path'                             => $upgrade_php,
] as $label => $path) {
    $src = is_file($path) ? file_get_contents($path) : '';
    check($src !== '', "{$label} exists", $path);
    // Strip comments so the rationale above each fix can keep naming `dpkg -s`.
    $code = preg_replace('/^\s*(#|\/\/).*$/m', '', $src);
    check(strpos($code, 'dpkg -s') === false,
        "{$label} does not treat a known package name as installed");
    check(strpos($code, 'install ok installed') !== false,
        "{$label} tests the dpkg Status field instead");
}


section('The agent reaches every node, not only management nodes');

// The agent is core: it does a machine's own backups, upgrades and health
// checks, and is how that machine is managed at all once SSH goes. It shipped
// as the server_manager plugin's host_installer, and the runner only runs
// ACTIVE plugins' installers — server_manager is active on management nodes and
// nowhere else, so the agent reached two machines out of twelve and the spec
// read "the rollout cost is configuration, not deployment" while no managed
// node had a binary at all (surveyed 2026-08-26).
$core_installer = $site_root . '/maintenance_scripts/install_tools/install_agent.sh';
$agent_src      = is_file($core_installer) ? file_get_contents($core_installer) : '';
check($agent_src !== '', 'the agent installer is a core install tool', $core_installer);

$runner_src = is_file($plugin_start) ? file_get_contents($plugin_start) : '';
check(strpos($runner_src, 'install_agent.sh') !== false,
    'the root-moment runner runs it');

// Ordering is the whole fix. The runner exits early when a site has no active
// plugins, so a core installer placed after that lookup would be skipped on
// exactly the machines this was meant to reach.
$core_at    = strpos($runner_src, 'CORE_INSTALLERS=');
$plugins_at = strpos($runner_src, 'ACTIVE_PLUGINS=');
check($core_at !== false && $plugins_at !== false && $core_at < $plugins_at,
    'core installers run before any plugin lookup can exit early');
check(preg_match('/bash "\$\{CORE_PATH\}" "\$\{SITENAME\}"/', $runner_src) === 1,
    'the core installer is told which site it is installing for');

// And it must not also be a plugin installer, or a management node runs it twice.
$sm_manifest = json_decode(file_get_contents(
    PathHelper::getIncludePath('plugins/server_manager/plugin.json')), true);
check(is_array($sm_manifest), 'server_manager plugin.json parses');
check(!isset($sm_manifest['host_installer']),
    'server_manager no longer declares the agent installer as its own',
    'a management node would run it twice: once as core, once as the plugin');

// What the agent does on a given machine is one setting. An installer that
// ignored it would turn a root service on everywhere at the next upgrade.
check(strpos($agent_src, 'agent_enabled') !== false,
    'the installer reads the agent_enabled setting');

// The binary lands on every deployment; the setting decides only whether it
// runs. Ordering carries that: converge the artifact first, then apply the
// switch. Gating the install on the switch instead would mean a machine
// switched on later has to fetch, decompress and verify an artifact at that
// moment — and gets nothing at all if the tree it is running never shipped one.
$converge_at = strpos($agent_src, 'converge_binary || true');
$switch_at   = strpos($agent_src, 'if [ "$AGENT_ENABLED" != "1" ]');
check($converge_at !== false && $switch_at !== false && $converge_at < $switch_at,
    'the binary is installed before the switch is consulted, so off still means installed');

// The switch is projected into a root-owned marker so the things that decide
// whether the agent runs keep working when the database does not. The keepalive
// is the one that matters: it runs every minute with no database at all, and a
// keepalive that ignored the marker would restart an agent an operator switched
// off, within the minute, forever.
check(strpos($agent_src, 'MARKER_FILE=') !== false,
    'the installer projects the switch into a marker file');
$project_at = strpos($agent_src, '> "$MARKER_FILE"');
check($project_at !== false && $project_at < $switch_at,
    'projection happens before the switch is applied');
check(preg_match('/write_supervise_script\(\)[^}]*\/etc\/joinery-agent\/enabled/s', $agent_src) === 1,
    'the cron keepalive reads the marker before starting the agent',
    'without this, off lasts under a minute');

// A binary that was just installed must be STARTED, even though something is
// already running — what is running is the previous version, and replacing a
// file does not replace a live process. This is the only path during an artifact
// move: the old agent's compiled-in artifact directory is gone, so it cannot
// self-update out of the mismatch. It happened on the dev plane: 1.6.1 on disk,
// 1.6.0 in memory, heartbeating with an empty bundled_version.
check(strpos($agent_src, 'BINARY_INSTALLED=1') !== false,
    'the installer records when it actually replaced the binary');
check(preg_match('/if agent_running && \[ "\$BINARY_INSTALLED" = "0" \]/', $agent_src) === 1,
    'a fresh binary is started even when a process is already running',
    'skipping the start leaves the new binary on disk and the old one running');

// And a machine ALREADY in that state must be curable. Replacing the file leaves
// the live process on an unlinked inode, which /proc/PID/exe reports as
// "(deleted)" — the one signal available from outside the process. Without this,
// a second installer run cannot tell anything is wrong, because on disk
// everything agrees.
check(strpos($agent_src, 'running_is_stale()') !== false,
    'the installer can tell a running process from the binary on disk');
check(strpos($agent_src, '(deleted)') !== false,
    'it detects the replaced-binary signature');
check(preg_match('/if agent_running && \[ "\$BINARY_INSTALLED" = "0" \] && ! running_is_stale/', $agent_src) === 1,
    'and a stale process is restarted rather than left alone');

// The runner must be invoked AS ROOT by the upgrade, or every installer it runs
// exits on its own root check and the upgrade still reports success. That is not
// hypothetical: on the fleet's one bare-metal node with a non-root SSH user, an
// otherwise-clean upgrade logged "agent installer: not running as root -
// skipping" and left the agent behind. A container is already root, so the same
// code path looked fine everywhere else.
$upgrade_src = is_file($upgrade_php) ? file_get_contents($upgrade_php) : '';
check(strpos($upgrade_src, "\$root_prefix . 'bash ' . escapeshellarg(\$installers_runner)") !== false,
    'the upgrade runs the host installers with its root prefix',
    'without it, a node whose SSH user is not root silently skips every installer');

// install.sh --enable-agent is how a site provisioned by a management node
// comes up running. It has to write the setting BEFORE the host installers run,
// or the agent is installed and left stopped until some later root moment.
$enable_at    = strpos($install_src, '"$ENABLE_AGENT" = true');
$installers_at = strpos($install_src, '_plugin_installers_start.sh" "$SITENAME"');
check(strpos($install_src, '--enable-agent)') !== false,
    'install.sh accepts --enable-agent');
check($enable_at !== false && $installers_at !== false && $enable_at < $installers_at,
    'and applies it before the host installers run');

// Off has to mean stopped AND unsupervised. The cron keepalive restarts the
// agent within a minute, so stopping without removing it reads to an operator
// as the switch not working.
check(preg_match('/stop_and_disable_agent\(\)[^}]*rm -f "\$CRON_FILE"/s', $agent_src) === 1,
    'switching off removes the cron keepalive, not just the process');
check(preg_match('/stop_and_disable_agent\(\)[^}]*systemctl disable/s', $agent_src) === 1,
    'and disables the systemd unit where there is one');

// Both sides read the same setting; both must read it the same way. Executed,
// not pattern-matched: the shell's own case block against the PHP function.
require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));
$switch_block = '';
if (preg_match('/AGENT_ENABLED="\$\(printf.*?\nesac/s', $agent_src, $m)) {
    $switch_block = $m[0];
}
check($switch_block !== '', 'the shell\'s reading of the switch is findable');

$shell_reads_on = function (string $value) use ($switch_block): bool {
    $script = 'AGENT_ENABLED="$1"' . "\n" . $switch_block . "\n" . 'echo "$AGENT_ENABLED"';
    $path = tempnam(sys_get_temp_dir(), 'agentsw');
    file_put_contents($path, $script);
    $out = shell_exec('bash ' . escapeshellarg($path) . ' ' . escapeshellarg($value));
    unlink($path);
    return trim((string)$out) === '1';
};

foreach (['1', 'true', 'TRUE', 'True', 'yes', 'on', ' on ', '0', '', 'no', 'off', 'o n', 'nonsense'] as $value) {
    check($shell_reads_on($value) === admin_management_node_agent_switch_on($value),
        'the installer and the admin page agree on ' . var_export($value, true),
        'shell: ' . var_export($shell_reads_on($value), true)
            . ', php: ' . var_export(admin_management_node_agent_switch_on($value), true));
}

section('The database password never becomes a command line');

// argv is readable by every account on the box through ps, for as long as the
// process runs — and the host's ps lists container processes too, so a CMD that
// builds a psql command line leaks just as surely as the installer does. Worse,
// a --build-arg promoted to ENV puts the password in the IMAGE: docker inspect
// prints it and docker history keeps it, after the container is gone and
// wherever the image travels. Measured on a live image 2026-08-06: 7 layers.
// install.sh with comment lines removed. A local copy: the shared $install_exec
// is not built until further down this file, and reading it here would silently
// match against an empty string — every negative check would pass vacuously.
$install_nocomment = implode("\n", array_filter(
    explode("\n", $install_src),
    function ($line) { return strpos(ltrim($line), '#') !== 0; }
));

$dockerfile = $site_root . '/maintenance_scripts/install_tools/Dockerfile.template';
$df_src     = is_file($dockerfile) ? file_get_contents($dockerfile) : '';
check($df_src !== '', 'the Dockerfile template exists', $dockerfile);

// Strip comments; the rationale above each fix names the thing it removed.
$df_code = preg_replace('/^\s*#.*$/m', '', $df_src);
check(preg_match('/^\s*ARG\s+POSTGRES_PASSWORD/m', $df_code) === 0,
    'the password is not a build argument');
check(preg_match('/^\s*ENV\s+POSTGRES_PASSWORD/m', $df_code) === 0,
    'and is never baked into the image as an ENV');
// The SQL still names the password — it has to. What matters is how it reaches
// psql: on stdin through a shell builtin, never as a -c argument, which argv
// would expose. So look for a psql invocation that carries it, not for the
// password appearing at all.
check(preg_match('/psql[^\n|]*-c[^\n]*POSTGRES_PASSWORD/', $df_code) === 0,
    'the first-run ALTER USER does not put it in a psql command line');
check(preg_match('/echo\s+"ALTER USER postgres PASSWORD[^\n]*\|\s*su -c "psql/', $df_code) === 1,
    'it is piped to psql on stdin instead');
check(strpos($df_code, 'JOINERY_DB_PASSWORD="${POSTGRES_PASSWORD}" ./_site_init.sh') !== false,
    'and site init receives it in the environment, not as a positional');

check(strpos($install_nocomment, '--build-arg POSTGRES_PASSWORD') === false,
    'install.sh passes no password build argument');
check(substr_count($install_nocomment, '--env-file "$ENV_FILE"') >= 2,
    'every docker run supplies it from a file instead',
    'found: ' . substr_count($install_nocomment, '--env-file "$ENV_FILE"'));
check(preg_match('/chmod 600 "\$ENV_FILE"/', $install_nocomment) === 1,
    'that file is owner-only');
check(strpos($install_nocomment, 'JOINERY_DB_PASSWORD="$POSTGRES_PASSWORD" "$SCRIPT_DIR/_site_init.sh"') !== false,
    'the bare-metal path passes it in the environment too');

// The array matters beyond secrecy: the old string was word-split, so a password
// containing a space shifted every argument after it and the domain landed in
// the password's slot.
check(preg_match('/local INIT_ARGS=\(/', $install_nocomment) === 1,
    'site-init arguments are an array, so a space in a value cannot shift them');

$init_sh  = $site_root . '/maintenance_scripts/install_tools/_site_init.sh';
$init_src = is_file($init_sh) ? file_get_contents($init_sh) : '';
check(strpos($init_src, 'JOINERY_DB_PASSWORD') !== false,
    '_site_init.sh reads the password from the environment when given one');


section('A site with no certificate is still reachable');

// The :443 vhost exists only when an origin cert does. If the :80 redirect to
// HTTPS is not gated on the SAME file, a site with no cert — --no-ssl, an IP,
// localhost, or an issue that failed — answers every request with a permanent
// redirect to a port serving nothing. install.sh states the opposite intent
// ("a missing cert means the site serves HTTP"), so this pins config to promise.
// Observed live 2026-08-06 on a --no-ssl install.
foreach ([
    'the bare-metal vhost' => $site_root . '/maintenance_scripts/install_tools/default_virtualhost.conf',
    'the proxy vhost'      => $site_root . '/maintenance_scripts/install_tools/default_proxy_vhost.conf',
] as $label => $path) {
    $src = is_file($path) ? file_get_contents($path) : '';
    check($src !== '', "{$label} exists", $path);

    // The redirect to https and the :443 block must be guarded by the same file.
    $guard = '<IfFile /etc/letsencrypt/live/{{DOMAIN_NAME}}/fullchain.pem>';
    check(substr_count($src, $guard) >= 2,
        "{$label} guards its redirect on the cert, not just its :443 block",
        'IfFile guards found: ' . substr_count($src, $guard));

    // Every unconditional redirect-to-https must sit inside such a guard. Walk
    // the file rather than pattern-match, so a new one added outside is caught.
    $depth = 0;
    $ungated = [];
    foreach (preg_split('/\R/', $src) as $n => $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') { continue; }
        if (stripos($t, '<IfFile') === 0)  { $depth++; continue; }
        if (stripos($t, '</IfFile') === 0) { $depth = max(0, $depth - 1); continue; }
        if ($depth === 0 && preg_match('~RewriteRule.*https://~i', $t)) {
            $ungated[] = ($n + 1) . ': ' . $t;
        }
    }
    check(!$ungated, "{$label} has no redirect to https outside a cert guard",
        implode(' | ', $ungated));

    // The www alias has to land somewhere that answers, so it needs both halves.
    check(strpos($src, '<IfFile !/etc/letsencrypt/live/{{DOMAIN_NAME}}/fullchain.pem>') !== false,
        "{$label} sends www to http while there is no cert");
}


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

// The postmaster runs as postgres, not as whoever ran the installer, and every
// file created here takes its mode from that caller's umask. Under 0077 the
// drop-in lands 0600 root:root and PostgreSQL REFUSES TO START — the install
// then dies at the next psql with a socket error that names nothing about
// permissions. Reproduced on a live container 2026-08-06: 0600 leaves the
// cluster down, 0644 brings it up. So the modes are stated, never inherited.
check(preg_match('~chmod 644 \$\{PG_CONFIG_DIR\}/conf\.d/10-joinery-logging\.conf~', $install_exec) === 1,
    'the logging drop-in is given an explicit mode, not the caller umask');
check(preg_match('~chmod 755 \$\{PG_CONFIG_DIR\}/conf\.d~', $install_exec) === 1,
    'and so is conf.d, which mkdir -p would otherwise create from the umask too');

// pg_hba.conf escapes this today only because the package created it first and
// tee keeps an existing file's mode. That is luck, not design: on a layout where
// it does not already exist the same 0600 root:root stops the server dead.
check(preg_match('~chmod 640 \$\{PG_CONFIG_DIR\}/pg_hba\.conf~', $install_exec) === 1,
    'pg_hba.conf states its mode rather than inheriting whatever tee left');
check(preg_match('~chown postgres:postgres \$\{PG_CONFIG_DIR\}/pg_hba\.conf~', $install_exec) === 1,
    'and its owner, since 640 is unreadable to the server if it stays root-owned');


section('The script reports the version it actually is');

// The help banner carried its own copy of the version and had drifted to 2.7
// while the file said 2.41. Nobody was lying; a second copy of a number just
// stops being updated. An operator diagnosing an install reads --help, believes
// they are running a script from many releases ago, and goes looking for a
// missing feature that is right there. So the banner is derived, and the way it
// is derived is pinned here.
check(preg_match('/echo "Joinery Installation Script v\d/', $install_src) === 0,
    'the help banner states no literal version of its own');
check(preg_match('/echo "Joinery Installation Script v\$\{INSTALLER_VERSION\}"/', $install_exec) === 1,
    'it prints the derived one instead');
check(preg_match('/^INSTALLER_VERSION="\$\(sed .*#VERSION .*BASH_SOURCE\[0\]\}".*\)"$/m', $install_exec) === 1,
    'which is read from the newest #VERSION header in the file itself');

// The derivation is a sed over the running file, so a header reformat breaks it
// silently and the fallback prints the word unknown. Run it and compare: this
// is the one install.sh subcommand whose entire job is to print and exit.
$help_out  = array();
$help_code = 1;
exec('timeout 20 bash ' . escapeshellarg($install_sh) . ' --help 2>&1', $help_out, $help_code);
$help_text = implode("\n", $help_out);
preg_match('/^#VERSION ([0-9][0-9.]*)/m', $install_src, $header_version);
check($help_code === 0 && $help_text !== '',
    'install.sh --help runs and prints without provisioning anything');
check(!empty($header_version[1])
    && strpos($help_text, 'Joinery Installation Script v' . $header_version[1]) !== false,
    'and the version it prints is the one at the top of the file (' . ($header_version[1] ?? '?') . ')');
check(strpos($help_text, 'Script vunknown') === false,
    'the fallback did not fire, so the header is still in the shape sed expects');


section('No prompt can end the script by going unanswered');

// install.sh runs under set -e, and read returns 1 at EOF. A bare read is
// therefore a script that dies silently the moment stdin is not a terminal —
// observed 2026-08-06: `install.sh docker` under cloud-init stopped dead after
// "Docker is not installed", exit 1, nothing installed, nothing printed.
// Every prompt carries `|| true`, so EOF takes the prompt's default instead:
// [y/N] prompts refuse, [Y/n] prompts proceed. This is the class, not the
// instance — the check walks every read in the file.
$bare_reads = [];
foreach (preg_split('/\R/', $install_src) as $n => $line) {
    $t = ltrim($line);
    if ($t === '' || $t[0] === '#') { continue; }
    // Prompting reads only; `while read` loops consume pipelines, not the user.
    if (preg_match('/\bread\s+-[ps]/', $t) && strpos($t, '|| true') === false) {
        $bare_reads[] = ($n + 1) . ': ' . trim($t);
    }
}
check(!$bare_reads, 'every prompting read in install.sh survives EOF (`|| true`)',
    implode(' | ', $bare_reads));

// The first command of every scripted install also states the non-tty decision
// explicitly, so the transcript says what was decided and why.
check(preg_match('/elif \[ ! -t 0 \]; then/', $install_exec) === 1,
    'the Docker prompt has an explicit no-terminal branch');


section('Global flags mean the same thing in either position');

// The top-level parse loop breaks on the first non-flag argument, so
// `install.sh docker -y` used to reach do_docker_install with -y as an unread
// positional and ASSUME_YES=0 — compounding the bare-read defect into a silent
// no-op. Each subcommand now routes stray arguments through one setter, and an
// unknown flag is a stop, never a silent discard.
check(strpos($install_exec, 'consume_global_flag()') !== false,
    'one setter owns the global flags');
foreach (['docker', 'host-harden', 'build-base', 'server', 'list'] as $sub) {
    check(preg_match('/Unknown option for ' . preg_quote($sub, '/') . '/', $install_src) === 1,
        "the {$sub} subcommand stops on a flag it does not know");
}
foreach (['do_docker_install', 'do_host_harden', 'do_build_base', 'do_server_setup', 'do_list'] as $fn) {
    // -y after the subcommand reaches the same setter as -y before it.
    $body = null;
    if (preg_match('/^' . $fn . '\(\)\s*\{(.*?)^\}/ms', $install_src, $m)) {
        $body = $m[1];
    }
    check($body !== null && strpos($body, 'consume_global_flag') !== false,
        "{$fn} accepts trailing global flags");
}
// site has its own option loop; -y and -q are cases in it, and anything
// flag-shaped that is not a known option stops (bare "-" stays positional:
// it means auto-generate the password).
check(preg_match('/-q\|--quiet\)\s*\n\s*QUIET_MODE=1/', $install_src) === 1,
    'the site subcommand accepts trailing -q/--quiet');
check(preg_match('/Unknown option for site/', $install_src) === 1,
    'and stops on a flag its loop does not know');


section('The health probe reports reachability, not liveness');

// The old probe requested http://localhost:PORT/ with Host: localhost, which
// the vhost's domain-gated redirect never matches. On a --no-ssl install whose
// every real request 301ed into a :443 vhost that did not exist, the installer
// printed "Site is responding with HTTP 200". The probe now carries the
// configured domain, and a redirect to a scheme the install did not configure
// is a failure, not a pass.
// The clone-source manifest check also keeps its HTTP status (to say why a
// source refused), but it asks another site's export endpoint, not this
// site's health — it is not a probe line.
$probe_lines = array_values(array_filter(
    preg_split('/\R/', $install_exec),
    function ($l) { return strpos($l, '%{http_code}') !== false && strpos($l, 'curl') !== false
        && strpos($l, 'clone_export') === false; }
));
check(count($probe_lines) >= 2, 'both install paths probe the site',
    'probe lines found: ' . count($probe_lines));
foreach ($probe_lines as $l) {
    check(strpos($l, '-H "Host: $DOMAIN_NAME"') !== false,
        'the probe asks for the site by its configured domain', trim($l));
    // curl -w already prints 000 on failure; `|| echo "000"` printed a second
    // one, reporting "HTTP response: 000000".
    check(strpos($l, '|| echo "000"') === false,
        'a failed probe reports 000 once, not twice', trim($l));
}
check(substr_count($install_exec, '"$REDIRECT_URL" == https://*') >= 2
    && substr_count($install_exec, '[ "$NO_SSL" = true ]') >= 2,
    'a redirect to https:// under --no-ssl is a recognized failure state');
// And it is a stop: waiting cannot fix configuration.
check(preg_match('/https:\/\/\* \]\] && \[ "\$NO_SSL" = true \]; then\n(.*\n){1,6}\s*exit 1/',
        $install_exec) === 1,
    'that state exits non-zero instead of retrying into a green summary');


section('The page cache is owned by the process that writes it');

// cache/ is a named volume, so nothing done at image build time can reach it;
// and the container start command runs update_database as root AFTER its
// ownership sweep, so root created cache/static_pages first and www-data could
// never write it. StaticPageCache then logged "caching disabled" on every
// request, for the life of the install. The mkdir+chown must come after the
// last root-run PHP step in the start command.
$mkdir_pos = strpos($df_code, 'mkdir -p "/var/www/html/${SITENAME}/cache/static_pages"');
$updatedb_pos = strpos($df_code, 'update_database.php');
$chown_cache_pos = strpos($df_code, 'chown -R www-data:www-data "/var/www/html/${SITENAME}/cache"');
check($mkdir_pos !== false, 'the container start command creates cache/static_pages');
check($chown_cache_pos !== false, 'and gives the cache tree to www-data');
check($updatedb_pos !== false && $mkdir_pos !== false && $mkdir_pos > $updatedb_pos
    && $chown_cache_pos > $updatedb_pos,
    'both happen after the root-run PHP steps that used to steal the directory first');

// The directory the code reads is {site root}/cache/static_pages —
// public_html/cache was a different directory that nothing reads, created by
// the step that looked like it covered this.
check(strpos($site_init_src, 'mkdir -p "$SITE_ROOT/cache/static_pages"') !== false,
    '_site_init.sh creates the cache directory the code actually uses');
$init_code = preg_replace('/^\s*#.*$/m', '', $site_init_src);
check(strpos($init_code, 'public_html/cache') === false,
    'and no longer touches public_html/cache', 'a second cache directory is drift');

$fix_perms     = $site_root . '/maintenance_scripts/install_tools/fix_permissions.sh';
$fix_perms_src = is_file($fix_perms) ? file_get_contents($fix_perms) : '';
check($fix_perms_src !== '', 'fix_permissions.sh exists', $fix_perms);
// The sweep corrects only what is already wrong, so the assertion is on the
// find that owns the tree rather than on a blanket chown -R. What matters here
// is unchanged either way: the cache directory has to exist before the sweep
// runs, or it is never given to www-data and page caching silently stays off.
$fp_mkdir = strpos($fix_perms_src, 'mkdir -p "$SITE_ROOT/cache/static_pages"');
$fp_chown = strpos($fix_perms_src, '-exec chown www-data:user1 {} +');
check($fp_mkdir !== false && $fp_chown !== false && $fp_mkdir < $fp_chown,
    'the permissions sweep guarantees the cache directory exists before it sweeps');


section('Exactly one writer per scheduled-task cron file');

// Two cron.d files ran the same runner — _site_init.sh's per-site file every
// minute and a generic Dockerfile one every five — colliding on every shared
// tick, with the runner's already-running guard as the only thing keeping it
// safe. One writer per environment: in a container the start command owns the
// file, because /etc/cron.d does not survive a rebuild and _site_init.sh only
// runs on first boot; on bare metal _site_init.sh owns it.
check(substr_count($df_code, 'process_scheduled_tasks.php') === 1,
    'the container start command writes exactly one scheduled-task cron entry',
    'found: ' . substr_count($df_code, 'process_scheduled_tasks.php'));
check(strpos($df_code, '/etc/cron.d/joinery-${SITENAME}') !== false,
    'and it is the per-site file');
check(strpos($df_code, '/etc/cron.d/scheduled-tasks') === false,
    'the generic file is not written at all');
check(preg_match('/if \[ "\$DOCKER_MODE" = false \]; then(?:(?!^fi$).)*CRON_FILE="\/etc\/cron\.d\/joinery-/ms',
        $site_init_src) === 1,
    '_site_init.sh writes its cron file only on bare metal');


section('apt cannot ask a question mid-install');

// iptables-persistent asked "Save current IPv4 rules?" through debconf's
// readline fallback in the middle of a non-interactive run. The frontend is
// exported once for the whole script, so a new apt line cannot reintroduce the
// prompt — per-call prefixes protect exactly one line and rot.
$dfe_pos = strpos($install_exec, 'export DEBIAN_FRONTEND=noninteractive');
$first_apt = null;
foreach (['apt-get install', 'apt install', 'apt-get update', 'apt update'] as $needle) {
    $p = strpos($install_exec, $needle);
    if ($p !== false && ($first_apt === null || $p < $first_apt)) { $first_apt = $p; }
}
check($dfe_pos !== false, 'install.sh exports DEBIAN_FRONTEND=noninteractive globally');
check($first_apt !== null && $dfe_pos !== false && $dfe_pos < $first_apt,
    'and does so before the first apt call');


section('Upgrading a request to HTTPS does not throw away what was typed');

// A 301 or 302 makes the browser re-issue a redirected POST as a GET and drop
// the body, so a form submitted at the moment the scheme upgrade appears is
// swallowed in flight: no error, no action, back to the same page. It happened
// for real — a deferred certificate was issued between a login page loading
// over http and its form being submitted, and the credentials never arrived.
// 308 keeps the method and the body, with the same permanent semantics.
foreach ([
    'default_virtualhost.conf' => $site_root . '/maintenance_scripts/install_tools/default_virtualhost.conf',
    'default_proxy_vhost.conf' => $site_root . '/maintenance_scripts/install_tools/default_proxy_vhost.conf',
] as $label => $path) {
    $vhost_src = is_file($path) ? file_get_contents($path) : '';
    check($vhost_src !== '', "{$label} exists", $path);

    // Every scheme upgrade in the file, whatever its shape.
    $upgrades = [];
    if (preg_match_all('/RewriteRule[^\n]*https:\/\/[^\n]*/', $vhost_src, $rm)) {
        $upgrades = $rm[0];
    }
    check(!empty($upgrades), "{$label} redirects http to https");

    $body_losing = array_filter($upgrades, function ($rule) {
        return strpos($rule, 'R=301') !== false
            || strpos($rule, 'R=302') !== false
            || strpos($rule, 'R=permanent') !== false
            || strpos($rule, 'R=temp') !== false;
    });
    check(empty($body_losing),
        "{$label} upgrades the scheme without discarding a POST",
        empty($body_losing) ? 'all use 308' : 'body-losing rule: ' . implode(' | ', $body_losing));
}


section('A package prompt cannot kill an unattended install');

// The Linode Ubuntu image ships grub-pc/install_devices holding the literal
// string "multiselect" — the debconf template type where a device path belongs.
// The first grub-pc upgrade then tries to install a bootloader to /multiselect,
// fails, and aborts the whole install. DEBIAN_FRONTEND=noninteractive does not
// help: it suppresses the prompt, it does not supply the answer.
$grub_pos   = strpos($install_src, "grub-pc/install_devices");
$upgrade_pos = strpos($install_src, 'apt update && apt upgrade -y');
check($grub_pos !== false, 'install.sh handles the grub-pc device answer');
check($grub_pos !== false && $upgrade_pos !== false && $grub_pos < $upgrade_pos,
    'and does so before the upgrade that would trip over it',
    'after the upgrade is after the failure');

// Clearing the bogus value is the part that works. install_devices_empty alone
// does not: the postinst consults it only when the list is empty, and the
// corrupt string is not empty. Verified on a box in the failed state.
check(strpos($install_src, "set grub-pc/install_devices ' | debconf-communicate") !== false,
    'by clearing the corrupt value, not only setting install_devices_empty');
check(strpos($install_src, 'install_devices_empty boolean true') !== false,
    'then recording that nowhere is the intended answer',
    'the host boots this guest; the guest has no boot sector to write');

// A real answer is a device path. Matching only the exact literal keeps a
// properly-partitioned box from being told to stop installing its bootloader.
check(strpos($install_src, '[ "$grub_devices" = "multiselect" ]') !== false,
    'and only when the value is exactly the leaked template type');


section('A request that matches no vhost reaches nothing');

// Apache answers anything no vhost claims from the main server, whose built-in
// DocumentRoot is /var/www/html — the directory holding every site's logs,
// config and maintenance scripts. That is reachable in practice, not in theory:
// mod_ssl is enabled from the start so the box listens on 443 immediately,
// while the site's :443 vhost exists only once a certificate does.
// specs/apache_no_cert_443_exposure.md.
check(strpos($install_src, 'DocumentRoot /var/www/unmatched') !== false,
    'the main server serves from a directory of its own',
    'not /var/www/html, which contains every site on the box');
check(preg_match('/<Directory \/var\/www\/unmatched>.*?Require all denied.*?<\/Directory>/s', $install_src) === 1,
    'and that directory denies everything',
    'an empty directory today is not a guarantee about tomorrow');
check(strpos($install_src, 'mkdir -p /var/www/unmatched') !== false,
    'the directory is created before Apache is told to use it',
    'a missing DocumentRoot is a startup failure, not a fallback');

// Appended to a config file that survives re-runs of `install.sh server`.
check(strpos($install_src, "grep -q 'BEGIN joinery unmatched-request root'") !== false,
    'the block is written once however often server setup re-runs');

// Ubuntu's stock default IS a vhost, rooted at /var/www/html. While it is
// enabled it catches unmatched requests before the main server is reached, so
// the empty DocumentRoot would protect nothing. _site_init.sh disables it when
// a site is created; server setup has to disable it too, or a box that has had
// `install.sh server` run and no site yet is exposed in the gap.
check(preg_match('/a2dissite 000-default\.conf/', $install_src) === 1,
    'server setup disables the stock default vhost',
    'otherwise it, not the main server, answers unmatched requests');

// The two rejected alternatives, pinned so a later edit does not quietly adopt
// one: disabling 000-default does not help (the main server is not a vhost),
// and deferring the module means a certificate arriving later stops Apache from
// starting on an unknown SSLEngine directive.
check(preg_match('/^\s*a2enmod ssl\s*$/m', $install_src) === 1,
    'mod_ssl is still enabled unconditionally',
    'the fix is where the request lands, not whether the port is open');

// Comments that describe a fallback nobody implements are worse than no
// comments: this one is why the missing :443 vhost went unquestioned.
$ssl_srcs = $install_src
    . (is_file($site_root . '/maintenance_scripts/sysadmin_tools/setup_ssl.sh')
        ? file_get_contents($site_root . '/maintenance_scripts/sysadmin_tools/setup_ssl.sh') : '');
check(!preg_match('/falls back to (a )?self-signed|self-signed fallback/i', $ssl_srcs),
    'nothing claims a self-signed fallback that provision_origin_cert does not perform',
    'it tries HTTP-01, then DNS-01, then returns having issued nothing');


section('The generated keepalive actually launches the agent');

// Every other check on this file reads its SOURCE. That is why the following
// shipped: the keepalive closed every descriptor above stdio from inside its own
// script file, which closed the shell's own copy of that file, so it stopped at
// that line and never reached the launch. The regexes above all still passed —
// the marker was read, the launch line was present, the text looked right.
//
// Both restart paths run through this script (cron, and the installer's own
// start_agent), so a node whose agent exited was never restarted by anything.
// joinerydemo at 0.8.347: the agent self-updated, exited as designed, stayed
// down. Nothing but running the thing catches that, so this runs it.
$tmp = sys_get_temp_dir() . '/keepalive_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

// The stand-in agent's PROCESS NAME has to be unique to this run, because
// pgrep's namespace is the whole machine and this suite's directory is not.
// Two copies running at once — the db tier's parallel batch, or two agents
// testing on one dev box — otherwise both install a binary called `fake-agent`,
// and the second copy's liveness probe finds the FIRST copy's still-sleeping
// process, correctly decides an agent is already running, and skips the launch.
// Both launch assertions then fail for a reason that has nothing to do with the
// keepalive.
//
// Kept to 15 characters: Linux truncates comm there, and `pgrep -x` matches
// against comm, so a longer name would never match itself.
$fake_agent = 'fake-agent-' . bin2hex(random_bytes(2));

if (!preg_match('/cat > "\$SUPERVISE_PATH" <<\x27SUPERVISE\x27\n(.*?)\nSUPERVISE\n/s', $agent_src, $km)) {
	check(false, 'the keepalive body can be extracted from the installer', 'heredoc shape changed');
} else {
	// Point the generated script at a stand-in agent and temp markers. Only the
	// paths change; the launch mechanics under test are the shipped ones.
	$body = $km[1];
	$body = str_replace('/usr/local/bin/joinery-agent', $tmp . '/' . $fake_agent, $body);
	// Also retarget the liveness probe: this box runs its own agent, and the
	// unmodified probe would find it, correctly decide one is already running,
	// and skip the launch — making the test pass for the wrong reason.
	$body = str_replace('pgrep -x joinery-agent', 'pgrep -x ' . $fake_agent, $body);
	$body = str_replace('/etc/joinery-agent/enabled', $tmp . '/enabled', $body);
	$body = str_replace('/etc/joinery-agent/joinery-agent.env', $tmp . '/agent.env', $body);
	$body = str_replace('/var/log/joinery-agent.log', $tmp . '/agent.log', $body);

	file_put_contents($tmp . '/keepalive.sh', $body);
	chmod($tmp . '/keepalive.sh', 0755);
	file_put_contents($tmp . '/enabled', "1\n");
	file_put_contents($tmp . '/agent.env', "");
	// Records that it ran, and which descriptors it inherited. The listing is
	// ls -l (targets, not bare numbers) because the observing shell parks its
	// OWN redirection descriptors at 10 and above - a bare number can never
	// distinguish those from a caller leak, but a target pointing at the
	// lockfile can only be inherited.
	file_put_contents($tmp . '/' . $fake_agent,
		"#!/bin/sh\necho started > " . $tmp . "/ran\nls -l /proc/\$\$/fd > " . $tmp . "/fds 2>/dev/null\nsleep 2\n");
	chmod($tmp . '/' . $fake_agent, 0755);
	touch($tmp . '/lockfile');

	// pgrep must not find a stray process of this run's own name and skip the
	// launch. With a per-run name the only way this is false is a genuine
	// collision, which is worth failing on rather than working around.
	$pgrep_safe = (trim((string)shell_exec('pgrep -x ' . escapeshellarg($fake_agent) . ' 2>/dev/null')) === '');

	// Run it holding descriptors open, exactly as an upgrade holding the
	// .upgrade.lock does when it calls the host installers - including
	// descriptors NUMBERED 10 AND ABOVE, which an upgrade process easily
	// holds. Those are the regression case for a real outage class: POSIX sh
	// (dash) parses `exec 10>&-` as "run the program named 10", so the
	// keepalive's descriptor-closing launcher exited 127 before starting the
	// agent whenever any fd >= 10 was inherited. The outer shell here must be
	// bash for the same reason - dash cannot OPEN fd 10 either.
	shell_exec('bash -c ' . escapeshellarg(
		'exec 9<' . $tmp . '/lockfile 10<' . $tmp . '/lockfile 11<' . $tmp . '/lockfile'
		. ' 12<' . $tmp . '/lockfile 13<' . $tmp . '/lockfile; ' . $tmp . '/keepalive.sh') . ' 2>/dev/null');

	// WAITED FOR, NOT SLEPT THROUGH. The keepalive launches the agent with
	// `nohup ... &` and returns immediately, so how long the child takes to get
	// scheduled and write its markers is a property of the box, not of the code
	// under test. A fixed one-second sleep passed on an idle machine and failed
	// under the db tier's parallel batch — reporting "the generated script
	// exited before launching" about a launch that simply had not happened yet.
	// The stand-in writes /ran and then /fds, so /fds is the later of the two
	// and the one worth waiting on.
	$deadline = microtime(true) + 15;
	while (!file_exists($tmp . '/fds') && microtime(true) < $deadline) {
		usleep(50000);
	}

	check($pgrep_safe && file_exists($tmp . '/ran'),
		'the keepalive starts the agent when none is running',
		'the generated script exited before launching — check that nothing closes the descriptor '
		. 'the shell reads its own script from');

	// And the reason the descriptor closing exists at all: the launched agent
	// must NOT inherit the upgrade lock, or every later upgrade on that node is
	// refused by a lock whose holder is the agent.
	$fds_listing = file_exists($tmp . '/fds') ? file_get_contents($tmp . '/fds') : '';
	$leaked = array();
	foreach (preg_split('/\n/', trim($fds_listing)) as $fd_line) {
		if (strpos($fd_line, $tmp . '/lockfile') !== false) $leaked[] = trim($fd_line);
	}
	check($fds_listing !== '' && $leaked === array(),
		'the launched agent does not inherit the caller descriptors',
		$fds_listing === '' ? 'fds listing missing'
			: ($leaked ? 'inherited: ' . implode('; ', $leaked) . ' — fds 9-13 all point at the stand-in upgrade lock' : ''));
}

foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);

harness_finish();
