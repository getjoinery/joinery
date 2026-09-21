<?php
/**
 * Logic for the account's connected sites page (/profile/server_manager/services).
 *
 * (specs/services_phase2_platform.md §4, E6). Every site that holds a key
 * for this account: its host, when it was connected, and each service it
 * holds with the paid-through date, the figure and the state — the page the
 * site's status answer points at as manage_url. In this phase there is
 * nothing to buy here; the date is what the operator set.
 *
 * Disconnect is a POST: every service the site holds is released (mail's
 * subaccount closed; backup storage kept 90 days and then pruned) and the site's
 * key is deactivated. It is how the account holder cuts off a site they no
 * longer run, or one they were tricked into approving.
 *
 * @version 1.0
 */

function profile_services_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$self_url = '/profile/server_manager/services';
	$self_regex = '~/profile/server_manager/services~';
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode($self_url));
	}

	$is_post = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST');
	$action = $is_post ? (string)($input['action'] ?? '') : '';

	if ($action === 'disconnect') {
		$host = JoineryServices::cleanHost((string)($input['host'] ?? ''));
		try {
			$released = ServicesConnect::disconnect($user_id, $host);
			$session->save_message(new DisplayMessage(htmlspecialchars($host) . ' is disconnected: its key no longer works and '
				. $released . ' service(s) were released. Stored backups are kept ' . ServiceTenant::RETENTION_DAYS
				. ' days and then pruned.', 'Disconnected', $self_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		} catch (\Throwable $e) {
			$session->save_message(new DisplayMessage('Could not disconnect ' . htmlspecialchars($host) . ': '
				. htmlspecialchars($e->getMessage()), 'Error', $self_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect($self_url);
	}

	return LogicResult::render(array(
		'session'  => $session,
		'sites'    => ServicesConnect::sitesFor($user_id),
		'self_url' => $self_url,
	));
}
