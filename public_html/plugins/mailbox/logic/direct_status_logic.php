<?php
/**
 * API action: mailbox/direct_status — will this message go direct, or as
 * ordinary email?
 *
 * POST /api/v1/action/mailbox/direct_status (session credential). Param: `to`
 * (one or more addresses, comma-separated). Returns
 * {direct: bool, addresses: {addr: bool}} — one answer per address plus a
 * summary for the compose field.
 *
 * **What this can and cannot say.** It answers the PUBLIC, per-domain question
 * only: does the recipient's domain publish a Joinery Direct capability record.
 * Whether that particular recipient will actually accept a direct delivery from
 * you is private, per-person, and lives only in their contact list on their
 * instance — the whole design refuses to make it queryable, because an endpoint
 * that answered it would be an oracle for their contacts and their block list.
 * So the compose indicator promises "this can go direct", never "this will".
 * That is also exactly what the sender itself knows at send time: it attempts
 * the good path whenever the domain supports it and lets the receiver answer
 * live.
 *
 * Everything it reads is public DNS anybody could look up, so it discloses
 * nothing — but it does let a signed-in caller drive resolver work, so it is
 * rate-limited per caller and served from the same cache the send path uses.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function direct_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
	require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectCapability.php'));
	require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$empty = array('direct' => false, 'addresses' => array());
	if (!DirectSettings::enabled()) {
		return LogicResult::render($empty);
	}
	if (!RequestLogger::check_rate_limit('mailbox_direct_status', 120, 60)) {
		// Over the cap the honest answer is "not known", which renders as
		// ordinary email — the same thing the send path does when discovery
		// fails, so the indicator never over-promises.
		return LogicResult::render($empty);
	}
	RequestLogger::log('mailbox_direct_status', 'lookup', true);

	$addresses = array();
	foreach (preg_split('/[,;]+/', (string)($input['to'] ?? '')) as $token) {
		$token = trim($token);
		if ($token === '') {
			continue;
		}
		// Accept "Name <addr>" the way the compose field holds it.
		if (preg_match('/<([^>]+)>/', $token, $m)) {
			$token = $m[1];
		}
		$token = strtolower(trim($token));
		if (filter_var($token, FILTER_VALIDATE_EMAIL)) {
			$addresses[$token] = true;
		}
	}
	if (empty($addresses)) {
		return LogicResult::render($empty);
	}

	$answers = array();
	$all_direct = true;
	foreach (array_keys($addresses) as $address) {
		$capable = DirectCapability::lookup(DirectProtocol::domainOf($address)) !== null;
		$answers[$address] = $capable;
		if (!$capable) {
			$all_direct = false;
		}
	}

	return LogicResult::render(array('direct' => $all_direct, 'addresses' => $answers));
}

function direct_status_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Whether a recipient address can be reached over Joinery Direct (per-domain capability only)',
	);
}
