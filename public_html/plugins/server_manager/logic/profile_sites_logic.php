<?php
/**
 * Logic for the buyer's sites page (/profile/server_manager).
 *
 * Everything somebody who bought a site needs from us after the purchase: where
 * their site is, how far setup has got, and — once, and only once — the
 * password their admin account was born with.
 *
 * REVEALING THE PASSWORD ERASES IT IN THE SAME REQUEST (E7). It is shown on
 * this page rather than emailed because email is a copy that persists in
 * somebody else's system, and it is erased on reveal because a first password
 * that stays readable forever is a permanent second key to the site. The site
 * forces a password change at first login, so the value is spent the moment it
 * is used; and once the site's own outbound mail is working, its forgot-password
 * is the way back in for a buyer who lost the reveal.
 *
 * The Connect section belongs to bring-your-own-cloud provisions only. A hosted
 * buyer never connects anything, and offering them the button would be offering
 * them a step that does nothing.
 *
 * Before payment the page shows the buyer's drafts (specs/managed_hosting_phase1_purchase.md
 * §4.5): a draft they have not finished, and a frozen one waiting in the cart.
 * Edit and Delete are POSTs handled here; Continue is the configure page. After
 * payment, a domain the registrar found taken offers an alternate name on the
 * same card (§7), and submitting one returns the registration to the queue
 * under the same paid-line guard.
 *
 * @version 1.1 - the pre-payment cards (draft, pending_payment) with Edit/Delete, and the taken-name
 *                alternate action (specs/managed_hosting_phase1_purchase.md §4.5, §7)
 * @version 1.0
 */

