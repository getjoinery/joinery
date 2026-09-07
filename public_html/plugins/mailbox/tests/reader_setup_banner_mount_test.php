<?php
/** @joinery-test
 * name: reader_setup_banner_mount
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * The reader's setup banner ("This mailbox needs attention") reaches operators
 * on BOTH reader mounts.
 *
 * The check is turned on by one mount option, setup_url_base. For a long time
 * only the admin reader passed it, while the profile reader — the "Email" item
 * everyone actually uses — never did, so an operator reading mail there was
 * never told a mailbox was broken. Pinned over real HTTP against the running
 * site:
 *
 *   - an operator (permission 10) opening /profile/mailbox/mailbox gets the
 *     reader with the setup link, so the banner can appear;
 *   - a member (permission 0) opening the same page gets no setup link — mail
 *     setup is operator work;
 *   - an admin page renders with the admin-header notice registry in place.
 *
 * Run: php plugins/mailbox/tests/reader_setup_banner_mount_test.php [base_url] [origin_ip]
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_http_boot($argv);
harness_boot();

$run_id = substr(md5(uniqid('rsb', true)), 0, 6);
$setup_link = '/plugins/mailbox/admin/admin_mailbox_setup?alias_id=';

/** The reader mount's config as the page embeds it — the setupUrlBase value or null. */
function rsb_setup_url_base(string $html): ?string {
	// The mount JSON-encodes its config; slashes may be escaped.
	if (preg_match('/"setupUrlBase"\s*:\s*("([^"]*)"|null)/', $html, $m)) {
		return isset($m[2]) && $m[1] !== 'null' ? stripslashes($m[2]) : null;
	}
	return null;
}

section('An operator reading mail on the profile reader gets the setup link');
$admin = make_user('rsb_admin_' . $run_id, 10);
$jar = harness_jar_new();
$csrf = harness_web_login($jar, $admin->get('usr_email'), 'TestPassword_rsb_admin_' . $run_id);
check($csrf !== null, 'the operator fixture signs in over HTTP');
$page = harness_request('GET', '/profile/mailbox/mailbox', array('jar' => $jar, 'accept' => null));
check($page['status'] === 200, 'the profile reader renders for the operator', 'status ' . $page['status']);
check(strpos($page['body'], 'setupUrlBase') !== false, 'the reader mount is on the page (the operator has all-access)');
check(rsb_setup_url_base($page['body']) === $setup_link,
	'setupUrlBase is the admin Setup page prefix', var_export(rsb_setup_url_base($page['body']), true));

section('An admin page renders with the notice registry in its header');
$admin_page = harness_request('GET', '/admin/admin_users', array('jar' => $jar, 'accept' => null));
check($admin_page['status'] === 200, 'an admin page answers 200', 'status ' . $admin_page['status']);
check(stripos($admin_page['body'], 'Fatal error') === false && stripos($admin_page['body'], 'AdminNotices') === false,
	'no fatal and no leaked class name in the page');

section('A member reading their own mail gets no setup link');
$member = make_user('rsb_member_' . $run_id, 0);
$mjar = harness_jar_new();
$mcsrf = harness_web_login($mjar, $member->get('usr_email'), 'TestPassword_rsb_member_' . $run_id);
check($mcsrf !== null, 'the member fixture signs in over HTTP');
$mpage = harness_request('GET', '/profile/mailbox/mailbox', array('jar' => $mjar, 'accept' => null));
check($mpage['status'] === 200, 'the profile reader renders for the member', 'status ' . $mpage['status']);
check(strpos($mpage['body'], $setup_link) === false, 'the setup link is absent for a member');
if (strpos($mpage['body'], 'setupUrlBase') !== false) {
	check(rsb_setup_url_base($mpage['body']) === null, 'a member who does hold a mailbox gets setupUrlBase null');
}

harness_finish();
