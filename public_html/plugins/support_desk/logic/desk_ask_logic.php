<?php
/**
 * API action: support_desk/desk_ask — a question from a site's Help panel.
 *
 * POST /api/v1/action/support_desk/desk_ask, JSON:
 *   { site: {host, version}, message, route?, consent: {bundle, live_reads},
 *     thread_id?, token?,                      a later question in a thread
 *     origin_nonce?, bundle? }                 a new thread
 * Sessionless; a connected site also signs with its services key pair. The
 * site's own server sends it (the Help panel's proxy), never a browser.
 * Answers {thread_id, token (new thread only), user_message_id, message_id,
 * state, status}; poll desk_poll with message_id while status is running.
 *
 * See plugins/support_desk/docs/overview.md and SupportIntake for the order of
 * checks.
 *
 * @version 0.1.0
 */
function desk_ask_logic(array $input): LogicResult {
	if ($limited = SupportApi::limit('desk_ask', empty($input['thread_id']))) {
		return $limited;
	}
	try {
		return SupportApi::ok('desk_ask', SupportIntake::ask($input, SupportApi::headers(), SupportApi::ip()));
	} catch (SupportRefusal $e) {
		return SupportApi::fail('desk_ask', $e);
	}
}

function desk_ask_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Ask the support desk a question from a site. Sessionless; a new thread is confirmed by a call back to the site.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => SupportApi::siteInput() + array(
			'message'      => array('type' => 'text', 'required' => true, 'max_length' => 8000, 'label' => 'Question'),
			'route'        => array('type' => 'text', 'required' => false, 'max_length' => 500, 'label' => 'Page or setup step asked from'),
			'consent'      => array('type' => 'object', 'required' => false, 'label' => 'Consent: {bundle, live_reads}'),
			'origin_nonce' => array('type' => 'text', 'required' => false, 'max_length' => 128, 'label' => 'One-time code the site issued'),
			'bundle'       => array('type' => 'object', 'required' => false, 'label' => 'What the site says about itself'),
		),
	);
}