function profile_sites_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_accounts_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trials_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedSiteDraft.php'));

	$self_url = ManagedSiteDraft::SITES_URL;
	$self_regex = '/\/profile\/server_manager/';

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode($self_url));
	}

	$is_post = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST');
	$action = $is_post ? (string)($input['action'] ?? '') : '';

	// ---- Draft actions: a POST, never a link ------------------------------
	if ($action === 'edit_draft' || $action === 'delete_draft') {
		$draft = ManagedSiteDraft::load_for_user((int)($input['cvp_customer_cloud_provision_id'] ?? 0), $user_id);
		if ($draft === null) {
			$session->save_message(new DisplayMessage('That site setup is not one of yours, or it is already paid for.',
				'Error', $self_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect($self_url);
		}
		if ($action === 'edit_draft') {
			ManagedSiteDraft::unfreeze($draft);
			return LogicResult::redirect(ManagedSiteDraft::CONFIGURE_URL
				. '?cvp_customer_cloud_provision_id=' . (int)$draft->key);
		}
		ManagedSiteDraft::delete($draft);
		$session->save_message(new DisplayMessage('The site setup for ' . htmlspecialchars($draft->get('cvp_domain'))
			. ' has been removed.', 'Removed', $self_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}

	// ---- The taken name: the buyer chooses another --------------------------
	if ($action === 'alternate_domain') {
		$message = profile_sites_take_alternate($input, $user_id);
		$session->save_message(new DisplayMessage($message['text'], $message['ok'] ? 'Thanks' : 'Error', $self_regex,
			$message['ok'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}

	$provisions = new MultiCustomerCloudProvision(
		array('user_id' => $user_id, 'deleted' => false), array('cvp_customer_cloud_provision_id' => 'DESC'));
	$provisions->load();

	// The reveal. A POST, and the method is checked rather than assumed.
	//
	// A browser performs a GET whenever it is told to, including by another
	// site, and SameSite=Lax sends the session cookie on a top-level cross-site
	// GET. A link or a prefetch carrying this buyer's cvp_customer_cloud_provision_id would then BURN
	// their one-time password: the attacker sees nothing, and the buyer loses
	// the only copy. A cross-site POST gets no cookie at all.
	$revealed = '';
	$revealed_domain = '';
	$error = '';
	if (($input['action'] ?? '') === 'reveal_password' && !$is_post) {
		$error = 'Showing a password is an action, not a link. Use the button on this page.';
	} elseif (($input['action'] ?? '') === 'reveal_password') {
		$wanted = (int)($input['cvp_customer_cloud_provision_id'] ?? 0);
		$target = null;
		foreach ($provisions as $provision) {
			// Matched inside the buyer's OWN list, so an id from somebody
			// else's site finds nothing rather than being checked and refused.
			if ((int)$provision->key === $wanted) { $target = $provision; break; }
		}
		if ($target === null) {
			$error = 'That site is not one of yours.';
		} elseif ((string)$target->get('cvp_status') !== 'done') {
			// Sealed but not yet applied. Revealing here spends the one copy on
			// a password the machine may never receive: an install that fails
			// and is retried installs WITHOUT one (holds_admin_password is false
			// after a reveal), leaving the buyer holding a password that opens
			// nothing and a forgot-password link that needs mail the site has
			// not got yet.
			$error = 'Your site is still being set up. The password will be here when it is ready.';
		} elseif ($target->admin_password_state() !== 'sealed') {
			$error = 'That password has already been shown once. Use the Forgot password link on '
				. 'your own site to set a new one.';
		} else {
			$opened = (new SecretBox())->open((string)$target->get('cvp_admin_pass_sealed'));
			if ($opened['state'] !== 'ok') {
				$error = 'That password can no longer be read. Use the Forgot password link on your '
					. 'own site to set a new one.';
			} else {
				$revealed = (string)$opened['value'];
				$revealed_domain = (string)$target->get('cvp_domain');
				// Erased in the SAME request that shows it. If the buyer never
				// reads what is on their screen, that is a password reset away;
				// a first password that stays readable is a second key.
				$target->set('cvp_admin_pass_sealed', null);
				$target->set('cvp_admin_pass_revealed_time', gmdate('Y-m-d H:i:s'));
				$target->save();
			}
		}
	}

	// One row per site, with everything the page renders already resolved —
	// the view asks no questions of its own.
	$product = ManagedSiteDraft::managed_product();
	$sites = array();
	$needs_connect = false;
	$in_progress = false;
	foreach ($provisions as $provision) {
		$hosted = $provision->is_operator_hosted();
		$status = (string)$provision->get('cvp_status');
		if (in_array($status, array('ready', 'booting', 'installing'), true)) {
			$in_progress = true;
		}
		if (!$hosted && $status === 'pending_connect') {
			$needs_connect = true;
		}
		$trial = ($hosted && !$provision->is_pre_payment()) ? HostedTrial::for_provision($provision->key) : null;

		// A domain we could not register because the name was taken after
		// payment: the card offers an alternate.
		$taken = null;
		$order_item_id = (int)$provision->get('cvp_external_order_item_id');
		if ($order_item_id > 0 && (string)$provision->get('cvp_domain_source') === 'register') {
			$rows = new MultiRegisteredDomain(array('external_order_item_id' => $order_item_id, 'deleted' => false));
			foreach ($rows as $rdm) {
				if ($rdm->offers_alternate()) {
					$taken = array('rdm_id' => (int)$rdm->key, 'domain' => (string)$rdm->get('rdm_domain'),
						'paid' => ManagedSiteDraft::money((string)$rdm->get('rdm_price_paid')));
				}
			}
		}

		$sites[] = array(
			'id'              => (int)$provision->key,
			'domain'          => (string)$provision->get('cvp_domain'),
			'url'             => 'https://' . (string)$provision->get('cvp_domain'),
			'hosted'          => $hosted,
			'status'          => $status,
			'pre_payment'     => $provision->is_pre_payment(),
			'summary'         => $provision->is_pre_payment() ? profile_sites_draft_summary($provision, $product) : '',
			'payment_form'    => ($status === 'pending_payment' && !ManagedSiteDraft::in_cart($provision))
				? ManagedSiteDraft::payment_form($provision, $product, 'Finish payment') : '',
			'in_cart'         => $status === 'pending_payment' && ManagedSiteDraft::in_cart($provision),
			'taken'           => $taken,
			// Offered only for a site that exists: a button that spends a
			// one-time secret must not be reachable before the secret means
			// anything.
			'password_state'  => ($status === 'done' || $provision->admin_password_state() === 'revealed')
				? $provision->admin_password_state() : 'pending',
			'mail_state'      => (string)$provision->get('cvp_mail_state'),
			'plan_state'      => $trial ? (string)$trial->get('htr_state') : '',
			'plan_until'      => $trial ? (string)($trial->get('htr_state') === HostedTrial::STATE_GRACE
				? $trial->get('htr_grace_ends_time') : $trial->get('htr_trial_ends_time')) : '',
		);
	}

	$account = CustomerCloudAccount::get_for_user($user_id, 'linode');

	return LogicResult::render(array(
		'session'           => $session,
		'sites'             => $sites,
		'revealed'          => $revealed,
		'revealed_domain'   => $revealed_domain,
		'error'             => $error,
		'needs_connect'     => $needs_connect,
		'in_progress'       => $in_progress,
		'account_connected' => $account !== null && $account->get('cca_status') === 'active',
		'managed_on_sale'   => $product !== null,
		'configure_url'     => ManagedSiteDraft::CONFIGURE_URL,
		'availability_js'   => ManagedDomainIntake::availabilityScript('alternate_domain', 'alternate_domain_status'),
	));
}

/** One line describing a draft: the domain, and what the buyer will pay. */
function profile_sites_draft_summary($provision, $product): string {
	$parts = array();
	if ((string)$provision->get('cvp_domain_source') === 'register') {
		$parts[] = 'we register the domain (' . ManagedSiteDraft::money((string)$provision->get('cvp_domain_quote'))
			. ' for the first year)';
	} else {
		$parts[] = 'your own domain';
	}
	$price = ManagedSiteDraft::price_sentence($product);
	if ($price !== '') {
		$parts[] = 'hosting ' . $price;
	}
	return ucfirst(implode('; ', $parts)) . '.';
}

/**
 * The buyer's alternate for a name that was taken after they paid.
 *
 * Checked the way the first name was — shape, ending, live availability —
 * plus one more rule: the paid amount must cover it. The registration phase
 * compares the paid line with the recorded price and would park a dearer
 * name for a person anyway; refusing it here tells the buyer while they can
 * still choose again. Then the row returns to pending and the next tick
 * registers it. The provision's own name follows while no box exists yet;
 * once one does, the operator is told to rename it by hand.
 *
 * @return array{ok:bool, text:string}
 */
function profile_sites_take_alternate(array $input, int $user_id): array {
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedDomainIntake.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));

	$rdm_id = (int)($input['rdm_registered_domain_id'] ?? 0);
	$rdm = $rdm_id > 0 ? new RegisteredDomain($rdm_id, TRUE) : null;
	if ($rdm === null || !$rdm->key || $rdm->get('rdm_delete_time')
			|| (int)$rdm->get('rdm_usr_user_id') !== $user_id || !$rdm->offers_alternate()) {
		return array('ok' => false, 'text' => 'That domain is not one of yours, or it is not waiting for another name.');
	}

	$name = ManagedDomainIntake::checkName((string)($input['alternate_domain'] ?? ''));
	if ($name['error'] !== '') {
		return array('ok' => false, 'text' => $name['error']);
	}
	$domain = $name['domain'];

	$quote = ManagedDomainIntake::quote($domain);
	if ($quote['error'] !== '') {
		return array('ok' => false, 'text' => $quote['error']);
	}
	$paid = (float)$rdm->get('rdm_price_paid');
	if ($paid > 0 && (float)$quote['price'] > $paid + 0.001) {
		return array('ok' => false, 'text' => htmlspecialchars($domain) . ' costs '
			. ManagedSiteDraft::money($quote['price']) . ' for the year, more than the '
			. ManagedSiteDraft::money((string)$paid) . ' you paid. Choose a name at that price or less.');
	}

	$queued = new MultiRegisteredDomain(array('domain' => $domain));
	foreach ($queued as $other) {
		if ((int)$other->key !== (int)$rdm->key) {
			return array('ok' => false, 'text' => htmlspecialchars($domain) . ' is already spoken for here. Try another.');
		}
	}

	$previous = (string)$rdm->get('rdm_domain');
	$rdm->take_alternate($domain);

	// The site's own name. Before the box exists it simply follows; after, a
	// rename is a person's job and the operator is told.
	$provisions = new MultiCustomerCloudProvision(array(
		'external_order_item_id' => (int)$rdm->get('rdm_external_order_item_id'), 'deleted' => false));
	foreach ($provisions as $provision) {
		$slug = ManagedSiteDraft::slug_for($domain);
		$no_box = in_array((string)$provision->get('cvp_status'), array('ready', 'pending_connect'), true)
			&& !(int)$provision->get('cvp_mgn_managed_node_id') && trim((string)$provision->get('cvp_instance_id')) === '';
		if ($no_box && CustomerCloudProvision::held_by_live($domain, $slug, (int)$provision->key) === null) {
			$provision->set('cvp_domain', $domain);
			$provision->set('cvp_slug', $slug);
			$provision->save();
		} else {
			profile_sites_alert_rename($provision, $previous, $domain);
		}
	}

	return array('ok' => true, 'text' => 'Thanks — we will register ' . htmlspecialchars($domain)
		. ' instead. It usually takes a few minutes.');
}

/** The box already exists under the old name: an operator renames it by hand. */
function profile_sites_alert_rename($provision, string $previous, string $domain): void {
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	$to = ProvisionManagedDomains::resolve_alert_recipient();
	if ($to === '') {
		error_log('profile_sites_logic: no alert recipient for the rename of provision #' . $provision->key);
		return;
	}
	$body = "A buyer chose an alternate domain after their first one was taken, and their site already exists.\n\n"
		. 'Provision: #' . $provision->key . ' (status ' . $provision->get('cvp_status') . ")\n"
		. 'Was: ' . $previous . "\n"
		. 'Now: ' . $domain . "\n"
		. 'Buyer: ' . $provision->get('cvp_buyer_email') . "\n\n"
		. "The registration row is back in the queue under the new name and will register on its own. The\n"
		. "site's own domain (the node's site URL, its certificate, its mail domain) still says the old name\n"
		. "and has to be renamed by hand.\n";
	try {
		EmailSender::quickSend($to, '[managed-hosting] Rename needed: ' . $previous . ' -> ' . $domain, $body);
	} catch (Throwable $e) {
		error_log('profile_sites_logic: rename alert failed: ' . $e->getMessage());
	}
}
