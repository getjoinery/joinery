<?php
/**
 * API action: support_origin_confirm — the support desk asks whether a new
 * thread's first question came from this site.
 *
 * POST { nonce } from the desk's server. Sessionless: the desk has no account
 * here. Answers {confirmed, destination, version}: confirmed only for a code
 * this site's Help panel issued in the last five minutes and has not used
 * (used here, so a code confirms once), with the desk it was issued for; the
 * desk refuses a code issued for another. Says nothing else about the site.
 *
 * No rate limit of its own: a code is 192 random bits, so there
 * is nothing to guess, and a miss is one indexed UPDATE. A limit keyed to the
 * caller would be keyed to the desk, and anyone who asks the desk to call
 * back with made-up codes could spend it and lock the real desk out.
 * See SupportChatClient and support_chatbot.md, Trust posture.
 *
 * @version 1.1.0
 */
function support_origin_confirm_logic(array $input): LogicResult {
	$destination = SiteSupportThread::consumeNonce((string)($input['nonce'] ?? ''));
	return LogicResult::render(array(
		'confirmed'   => $destination !== null,
		'destination' => (string)$destination,
		'version'     => $destination !== null ? (string)LibraryFunctions::get_joinery_version() : '',
	));
}

function support_origin_confirm_logic_descriptor(): array {
	return array(
		'description'      => 'Confirm to a support desk that a new thread\'s first question came from this site.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => array(
			'nonce' => array('type' => 'text', 'required' => true, 'max_length' => 128, 'label' => 'The one-time code'),
		),
	);
}
