<?php
/**
 * Logic for one webhook: its settings, its signing secret, its deliveries,
 * and the actions on them (send a test, pause or resume, make a new secret,
 * send a given-up delivery again, delete). See docs/webhooks.md.
 *
 * @version 1.0
 */

function admin_webhook_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	$receiver = new SignalReceiver((int)($input['sgr_signal_receiver_id'] ?? 0), TRUE);
	if (!$receiver->key || $receiver->get('sgr_kind') !== SignalReceiver::KIND_WEBHOOK) {
		return LogicResult::error('That webhook does not exist.');
	}
	$id = (int)$receiver->key;
	$here = '/admin/admin_webhook?sgr_signal_receiver_id=' . $id;
	if (!empty($input['filter'])) {
		$here .= '&filter=' . urlencode((string)$input['filter']);
	}

	if (LibraryFunctions::isFormSubmission() && isset($input['action'])) {
		$flash = function ($text, $type) use ($session) {
			$session->save_message(new DisplayMessage($text, '', NULL, $type, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		};

		switch ((string)$input['action']) {
			case 'test':
				$outcome = SignalDeliveryQueue::send_test($receiver);
				$flash('Test message: ' . $outcome['message'], $outcome['result'] === 'delivered'
					? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_WARNING);
				return LogicResult::redirect($here);

			case 'pause':
			case 'resume':
				$receiver->set('sgr_is_active', $input['action'] === 'resume');
				$receiver->save();
				$flash($input['action'] === 'resume'
					? 'Resumed. Waiting deliveries go out on the next run.'
					: 'Paused. Nothing new is queued for it, and waiting deliveries hold until it is resumed.',
					DisplayMessage::MESSAGE_ANNOUNCEMENT);
				return LogicResult::redirect($here);

			case 'new_secret':
				$receiver->new_signing_secret();
				$receiver->save();
				$flash('New signing secret made. Messages are signed with it from now on; '
					. 'put it in the receiving app.', DisplayMessage::MESSAGE_ANNOUNCEMENT);
				return LogicResult::redirect($here);

			case 'retry':
				$delivery = new SignalDelivery((int)($input['sgd_signal_delivery_id'] ?? 0), TRUE);
				if (!$delivery->key || (int)$delivery->get('sgd_sgr_signal_receiver_id') !== $id) {
					return LogicResult::error('That delivery does not belong to this webhook.');
				}
				try {
					$delivery->retry_now();
					$flash($receiver->get('sgr_is_active')
						? 'Queued again; it goes out on the next run.'
						: 'Queued again; it goes out once this webhook is resumed.',
						DisplayMessage::MESSAGE_ANNOUNCEMENT);
				} catch (SignalDeliveryException $e) {
					$flash($e->getMessage(), DisplayMessage::MESSAGE_WARNING);
				}
				return LogicResult::redirect($here);

			case 'delete':
				$receiver->permanent_delete();
				$flash('Webhook deleted, with its deliveries.', DisplayMessage::MESSAGE_ANNOUNCEMENT);
				return LogicResult::redirect('/admin/admin_webhooks');
		}
	}

	$statuses = array(
		'All'       => '',
		'Waiting'   => SignalDelivery::STATUS_PENDING,
		'Delivered' => SignalDelivery::STATUS_DELIVERED,
		'Gave up'   => SignalDelivery::STATUS_FAILED,
		'Withheld'  => SignalDelivery::STATUS_WITHHELD,
	);
	$filter = (string)LibraryFunctions::fetch_variable_local($input, 'filter', '');
	if (!in_array($filter, $statuses, true)) {
		$filter = '';
	}
	$criteria = array('receiver_id' => $id);
	if ($filter !== '') {
		$criteria['status'] = $filter;
	}

	$numperpage = 30;
	$offset = max(0, (int)LibraryFunctions::fetch_variable_local($input, 'offset', 0));
	$deliveries = new MultiSignalDelivery($criteria, array('sgd_signal_delivery_id' => 'DESC'), $numperpage, $offset);

	return LogicResult::render(array(
		'session'    => $session,
		'settings'   => Globalvars::get_instance(),
		'receiver'   => $receiver,
		'secret'     => $receiver->signing_secret(),
		'catalog'    => SignalBus::signals(),
		'here'       => $here,
		'filter'     => $filter,
		'statuses'   => $statuses,
		'deliveries' => $deliveries,
		'numrecords' => $deliveries->count_all(),
		'numperpage' => $numperpage,
	));
}
