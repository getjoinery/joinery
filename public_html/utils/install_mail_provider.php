#!/usr/bin/php
<?php
/**
 * install_mail_provider.php — set up a fresh site's email from a sending key
 * the deployer supplied on the deploy form.
 *
 * The setup wizard's Email step is one ceremony: save the provider and the
 * From address, provision the owner's mailbox for that address, register the
 * From domain at the provider, publish the domain's mail records through a
 * DNS credential, ask the provider to verify. Everything up to the human
 * proof (a test message the owner confirms arrived) is mechanical, and a
 * first-boot installer that holds the key can do all of it — so the wizard
 * opens on the proof, or on a DNS wait, instead of on an empty form.
 *
 * This script calls the wizard's own functions (setup_logic.php), so what an
 * installer does and what the wizard does cannot drift apart.
 *
 * Inputs arrive as environment variables, never on argv — a secret on argv is
 * visible to every process on the box:
 *
 *   JOINERY_MAIL_API_KEY    required — the provider's API key
 *   JOINERY_MAIL_PROVIDER   optional — a discovered provider key. Blank means
 *                           detect it from the key: the providers one key
 *                           configures (SingleKeyProvider) declare the shape
 *                           of their keys, and a key no shape matches is
 *                           tried live against each of them in turn. Named
 *                           or detected, the provider must be a
 *                           SingleKeyProvider whose settings group declares
 *                           exactly one secret, which is where the key goes;
 *                           SMTP is neither.
 *   JOINERY_MAIL_FROM       optional — the From address, which is also the
 *                           owner's mailbox here. Blank derives it: the admin
 *                           address itself when it is on the site's domain,
 *                           otherwise its local part on the site's domain.
 *   JOINERY_ADMIN_EMAIL     optional — names the owner (the account the
 *                           mailbox is granted to). Blank means the first
 *                           permission-10 account.
 *
 * The DNS publish uses the credential an installer kept for the wizard
 * (DnsInstallCredential), consumed here exactly as the wizard would consume
 * it. Without one the records are left for the wizard's DNS stage, which
 * shows them to add by hand.
 *
 * A key the provider rejects leaves the site as it was found: every setting
 * written here is put back to its previous value, so a site that already
 * sent mail still does and a fresh site's wizard asks afresh rather than
 * showing a provider that cannot send.
 *
 * Prints INSTALL_MAIL_PROVIDER=ok or =error as the first line, then key=value
 * lines: provider=, from=, registered=, dns=, state= (the provider's verdict on
 * the sending domain), reason= on error. Exits 0 on success, 2 on unusable
 * input, 1 when the provider rejected the key or a write failed.
 *
 * @version 1.1 - the provider is detected from the key when none is named
 *                (EmailSender::providersForApiKey), tried live in order; a
 *                provider without a registrar API is accepted and simply
 *                skips registration, as the wizard does
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('logic/setup_logic.php'));

function install_mail_provider_fail(string $reason, int $code): void {
	echo "INSTALL_MAIL_PROVIDER=error\n";
	echo 'reason=' . str_replace(array("\r", "\n"), ' ', $reason) . "\n";
	exit($code);
}

function install_mail_provider_line(string $value): string {
	return str_replace(array("\r", "\n"), ' ', $value);
}

$named       = strtolower(trim((string)getenv('JOINERY_MAIL_PROVIDER')));
$api_key     = trim((string)getenv('JOINERY_MAIL_API_KEY'));
$from        = strtolower(trim((string)getenv('JOINERY_MAIL_FROM')));
$admin_email = strtolower(trim((string)getenv('JOINERY_ADMIN_EMAIL')));
if ($api_key === '') {
	install_mail_provider_fail('JOINERY_MAIL_API_KEY is required.', 2);
}

// ---- The candidate providers, and the one setting each key belongs in ----
// Named: that one provider. Blank: whichever single-key providers the key's
// shape points at, or all of them when no shape matches — tried live in
// order below, the first the provider accepts wins.
$providers = EmailSender::getDiscoveredProviders();
if ($named !== '') {
	if (!isset($providers[$named])) {
		install_mail_provider_fail("'$named' is not an email provider this site knows (" . implode(', ', array_keys($providers)) . ').', 2);
	}
	if (!in_array('SingleKeyProvider', class_implements($providers[$named]) ?: array(), true)) {
		install_mail_provider_fail($providers[$named]::getLabel() . ' is not set up from a single key; use the setup wizard.', 2);
	}
	$candidates = array($named => $providers[$named]);
} else {
	$candidates = EmailSender::providersForApiKey($api_key);
	if (!$candidates) {
		install_mail_provider_fail('No provider on this site is set up from a single key.', 2);
	}
}
$key_settings = array();
foreach ($candidates as $candidate_key => $candidate_class) {
	$secrets = array();
	foreach (SettingsFieldRenderer::namesFor('email_provider_' . $candidate_key, 'core') as $name) {
		if (SettingsDeclarations::isSecret($name)) {
			$secrets[] = $name;
		}
	}
	if (count($secrets) !== 1) {
		install_mail_provider_fail($candidate_class::getLabel() . ' declares ' . count($secrets)
			. ' secret settings, so a single key cannot configure it; use the setup wizard.', 2);
	}
	$key_settings[$candidate_key] = $secrets[0];
}

// ---- The owner: the account the mailbox is granted to ----
$owner = null;
if ($admin_email !== '') {
	$candidates = new MultiUser(array('usr_email' => $admin_email));
	foreach ($candidates as $row) {
		if (!$row->get('usr_delete_time')) {
			$owner = $row;
			break;
		}
	}
}
if ($owner === null) {
	$admins = new MultiUser(array('usr_permission' => 10), array('usr_user_id' => 'ASC'));
	foreach ($admins as $row) {
		if (!$row->get('usr_delete_time')) {
			$owner = $row;
			break;
		}
	}
}
if ($owner === null || !$owner->key) {
	install_mail_provider_fail('No owner account was found to grant the mailbox to.', 1);
}

// ---- The From address: the site's domain, the owner's handle ----
$settings = Globalvars::get_instance();
$web = trim((string)$settings->get_setting('webDir'));
$site_domain = strtolower(trim((string)preg_replace('#^https?://#', '', rtrim($web, '/'))));
$site_domain = (string)preg_replace('/:\d+$/', '', $site_domain);
$site_domain = (string)preg_replace('/^www\./', '', $site_domain);
if ($site_domain === '' || $site_domain === 'localhost' || filter_var($site_domain, FILTER_VALIDATE_IP)) {
	install_mail_provider_fail('This site has no domain yet (webDir names ' . ($site_domain === '' ? 'nothing' : $site_domain)
		. '), so there is no address to send from.', 2);
}
if ($from === '') {
	$at = strrpos($admin_email, '@');
	$admin_domain = ($at !== false) ? rtrim(substr($admin_email, $at + 1), '.') : '';
	if ($admin_domain === $site_domain) {
		$from = $admin_email;
	} else {
		$local = ($at !== false) ? (string)preg_replace('/[^a-z0-9]/', '', substr($admin_email, 0, $at)) : '';
		$from = ($local !== '' ? $local : 'admin') . '@' . $site_domain;
	}
}
if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
	install_mail_provider_fail("'$from' is not a valid From address.", 2);
}
$sending_domain = strtolower(rtrim(substr($from, strrpos($from, '@') + 1), '.'));

// ---- Write, then validate against what was written (as the wizard does) ----
// One candidate at a time: write its settings, ask it whether the key is
// good, and on a refusal put every row back exactly before trying the next.
// What was there before is read straight from the table (the settings
// singleton memoizes), so a rejected key leaves the site as it was found.
$from_name = '';
if (trim((string)$settings->get_setting('defaultemailname')) === '') {
	$from_name = trim(trim((string)$owner->get('usr_first_name')) . ' ' . trim((string)$owner->get('usr_last_name')));
	if ($from_name === '') {
		$from_name = trim((string)$settings->get_setting('site_name'));
	}
	if ($from_name === '') {
		$from_name = $sending_domain;
	}
}
$read_raw = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
$service = '';
$provider_class = null;
$refusals = array();
$touched = array();
foreach ($candidates as $candidate_key => $candidate_class) {
	$writes = array(
		'email_service' => $candidate_key,
		'defaultemail'  => $from,
		$key_settings[$candidate_key] => $api_key,
	);
	if ($from_name !== '') {
		$writes['defaultemailname'] = $from_name;
	}
	if ($candidate_key === 'mailgun') {
		// The Mailgun sending domain is the From address's domain — the wizard
		// asks no separate question for it, and neither does this.
		$writes['mailgun_domain'] = $sending_domain;
	}
	$previous = array();
	foreach (array_keys($writes) as $name) {
		$read_raw->execute(array($name));
		$v = $read_raw->fetchColumn();
		$previous[$name] = ($v === false || $v === null) ? '' : (string)$v;
	}
	$touched = array_merge($touched, array_keys($writes));
	// A value the settings singleton cached while this candidate was being
	// asked must not answer for the next one, or for the ceremony after.
	$revert = function () use ($previous, $settings) {
		foreach ($previous as $name => $value) {
			Setting::put($name, $value);
			$settings->forget_setting($name);
		}
	};
	try {
		foreach ($writes as $name => $value) {
			Setting::put($name, $value);
		}
	} catch (Throwable $e) {
		$revert();
		install_mail_provider_fail('Could not write the email settings: ' . $e->getMessage(), 1);
	}
	EmailSender::resetProviderCache();
	$validation = EmailSender::validateService($candidate_key);
	if (empty($validation['valid'])) {
		$revert();
		$refusals[] = $candidate_class::getLabel() . ': not fully configured (' . implode(', ', (array)($validation['errors'] ?? array())) . ')';
		continue;
	}
	if (is_callable(array($candidate_class, 'validateApiConnection'))) {
		$live = $candidate_class::validateApiConnection();
		if (empty($live['success'])) {
			$revert();
			$refusals[] = $candidate_class::getLabel() . ': ' . (string)($live['error'] ?? ($live['label'] ?? 'rejected the key'));
			continue;
		}
	}
	$service = $candidate_key;
	$provider_class = $candidate_class;
	break;
}
if ($provider_class === null) {
	install_mail_provider_fail((count($candidates) === 1 ? 'The provider rejected the key' : 'No provider accepted the key')
		. ' — ' . implode('; ', $refusals), 1);
}
foreach (array_unique($touched) as $name) {
	$settings->forget_setting($name);
}

// ---- The wizard's own ceremony from here ----
$reg = _setup_mail_register($owner, $service);

$dns = 'not published: no DNS credential was kept by the installer';
$checked_state = '';
if ($reg['registered'] === false) {
	// Nothing at the provider means no DKIM records in the plan. Keep the
	// credential for the wizard's retry rather than spending it on a half plan.
	$dns = 'not published: the sending domain could not be registered at ' . $provider_class::getLabel()
		. ' (' . $reg['register_error'] . ')';
} else {
	$install_cred = DnsInstallCredential::stored();
	if ($install_cred !== null) {
		$driver_class = DnsDriverRegistry::get($install_cred['driver']);
		$plan = _setup_wizard_dns_plan($sending_domain);
		if ($driver_class === null) {
			$dns = 'not published: the kept DNS credential names no driver';
		} elseif ($plan === null || $plan->isEmpty()) {
			$dns = 'not published: no records to publish yet';
		} else {
			// Consume on use, whatever the outcome — the wizard's contract.
			DnsInstallCredential::consume();
			$credential = array_merge(array('account_id' => ''), $install_cred['credential']);
			$published = _setup_mail_publish($driver_class, $credential, $plan, $sending_domain, $provider_class);
			unset($credential, $install_cred);
			$checked_state = $published['checked_state'];
			$dns = ($published['error'] !== '')
				? 'failed: ' . $published['error'] . ($published['summary'] !== '' ? ' (' . $published['summary'] . ')' : '')
				: 'published: ' . $published['summary'];
		}
	}
}
_setup_refresh_receiving_verdict($sending_domain);

if ($checked_state === '' && $registrar) {
	$checked_state = (string)$provider_class::getSendingDomainState($sending_domain);
}

echo "INSTALL_MAIL_PROVIDER=ok\n";
echo 'provider=' . $service . "\n";
echo 'from=' . $from . "\n";
echo 'registered=' . ($reg['registered'] === null ? 'n/a' : ($reg['registered'] ? 'ok' : 'error: ' . install_mail_provider_line($reg['register_error']))) . "\n";
echo 'dns=' . install_mail_provider_line($dns) . "\n";
echo 'state=' . ($checked_state !== '' ? $checked_state : 'unknown') . "\n";
exit(0);
