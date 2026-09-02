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
 * The answer is also about the SENDER. A message can only go direct from a
 * domain this deployment holds a signing identity for; a connected account's
 * address (user@gmail.com), or any hosted domain not yet published, sends as
 * ordinary email whatever the recipient supports. The compose passes the From
 * mailbox as `alias_id` (a grant the caller holds); without one the recipient
 * half alone is answered, as before.
 *
 * @version 1.2.0
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

	// The sending side: no signing identity for the From domain means no
	// direct path, whoever the recipient is. The alias must be one the caller
	// can send from, so this cannot be turned into a probe of other mailboxes.
	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id > 0) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
		require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
		$viewer = MailboxViewer::fromSession($session);
		if (!$viewer->canAccess($alias_id)) {
			return LogicResult::render($empty);
		}
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			return LogicResult::render($empty);
		}
		$from_domain = DirectProtocol::domainOf(strtolower($alias->get_full_address()));
		if ($from_domain === '' || !DirectSigningIdentity::hasIdentity($from_domain)) {
			return LogicResult::render($empty);
		}
	}

	// Same tolerant split as MailboxSender::parseAddressList — the field this
	// reads is the live compose To box, so it must accept whatever separators a
	// person types (commas, semicolons, spaces, tabs). Non-addresses are skipped
	// rather than refused: the hint has no business rejecting a half-typed field.
	$addresses = array();
	$collect = function ($token) use (&$addresses) {
		$token = strtolower(trim((string)$token));
		if ($token !== '' && filter_var($token, FILTER_VALIDATE_EMAIL)) {
			$addresses[$token] = true;
		}
	};
	// 'Name <addr>' groups first — a display name may contain the whitespace
	// treated as a separator between bare addresses below.
	$rest = preg_replace_callback('/[^,;<>]*<([^<>]*)>/', function ($m) use ($collect) {
		$collect($m[1]);
		return ' ';
	}, (string)($input['to'] ?? ''));
	foreach (preg_split('/[\s,;]+/', (string)$rest, -1, PREG_SPLIT_NO_EMPTY) as $token) {
		$collect($token);
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
		'description' => 'Whether a recipient address can be reached over Joinery Direct from the given From mailbox (per-domain capability only)',
		'input' => array(
			'to'       => array('type' => 'string', 'required' => true, 'label' => 'One or more recipient addresses'),
			'alias_id' => array('type' => 'int', 'required' => false, 'min' => 0, 'label' => 'The From mailbox; without it only the recipient side is answered'),
		),
	);
}
