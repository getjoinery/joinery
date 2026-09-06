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
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function profile_sites_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trial_class.php'));

	$self_url = '/profile/server_manager';

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode($self_url));
	}

	$provisions = new MultiCustomerCloudProvision(
		array('user_id' => $user_id, 'deleted' => false), array('cvp_id' => 'DESC'));
	$provisions->load();

	// The reveal. A POST, and the method is checked rather than assumed.
	//
	// A browser performs a GET whenever it is told to, including by another
	// site, and SameSite=Lax sends the session cookie on a top-level cross-site
	// GET. A link or a prefetch carrying this buyer's cvp_id would then BURN
	// their one-time password: the attacker sees nothing, and the buyer loses
	// the only copy. A cross-site POST gets no cookie at all.
	$revealed = '';
	$revealed_domain = '';
	$error = '';
	$is_post = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST');
	if (($input['action'] ?? '') === 'reveal_password' && !$is_post) {
		$error = 'Showing a password is an action, not a link. Use the button on this page.';
	} elseif (($input['action'] ?? '') === 'reveal_password') {
		$wanted = (int)($input['cvp_id'] ?? 0);
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
		$trial = $hosted ? HostedTrial::for_provision($provision->key) : null;
		$sites[] = array(
			'id'              => (int)$provision->key,
			'domain'          => (string)$provision->get('cvp_domain'),
			'url'             => 'https://' . (string)$provision->get('cvp_domain'),
			'hosted'          => $hosted,
			'status'          => $status,
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
	));
}
