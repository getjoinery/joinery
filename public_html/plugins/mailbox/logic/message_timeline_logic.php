<?php
/**
 * API action: mailbox/message_timeline — everything the platform knows happened
 * to one message, as a timeline (specs/mailbox_message_timeline.md).
 *
 * POST /api/v1/action/mailbox/message_timeline (session credential). Params:
 * message_id; refresh_delivery=1 to ask the carrier again about an attempt the
 * cache calls settled (the panel's Check again button).
 *
 * Every line is a stored fact or the carrier's live answer; where a question has
 * no answer the response says so in `notes` rather than guessing. Assembled from:
 *   - the message row (arrival, fetch, authentication, spam, AI scan, read time,
 *     sealed state, current labels),
 *   - the Received: chain in its raw headers (hops), readable only in-window on
 *     a protected mailbox,
 *   - the routing-log rows that name it (how it was routed),
 *   - the send-attempt rows that produced it or answered it (sends, failures,
 *     receipts, forwards), and for each attempt the carrier's delivery events —
 *     cached on the attempt, refreshed while the status is still moving.
 *
 * Authorization is mailbox-grant scope, identical to reading the message body
 * (MailboxViewer); a NULL-alias message stays superadmin-only. A closed vault
 * window omits the header-derived and sealed lines and returns locked:true
 * beside the lines that ARE readable — the panel shows what it can.
 *
 * @version 1.1
 */

function message_timeline_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$id = intval($input['message_id'] ?? 0);
	if ($id <= 0) {
		return LogicResult::error('No message specified.');
	}

	$message = new InboundEmailMessage($id, TRUE);
	if (!$message->key) {
		return LogicResult::error('That message no longer exists.');
	}

	$viewer = MailboxViewer::fromSession($session);
	$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
	$allowed = $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
	if (!$allowed) {
		return LogicResult::error('You do not have access to this mailbox.');
	}

	$builder = new MailboxMessageTimeline($message, !empty($input['refresh_delivery']));
	try {
		return LogicResult::render($builder->build());
	} catch (Throwable $e) {
		error_log('mailbox/message_timeline: ' . $e->getMessage());
		return LogicResult::error('The timeline could not be assembled.');
	}
}

function message_timeline_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'The timeline of everything recorded about one mail message',
		'input' => [
			'message_id' => ['type' => 'int', 'required' => true, 'label' => 'Message ID'],
			'refresh_delivery' => ['type' => 'bool', 'required' => false, 'label' => 'Re-query delivery state'],
		],
	);
}
?>
