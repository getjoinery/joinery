<?php
/**
 * Logic for the take-ownership page (/profile/server_manager/domain).
 *
 * Where a buyer whose domain we registered moves it into their own registrar
 * account. Three steps, and only the middle one is ours: they create a free
 * account, they tell us its name, and then they finish in their own dashboard.
 *
 * Submitting the account name is what queues the push — at Namecheap the
 * Change Ownership push has no API, so an operator performs it and the
 * pipeline watches for the domain to leave our account.
 *
 * The page is reachable at any time, not only inside the six-month prompt
 * window. A buyer who finds it early and graduates early is a good outcome;
 * only the notice on their box waits.
 *
 * @version 1.0
 */

function profile_domain_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));

	$self_url = '/profile/server_manager/domain';
	$self_regex = '/\/profile\/server_manager\/domain/';

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode($self_url));
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($input['action'] ?? '') === 'request_push') {
		$row = profile_domain_row_for_user((int)($input['rdm_id'] ?? 0), $user_id);
		if ($row === null) {
			return LogicResult::error('That domain is not one of yours.');
		}

		$username = trim((string)($input['ncp_username'] ?? ''));
		if ($username === '') {
			$session->save_message(new DisplayMessage(
				'Enter the username or email of the registrar account to move the domain into.',
				'Error', $self_regex, DisplayMessage::MESSAGE_ERROR,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect($self_url);
		}

		if ($row->get('rdm_graduation_state') === RegisteredDomain::GRAD_OPERATOR) {
			$row->set('rdm_ncp_username', mb_substr($username, 0, 128));
			$row->set('rdm_graduation_state', RegisteredDomain::GRAD_REQUESTED);
			$row->save();
			profile_domain_alert_operator($row);
		}

		$session->save_message(new DisplayMessage(
			'Thanks — we will send ' . htmlspecialchars($row->get('rdm_domain'))
				. ' to that account, usually within a day. Watch for the invitation email.',
			'Success', $self_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}

	$domains = new MultiRegisteredDomain(
		array('user_id' => $user_id, 'deleted' => false),
		array('rdm_id' => 'DESC'));
	$domains->load();

	return LogicResult::render(array(
		'session' => $session,
		'domains' => $domains,
	));
}

/** One of the signed-in user's own domain rows, or null. */
function profile_domain_row_for_user(int $rdm_id, int $user_id) {
	if ($rdm_id <= 0) {
		return null;
	}
	$row = new RegisteredDomain($rdm_id, TRUE);
	if (!$row->key || (int)$row->get('rdm_usr_user_id') !== $user_id
			|| $row->get('rdm_delete_time')) {
		return null;
	}
	return $row;
}

/**
 * Tell the operator a push is waiting. Sent once, on the transition — the
 * watcher never re-alerts, because a queue that emails every tick stops being
 * read.
 */
function profile_domain_alert_operator($row): void {
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));

	$to = ProvisionManagedDomains::resolve_alert_recipient();
	if ($to === '') {
		error_log('profile_domain_logic: no alert recipient for the push request on '
			. $row->get('rdm_domain'));
		return;
	}
	$body = "A buyer has asked for their domain to be handed over.\n\n"
		. 'Domain: ' . $row->get('rdm_domain') . "\n"
		. 'Their registrar account: ' . $row->get('rdm_ncp_username') . "\n"
		. 'Buyer: ' . $row->get('rdm_buyer_email') . "\n\n"
		. "In the registrar dashboard, open the domain and use Change Ownership to push it to that\n"
		. "account, then mark it sent on /admin/server_manager/domains. The pipeline notices on its\n"
		. "own once the domain actually leaves the account.\n";
	try {
		EmailSender::quickSend($to, '[managed-domain] Hand-over requested: ' . $row->get('rdm_domain'), $body);
	} catch (Throwable $e) {
		error_log('profile_domain_logic: push alert send failed: ' . $e->getMessage());
	}
}
