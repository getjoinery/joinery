<?php
/**
 * admin_domains_logic - the operator's managed-domain queue.
 *
 * Two things need a person here and nothing else does: a hand-over waiting to
 * be pushed in the registrar dashboard (the push has no API), and a
 * registration that failed terminally and needs a decision about the buyer.
 * Everything else on this page is read-only reassurance.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));

/** How much of the ledger one page shows. */
const ADMIN_DOMAINS_LEDGER_LIMIT = 200;

function admin_domains_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_url = '/admin/server_manager/domains';
	$page_regex = '/\/admin\/server_manager\/domains/';

	// Actions run before any display data is read, and always redirect, so a
	// refresh never repeats one.
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$action = (string)($input['action'] ?? '');
		$row = null;
		if ((int)($input['rdm_id'] ?? 0) > 0) {
			$row = new RegisteredDomain((int)$input['rdm_id'], TRUE);
			if (!$row->key) { $row = null; }
		}

		$message = null;
		$error = null;
		if ($row === null) {
			$error = 'That domain row no longer exists.';
		} elseif ($action === 'mark_pushed') {
			$row->set('rdm_graduation_state', RegisteredDomain::GRAD_SENT);
			$row->save();
			$message = $row->get('rdm_domain') . ' is marked as pushed. The pipeline confirms it '
				. 'automatically once the domain actually leaves the account.';
		} elseif ($action === 'retry') {
			// Back to the start of the fulfillment axis. The step timestamps
			// are left alone: whatever already succeeded stays done.
			$row->set('rdm_status', RegisteredDomain::STATUS_PENDING);
			$row->set('rdm_error', null);
			$row->save();
			$message = $row->get('rdm_domain') . ' is queued again.';
		} else {
			$error = 'Unknown action.';
		}

		if ($message) {
			$session->save_message(new DisplayMessage($message, 'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		if ($error) {
			$session->save_message(new DisplayMessage($error, 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect($page_url);
	}

	$pending_pushes = new MultiRegisteredDomain(
		array('graduation_state' => RegisteredDomain::GRAD_REQUESTED, 'deleted' => false),
		array('rdm_id' => 'ASC'));
	$pending_pushes->load();

	$failures = new MultiRegisteredDomain(
		array('status' => RegisteredDomain::STATUS_FAILED, 'deleted' => false),
		array('rdm_id' => 'DESC'));
	$failures->load();

	// Newest first, capped: the two tables above are the working surfaces, and
	// this one is a ledger nobody reads past the first screen of. The count is
	// shown separately so a capped page says so rather than looking complete.
	$all = new MultiRegisteredDomain(
		array('deleted' => false), array('rdm_id' => 'DESC'), ADMIN_DOMAINS_LEDGER_LIMIT, 0);
	$total = (int)$all->count_all();
	$all->load();

	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedDomainRequirement.php'));
	$registrar = DomainRegistrarRegistry::firstConfigured();

	return LogicResult::render(array(
		'session'          => $session,
		'pending_pushes'   => $pending_pushes,
		'failures'         => $failures,
		'all_domains'      => $all,
		'total_domains'    => $total,
		'ledger_limit'     => ADMIN_DOMAINS_LEDGER_LIMIT,
		'registrar_label'  => $registrar ? $registrar::getLabel() : '',
		'registrar_ready'  => $registrar !== null,
		'product_ready'    => ManagedDomainRequirement::domainProductSellable(),
		'offered_tlds'     => DomainRegistrarRegistry::offeredTldsPhrase(),
	));
}
