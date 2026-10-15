<?php
/**
 * API action: support_state — what the Help panel draws before anything is
 * asked: where questions go, whether this site has said what it may send
 * (and, when it has not, the facts it would send, section by section), and
 * how many replies from a person are unread.
 *
 * POST /api/v1/action/support_state { route? }. Browser session, permission 5.
 * See support_chatbot.md, The site side.
 *
 * @version 1.0.0
 */
function support_state_logic(array $input): LogicResult {
	if ($refused = SupportPanel::guard()) {
		return $refused;
	}
	return LogicResult::render(SupportPanel::state(SessionControl::get_instance(), (string)($input['route'] ?? '')));
}

function support_state_logic_descriptor(): array {
	return array(
		'description' => 'The Help panel\'s starting state: destination, consent, the facts a first question would send, unread replies.',
		'input'       => array(
			'route' => array('type' => 'text', 'required' => false, 'max_length' => 500, 'label' => 'Page or setup step'),
		),
		'auth'        => array('capability' => null, 'requires_browser_session' => true, 'min_user_permission' => 5),
	);
}
