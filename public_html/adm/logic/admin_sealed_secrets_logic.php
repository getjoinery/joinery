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
 * It also holds the replace-after-a-break-in list (SecretReplacementList):
 * open it, re-mint or re-enter each kind of stored secret, mark it done, and
 * close it once everything is.
 *
 * @version 1.1 - the replace-after-a-break-in list (specs/security_weak_spots_2026_11.md WP6)
 * @version 1.0
 */
function admin_sealed_secrets_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/SecretReconciler.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();

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

			case 'list_start':
				SecretReplacementList::start((int)$session->get_user_id());
				$page_vars['flash'] = 'Replacement list opened. Work through it, marking each kind done as you replace it.';
				break;

			case 'list_mark':
				$why = SecretReplacementList::mark((string)($input['locator'] ?? ''), !empty($input['done']),
					(int)$session->get_user_id(), null, (string)($input['reason'] ?? ''));
				if ($why !== '') {
					$page_vars['flash'] = $why;
					$page_vars['flash_kind'] = 'danger';
				}
				break;

			case 'list_remint':
				// Re-mint in place, then mark it done: the list's own action for a
				// kind the machine can make again.
				$locator = (string)($input['locator'] ?? '');
				if (!SecretReconciler::can_write_now()) {
					$page_vars['flash'] = 'This request has opened sealed content, so it cannot write a new secret. '
						. 'Reload the page and press the button again.';
					$page_vars['flash_kind'] = 'danger';
				} elseif (SecretReplacementList::current() !== null
						&& SecretReconciler::acknowledge_remint($locator, (int)$session->get_user_id())) {
					$why = SecretReplacementList::mark($locator, true, (int)$session->get_user_id());
					$page_vars['flash'] = 'Replaced with a new one' . ($why === '' ? ', and marked done.' : '. ' . $why);
				} else {
					$page_vars['flash'] = 'No new value was stored, so nothing was replaced. Replace it on its feature\'s page.';
					$page_vars['flash_kind'] = 'danger';
				}
				break;

			case 'list_finish':
				if (SecretReplacementList::finish()) {
					$page_vars['flash'] = 'Replacement list closed: every stored secret has been replaced.';
				} else {
					$page_vars['flash'] = 'The list stays open until every item is marked done.';
					$page_vars['flash_kind'] = 'danger';
				}
				break;

			default:
				$page_vars['flash'] = 'Unknown action.';
				$page_vars['flash_kind'] = 'danger';
		}
	}

	$page_vars['dead_items'] = SecretReconciler::dead_items();
	$page_vars['replacement_list'] = SecretReplacementList::current();
	$page_vars['replacement_items'] = $page_vars['replacement_list'] !== null ? SecretReplacementList::items() : array();
	$page_vars['verdict'] = SecretReconciler::attention_verdict();
	return LogicResult::render($page_vars);
}
