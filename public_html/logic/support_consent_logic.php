<?php
/**
 * API action: support_consent — what the Help panel may send about this site.
 *
 * POST { bundle: yes|no, live_reads?: yes|no }. bundle is asked once in the
 * panel, of any administrator who opens it; live_reads (reads of this site by
 * a management node it is joined to, during a thread) takes permission 9.
 * Both are changeable on the Management Node page.
 *
 * @version 1.0.0
 */
function support_consent_logic(array $input): LogicResult {
	if ($refused = SupportPanel::guard()) {
		return $refused;
	}
	$session = SessionControl::get_instance();
	$bundle = (string)($input['bundle'] ?? '');
	if (!in_array($bundle, array('yes', 'no'), true)) {
		return LogicResult::error('Say yes or no.');
	}
	Setting::put('support_chat_consent', $bundle);
	if ($bundle === 'no') {
		Setting::put('support_chat_live_reads', 'no');
	} elseif (isset($input['live_reads']) && Access::decide(SupportChatClient::LIVE_READS_PERMISSION, $session)) {
		Setting::put('support_chat_live_reads', (string)$input['live_reads'] === 'yes' ? 'yes' : 'no');
	}
	Globalvars::get_instance()->forget_setting('support_chat_consent');
	Globalvars::get_instance()->forget_setting('support_chat_live_reads');
	return LogicResult::render(array('consent' => SupportChatClient::consent()));
}

function support_consent_logic_descriptor(): array {
	return array(
		'description' => 'Record what the Help panel may send about this site, and whether a joined management node may read it during a thread.',
		'mutates'     => true,
		'input'       => array(
			'bundle'     => array('type' => 'text', 'required' => true, 'max_length' => 3, 'label' => 'yes or no'),
			'live_reads' => array('type' => 'text', 'required' => false, 'max_length' => 3, 'label' => 'yes or no'),
		),
		'auth'        => array('requires_browser_session' => true, 'min_user_permission' => 5),
	);
}
