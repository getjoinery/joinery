<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Secrets health — the acting surface for the secret.unreadable alert.
 *
 * Lists every stored secret that cannot be decrypted with this install's key and
 * carries the two actions: re-enter an operator credential (a link to where it is
 * entered), and acknowledge a destructive re-mint of a regenerable-breaks-things
 * secret (which unpairs devices / drops pinned peers, so it is confirmed). Also
 * offers "reconcile now" so an operator who just re-entered a credential can clear
 * its alert without waiting for the next update_database.
 *
 * When every secret opens this page is empty and silent — it exists to act on an
 * alert you were already sent, not to be patrolled.
 *
 * @version 1.0
 */
function admin_sealed_secrets_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/SecretReconciler.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array('settings' => $settings, 'session' => $session, 'flash' => null, 'flash_kind' => 'success');

	if (LibraryFunctions::isFormSubmission() && isset($input['action'])) {
		switch ($input['action']) {
			case 'reconcile':
				$r = SecretReconciler::reconcile(array('acting_user_id' => (int)$session->get_user_id()));
				$page_vars['flash'] = 'Reconciled — ' . $r['summary'] . '.';
				break;

			case 'ack_remint':
				$locator = (string)($input['locator'] ?? '');
				$ok = SecretReconciler::acknowledge_remint($locator, (int)$session->get_user_id());
				if ($ok) {
					// Refresh the verdict so the re-minted secret drops off the list.
					SecretReconciler::reconcile(array('acting_user_id' => (int)$session->get_user_id()));
					$page_vars['flash'] = 'Re-minted. Any devices or peers pinned to the old value must reconnect.';
				} else {
					$page_vars['flash'] = 'That secret could not be re-minted here.';
					$page_vars['flash_kind'] = 'danger';
				}
				break;

			default:
				$page_vars['flash'] = 'Unknown action.';
				$page_vars['flash_kind'] = 'danger';
		}
	}

	$page_vars['dead_items'] = SecretReconciler::dead_items();
	$page_vars['verdict'] = SecretReconciler::attention_verdict();
	return LogicResult::render($page_vars);
}
