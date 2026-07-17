<?php
/**
 * AJAX-estate migration — functional coverage for the core /api/v1 actions that
 * replaced the legacy core /ajax/ endpoints (specs/ajax_estate_migration.md).
 *
 * For each migrated core action: the response envelope shape, the auth floor
 * (below-floor keys get 403; unauthenticated is denied), and a payload
 * spot-check. Two behaviors are pinned explicitly:
 *   - email_available refuses an unauthenticated caller (401 for the anonymous
 *     browser principal) — a regression guard against the old open oracle,
 *     which returned true/false to anyone.
 *   - notification_mark_read's $_SESSION unread-count invalidation survives to a
 *     second request over a real browser session (the session_write contract):
 *     the chrome badge recomputes from the DB on the next page render.
 *   - notification_unread_count works as a plain read-only action.
 *   - bookings/booking_slots answers a sessionless caller (no credential) and
 *     fail-soft returns an empty list for an unknown slug.
 *
 * Run: php tests/functional/api/ajax_migration_actions_test.php [base_url] [origin_ip]
 *
 * @version 1.0.0
 */

/** @joinery-test
 * name: api_ajax_migration
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('data/notifications_class.php'));

$suffix = strtoupper(LibraryFunctions::random_string(6));

// A cookie-jar request that follows redirects (this suite reads admin pages
// behind the post-login 302). $body defaults to JSON.
function jar_curl($method, $path, $jar, $headers = array(), $body = null) {
	return harness_request($method, $path, array(
		'jar' => $jar, 'headers' => $headers, 'body' => $body, 'follow' => true));
}

try {
	echo 'Base URL: ' . harness_http_base_url() . "\nTest suffix: $suffix\n";

	section('Setup: staff / member / superadmin keys');
	$staff  = make_user($suffix . 'ST', 5);
	$member = make_user($suffix . 'MB', 0);
	$super  = make_user($suffix . 'SA', 10);
	// Activate so the web-login path (activation_required_login) accepts them.
	foreach (array($staff, $member, $super) as $u) {
		$u->set('usr_is_activated', true);
		$u->set('usr_email_is_verified', true);
		$u->save();
	}
	$k_staff  = make_machine_key($staff->key,  'ajaxmig-staff-'  . $suffix);
	$k_member = make_machine_key($member->key, 'ajaxmig-member-' . $suffix);
	$k_super  = make_machine_key($super->key,  'ajaxmig-super-'  . $suffix);
	$H_staff  = key_headers($k_staff['api_key']->get('apk_public_key'),  $k_staff['secret_key']);
	$H_member = key_headers($k_member['api_key']->get('apk_public_key'), $k_member['secret_key']);
	$H_super  = key_headers($k_super['api_key']->get('apk_public_key'),  $k_super['secret_key']);
	// Assert the fixtures actually materialized rather than a can't-fail check(true).
	check($staff->key > 0 && $member->key > 0 && $super->key > 0
		&& !empty($k_staff['secret_key']) && !empty($k_member['secret_key']) && !empty($k_super['secret_key']),
		'fixtures created (3 users + 3 machine keys)');

	// ------------------------------------------------------------------
	section('email_available — security fix (was an open oracle) + floor 5');
	// Anonymous browser principal (valid CSRF proof, no signed-in user): the
	// action is not open — it is denied 401 (regression guard).
	$anon = harness_jar_new('ajaxmig');
	jar_curl('GET', '/', $anon); // distributes the joinery_api_csrf mirror cookie
	$anon_csrf = harness_jar_csrf($anon);
	check($anon_csrf !== null, 'anonymous visitor received a joinery_api_csrf cookie');
	$r = jar_curl('POST', '/api/v1/action/email_available', $anon,
		array('X-Joinery-Csrf: ' . $anon_csrf), array('email' => 'probe@example.com'));
	check($r['status'] === 401, 'unauthenticated email_available -> 401 (not the old open oracle)', 'status ' . $r['status']);

	$r = api_request('POST', '/api/v1/action/email_available', $H_member, array('email' => 'x@example.com'));
	check($r['status'] === 403, 'member (perm 0) email_available -> 403 (below floor 5)', 'status ' . $r['status']);

	$r = api_request('POST', '/api/v1/action/email_available', $H_staff, array('email' => $staff->get('usr_email')));
	check($r['status'] === 200 && isset($r['json']['data']['valid']) && $r['json']['data']['valid'] === false,
		'staff: existing email -> {valid:false}', json_encode($r['json']['data'] ?? null));
	$r = api_request('POST', '/api/v1/action/email_available', $H_staff, array('email' => 'free_' . strtolower($suffix) . '@example.com'));
	check($r['status'] === 200 && ($r['json']['data']['valid'] ?? null) === true, 'staff: fresh email -> {valid:true}');

	// ------------------------------------------------------------------
	section('user_search — floor 5, {items:[{id,text}]}');
	$r = api_request('POST', '/api/v1/action/user_search', $H_member, array('q' => 'harnesstest'));
	check($r['status'] === 403, 'member user_search -> 403');
	$r = api_request('POST', '/api/v1/action/user_search', $H_staff, array('q' => 'harnesstest', 'includenone' => true));
	check($r['status'] === 200 && isset($r['json']['data']['items']) && is_array($r['json']['data']['items']),
		'staff user_search -> {items:[...]}');
	$items = $r['json']['data']['items'] ?? array();
	check(!empty($items) && ($items[0]['id'] ?? null) === 0 && ($items[0]['text'] ?? '') === 'None',
		'includenone prepends the {id:0, text:None} row');

	// ------------------------------------------------------------------
	section('image_list — floor 5, {images,total,hasMore}');
	$r = api_request('POST', '/api/v1/action/image_list', $H_member, array('limit' => 2));
	check($r['status'] === 403, 'member image_list -> 403');
	$r = api_request('POST', '/api/v1/action/image_list', $H_staff, array('limit' => 2));
	$d = $r['json']['data'] ?? array();
	check($r['status'] === 200 && array_key_exists('images', $d) && array_key_exists('total', $d) && array_key_exists('hasMore', $d),
		'staff image_list -> {images,total,hasMore}');

	// ------------------------------------------------------------------
	section('validate_server_file — floor 5, enum-guarded');
	$r = api_request('POST', '/api/v1/action/validate_server_file', $H_member, array('field' => 'logo_link', 'value' => ''));
	check($r['status'] === 403, 'member validate_server_file -> 403');
	$r = api_request('POST', '/api/v1/action/validate_server_file', $H_staff, array('field' => 'logo_link', 'value' => ''));
	check($r['status'] === 200 && ($r['json']['data']['valid'] ?? null) === true, 'staff empty logo_link -> {valid:true}');
	$r = api_request('POST', '/api/v1/action/validate_server_file', $H_staff, array('field' => 'logo_link', 'value' => '/definitely_missing_' . $suffix . '.png'));
	check(($r['json']['data']['valid'] ?? null) === false, 'staff missing file -> {valid:false}');
	$r = api_request('POST', '/api/v1/action/validate_server_file', $H_staff, array('field' => '/etc/passwd', 'value' => 'x'));
	check($r['status'] === 422, 'non-whitelisted field -> 422 (enum blocks arbitrary paths)', 'status ' . $r['status']);

	// ------------------------------------------------------------------
	section('plugin_provisioning_check — floor 5, {plugins}');
	$r = api_request('POST', '/api/v1/action/plugin_provisioning_check', $H_member, array());
	check($r['status'] === 403, 'member plugin_provisioning_check -> 403');
	$r = api_request('POST', '/api/v1/action/plugin_provisioning_check', $H_staff, array());
	check($r['status'] === 200 && array_key_exists('plugins', $r['json']['data'] ?? array()), 'staff -> {plugins}');

	// ------------------------------------------------------------------
	section('theme_switch — floor 10');
	$r = api_request('POST', '/api/v1/action/theme_switch', $H_staff, array('theme' => 'getjoinery'));
	check($r['status'] === 403, 'staff (perm 5) theme_switch -> 403 (below floor 10)', 'status ' . $r['status']);
	$current_theme = Globalvars::get_instance()->get_setting('theme_template');
	$r = api_request('POST', '/api/v1/action/theme_switch', $H_super, array('theme' => $current_theme));
	check($r['status'] === 200 && ($r['json']['data']['switched'] ?? null) === true,
		'superadmin re-activates current theme -> {switched:true} (idempotent)', 'status ' . $r['status']);

	// ------------------------------------------------------------------
	section('reaction_toggle / status / count — logged-in, round-trip');
	$eid = (int) $member->key; // any stable entity id owned-agnostic; reactions are per (type,id)
	$r = api_request('POST', '/api/v1/action/reaction_toggle', $H_member, array('entity_type' => 'ajaxmigtest', 'entity_id' => $eid));
	check($r['status'] === 200 && ($r['json']['data']['action'] ?? null) === 'reacted' && ($r['json']['data']['count'] ?? null) === 1,
		'reaction_toggle -> {action:reacted, count:1}', json_encode($r['json']['data'] ?? null));
	$r = api_request('POST', '/api/v1/action/reaction_status', $H_member, array('entity_type' => 'ajaxmigtest', 'entity_id' => $eid));
	check(($r['json']['data']['reacted'] ?? null) === true && ($r['json']['data']['count'] ?? null) === 1, 'reaction_status -> {reacted:true, count:1}');
	$r = api_request('POST', '/api/v1/action/reaction_count', $H_member, array('entity_type' => 'ajaxmigtest', 'entity_id' => $eid));
	check(($r['json']['data']['count'] ?? null) === 1, 'reaction_count -> {count:1}');
	$r = api_request('POST', '/api/v1/action/reaction_toggle', $H_member, array('entity_type' => 'ajaxmigtest', 'entity_id' => $eid));
	check(($r['json']['data']['action'] ?? null) === 'unreacted' && ($r['json']['data']['count'] ?? null) === 0, 'reaction_toggle back -> {action:unreacted, count:0}');
	$r = api_request('POST', '/api/v1/action/reaction_toggle', $H_member, array('entity_type' => 'BAD-TYPE', 'entity_id' => 1));
	check($r['status'] === 422 && ($r['json']['errortype'] ?? '') === 'ActionError', 'invalid entity_type -> ActionError');

	// ------------------------------------------------------------------
	section('notifications — read-only count + session_write invalidation');
	// Give staff three unread notifications (staff sees the admin chrome badge
	// that surfaces $_SESSION['notification_unread_count']).
	$ntf_ids = array();
	for ($i = 0; $i < 3; $i++) {
		$ntf = new Notification(NULL);
		$ntf->set('ntf_usr_user_id', $staff->key);
		$ntf->set('ntf_type', 'ajaxmig');
		$ntf->set('ntf_title', 'ajaxmig test ' . $suffix . ' #' . $i);
		$ntf->set('ntf_is_read', false);
		$ntf->save();
		$ntf_ids[] = (int) $ntf->key;
		harness_register_row('ntf_notifications', 'ntf_notification_id', $ntf->key);
	}
	$r = api_request('POST', '/api/v1/action/notification_unread_count', $H_staff, array());
	check($r['status'] === 200 && ($r['json']['data']['unread_count'] ?? null) === 3,
		'notification_unread_count (read-only) -> 3', json_encode($r['json']['data'] ?? null));

	// session_write: over a real browser session, mark_read must invalidate the
	// $_SESSION unread cache so the next page render recomputes (3 -> 2). A machine
	// key cannot show this (each request is a fresh simulated session), so we log
	// in as staff and read the admin chrome badge before and after.
	$sess = harness_jar_new('ajaxmig');
	harness_web_login($sess, $staff->get('usr_email'), 'TestPassword_' . $suffix . 'ST');
	$badge_of = function ($raw) { return preg_match('/notifications-count">\s*(\d+)\s*</', $raw, $m) ? (int) $m[1] : null; };
	$page1 = jar_curl('GET', '/admin/admin_users', $sess);
	$sess_csrf = harness_jar_csrf($sess);
	$logged_in = strpos($page1['raw'], 'name="password"') === false && strpos($page1['raw'], 'notifications-count') !== false;
	check($logged_in && $sess_csrf !== null, 'browser session established (admin chrome + csrf)',
		'logged_in=' . var_export($logged_in, true) . ' csrf=' . ($sess_csrf === null ? 'empty' : 'set'));
	check($badge_of($page1['raw']) === 3, 'chrome notification badge shows 3 before mark_read', 'badge ' . var_export($badge_of($page1['raw']), true));
	$mr = jar_curl('POST', '/api/v1/action/notification_mark_read', $sess,
		array('X-Joinery-Csrf: ' . $sess_csrf), array('notification_id' => $ntf_ids[0]));
	check($mr['status'] === 200 && ($mr['json']['data']['marked'] ?? null) === true, 'browser-session mark_read -> {marked:true}');
	$page2 = jar_curl('GET', '/admin/admin_users', $sess);
	check($badge_of($page2['raw']) === 2, 'session_write: badge recomputes to 2 on the second request (invalidation survived)', 'badge ' . var_export($badge_of($page2['raw']), true));

	// ------------------------------------------------------------------
	section('bookings/booking_slots — sessionless (no credential) + fail-soft');
	$r = api_request('POST', '/api/v1/action/bookings/booking_slots', array(), array('slug' => 'nonexistent-' . strtolower($suffix)));
	check($r['status'] === 200 && isset($r['json']['data']['slots']) && $r['json']['data']['slots'] === array(),
		'sessionless POST (no key/CSRF) -> 200 {slots:[]} for an unknown slug (fail-soft)', 'status ' . $r['status']);
	$r = api_request('POST', '/api/v1/action/bookings/booking_slots', array(), array());
	check($r['status'] === 200 && is_array($r['json']['data']['slots'] ?? null),
		'sessionless POST with no slug -> 200 {slots:[...]} (never an auth error)');

} catch (Throwable $e) {
	// Record the crash as a failing check so it reaches the result contract —
	// previously the catch printed FATAL then called harness_finish() with no
	// failing check, emitting a GREEN contract for a crashed suite.
	check(false, 'unhandled exception mid-suite', $e->getMessage());
	echo "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
	harness_teardown_data();
}

harness_finish();
?>
