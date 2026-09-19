<?php
/**
 * Logic for the configure page (/profile/server_manager/configure).
 *
 * The one page where a buyer describes the Managed site they want: the domain
 * (theirs, or one we register for them), the site's name, where it lives, and
 * the admin email. Nothing about the site is asked in the cart.
 *
 * Two steps, so the cart is entered by the store's own path and nothing is
 * faked:
 *
 *   1. Save and continue — the form's submit. Validates the whole draft
 *      (ManagedSiteDraft::save_and_freeze), freezes it at pending_payment and
 *      renders the summary with step 2.
 *   2. Continue to payment — a single button posting the frozen draft's id at
 *      the Managed product's URL, where product_logic adds the line (and the
 *      domain-year line from the frozen quote) and sends the buyer to the cart.
 *
 * Edit on a frozen draft returns it to draft. The cart line, if still there,
 * is stale from that moment and the charge refuses it — the page says so
 * before the buyer confirms. Delete removes a draft; a paid site is never
 * deletable here.
 *
 * @version 1.1 - a visitor without a session goes to the start page (step 1, the account), and the
 *                page carries the step strip
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §4.1, §4.2
 */

function profile_configure_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedSiteDraft.php'));

	$self_url = ManagedSiteDraft::CONFIGURE_URL;
	$self_regex = '/\/profile\/server_manager\/configure/';

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		// Step 1 is the account. The start page says so and offers sign-in
		// and sign-up side by side; both land back here.
		return LogicResult::redirect(ManagedSiteDraft::START_URL);
	}
	$user = new User($user_id, TRUE);

	$is_post = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST');
	$action = $is_post ? (string)($input['action'] ?? '') : '';

	$row = null;
	$wanted = (int)($input['cvp_customer_cloud_provision_id'] ?? 0);
	if ($wanted > 0) {
		$row = ManagedSiteDraft::load_for_user($wanted, $user_id);
		if ($row === null) {
			$session->save_message(new DisplayMessage(
				'That site setup is not one of yours, or it is already paid for.',
				'Error', '/\/profile\/server_manager/', DisplayMessage::MESSAGE_ERROR,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect(ManagedSiteDraft::SITES_URL);
		}
	}

	// ---- Actions (POST only; a link never changes a draft) ----------------

	if ($action === 'edit' && $row !== null) {
		ManagedSiteDraft::unfreeze($row);
		return LogicResult::redirect($self_url . '?cvp_customer_cloud_provision_id=' . (int)$row->key);
	}

	if ($action === 'delete' && $row !== null) {
		ManagedSiteDraft::delete($row);
		$session->save_message(new DisplayMessage('The site setup for ' . htmlspecialchars($row->get('cvp_domain'))
			. ' has been removed.', 'Removed', '/\/profile\/server_manager/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect(ManagedSiteDraft::SITES_URL);
	}

	$errors = array();
	if ($action === 'save') {
		if ($row !== null && (string)$row->get('cvp_status') === 'pending_payment') {
			// A frozen row is edited through Edit, never by a re-submit that
			// slipped past the summary page.
			return LogicResult::redirect($self_url . '?cvp_customer_cloud_provision_id=' . (int)$row->key);
		}
		$result = ManagedSiteDraft::save_and_freeze($row, $input, $user);
		if (empty($result['errors'])) {
			return LogicResult::redirect($self_url . '?cvp_customer_cloud_provision_id=' . (int)$result['row']->key);
		}
		$errors = $result['errors'];
	}

	// ---- Render ------------------------------------------------------------

	$product = ManagedSiteDraft::managed_product();
	$mode = ($row !== null && (string)$row->get('cvp_status') === 'pending_payment') ? 'summary' : 'form';

	// The form shows what was posted when it failed, the row when it exists,
	// and the account's details for a brand-new draft.
	$values = ManagedSiteDraft::form_values($row, $user);
	if ($action === 'save') {
		foreach ($values as $field => $unused) {
			if (array_key_exists($field, $input)) {
				$values[$field] = (string)$input[$field];
			}
		}
		foreach (array_keys(ManagedDomainIntake::CONTACT_FIELDS) as $field) {
			if (array_key_exists($field, $input)) {
				$values[$field] = (string)$input[$field];
			}
		}
	}

	$regions = ManagedSiteDraft::regions();

	return LogicResult::render(array(
		'session'          => $session,
		'mode'             => $mode,
		'row'              => $row,
		'errors'           => $errors,
		'values'           => $values,
		'regions'          => $regions,
		'sellable'         => ManagedDomainIntake::sellable(),
		'offered_tlds'     => DomainRegistrarRegistry::offeredTldsPhrase(),
		'product'          => $product,
		'price_sentence'   => ManagedSiteDraft::price_sentence($product),
		'domain_sentence'  => $row ? ManagedSiteDraft::domain_sentence($row) : '',
		'payment_form'     => ($row && $mode === 'summary') ? ManagedSiteDraft::payment_form($row, $product) : '',
		'in_cart'          => ($row && $mode === 'summary') ? ManagedSiteDraft::in_cart($row) : false,
		'availability_js'  => ManagedDomainIntake::availabilityScript('cvp_domain', 'managed_domain_status',
			'cvp_domain_source', 'register'),
		// The form is step 2; the summary, whose one button is Continue to
		// payment, is the start of step 3.
		'steps_html'       => ManagedSiteDraft::steps_html($mode === 'summary' ? 3 : 2),
	));
}
