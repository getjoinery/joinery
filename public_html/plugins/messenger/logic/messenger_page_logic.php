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
 * @version 1.0.0
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
			'federation_available' => false,
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

	// Whether this deployment can chat across sites at all — the affordance for
	// it is only shown where it would work.
	$federation_available = MessengerFederation::available()
		&& MessengerFederation::addressFor($user_id) !== null;

	return LogicResult::render(array(
		'session'       => $session,
		'unavailable'   => null,
		'user_id'       => $user_id,
		'conversations' => $rows,
		'open'          => $open,
		'client'        => Messenger::clientSettings(),
		'federation_available' => $federation_available,
	));
}
?>
