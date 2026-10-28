<?php
/** @joinery-test
 * name: terms_owed
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * TermsOwed: who is asked to accept which terms (specs/easy_signup_completion.md Q7).
 *
 * A site's own terms bind its members, and only when the site names them; a
 * hosted site's operators accept getjoinery's terms; a self-hosted site's
 * operators, and a site renting only getjoinery's services, accept none.
 * Every path that asks (the sign-in gate, the terms page, the password pages)
 * reads the answer here.
 *
 * Settings are overridden in memory only; the users are never saved.
 * Run: php tests/account_security/terms_owed_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$person = function (int $permission, ?string $accepted = null): User {
	$u = new User(null);
	$u->set('usr_permission', $permission);
	$u->set('usr_terms_accepted_time', $accepted);
	return $u;
};
$member = $person(0);
$admin = $person(10);

section('A member accepts the site\'s own terms, when the site names them');
harness_set_setting_mem('hosted_plan_state', 'none-of-these');
harness_set_setting_mem('terms_url', 'https://example.org/terms');
harness_set_setting_mem('privacy_url', 'https://example.org/privacy');
$owed = TermsOwed::owed($member);
check(is_array($owed) && $owed['terms'] === 'https://example.org/terms' && empty($owed['joinery']),
	'a member owes the site\'s terms', json_encode($owed));
check(TermsOwed::owed($person(0, '2026-10-28 00:00:00')) === null, 'a member who accepted owes nothing');
check(strpos(TermsOwed::linksHtml($owed), 'https://example.org/privacy') !== false
	&& strpos(TermsOwed::linksHtml($owed), "getjoinery's") === false, 'the links are the site\'s, unnamed');
check(TermsOwed::labelText($owed) === 'I agree to the Terms of Use and Privacy Policy.', 'the checkbox names both');

harness_set_setting_mem('privacy_url', '');
if (harness_stored_setting_is_blank('privacy_url')) {
	check(TermsOwed::labelText(TermsOwed::owed($member)) === 'I agree to the Terms of Use.', 'a site with terms but no privacy page names only its terms');
} else {
	harness_skip('a site with terms but no privacy page names only its terms', 'this box stores a privacy_url, and a blank cannot be forced in memory');
}
if (harness_stored_setting_is_blank('terms_url') && harness_stored_setting_is_blank('privacy_url')) {
	harness_set_setting_mem('terms_url', '');
	check(TermsOwed::owed($member) === null, 'a site that names no terms asks its members nothing');
} else {
	harness_skip('a site that names no terms asks its members nothing', 'this box stores terms URLs, and a blank cannot be forced in memory');
}

section('A self-hosted site\'s operators accept nobody\'s terms');
harness_set_setting_mem('terms_url', 'https://example.org/terms');
harness_set_setting_mem('hosted_plan_state', 'none-of-these');
if (!HostedPlanNotice::applies()) {
	check(TermsOwed::owed($admin) === null, 'a self-hosted site\'s admin owes nothing, even with site terms set');
} else {
	harness_skip('a self-hosted site\'s admin owes nothing', 'the in-memory state did not take');
}
harness_set_setting_mem('hosted_plan_state', HostedPlanNotice::STATE_SERVICES);
check(TermsOwed::owed($admin) === null, 'renting only getjoinery\'s services does not make the site hosted');

section('A hosted site\'s operators accept getjoinery\'s terms, whatever the site names');
harness_set_setting_mem('hosted_plan_state', 'trial');
$owed = TermsOwed::owed($admin);
check(is_array($owed) && !empty($owed['joinery']) && $owed['terms'] === TermsOwed::JOINERY_TERMS_URL
	&& $owed['privacy'] === TermsOwed::JOINERY_PRIVACY_URL, 'a hosted site\'s admin owes getjoinery\'s terms', json_encode($owed));
check(strpos(TermsOwed::linksHtml($owed), "getjoinery's") === 0, 'and the page names them as getjoinery\'s');
check(TermsOwed::labelText($owed) === "I agree to getjoinery's Terms of Use and Privacy Policy.", 'so does the checkbox');
check(TermsOwed::owed($person(10, '2026-10-28 00:00:00')) === null, 'an admin who accepted owes nothing');
$owed = TermsOwed::owed($member);
check(is_array($owed) && empty($owed['joinery']), 'a hosted site\'s member still owes the site\'s own terms');

section('Every path that asks reads TermsOwed');
foreach (array('includes/SessionControl.php', 'logic/terms_accept_logic.php', 'logic/password_reset_2_logic.php',
		'logic/password_set_logic.php') as $file) {
	$src = file_get_contents(PathHelper::getIncludePath($file));
	check(strpos($src, 'TermsOwed::owed(') !== false, $file . ' asks TermsOwed');
}
foreach (array('views/terms-accept.php', 'views/password-set.php', 'logic/password_reset_2_logic.php') as $file) {
	$src = file_get_contents(PathHelper::getIncludePath($file));
	check(strpos($src, "get_setting('terms_url')") === false, $file . ' does not read terms_url itself');
}

harness_finish();
