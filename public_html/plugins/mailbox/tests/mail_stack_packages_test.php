<?php
/** @joinery-test
 * name: mailbox_mail_stack_packages
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The mail stack's package list has to resolve honestly on a PostgreSQL box.
 *
 * opendmarc depends on `dbconfig-mysql | dbconfig-no-thanks`. An unresolved
 * alternative is satisfied by the first option, so apt quietly installs a MySQL
 * client stack onto a platform that has never used MySQL, and dbconfig-common
 * then fails provisioning a database against a server that is not there:
 *
 *   ERROR 2002 (HY000): Can't connect to local MySQL server through socket ...
 *   dbconfig-common: opendmarc configure: noninteractive fail.
 *
 * Nothing breaks — that database feeds opendmarc-import and opendmarc-reports,
 * which nothing here runs — but a deployment log whose contract is that errors
 * mean something ends every successful run with two of them. Naming the other
 * alternative resolves the dependency the way this platform actually wants.
 *
 * Found on the first StackScript box to install the default bundle: the mailbox
 * host installer had never run unattended before, so nothing had ever seen it.
 *
 * Run:  php plugins/mailbox/tests/mail_stack_packages_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');

harness_boot();

$installer = __DIR__ . '/../provisioning/install_email.sh';
$src = is_file($installer) ? file_get_contents($installer) : '';

section('The mail stack does not drag MySQL onto a PostgreSQL box');

check($src !== '', 'the host installer exists', $installer);

// The package list is one array; both names have to be in it, and the
// alternative has to be named or apt picks MySQL for us.
$has_list = preg_match('/^PACKAGES=\(([^)]*)\)/m', $src, $m) === 1;
check($has_list, 'the installer declares its package list');

$packages = $has_list ? preg_split('/\s+/', trim($m[1])) : [];
check(in_array('opendmarc', $packages, true),
    'opendmarc is still installed',
    'it stamps the Authentication-Results the router reads');
check(in_array('dbconfig-no-thanks', $packages, true),
    'and dbconfig-no-thanks is named alongside it',
    'otherwise apt satisfies the alternative with dbconfig-mysql');

// Order is not what decides it — apt resolves the whole transaction — but a
// reader should see the reason sitting next to the thing it explains.
check(strpos($src, 'dbconfig-mysql | dbconfig-no-thanks') !== false,
    'and the dependency it resolves is written down',
    'a bare package name in a list explains nothing');

// The platform is PostgreSQL. A MySQL client package appearing in a list here
// would mean somebody solved this the other way round.
$mysql_named = array_filter($packages, function ($p) {
    return stripos($p, 'mysql') !== false && $p !== 'dbconfig-no-thanks';
});
check(empty($mysql_named),
    'no MySQL package is requested by name',
    empty($mysql_named) ? 'none' : implode(', ', $mysql_named));

harness_finish();
