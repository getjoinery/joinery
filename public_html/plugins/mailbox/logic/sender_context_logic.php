<?php
/**
 * API action: mailbox/sender_context — who the correspondent is on this platform.
 *
 * POST /api/v1/action/mailbox/sender_context (session credential). Param: message_id (the
 * client sends a MESSAGE id, never an address, so this endpoint can't be used as a
 * membership oracle — the counterparty address is re-derived server-side from a message
 * the caller can already see). Auth: the message must be in the caller's mailbox scope.
 *
 * Every mailbox user gets the contact half: the counterparty's address, the display name
 * from the message, and what the CALLER'S OWN contact store knows about that address
 * ({contact:null} when nothing, {contact:{locked:true}} when their vault is closed).
 *
 * The site-account half is admin-only (permission 5+) — member records, orders and
 * registrations are operator data, so a non-admin mailbox grantee never gets another
 * member's history. `account_visible` says whether that half was evaluated at all, so the
 * client can tell "no account here" from "not disclosed to you". For an admin, a match by
 * email (User::GetByEmail, case-insensitive) adds the member card plus recent event
 * registrations / orders / conversation count — each section present only when its
 * plugin/feature is active. No match → {is_member:false}.
 *
 * @version 1.2.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function sender_context_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}
	// Member records are operator data — the site-account half is admins only (level 5+).
	// The contact half is the caller's own data and needs no such gate.
	$can_see_account = (intval($session->get_permission()) >= 5);

	$message_id = intval($input['message_id'] ?? 0);
	if ($message_id <= 0) {
		return LogicResult::error('No message specified.');
	}

	// Load the message and enforce the caller's mailbox scope (the alias must be one
	// they can access) — this is what makes the derived address non-oracle.
	$msg = new InboundEmailMessage($message_id, TRUE);
	if (!$msg->key || $msg->get('iem_delete_time')) {
		return LogicResult::error('That message no longer exists.');
	}
	$alias_id = intval($msg->get('iem_iea_inbound_email_alias_id'));
	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->isAllAccess() && ($alias_id <= 0 || !$viewer->canAccess($alias_id))) {
		return LogicResult::error('Not authorized.');
	}

	// Derive the counterparty: the sender of an inbound message, the recipient of an
	// outbound one. Reading a sealed field needs the window (the admin is in-window
	// while reading the thread); a closed window returns a soft locked signal.
	try {
		$is_outbound = ($msg->get('iem_direction') === 'outbound');
		$raw = $is_outbound ? (string)$msg->get('iem_recipient') : (string)$msg->get('iem_sender');
	} catch (Throwable $e) {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		if ($e instanceof VaultLockedException) {
			return LogicResult::render(array('locked' => true));
		}
		throw $e;
	}

	$parsed = MailboxContacts::parseAddress($raw);
	if ($parsed === null) {
		// The recipient field can hold several addresses — try the first token.
		foreach (preg_split('/[,;]+/', $raw) as $tok) {
			$parsed = MailboxContacts::parseAddress((string)$tok);
			if ($parsed !== null) { break; }
		}
	}
	if ($parsed === null) {
		return LogicResult::render(array(
			'is_member'       => false,
			'account_visible' => $can_see_account,
			'address'         => '',
			'message_id'      => $message_id,
		));
	}
	$address = $parsed[0];

	// What the caller's own contact store holds for this address — saved, merely seen, or
	// nothing. Independent of whether they also have an account here.
	$contact = (new MailboxContacts())->lookup(intval($session->get_user_id()), $address);

	$base = array(
		'message_id'      => $message_id,
		'address'         => $address,
		'display_name'    => (string)$parsed[1],
		'contact'         => $contact,
		'account_visible' => $can_see_account,
	);

	// A non-admin stops here: the contact half only, and no signal either way about
	// whether this address belongs to a member.
	if (!$can_see_account) {
		return LogicResult::render(array_merge($base, array('is_member' => false)));
	}

	$user = User::GetByEmail($address);
	if (!$user || !$user->key) {
		return LogicResult::render(array_merge($base, array('is_member' => false)));
	}
	$uid = intval($user->key);

	$payload = array_merge($base, array(
		'is_member' => true,
		'member'    => array(
			'user_id'        => $uid,
			'name'           => trim((string)$user->display_name()) ?: $address,
			'email'          => (string)$user->get('usr_email'),
			'email_verified' => (bool)$user->get('usr_email_is_verified'),
			'member_since'   => $user->get('usr_terms_accepted_time'),
			'edit_url'       => '/admin/admin_user_edit?usr_user_id=' . $uid,
		),
	));

	// Recent event registrations — only when the event_manager plugin is active.
	if (PluginHelper::isPluginActive('event_manager')) {
		try {
			require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
			require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
			$regs = new MultiEventRegistrant(array('user_id' => $uid), array('evr_create_time' => 'DESC'));
			$regs->load();
			$out = array();
			foreach ($regs as $r) {
				if (count($out) >= 5) { break; }
				$ev = new Event(intval($r->get('evr_evt_event_id')), TRUE);
				$out[] = array(
					'event' => $ev->key ? (string)$ev->get('evt_name') : 'Event #' . intval($r->get('evr_evt_event_id')),
					'time'  => $r->get('evr_create_time'),
				);
			}
			$payload['registrations'] = $out;
		} catch (Throwable $e) {
			error_log('sender_context registrations: ' . $e->getMessage());
		}
	}

	// Recent orders — only when the store plugin is active.
	if (PluginHelper::isPluginActive('store')) {
		try {
			require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
			$orders = new MultiOrder(array('user_id' => $uid), array('ord_timestamp' => 'DESC'));
			$orders->load();
			$status_label = array(1 => 'Unpaid', 2 => 'Paid', 3 => 'Error');
			$out = array();
			foreach ($orders as $o) {
				if (count($out) >= 5) { break; }
				$out[] = array(
					'id'     => intval($o->get('ord_order_id')),
					'status' => $status_label[intval($o->get('ord_status'))] ?? 'Unknown',
					'total'  => (float)$o->get('ord_total_cost'),
					'time'   => $o->get('ord_timestamp'),
				);
			}
			$payload['orders'] = $out;
		} catch (Throwable $e) {
			error_log('sender_context orders: ' . $e->getMessage());
		}
	}

	// Core conversations the member participates in — shown only when there are some.
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT COUNT(*) FROM cnp_conversation_participants WHERE cnp_usr_user_id = ?');
		$stmt->execute(array($uid));
		$conv_count = intval($stmt->fetchColumn());
		if ($conv_count > 0) {
			$payload['conversations'] = array('count' => $conv_count);
		}
	} catch (Throwable $e) {
		/* messaging not in use / table absent — section simply absent */
	}

	return LogicResult::render($payload);
}

function sender_context_logic_api() {
	return array(
		'requires_session' => true,
		'description' => 'Resolve a thread counterparty to the caller\'s contact entry, plus their member record (admin only)',
	);
}
?>
