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
 * A Managed site can move to its buyer's own Linode account
 * (specs/managed_to_self_hosted_transfer.md §6): each such site carries where
 * its move stands, and the card's buttons call the transfer_customer API
 * action. After the move the site is no longer hosted, so its plan and mail
 * lines go and the move's card tells the whole story, with "Stop managing this
 * site" once the customer wants us gone.
 *
 * @version 1.26 - let_go_server cancels a bundle bought for the server's site; joined_servers carries bundle_id
 * @version 1.25 - a server the buyer already runs: add_server, claim_server (?claim= from the site's Management Node
 *   page) and let_go_server (JoinedServers)
 * @version 1.24 - remove_site: a buyer stops our management of a site on their own server, or removes an order that
 *   never built and cost nothing (SiteRemoval; walkthrough Q36)
 * @version 1.23 - provider_refusal: the provider's words when the buyer's own account would not make the server;
 *   an expired Linode grant is not called connected
 * @version 1.22 - a buyer's own-domain records are listed once, whole, with the site's own (Joinery Direct among them);
 *                dns_pending while the list is held (ProvisionBuyerDns, walkthrough Q16)
 * @version 1.21 - a built site reads Finishing setup, with no password to show, until it has been told what it comes with
 *                (SmHosting::site_told; walkthrough Q4)
 * @version 1.20 - a setup only partly in the cart is dropped and offered for payment again (ManagedSiteDraft::in_cart_whole)
 * @version 1.19 - each site's licence line (BusinessLicense::site_line, hosting_after_purchase WP7)
 * @version 1.18 - each paid site links where it is managed (subscription, billing, domain, email and backups) and
 *                its hosting row names the price (hosting_after_purchase N2, S1)
 * @version 1.17 - the hosting row is one sentence per plan state with the date that matters: renews, cancelled to a
 *                date, payment needed by, shut down, moved (hosting_after_purchase P2, S3)
 * @version 1.16 - reads the paying line through CustomerCloudProvision::local_order_item(), never another store's id (hosting_after_purchase B11)
 * @version 1.15 - an own-Linode draft is paid for like any other; its Linode is connected after payment
 * @version 1.14 - an own-Linode draft without a live Linode grant sends its buyer to Connect on the setup (needs_linode)
 * @version 1.13 - a site built on its buyer's own Linode is described like a hosted one (profile_sites_we_built):
 *                its records, and its email only with the bundle; own_linode and with_services for its card
 * @version 1.12 - the box's write receipts are saved on the buyer's-own-site rule (rule_checked_write): a member was refused
 * @version 1.11 - the publish box (dns_box) adds a buyer's own-domain records through their DNS host; aaaa_record
 *                 lists the optional IPv6 record while the server has one the name does not answer with
 * @version 1.10 - dmarc_record: the optional DMARC record while the buyer's domain has none (hosting_paid_launch B4)
 * @version 1.9 - suspended: an open suspension's reason, for the customer (HostedSuspensions, hosting_launch S6)
 * @version 1.8 - mail_records lists a Starter site's inbound records (MX, ownership TXT) on the buyer's own domain (S5)
 * @version 1.7 - lists only rows that hold a site: a starter host or relay shard made by an admin is not one of their sites
 * @version 1.6 - a draft's price and payment button are its own plan's product; the page offers a new site while any plan is on sale
 * @version 1.5 - mail_records: a Managed site on the buyer's own domain lists the email records SMTP2GO
 *                asked for that do not resolve yet, so its mail can be signed (B3)
 * @version 1.4 - dns_record: a built Managed site on the buyer's own domain shows its A or AAAA record until the
 *                certificate issues or the name resolves to the server
 * @version 1.3 - the move card asks CustomerCloudProvision::is_sold()
 * @version 1.2 - the move-to-your-own-Linode card's state per site
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
	require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeDnsPlan.php'));

	$self_url = ManagedSiteDraft::SITES_URL;
	$self_regex = '/\/profile\/server_manager/';

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	// A site's Management Node page sends its owner here with the fingerprint
	// of the join it just filed (?claim=); it survives the sign-in.
	$claim = strtolower(preg_replace('/[^0-9a-f]/i', '', (string)($input['claim'] ?? '')));
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode($self_url . ($claim !== '' ? '?claim=' . $claim : '')));
	}

	$is_post = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST');
	$action = $is_post ? (string)($input['action'] ?? '') : '';

	// ---- Send the email confirmation link again (walkthrough Q6) -----------
	if ($action === 'resend_confirmation') {
		$me = new User($user_id, TRUE);
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		if (!SmHosting::email_unconfirmed($me)) {
			$text = 'Your email address is already confirmed.';
			$kind = DisplayMessage::MESSAGE_ANNOUNCEMENT;
		} elseif (!RequestLogger::check_attempt_limit('resend_confirmation', 3, 900, NULL)) {
			$text = 'We have sent that link a few times already. Check your spam folder, or try again in a few minutes.';
			$kind = DisplayMessage::MESSAGE_WARNING;
		} else {
			RequestLogger::log('resend_confirmation', 'resend_confirmation', TRUE);
			Activation::email_activate_send($me, TRUE);
			$text = 'We sent the link again to ' . htmlspecialchars((string)$me->get('usr_email')) . '.';
			$kind = DisplayMessage::MESSAGE_ANNOUNCEMENT;
		}
		$session->save_message(new DisplayMessage($text, 'Confirm your email', $self_regex, $kind,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}

	// ---- A server the buyer already runs: add, claim, let go (JoinedServers) ---
	if ($action === 'add_server') {
		$go = JoinedServers::site_connect_url((string)($input['server_address'] ?? ''));
		if ($go === '') {
			$session->save_message(new DisplayMessage('That does not look like a site address. Enter the address you open your site at, like example.com.',
				'Add a server', $self_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect($self_url);
		}
		// Shown as a link on this page, not sent as a redirect: the page's
		// script policy lets a form's answer go nowhere but this site.
		return LogicResult::redirect($self_url . '?server=' . rawurlencode((string)parse_url($go, PHP_URL_HOST)));
	}
	if ($action === 'claim_server') {
		// The request came from a machine whose own admin sent this buyer here
		// with its fingerprint: claiming it, and the node it becomes for an
		// account that pays us, are written on that rule.
		$claim_fp = (string)($input['fingerprint'] ?? '');
		$claimed = SystemBase::rule_checked_write(function () use ($claim_fp, $user_id) {
			return JoinedServers::claim($claim_fp, new User($user_id, TRUE));
		});
		$name = htmlspecialchars($claimed['name'] !== '' ? $claimed['name'] : 'That server');
		$kind = DisplayMessage::MESSAGE_ANNOUNCEMENT;
		switch ($claimed['state']) {
			case JoinedServers::STATE_APPROVED:
				$text = $name . ' is connected and listed below. Its updates, alerts and health checks run from here from now on.';
				break;
			case JoinedServers::STATE_WAITING:
				$text = $name . ' is added to your account and waits for one of us to approve it. Nothing more is needed from you; '
					. 'it appears below once that is done.';
				break;
			case JoinedServers::STATE_LIMIT:
				$text = 'Your account already has ' . JoinedServers::LIMIT . ' servers you added, the most one account can have. Write to us if you need more.';
				$kind = DisplayMessage::MESSAGE_ERROR;
				break;
			case JoinedServers::STATE_TAKEN:
				$text = 'That request already belongs to another account. Cancel it on your site\'s Management Node page and connect again.';
				$kind = DisplayMessage::MESSAGE_ERROR;
				break;
			default:
				$text = 'We could not find that request: it may have run out (a request lasts an hour). Press Connect again on your site\'s Management Node page.';
				$kind = DisplayMessage::MESSAGE_ERROR;
		}
		$session->save_message(new DisplayMessage($text, 'Add a server', $self_regex, $kind, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}
	if ($action === 'let_go_server') {
		try {
			$mine = new ManagedNode((int)($input['mgn_managed_node_id'] ?? 0), TRUE);
			$mine_name = (string)$mine->get('mgn_name');
			// let_go() refuses a server that is not this buyer's own.
			$let_go = array('bundle_cancelled' => false);
			SystemBase::rule_checked_write(function () use ($mine, $user_id, &$let_go) { $let_go = JoinedServers::let_go($mine, (int)$user_id); });
			$text = 'We have stopped managing ' . htmlspecialchars($mine_name) . ' and taken it off this page. Your server is untouched. '
				. ($let_go['bundle_cancelled'] ? 'Its email and backups subscription is cancelled and runs to the end of the period you have paid for. ' : '')
				. 'Your site\'s own Management Node page says so within a few minutes, with the Disconnect button that ends the connection on its side.';
			$kind = DisplayMessage::MESSAGE_ANNOUNCEMENT;
		} catch (Throwable $e) {
			error_log('profile_sites: user ' . (int)$user_id . ' could not let go of node ' . (int)($input['mgn_managed_node_id'] ?? 0)
				. ': ' . $e->getMessage());
			$text = 'That server could not be removed from here. Write to us and we will do it.';
			$kind = DisplayMessage::MESSAGE_ERROR;
		}
		$session->save_message(new DisplayMessage($text, 'Your sites', $self_regex, $kind, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}

	// ---- The buyer removes a site on their own server from this page ---------
	if ($action === 'remove_site') {
		$gone = new CustomerCloudProvision((int)($input['cvp_customer_cloud_provision_id'] ?? 0), TRUE);
		if (!$gone->key || (int)$gone->get('cvp_usr_user_id') !== (int)$user_id || $gone->get('cvp_delete_time')) {
			return LogicResult::redirect($self_url);
		}
		try {
			// The site is this buyer's own (checked above): letting it go
			// writes its node and its row, which a member may not otherwise.
			$removed = SystemBase::rule_checked_write(function () use ($gone) { return SiteRemoval::remove($gone); });
			$text = $removed['kind'] === SiteRemoval::KIND_STOP
				? 'We have stopped managing ' . htmlspecialchars((string)$gone->get('cvp_domain')) . ' and taken it off this page. Your server is untouched. '
				. 'Your site\'s own Management Node page says so within a few minutes, with the Disconnect button that ends the connection on its side.'
					. ($removed['bundle_cancelled'] ? ' Its email and backups subscription is cancelled and runs to the end of the paid period.' : '')
				: htmlspecialchars((string)$gone->get('cvp_domain')) . ' has been removed.';
			$kind = DisplayMessage::MESSAGE_ANNOUNCEMENT;
		} catch (Throwable $e) {
			error_log('profile_sites_logic: remove_site #' . (int)$gone->key . ': ' . $e->getMessage());
			$text = 'That site could not be removed from here. Write to us and we will do it.';
			$kind = DisplayMessage::MESSAGE_ERROR;
		}
		$session->save_message(new DisplayMessage($text, 'Your sites', $self_regex, $kind, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}

	// ---- Try again after the buyer's own account refused the server ---------
	// While the connection is still good the site goes straight back to be
	// built; once it has run out, the buyer connects again, which does the same.
	if ($action === 'retry_refused') {
		$refused = new CustomerCloudProvision((int)($input['cvp_customer_cloud_provision_id'] ?? 0), TRUE);
		if (!$refused->key || (int)$refused->get('cvp_usr_user_id') !== (int)$user_id || $refused->get('cvp_delete_time')
				|| $refused->provider_refusal() === '') {
			return LogicResult::redirect($self_url);
		}
		$grant = CustomerCloudAccount::live_for_build((int)$user_id);
		if ($grant === null) {
			return LogicResult::redirect('/profile/server_manager/connect_cloud');
		}
		$refused->set('cvp_cca_customer_cloud_account_id', (int)$grant->key);
		$refused->set('cvp_status', 'ready');
		$refused->set('cvp_error', null);
		$refused->save();
		$session->save_message(new DisplayMessage('We are trying again to create the server for '
			. htmlspecialchars((string)$refused->get('cvp_domain')) . '.', 'Trying again', $self_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($self_url);
	}

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
		array('user_id' => $user_id, 'has_site' => true, 'deleted' => false), array('cvp_customer_cloud_provision_id' => 'DESC'));
	$provisions->load();

	// "Add these records for me": the publish box writes a site's records
	// through the buyer's own DNS host, with a credential that lives for the
	// one request. The site is found in the buyer's own list, so an id from
	// somebody else's site has no plan and the box does nothing.
	$dns_site_id = (int)($input['dns_site'] ?? 0);
	$dns_provision = null;
	foreach ($provisions as $provision) {
		if ((int)$provision->key === $dns_site_id) { $dns_provision = $provision; break; }
	}
	// The write receipts it keeps (which records were written here, and when)
	// are saved on that rule: the domain is the buyer's own.
	$dns_result = SystemBase::rule_checked_write(function () use ($input, $dns_provision, $self_url, $dns_site_id) {
		return DnsPublishBox::handle($input, function () use ($dns_provision) {
			return $dns_provision !== null ? profile_sites_dns_plan($dns_provision) : null;
		}, $self_url . '?dns_site=' . $dns_site_id, 0);
	});
	if ($dns_result !== null) {
		return $dns_result;
	}

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
		} elseif ((string)$target->get('cvp_status') !== 'done' || !SmHosting::site_told($target)) {
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
	$sites = array();
	$needs_connect = false;
	$in_progress = false;
	foreach ($provisions as $provision) {
		$hosted = $provision->is_operator_hosted();
		$status = (string)$provision->get('cvp_status');
		// Built, but not yet told what it comes with: its own pages would
		// ask for a mail provider it already has. Shown as finishing, with no
		// password to read yet, for the minute or two that takes (SmHosting).
		if ($status === 'done' && !SmHosting::site_told($provision)) {
			$status = 'finishing';
		}
		if (in_array($status, array('ready', 'booting', 'installing', 'finishing'), true)) {
			$in_progress = true;
		}
		if (!$hosted && $status === 'pending_connect') {
			$needs_connect = true;
		}
		$trial = ($hosted && !$provision->is_pre_payment()) ? HostedTrial::for_provision($provision->key) : null;

		// A domain we could not register because the name was taken after
		// payment: the card offers an alternate.
		$taken = null;
		$order_item_id = $provision->local_order_item_id();
		if ($order_item_id > 0 && (string)$provision->get('cvp_domain_source') === 'register') {
			$rows = new MultiRegisteredDomain(array('external_order_item_id' => $order_item_id, 'deleted' => false));
			foreach ($rows as $rdm) {
				if ($rdm->offers_alternate()) {
					$taken = array('rdm_id' => (int)$rdm->key, 'domain' => (string)$rdm->get('rdm_domain'),
						'paid' => ManagedSiteDraft::money((string)$rdm->get('rdm_price_paid')));
				}
			}
		}

		$dns_record   = profile_sites_dns_record($provision);
		$mail_records = profile_sites_mail_records($provision);
		$dmarc_record = profile_sites_dmarc_record($provision);
		$aaaa_record  = profile_sites_aaaa_record($provision);
		$dns_box = null;
		if ($dns_record !== null || $mail_records || $dmarc_record !== null || $aaaa_record !== null) {
			$plan = profile_sites_dns_plan($provision);
			if ($plan !== null) {
				$dns_box = DnsPublishBox::build($plan,
					(int)$provision->key === $dns_site_id ? $input : array(),
					$self_url . '?dns_site=' . (int)$provision->key);
			}
		}

		$sites[] = array(
			// The buyer's own-domain records are held until every one is known,
			// so there is one trip to the registrar (ProvisionBuyerDns).
			'dns_pending'     => in_array((string)$provision->get('cvp_status'), array('installing', 'done'), true)
				&& !ProvisionBuyerDns::listed($provision),
			'dns_record'      => $dns_record,
			'mail_records'    => $mail_records,
			// The site's own receiving record, held back because the domain
			// already receives email somewhere else (ProvisionBuyerDns).
			'mx_left_alone'   => (profile_sites_we_built($provision) && !$provision->is_pre_payment()
				&& !JobResultProcessor::plane_registered_domain($provision) && ProvisionBuyerDns::listed($provision))
				? ProvisionBuyerDns::mx_left_alone($provision) : null,
			'dmarc_record'    => $dmarc_record,
			'aaaa_record'     => $aaaa_record,
			'dns_box'         => $dns_box,
			'id'              => (int)$provision->key,
			'domain'          => (string)$provision->get('cvp_domain'),
			'url'             => 'https://' . (string)$provision->get('cvp_domain'),
			'hosted'          => $hosted,
			'status'          => $status,
			'pre_payment'     => $provision->is_pre_payment(),
			'summary'         => $provision->is_pre_payment()
				? profile_sites_draft_summary($provision, ManagedSiteDraft::product_for_row($provision)) : '',
			// Whole in the cart, or not in it: a part-set is dropped (in_cart_whole).
			'payment_form'    => ($status === 'pending_payment' && !($whole = ManagedSiteDraft::in_cart_whole($provision)))
				? ManagedSiteDraft::payment_form($provision, ManagedSiteDraft::product_for_row($provision), 'Finish payment') : '',
			'in_cart'         => $status === 'pending_payment' && !empty($whole),
			'taken'           => $taken,
			// Where the buyer manages what this site is paid by and holds
			// (hosting_after_purchase N2): its subscription, its card, the
			// domain we registered, and the email and backups it rents.
			'links'           => $provision->is_pre_payment() ? array() : profile_sites_links($provision),
			// Offered only for a site that exists: a button that spends a
			// one-time secret must not be reachable before the secret means
			// anything.
			'password_state'  => ($status === 'done' || $provision->admin_password_state() === 'revealed')
				? $provision->admin_password_state() : 'pending',
			'transfer'        => $provision->is_pre_payment() ? null : profile_sites_transfer($provision, $session),
			// A site on the buyer's own server can be let go from here (SiteRemoval).
			'removal'         => ($removal_kind = SiteRemoval::offered($provision)),
			'removal_question' => $removal_kind !== '' ? SiteRemoval::question($provision) : '',
			'mail_state'      => (string)$provision->get('cvp_mail_state'),
			// Waiting for the buyer's Linode connection, and whether a
			// connection was tried and the provider refused it: the card says
			// so, with the way to connect again.
			'needs_connect'   => !$hosted && $status === 'pending_connect',
			'connect_refused' => !$hosted && $status === 'pending_connect' && trim((string)$provision->get('cvp_error')) !== '',
			'provider_refusal' => !$hosted ? $provision->provider_refusal() : '',
			'grant_live'      => !$hosted && $status === 'pending_connect' && CustomerCloudAccount::live_for_build((int)$user_id) !== null,
			// On its buyer's own Linode: the card says whose server it is, and
			// whether our email and backups came with it.
			'own_linode'      => !$provision->is_pre_payment() && ManagedSiteDraft::plan_of($provision) === 'own_linode',
			'with_services'   => $provision->bundle_order_item_id() > 0,
			'show_mail'       => $hosted || (int)$provision->get('cvp_services_order_item_id') > 0,
			// An open suspension's reason, shown to the customer (HostedSuspensions).
			'suspended'       => ($sus = HostedSuspension::open_for($provision->key)) ? (string)$sus->get('hsu_reason') : null,
			'plan_state'      => $trial ? (string)$trial->get('htr_state') : '',
			'plan_line'       => $trial ? profile_sites_plan_line($trial, $provision->local_order_item(), $session) : '',
			'licence_line'    => BusinessLicense::site_line($provision),
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
		'email_unconfirmed' => ($viewer = new User($user_id, TRUE)) && SmHosting::email_unconfirmed($viewer)
			? SmHosting::email_unconfirmed_line($viewer) : '',
		'in_progress'       => $in_progress,
		// Servers the buyer runs and added themselves (JoinedServers): the
		// ones we manage, the ones waiting for a person, and a request their
		// site just sent them here to claim.
		'joined_servers'    => array_map(function ($node) {
			return array('id' => (int)$node->key, 'name' => (string)$node->get('mgn_name'),
				'since' => (string)$node->get('mgn_agent_paired_time'),
				'connected' => trim((string)$node->get('mgn_agent_public_key')) !== '',
				'bundle_id' => ($joined_bundle = JoinedServers::bundle_order_item_id($node)),
				'bundle_cancels' => SiteRemoval::bundle_cancellable($joined_bundle));
		}, JoinedServers::for_user((int)$user_id)),
		'joined_waiting'    => JoinedServers::waiting_for_user((int)$user_id),
		'claim_request'     => ($claim !== '' && ($claim_row = JoinedServers::request_for($claim)) !== null)
			? array('fingerprint' => $claim, 'name' => (string)$claim_row->get('ajr_claimed_name'),
				'instant' => JoinedServers::pays_us((int)$user_id)) : null,
		'claim_missing'     => $claim !== '' && JoinedServers::request_for($claim) === null,
		// The buyer named a site to add: the way to its own Management Node page.
		'add_server_url'    => JoinedServers::site_connect_url((string)($input['server'] ?? '')),
		// A grant that ran out is not a connection: the page must not say it is.
		'account_connected' => $account !== null && $account->get('cca_status') === 'active'
			&& !CustomerCloudAccount::grant_expired($account),
		'managed_on_sale'   => count(ManagedSiteDraft::offered_plans()) > 0,
		'configure_url'     => ManagedSiteDraft::CONFIGURE_URL,
		'availability_html'   => ManagedDomainIntake::availabilityScript('alternate_domain', 'alternate_domain_status'),
	));
}

/**
 * The address record a built Managed site on its buyer's own domain still
 * waits for, as array('type' => 'A'|'AAAA', 'value' => address), or null when
 * there is nothing to add: a name we registered and published, no server yet,
 * a certificate already issued (which proves the name reached the server, a
 * proxied one included), or the name already resolving to the address.
 * Until then the site has no address and no certificate.
 */
function profile_sites_dns_record($provision): ?array {
	if (!profile_sites_we_built($provision) || $provision->is_pre_payment()
			|| !in_array((string)$provision->get('cvp_status'), array('installing', 'done'), true)
			|| JobResultProcessor::plane_registered_domain($provision) || !ProvisionBuyerDns::listed($provision)) {
		return null;
	}
	$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
	if ($node_id <= 0) {
		return null;
	}
	$node = new ManagedNode($node_id, TRUE);
	if ((string)$node->get('mgn_ssl_state') === 'active') {
		return null;
	}
	$ip = JobResultProcessor::dns_target_ip($node);
	$packed = $ip !== '' ? @inet_pton($ip) : false;
	if ($packed === false) {
		return null;   // a hostname (resolving it may give a proxy's address), or nothing
	}
	$type = strlen($packed) === 16 ? 'AAAA' : 'A';
	try {
		$resolved = $type === 'A' ? DnsResolver::getA((string)$provision->get('cvp_domain'))
			: DnsResolver::getAaaa((string)$provision->get('cvp_domain'));
		foreach ($resolved as $address) {
			if (@inet_pton($address) === $packed) {
				return null;
			}
		}
	} catch (DnsLookupException $e) {
		// Unknown is shown: the record is harmless to repeat.
	}
	return array('type' => $type, 'value' => $ip);
}

/**
 * A site we built and whose records we describe: one we host, or one built on
 * its buyer's own Linode (specs/own_linode_we_install.md W6). A site on a
 * buyer's own cloud account set up any other way is theirs to describe.
 */
function profile_sites_we_built($provision): bool {
	return $provision->is_operator_hosted() || ManagedSiteDraft::plan_of($provision) === 'own_linode';
}

/**
 * The IPv6 record a hosted site on its buyer's own domain can add, as
 * array('type' => 'AAAA', 'value' => address), or null: the server has no
 * IPv6 address, the name already answers with it, or we registered the name
 * (and published it ourselves). Optional, so it is listed on its own: the
 * site works without it, and with it visitors on IPv6 reach it directly.
 */
function profile_sites_aaaa_record($provision): ?array {
	if (!profile_sites_we_built($provision) || $provision->is_pre_payment()
			|| !in_array((string)$provision->get('cvp_status'), array('installing', 'done'), true)
			|| JobResultProcessor::plane_registered_domain($provision) || !ProvisionBuyerDns::listed($provision)) {
		return null;
	}
	$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
	if ($node_id <= 0) {
		return null;
	}
	$v6 = NodeDnsPlan::publicAddresses(new ManagedNode($node_id, TRUE))['AAAA'];
	$packed = $v6 !== '' ? @inet_pton($v6) : false;
	if ($packed === false) {
		return null;
	}
	try {
		foreach (DnsResolver::getAaaa((string)$provision->get('cvp_domain')) as $address) {
			if (@inet_pton($address) === $packed) {
				return null;
			}
		}
	} catch (DnsLookupException $e) {
		// Unknown is shown: the record is harmless to repeat.
	}
	return array('type' => 'AAAA', 'value' => $v6);
}

/**
 * Every record a hosted site on its buyer's own domain asks for, as one plan
 * for the publish box: the address records (A, and AAAA where the server has
 * IPv6), the email records SMTP2GO asked for, a Starter site's inbound
 * records, and DMARC while the domain has none. Records already in place show
 * as unchanged in the box; one that would replace a live value needs its own
 * tick. Null when there is nothing to publish (a name we registered, a site
 * not built yet).
 */
function profile_sites_dns_plan($provision): ?DnsRecordPlan {
	if (!profile_sites_we_built($provision) || $provision->is_pre_payment()
			|| !in_array((string)$provision->get('cvp_status'), array('installing', 'done'), true)
			|| JobResultProcessor::plane_registered_domain($provision) || !ProvisionBuyerDns::listed($provision)) {
		return null;
	}
	$domain = strtolower(trim((string)$provision->get('cvp_domain')));
	$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
	if ($domain === '' || $node_id <= 0) {
		return null;
	}
	$plan = new DnsRecordPlan($domain, 'server_manager');
	NodeDnsPlan::addAddressRecords($plan, $domain, NodeDnsPlan::publicAddresses(new ManagedNode($node_id, TRUE)),
		'Points ' . $domain . ' at your site\'s server.');
	$plane_mail = profile_sites_plane_mail_records($provision);
	$mail = array_merge($plane_mail, ProvisionBuyerDns::site_records_for_buyer($provision, $plane_mail));
	foreach ($mail as $record) {
		$type = strtoupper((string)($record['type'] ?? ''));
		$name = rtrim((string)($record['name'] ?? ''), '.');
		$value = rtrim((string)($record['value'] ?? ''), '.');
		if ($type === '' || $name === '' || $value === '') {
			continue;
		}
		$plan->addRecord($type, $name, $value, null,
			isset($record['priority']) ? (int)$record['priority'] : null, (string)($record['note'] ?? ''));
	}
	if ($plane_mail && !ProvisionHostedMail::has_dmarc($domain)) {
		$dmarc = ProvisionHostedMail::dmarc_record($domain);
		$plan->addRecord($dmarc['type'], $dmarc['name'], $dmarc['value'], null, null, $dmarc['note']);
	}
	return $plan->isEmpty() ? null : $plan;
}

/**
 * The email records a hosted site on its buyer's own domain still needs, as
 * a list of array('type', 'name', 'value', 'note'), or [] when there is
 * nothing to add: a name we registered (we publish them ourselves), no mail
 * set up yet, or every record already resolving to what SMTP2GO asked for.
 * A Starter site's inbound records come first (profile_sites_inbound_records).
 * Until they are in place the site's mail goes out unsigned and is more
 * likely to be filtered. Only CNAMEs can be checked here; a record of another
 * type is listed until mail setup has verified the domain. The optional DMARC
 * record is profile_sites_dmarc_record()'s, shown on its own.
 */
function profile_sites_mail_records($provision): array {
	if (!profile_sites_we_built($provision) || $provision->is_pre_payment()
			|| JobResultProcessor::plane_registered_domain($provision) || !ProvisionBuyerDns::listed($provision)) {
		return array();
	}
	$missing = profile_sites_inbound_records($provision);
	$records = ProvisionHostedMail::records_of($provision);
	$verified = in_array((string)$provision->get('cvp_mail_state'), array('domain_verified', 'smtp_user_created', 'done'), true)
		&& trim((string)$provision->get('cvp_mail_error')) === '';
	foreach ($records as $record) {
		$type = strtoupper((string)($record['type'] ?? ''));
		$name = rtrim((string)($record['name'] ?? ''), '.');
		$value = rtrim((string)($record['value'] ?? ''), '.');
		if ($name === '' || $value === '') {
			continue;
		}
		if ($type === 'CNAME') {
			try {
				$found = DnsResolver::getCname($name);
				if ($found !== null && strcasecmp(rtrim($found, '.'), $value) === 0) {
					continue;
				}
			} catch (DnsLookupException $e) {
				// Unknown is shown: the record is harmless to repeat.
			}
		} elseif ($verified) {
			continue;
		}
		$missing[] = array('type' => $type, 'name' => $name, 'value' => $value,
			'note' => (string)($record['note'] ?? ''));
	}
	// What only the built site knows, Joinery Direct among it: listed until
	// the name answers with it.
	foreach (ProvisionBuyerDns::site_records_for_buyer($provision, profile_sites_plane_mail_records($provision)) as $record) {
		if (profile_sites_record_in_place($record)) {
			continue;
		}
		$missing[] = array('type' => $record['type'], 'name' => $record['name'],
			'value' => ($record['type'] === 'MX' ? (int)($record['priority'] ?? 10) . ' ' : '') . $record['value'],
			'note' => $record['note']);
	}
	return $missing;
}

/** The sending and inbound records this plane lists for a site, placed or not. */
function profile_sites_plane_mail_records($provision): array {
	$mail = ProvisionHostedMail::records_of($provision);
	// The relay's records, without the MX where the domain already receives
	// email elsewhere (ProvisionBuyerDns::plane_inbound_records).
	$mail = array_merge(ProvisionBuyerDns::plane_inbound_records($provision), $mail);
	return $mail;
}

/**
 * Whether a record (type, name, value) already answers in public DNS. An SRV
 * value is its four fields, 'priority weight port target'; the target and port
 * are what is compared. A lookup that fails is read as not in place: the
 * record is harmless to repeat.
 */
function profile_sites_record_in_place(array $record): bool {
	$type = strtoupper((string)$record['type']);
	$name = (string)$record['name'];
	$value = rtrim((string)$record['value'], '.');
	try {
		if ($type === 'CNAME') {
			$found = DnsResolver::getCname($name);
			return $found !== null && strcasecmp(rtrim($found, '.'), $value) === 0;
		}
		if ($type === 'TXT') {
			return in_array($value, DnsResolver::getTxt($name), true);
		}
		if ($type === 'MX') {
			foreach (DnsResolver::getMx($name) as $mx) {
				if (strcasecmp(rtrim((string)$mx['host'], '.'), $value) === 0) {
					return true;
				}
			}
			return false;
		}
		if ($type === 'SRV') {
			$fields = preg_split('/\s+/', trim($value));
			$target = strtolower((string)end($fields));
			$port = count($fields) >= 4 ? (int)$fields[count($fields) - 2] : 0;
			foreach (DnsResolver::getSrv($name) as $srv) {
				if (strtolower((string)$srv['host']) === $target && ($port === 0 || (int)$srv['port'] === $port)) {
					return true;
				}
			}
		}
	} catch (DnsLookupException $e) {
		// Unknown is shown.
	}
	return false;
}

/**
 * The DMARC record a hosted site on its buyer's own domain is offered while
 * the domain has none (ProvisionHostedMail::dmarc_record), or null. Optional,
 * so it is shown on its own and never keeps the email records box open: mail
 * arrives and sends without it.
 */
function profile_sites_dmarc_record($provision): ?array {
	if (!profile_sites_we_built($provision) || $provision->is_pre_payment()
			|| JobResultProcessor::plane_registered_domain($provision) || !ProvisionHostedMail::records_of($provision)
			|| !ProvisionBuyerDns::listed($provision)) {
		return null;
	}
	$domain = (string)$provision->get('cvp_domain');
	return ($domain !== '' && !ProvisionHostedMail::has_dmarc($domain)) ? ProvisionHostedMail::dmarc_record($domain) : null;
}

/**
 * The inbound records a Starter site on its buyer's own domain still needs
 * for its relay slot (FleetService::site_inbound_records): the MX until it
 * names the slot, and the ownership TXT until the claim is verified (it is
 * not listed after that). A name we registered gets them from us, so lists
 * none.
 */
function profile_sites_inbound_records($provision): array {
	if ((string)$provision->get('cvp_inbound_dns_state') !== 'buyer' || !class_exists('FleetService')) {
		return array();
	}
	$missing = array();
	foreach (ProvisionBuyerDns::plane_inbound_records($provision) as $record) {
		$type = strtoupper((string)$record['type']);
		$name = rtrim((string)$record['name'], '.');
		$value = rtrim((string)$record['value'], '.');
		try {
			if ($type === 'MX') {
				$found = false;
				foreach (DnsResolver::getMx($name) as $mx) {
					$found = $found || strcasecmp(rtrim((string)$mx['host'], '.'), $value) === 0;
				}
				if ($found) {
					continue;
				}
			} elseif ($type === 'TXT' && in_array($value, DnsResolver::getTxt($name), true)) {
				continue;
			}
		} catch (DnsLookupException $e) {
			// Unknown is shown: the record is harmless to repeat.
		}
		$missing[] = array('type' => $type, 'name' => $name,
			'value' => ($type === 'MX' ? (int)($record['priority'] ?? 10) . ' ' : '') . $value,
			'note' => (string)($record['note'] ?? ''));
	}
	return $missing;
}

/**
 * Where a site's move to its buyer's own Linode account stands, for the card,
 * or null when the card has nothing to say (not a Managed site we could hand
 * over, and no move in its history).
 */
function profile_sites_transfer($provision, $session): ?array {
	$row = InstanceTransfer::latest_for_provision((int)$provision->key);
	if ($row !== null && $row->state() === InstanceTransfer::STATE_CANCELED) {
		$row = null;
	}
	$offerable = $provision->is_operator_hosted() && (string)$provision->get('cvp_status') === 'done'
		&& trim((string)$provision->get('cvp_instance_id')) !== ''
		&& $provision->is_sold();
	if ($row === null && !$offerable) {
		return null;
	}
	$tz = $session->get_timezone();
	$expiry = $row ? trim((string)$row->get('itx_token_expiry')) : '';
	$paid_until = $row ? trim((string)$row->get('itx_paid_until')) : '';
	$node = InstanceTransfers::node_of($provision);
	$referral = trim((string)Globalvars::get_instance()->get_setting('server_manager_linode_referral_url', false, true));
	return array(
		'state'        => $row ? $row->state() : '',
		'state_label'  => $row ? $row->state_label() : '',
		'code_expiry'  => $expiry !== '' ? LibraryFunctions::convert_time($expiry, 'UTC', $tz, 'F j \a\t g:i a') : '',
		'cancelable'   => $row !== null && $row->cancelable(),
		'was_shut_down' => $row !== null && (bool)$row->get('itx_deletion_withdrawn'),
		'paid_until'   => $paid_until !== '' ? LibraryFunctions::convert_time($paid_until, 'UTC', $tz, 'F j, Y') : '',
		'managed'      => $node !== null && (bool)$node->get('mgn_enabled'),
		'signup_url'   => strpos($referral, 'https://') === 0 ? $referral : 'https://www.linode.com/',
		'label'        => InstanceTransfers::label_hint($provision),
	);
}

/**
 * The pages a paid site is managed from, label => url: the subscription and
 * the card when this site's store bills it, the domain when we registered it,
 * the connected services when it rents email and backups.
 */
function profile_sites_links(CustomerCloudProvision $provision): array {
	$links = array();
	$item = $provision->local_order_item();
	if ($item !== null && $item->get('odi_is_subscription')) {
		$links['Subscription'] = '/profile/subscriptions';
		$links['Billing and card'] = '/profile/billing';
	}
	if ((string)$provision->get('cvp_domain_source') === 'register') {
		$links['Domain'] = '/profile/server_manager/domain';
	}
	if ($provision->bundle_order_item_id() > 0) {
		$links[ManagedSiteDraft::BUNDLE_NAME] = '/profile/server_manager/services';
	}
	return $links;
}

/**
 * The hosting row of a hosted site, one sentence a buyer reads: where its plan
 * stands and the date that matters to them. A cancel waiting for the end of
 * the paid period is said here (the store's line carries it before any signal).
 */
function profile_sites_plan_line(HostedTrial $trial, ?OrderItem $item, $session): string {
	$day = function ($when) use ($session) {
		$when = trim((string)$when);
		return $when === '' ? '' : LibraryFunctions::convert_time($when, 'UTC', $session->get_timezone(), 'F j, Y');
	};
	$cancelled = $item !== null && trim((string)$item->get('odi_subscription_cancelled_time')) !== '';
	$period_end = $item !== null ? $day($item->get('odi_subscription_period_end')) : '';
	switch ((string)$trial->get('htr_state')) {
		case HostedTrial::STATE_TRIAL:
			if ($cancelled) {
				return 'Cancelled: free until ' . $day($trial->get('htr_trial_ends_time')) . ', then it ends';
			}
			return 'Free trial until ' . $day($trial->get('htr_trial_ends_time'));
		case HostedTrial::STATE_SUBSCRIBED:
			if ($cancelled) {
				return $period_end !== '' ? 'Cancelled: runs until ' . $period_end . ', then it ends' : 'Cancelled';
			}
			$price = $item !== null ? $item->subscription_price_label() : '';
			return trim('Subscribed' . ($price !== '' ? ', ' . $price : '') . ($period_end !== '' ? ', renews ' . $period_end : ''));
		case HostedTrial::STATE_GRACE:
			$until = $day($trial->get('htr_grace_ends_time'));
			return $cancelled
				? 'Ended: runs until ' . $until . ', then it is shut down'
				: 'Payment needed by ' . $until . ', or the site is shut down';
		case HostedTrial::STATE_SHUTDOWN:
			return 'Shut down. Backups kept until ' . $day($trial->get('htr_shelf_ends_time'));
		case HostedTrial::STATE_TRANSFERRED:
			return 'Moved to your own Linode account';
	}
	return '';
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
		$parts[] = ManagedSiteDraft::PLANS[ManagedSiteDraft::plan_of($provision)]['label'] . ' hosting ' . $price;
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
