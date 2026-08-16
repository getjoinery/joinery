<?php
/**
 * Page logic for the Messages app (/profile/messenger).
 *
 * Gathers what the app needs to draw itself: the member's conversation list
 * and, when the URL names one (?c=), that conversation's newest page of
 * messages. The view embeds it in the page, so the app paints without a
 * round-trip; everything after that arrives through messenger_poll.
 *
 * Not an API action: this is a page, so it has no descriptor.
 *
 * @version 1.1.0
 * @changelog 1.1.0 - Ships structured federation state (site_ready / member_can_send / admin_notice) so the picker explains the next unfinished step instead of hiding
 */


function messenger_page_logic(array $input): LogicResult {

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/messenger'));
	}

	try {
		$user_id = Messenger::requireMember();
	} catch (MessengerRefusal $e) {
		return LogicResult::render(array(
			'session'       => $session,
			'unavailable'   => $e->getMessage(),
			'conversations' => array(),
			'open'          => null,
			'client'        => Messenger::clientSettings(),
			'federation'    => array(
				'site_ready' => false, 'member_can_send' => false, 'admin_notice' => '',
			),
		));
	}

	$conversations = new MultiConversation(
		array('participant_user_id' => $user_id, 'deleted' => false),
		array(), Messenger::INBOX_SIZE
	);

	$rows = array();
	foreach ($conversations as $conversation) {
		$rows[] = Messenger::conversationPayload($conversation, $user_id);
	}

	// ?c= names the conversation to open. An id the member is not in simply
	// opens nothing — the same answer as an id that does not exist.
	$open = null;
	$requested = isset($input['c']) ? (int)$input['c'] : 0;
	if ($requested) {
		try {
			$conversation = Messenger::conversationFor($requested, $user_id);
			$page = Messenger::threadPage($conversation);
			// Reading is not recorded here. Marking a conversation read is a
			// write, and this is a page view — the browser's first poll (a POST)
			// carries mark_read and does it a moment later.
			$open = array(
				'conversation' => Messenger::conversationPayload($conversation, $user_id),
				'messages'     => Messenger::messagesPayload($page['messages'], $user_id),
				'has_more'     => $page['has_more'],
			);
		} catch (MessengerRefusal $e) {
			$open = null;
		}
	}

	// Cross-site chat state, in the picker's terms (the reachability spec):
	// site_ready      — the SITE can chat across instances (S1–S3 false it)
	// member_can_send — this member holds a mailbox on a signable domain (S4)
	// admin_notice    — silence reads as breakage, so a superadmin (who can
	//                   reach the switch and the Setup tab) is pointed at the
	//                   next unfinished step instead:
	//                   'not_set_up'  — the switch is off, or no mailbox (S1, S2)
	//                   'unpublished' — the switch is ON but the DNS half is
	//                                   not done (S3's missing identity is an
	//                                   implementation detail: the Setup tab
	//                                   mints it while planning, so the fix
	//                                   for S3 and S6 is the same tab)
	$site_ready = MessengerFederation::siteReady();
	$federation = array(
		'site_ready'      => $site_ready,
		'member_can_send' => $site_ready && MessengerFederation::addressFor($user_id) !== null,
		'admin_notice'    => '',
	);
	if ($session->get_permission() >= 10) {
		if (!MessengerFederation::available()) {
			$federation['admin_notice'] = 'not_set_up';
		} elseif (!$site_ready) {
			$federation['admin_notice'] = 'unpublished';
		} else {
			// One capability lookup per identity domain, served from the same
			// cache the send path uses — a page load costs a DNS query only
			// when the cache has expired, and only for superadmins.
			foreach (new MultiDirectIdentity(array('is_active' => true)) as $identity) {
				$capability = DirectCapability::lookup((string)$identity->get('jdi_domain'));
				if ($capability === null || !isset($capability['keys'][(string)$identity->get('jdi_key_id')])) {
					$federation['admin_notice'] = 'unpublished';
					break;
				}
			}
		}
	}

	return LogicResult::render(array(
		'session'       => $session,
		'unavailable'   => null,
		'user_id'       => $user_id,
		'conversations' => $rows,
		'open'          => $open,
		'client'        => Messenger::clientSettings(),
		'federation'    => $federation,
	));
}
?>
