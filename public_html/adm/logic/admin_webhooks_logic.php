<?php
/**
 * Logic for the webhook list: every webhook receiver with its delivery
 * counts. See docs/webhooks.md.
 *
 * @version 1.0
 */

function admin_webhooks_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	$receivers = new MultiSignalReceiver(
		array('kind' => SignalReceiver::KIND_WEBHOOK),
		array('sgr_signal_receiver_id' => 'ASC')
	);
	$ids = array();
	foreach ($receivers as $receiver) {
		$ids[] = (int)$receiver->key;
	}

	return LogicResult::render(array(
		'session'   => $session,
		'settings'  => Globalvars::get_instance(),
		'receivers' => $receivers,
		'summaries' => SignalDelivery::receiver_summaries($ids),
	));
}
