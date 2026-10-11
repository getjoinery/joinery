<?php
/**
 * Logic for the member Mail apps page (/profile/mail_apps): "Use Thunderbird
 * or another mail program."
 *
 * Lists the mailboxes the person holds a grant on and whether a mail program
 * can open each (Fortress cannot), the settings to type into the program,
 * and the person's mail-app credentials with when and where each was last
 * used. Making an app password goes through core's app_password_create
 * action (a step-up first) and revoking one through app_credential_revoke;
 * the page's script calls both.
 *
 * When any of the mailboxes is Private, the page says what a mail app costs
 * before the first password is made (specs/mail_apps_plugin.md § What the
 * person is told): while it is checking, the mail stays unlocked on the
 * server up to the window's absolute cap, and the program keeps its own copy.
 *
 * @version 1.0.0
 */

function mail_apps_page_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if ($user_id <= 0) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/mail_apps'));
	}
	$settings = Globalvars::get_instance();

	$mailboxes = array();
	$private_domains = array();
	$hardened_domains = array();
	foreach (InboundEmailMailboxGrant::alias_ids_for_user($user_id) as $alias_id) {
		$alias = new InboundEmailAlias(intval($alias_id), TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			continue;
		}
		$address = strtolower((string)$alias->get_full_address());
		$fortress = $alias->is_fortress();
		$private = !$fortress && $alias->seals_content();
		$domain_name = substr(strrchr($address, '@') ?: '@', 1);
		if ($private) {
			$private_domains[$domain_name] = true;
			$domain = new InboundEmailDomain(intval($alias->get('iea_ied_inbound_email_domain_id')), TRUE);
			if ($domain->key && ($domain->send_lock_requested() || $domain->relay_seals_to_owner())) {
				$hardened_domains[$domain_name] = true;
			}
		}
		$mailboxes[] = array(
			'address' => $address,
			'level'   => $fortress ? 'Fortress' : ($private ? 'Private' : 'Standard'),
			'offered' => !$fortress,
			'note'    => $fortress ? MailAppContext::FORTRESS_REFUSAL
				: ($private ? 'Works while your vault is unlocked on the website.' : ''),
		);
	}
	usort($mailboxes, function ($a, $b) { return strcasecmp($a['address'], $b['address']); });

	$credentials = array();
	foreach (new MultiApiKey(array('user_id' => $user_id, 'purpose' => 'mail', 'deleted' => false),
			array('apk_create_time' => 'DESC')) as $key) {
		$credentials[] = array(
			'id'        => intval($key->key),
			'name'      => (string)$key->get('apk_name'),
			'kind'      => (string)($key->kind_label() ?? ''),
			'created'   => $key->get('apk_create_time'),
			'last_used' => $key->get('apk_last_used_time'),
			'last_ip'   => (string)$key->get('apk_last_used_ip'),
		);
	}

	// Programs connect to the mail host name, which reaches this server even
	// when the web name is behind a CDN (MailAppGateway::servedName()).
	$host = MailAppGateway::servedName();

	return LogicResult::render(array(
		'session'          => $session,
		'enabled'          => (bool)$settings->get_setting('mail_apps_enabled'),
		'host'             => $host,
		'mailboxes'        => $mailboxes,
		'any_offered'      => count(array_filter($mailboxes, function ($m) { return $m['offered']; })) > 0,
		'private_domains'  => array_keys($private_domains),
		'hardened_domains' => array_keys($hardened_domains),
		'credentials'      => $credentials,
	));
}
?>
