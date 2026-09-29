<?php
/** @joinery-test
 * name: same_site_return
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The step-up ceremony's return path stays on this site
 * (SessionControl::same_site_return, used by require_recent_second_factor,
 * stepup_redirect and verify_stepup_logic). Browsers read `/\host` as
 * `//host` and drop tabs and newlines inside a URL, so each of those is
 * another site and must come back as /profile.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('Same-site paths pass through');
foreach (array('/profile', '/drive?folder=3', '/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id=3&target_level=private',
		'/x?y=%2F%2Fevil.com') as $ok) {
	check(SessionControl::same_site_return($ok) === $ok, 'kept: ' . $ok);
}

section('Anything that can reach another site becomes /profile');
foreach (array('' => 'empty', 'profile' => 'no leading slash', 'https://evil.com' => 'absolute', '//evil.com' => 'protocol-relative',
		"/\\evil.com" => 'slash-backslash', "/\t/evil.com" => 'a tab', "/\n/evil.com" => 'a newline', "/\r/evil.com" => 'a carriage return',
		"/a b" => 'a space', "/p\\q" => 'a backslash later on', "/x\x7f" => 'DEL') as $bad => $why) {
	check(SessionControl::same_site_return($bad) === '/profile', 'refused: ' . $why);
}
check(strpos((string)SessionControl::stepup_redirect("/\t/evil.com")->redirect, rawurlencode('/profile')) !== false,
	'stepup_redirect sends a bad return to /profile too');

harness_finish();
?>
