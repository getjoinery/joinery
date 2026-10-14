<?php
/**
 * Logic for adding or editing a webhook: its name, URL, the signals it
 * receives, and whether it is active. A new webhook gets its signing secret
 * here. See docs/webhooks.md.
 *
 * @version 1.0
 */

function admin_webhook_edit_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$id = (int)($input['edit_primary_key_value'] ?? $input['sgr_signal_receiver_id'] ?? 0);
	if ($id > 0) {
		$receiver = new SignalReceiver($id, TRUE);
		if (!$receiver->key || $receiver->get('sgr_kind') !== SignalReceiver::KIND_WEBHOOK) {
			return LogicResult::error('That webhook does not exist.');
		}
	} else {
		$receiver = new SignalReceiver(NULL);
		$receiver->set('sgr_kind', SignalReceiver::KIND_WEBHOOK);
		$receiver->set('sgr_is_active', true);
		$receiver->set('sgr_signals', array(SignalReceiver::ALL_SIGNALS));
	}

	$catalog = SignalBus::signals();

	$page_vars = array(
		'session'  => $session,
		'settings' => Globalvars::get_instance(),
		'receiver' => $receiver,
		'catalog'  => $catalog,
	);

	if (LibraryFunctions::isFormSubmission()) {
		$is_new = !$receiver->key;

		if (($input['send'] ?? 'all') === 'all') {
			$signals = array(SignalReceiver::ALL_SIGNALS);
		} else {
			$chosen = isset($input['signals']) && is_array($input['signals']) ? $input['signals'] : array();
			// Keep a name already saved even when its plugin is off right now,
			// so saving this form never drops it silently.
			$known = array_merge(array_keys($catalog), $receiver->signals());
			$signals = array_values(array_unique(array_filter($chosen, function ($s) use ($known) {
				return is_string($s) && $s !== SignalReceiver::ALL_SIGNALS && in_array($s, $known, true);
			})));
		}

		$receiver->set('sgr_name', trim((string)($input['sgr_name'] ?? '')));
		$receiver->set('sgr_url', trim((string)($input['sgr_url'] ?? '')));
		$receiver->set('sgr_signals', $signals);
		$receiver->set('sgr_is_active', !empty($input['sgr_is_active']));

		try {
			$receiver->prepare();
			if ($is_new) {
				$receiver->new_signing_secret();
			}
			$receiver->save();
		} catch (DisplayableErrorMessage $e) {
			return LogicResult::error($e->getMessage(), $page_vars);
		}
		SignalDeliveryQueue::reset_cache();

		$session->save_message(new DisplayMessage(
			$is_new ? 'Webhook added. Put its signing secret, below, in the receiving app.' : 'Saved.',
			'', NULL, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect('/admin/admin_webhook?sgr_signal_receiver_id=' . (int)$receiver->key);
	}

	return LogicResult::render($page_vars);
}
