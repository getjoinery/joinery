<?php
/** @joinery-test
 * name: event_sessions_access
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * An event's session materials go to its registrants only.
 *
 * Both pages that show them (/profile/event_sessions, and the course layout at
 * /profile/event_sessions_course) are also API actions, so the logic itself
 * must hold the materials back: a member who is not registered, or whose
 * registration has expired, gets the reason (error_message) and nothing else.
 * A registrant gets the sessions.
 *
 * Run: php tests/run.php db --filter=event_sessions_access
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

if (!PluginHelper::isPluginActive('event_manager')) {
	section('Event session access');
	harness_skip('event_manager active', 'plugin inactive on this deployment');
	harness_finish();
	return;
}

require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_sessions_class.php'));

/** Sign $user in as the session the logic reads. */
function esa_sign_in($user) {
	$_SESSION = array();
	SessionControl::get_instance()->store_session_variables($user);
}

/** An event with one session, in the given session layout. */
function esa_event($display_type) {
	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest sessions event ' . bin2hex(random_bytes(3)));
	$event->set('evt_start_time', gmdate('Y-m-d H:i:s', time() + 86400));
	$event->set('evt_end_time', gmdate('Y-m-d H:i:s', time() + 90000));
	$event->set('evt_status', Event::STATUS_ACTIVE);
	$event->set('evt_visibility', Event::VISIBILITY_PUBLIC);
	$event->set('evt_session_display_type', $display_type);
	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	$s = new EventSession(NULL);
	$s->set('evs_evt_event_id', $event->key);
	$s->set('evs_session_number', 1);
	$s->set('evs_title', 'Session one');
	$s->set('evs_content', 'Session materials');
	$s->set('evs_is_public', true);
	$s->save();
	$s->load();
	harness_register_row('evs_event_sessions', 'evs_event_session_id', $s->key);
	return $event;
}

function esa_register($user, $event, $expires = null) {
	$reg = new EventRegistrant(NULL);
	$reg->set('evr_evt_event_id', $event->key);
	$reg->set('evr_usr_user_id', $user->key);
	if ($expires !== null) {
		$reg->set('evr_expires_time', $expires);
	}
	$reg->save();
	$reg->load();
	harness_register_row('evr_event_registrants', 'evr_event_registrant_id', $reg->key);
}

$pages = array(
	'sessions list' => array(1, 'plugins/event_manager/logic/event_sessions_logic.php', 'event_sessions_logic'),
	'course'        => array(2, 'plugins/event_manager/logic/event_sessions_course_logic.php', 'event_sessions_course_logic'),
);

$stranger = make_user('EsaStranger');
$member = make_user('EsaMember');
$lapsed = make_user('EsaLapsed');

foreach ($pages as $label => list($display_type, $path, $fn)) {
	section("The $label page");
	$event = esa_event($display_type);
	esa_register($member, $event);
	esa_register($lapsed, $event, gmdate('Y-m-d H:i:s', time() - 86400));

	esa_sign_in($stranger);
	$r = harness_call_logic($path, $fn, array('evt_event_id' => $event->key), 'GET');
	$data = $r->data ?? array();
	check(!empty($data['error_message']) && strpos($data['error_message'], 'not registered') !== false,
		'a member who is not registered is told so', json_encode(array_keys($data)));
	check(array_keys($data) === array('error_message'),
		'and gets nothing else: no sessions, no video', json_encode(array_keys($data)));

	esa_sign_in($lapsed);
	$r = harness_call_logic($path, $fn, array('evt_event_id' => $event->key), 'GET');
	$data = $r->data ?? array();
	check(!empty($data['error_message']) && strpos($data['error_message'], 'expired') !== false
			&& array_keys($data) === array('error_message'),
		'a registration that has expired gets the reason and nothing else', json_encode(array_keys($data)));

	esa_sign_in($member);
	$r = harness_call_logic($path, $fn, array('evt_event_id' => $event->key), 'GET');
	$data = $r->data ?? array();
	check(empty($data['error_message']) && isset($data['event_sessions']),
		'a registrant gets the sessions', json_encode(array_keys($data)));
}

harness_finish();
