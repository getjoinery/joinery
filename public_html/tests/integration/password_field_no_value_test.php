<?php
/** @joinery-test
 * name: password_field_no_value
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A stored credential never reaches the page, and a save never loses or wipes
 * one by accident.
 *
 * The contract (specs/stored_secret_fields_mask.md): a page says whether a
 * value is stored with passwordinput()'s `stored` option, and reads the
 * submission with FormWriterV2Base::process_secretinput(). A stored field is
 * drawn locked — disabled, with a Reset button — and a browser does not submit
 * a disabled field, so absent means keep. After Reset the field is submitted
 * like any other: blank removes, text replaces.
 *
 * Guarded here:
 *   A. No password field emits a value, whatever the caller passes.
 *   B. `stored` alone decides the locked state; the bound value never does.
 *   C. Every credential rendered on a settings page is declared `secret`.
 *   D. process_secretinput() and validate() read absent, blank and text right.
 *   E. The write paths: SettingsWriter, OAuth2ProviderConfig, the provisioning
 *      setup card and the core backup target form keep, remove and replace.
 *   G. Nothing outside FormWriter draws the lock, and no page still tells
 *      people to leave a field blank to keep it.
 *
 * Run: php tests/integration/password_field_no_value_test.php
 *
 * @version 2.0 - the locked-field contract: `stored`, Reset, process_secretinput()
 * @version 1.1 - a promotion code is credential-shaped (server_manager_namecheap_promotion_code)
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2JSON.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_settings_logic.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_settings_email_logic.php'));

$db = DbConnector::get_instance()->get_db_link();

// FormWriter starts a session for its CSRF token. Do it here, before the first
// line of test output, or every render warns that headers are already sent.
if (session_status() === PHP_SESSION_NONE) {
	@session_start();
}

// Fields echo. Capture what one actually puts on the page.
$render = function (FormWriterV2HTML5 $fw, string $method, ...$args): string {
	ob_start();
	$fw->$method(...$args);
	return (string)ob_get_clean();
};

$dots = FormWriterV2Base::STORED_SECRET_PLACEHOLDER;


// =========================================================================
section('A. A password field never emits a value');
// =========================================================================

// Every way a value can reach the field: passed explicitly, bound through
// set_values(), and as a multi-line credential. All must come out empty.
$fw = new FormWriterV2HTML5('pwtest');
$html = $render($fw, 'passwordinput', 'probe_explicit', 'Probe', array(
	'value' => 'hunter2-explicit',
));
check(strpos($html, 'hunter2-explicit') === false,
	'an explicitly passed value does not reach the HTML', $html);
check(!preg_match('/type="password"[^>]*\svalue="[^"]+"/', $html),
	'the password input carries no non-empty value attribute', $html);

$fw2 = new FormWriterV2HTML5('pwtest2');
$fw2->set_values(array('probe_bound' => 'hunter2-bound'));
$html2 = $render($fw2, 'passwordinput', 'probe_bound', 'Probe', array('stored' => true));
check(strpos($html2, 'hunter2-bound') === false,
	'a value bound with set_values() does not reach the HTML either', $html2);

$fw_rows = new FormWriterV2HTML5('pwtest_rows');
$rows_html = $render($fw_rows, 'passwordinput', 'probe_pem', 'PEM key', array(
	'rows' => 4, 'value' => '-----BEGIN PRIVATE KEY-----hunter2',
));
check(strpos($rows_html, '<textarea') !== false, 'rows draws a multi-line credential as a textarea', $rows_html);
check(strpos($rows_html, 'hunter2') === false, 'and the textarea is empty too', $rows_html);

// A text field on the same form still round-trips, so the rule is scoped to
// credentials rather than being a blanket "never emit a value".
$fw3 = new FormWriterV2HTML5('pwtest3');
$html3 = $render($fw3, 'textinput', 'probe_text', 'Probe', array(
	'value' => 'ordinary-value',
));
check(strpos($html3, 'ordinary-value') !== false,
	'a plain text field is unaffected', $html3);


// =========================================================================
section('B. `stored` alone decides the locked field');
// =========================================================================

// Two stored fields back to back: the script tag comes once however many
// fields a page carries. (The earlier stored render in section A already
// emitted it for this process, so here it must not appear at all.)
$fw4 = new FormWriterV2HTML5('pwtest4');
$stored_html = $render($fw4, 'passwordinput', 'probe_stored', 'Probe', array(
	'stored' => true, 'required' => true, 'placeholder' => 'paste the key here',
));
$stored_html2 = $render($fw4, 'passwordinput', 'probe_stored2', 'Probe 2', array('stored' => true));
check(substr_count($html2 . $stored_html . $stored_html2, 'stored-secret.js') === 1,
	'the stored-secret script is emitted once per request', $html2 . $stored_html . $stored_html2);
check(preg_match('/<input type="password"[^>]*\sdisabled/', $stored_html) === 1,
	'a stored field is drawn disabled, so the browser does not submit it', $stored_html);
check(strpos($stored_html, 'placeholder="' . $dots . '"') !== false,
	'it shows the dots as its placeholder', $stored_html);
check(strpos($stored_html, 'paste the key here') === false,
	'a caller\'s own placeholder does not leak onto a stored field', $stored_html);
check(strpos($stored_html, 'data-stored-secret-for="probe_stored"') !== false,
	'it carries a Reset button bound to the field', $stored_html);
check(preg_match('/<input type="password"[^>]*\srequired/', $stored_html) === 0
		&& strpos($stored_html, 'data-required') !== false,
	'a required stored field drops `required` while locked and keeps it for Reset', $stored_html);
check(strpos($stored_html, 'autocomplete="new-password"') !== false,
	'a credential field is kept from the password manager', $stored_html);

$fw5 = new FormWriterV2HTML5('pwtest5');
$fw5->set_values(array('probe_empty' => 'something-bound'));
$empty_html = $render($fw5, 'passwordinput', 'probe_empty', 'Probe', array('stored' => false));
check(preg_match('/<input type="password"[^>]*\sdisabled/', $empty_html) === 0
		&& strpos($empty_html, 'data-stored-secret-for') === false
		&& strpos($empty_html, $dots) === false,
	'stored => false draws an open, empty field with no Reset, even with a value bound', $empty_html);

$fw5b = new FormWriterV2HTML5('pwtest5b');
$login_html = $render($fw5b, 'passwordinput', 'probe_login', 'Password', array('value' => 'bound'));
check(strpos($login_html, 'disabled') === false && strpos($login_html, 'new-password') === false,
	'a field that never says `stored` (a sign-in field) draws exactly as a plain password', $login_html);

$fw6 = new FormWriterV2HTML5('pwtest6');
$locked_html = $render($fw6, 'passwordinput', 'probe_page_locked', 'Probe', array(
	'stored' => true, 'disabled' => true,
));
check(strpos($locked_html, $dots) !== false && strpos($locked_html, 'data-stored-secret-for') === false,
	'a stored field the page itself disabled shows the dots and offers no Reset', $locked_html);

$json = new FormWriterV2JSON('pwjson');
ob_start();
$json->begin_form();
$json->passwordinput('probe_json', 'Probe', array('stored' => true, 'value' => 'hunter2-json'));
$json->end_form();
ob_end_clean();
$json_field = null;
foreach ($json->getDefinition()['fields'] as $f) {
	if ($f['name'] === 'probe_json') $json_field = $f;
}
check($json_field !== null && !empty($json_field['stored']) && !array_key_exists('value', $json_field)
		&& strpos(json_encode($json_field), 'hunter2') === false,
	'the JSON writer says `stored` and carries no value', json_encode($json_field));


// =========================================================================
section('C. Every settings credential is declared secret');
// =========================================================================

// The write paths key off the declaration, not off the field type, so a
// credential the manifest does not mark is drawn as an ordinary field.
$must_be_secret = array(
	// core — email providers and the OAuth client secrets
	'smtp_password', 'sendgrid_api_key', 'sendgrid_inbound_secret', 'mailgun_api_key',
	'mailgun_webhook_signing_key', 'mailjet_api_secret', 'brevo_api_key',
	'postmark_server_token', 'resend_api_key', 'ses_secret_access_key',
	'mailchimp_api_key', 'cloud_storage_secret_key',
	'oauth_google_client_secret', 'oauth_microsoft_client_secret',
	'oauth_linode_client_secret', 'oauth_digitalocean_client_secret',
	'oauth_dnsimple_client_secret',
	// plugins
	'mailbox_forwarding_smtp_password', 'mailbox_srs_secret', 'mailbox_fleet_api_secret_key',
	'dns_filtering_dns_api_key', 'dns_filtering_dns_secondary_api_key',
	'joinery_ai_anthropic_api_key', 'joinery_ai_local_api_key', 'joinery_ai_fireworks_api_key',
	'joinery_ai_brave_search_api_key', 'joinery_ai_market_data_api_key',
	'stripe_api_pkey', 'stripe_api_pkey_test', 'stripe_endpoint_secret',
	'paypal_api_secret', 'paypal_api_secret_test',
	'server_manager_getjoinery_api_secret_key',
);
$unmarked = array();
foreach ($must_be_secret as $name) {
	if (!SettingsDeclarations::isSecret($name)) $unmarked[] = $name;
}
check(empty($unmarked),
	count($must_be_secret) . ' known credentials are declared secret',
	'not marked: ' . implode(', ', $unmarked));

// The publishable half of a credential pair is not a secret — it is meant to be
// visible. The store's naming is inverted: stripe_api_key is the PUBLISHABLE key
// and stripe_api_pkey is the secret; paypal_api_key is the client id.
foreach (array('stripe_api_key', 'stripe_api_key_test', 'paypal_api_key', 'paypal_api_key_test',
               'ses_access_key_id', 'mailjet_api_key', 'smtp_username') as $name) {
	check(!SettingsDeclarations::isSecret($name), "$name is public and stays writable-to-empty");
}

// The list above is a checklist, and a checklist only covers what someone
// remembered to add. This is the rule: a setting whose NAME says credential is
// one unless somebody says otherwise in writing.
//
// Adding a name here is the exception, and each one owes a reason.
$public_by_design = array(
	// The visible half of a credential pair. Masking these buys nothing, and
	// the store's naming is confusingly inverted: *_api_key is the publishable
	// key, *_api_pkey is the secret.
	'stripe_api_key'      => 'Stripe PUBLISHABLE key (pk_...); the secret is stripe_api_pkey',
	'stripe_api_key_test' => 'Stripe test publishable key',
	'paypal_api_key'      => 'PayPal Client ID; the secret is paypal_api_secret',
	'paypal_api_key_test' => 'PayPal test Client ID',
	'mailjet_api_key'     => 'Mailjet documents this as the public part of the pair',
);

// A promotion code belongs here too: whoever holds it gets the discount, so it
// is kept like a key, not published like a price.
$credential_shaped = '/(secret|password|passwd|_token$|api_key|apikey|_pkey|_private$|private_key|signing_key|service_account|credential|promotion_code|promo_code)/i';
$unmarked = array();
foreach (SettingsDeclarations::all() as $name => $declaration) {
	// A machine-written value never reaches a form, so it cannot leak through one.
	if (!empty($declaration['managed'])) continue;
	if (!empty($declaration['secret'])) continue;
	if (isset($public_by_design[$name])) continue;
	if (preg_match($credential_shaped, $name)) $unmarked[] = $name;
}
check(empty($unmarked),
	'every credential-shaped setting name is declared secret, or justified as public',
	"not marked secret: " . implode(', ', $unmarked)
		. "\nAdd \"secret\": true to the declaration, or add the name to \$public_by_design with a reason.");

// The rule is only worth having if it actually covers the credentials we know
// about — a pattern that matches nothing would pass silently forever.
$missed = array();
foreach (SettingsDeclarations::all() as $name => $declaration) {
	if (empty($declaration['secret'])) continue;
	if (!empty($declaration['managed'])) continue;
	if (!preg_match($credential_shaped, $name)) $missed[] = $name;
}
check(empty($missed),
	'and the rule recognises every renderable credential already declared',
	'named in a way the pattern misses: ' . implode(', ', $missed));

foreach (array_keys($public_by_design) as $name) {
	check(SettingsDeclarations::isDeclared($name),
		"$name (allowlisted as public) still exists — stale entries hide real gaps");
}

// The renderer draws a declared secret through the same lock.
ob_start();
SettingsFieldRenderer::secretField(new FormWriterV2HTML5('pwtest8'), 'smtp_password', 'SMTP password', 'something-stored');
$secret_field_html = (string)ob_get_clean();
check(strpos($secret_field_html, 'data-stored-secret-for="smtp_password"') !== false
		&& strpos($secret_field_html, 'something-stored') === false
		&& strpos($secret_field_html, 'clear__') === false,
	'SettingsFieldRenderer::secretField() draws a stored secret locked, with no Clear box', $secret_field_html);


// =========================================================================
section('D. process_secretinput() and validate()');
// =========================================================================

$K = FormWriterV2Base::SECRET_KEEP;
$C = FormWriterV2Base::SECRET_CLEAR;
$S = FormWriterV2Base::SECRET_SET;
$cases = array(
	// post, has_stored, expected action, expected value, label
	array(array(), true, $K, null, 'absent with something stored → keep'),
	array(array(), false, $K, null, 'absent with nothing stored → keep'),
	array(array('k' => ''), true, $C, null, 'empty with something stored → clear'),
	array(array('k' => ''), false, $K, null, 'empty with nothing stored → keep'),
	array(array('k' => "  \n "), true, $C, null, 'whitespace-only reads as empty'),
	array(array('k' => '  abc '), true, $S, 'abc', 'text → set, trimmed'),
	array(array('k' => '*****'), true, $S, '*****', 'a value made only of asterisks is stored like any other'),
	array(array('k' => array('x')), true, $K, null, 'a malformed array submission keeps'),
	array(array('k' => null), true, $K, null, 'a null (JSON) submission keeps'),
);
foreach ($cases as list($post, $has, $want_action, $want_value, $label)) {
	list($action, $value) = FormWriterV2Base::process_secretinput($post, 'k', $has);
	check($action === $want_action && $value === $want_value, $label,
		'got ' . json_encode(array($action, $value)));
}

$fw_v = new FormWriterV2HTML5('pwvalidate');
$render($fw_v, 'passwordinput', 'req_secret', 'Required secret', array('stored' => true, 'required' => true));
check($fw_v->validate(array('other' => 'x')) === true,
	'validate() passes a required stored field that did not arrive (it is locked)');
check($fw_v->validate(array('req_secret' => '')) === false,
	'and fails it when it was unlocked and submitted empty');


// =========================================================================
section('E. The write paths keep, remove and replace');
// =========================================================================

$admin_uid = (int)$db->query(
	"SELECT usr_user_id FROM usr_users
	  WHERE usr_permission >= 10 AND usr_delete_time IS NULL
	  ORDER BY usr_user_id LIMIT 1"
)->fetchColumn();

// Globalvars caches a non-blank setting for the process; a write below goes
// straight to the row, so each read afterwards must forget the cached copy.
$forget = function (string $name) {
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$arr = $ref->getValue($gv);
	if (is_array($arr)) {
		unset($arr[$name]);
		$ref->setValue($gv, $arr);
	}
};
$read = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
$raw = function (string $name) use ($read) {
	$read->execute(array($name));
	return $read->fetchColumn();
};
$put = function (string $name, $value) use ($db, $forget) {
	$db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?")->execute(array($value, $name));
	$forget($name);
};
$restore_later = function (string $name) use ($raw, $put) {
	$original = $raw($name);
	if ($original === false) return false;
	harness_defer(function () use ($put, $name, $original) { $put($name, $original); });
	return true;
};

if (!$admin_uid) {
	harness_skip('no superadmin account to act as');
} else {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $admin_uid;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = 10;

	// ── SettingsWriter, through both settings pages ──
	$probe = 'smtp_password';
	if (!$restore_later($probe)) {
		harness_skip("$probe has no row on this deployment");
	} else {
		$put($probe, 'planted-secret-value');

		admin_settings_logic(array());
		check($raw($probe) === 'planted-secret-value',
			'General save with the secret absent (locked) keeps it');

		admin_settings_email_logic(array());
		$forget($probe);
		check($raw($probe) === 'planted-secret-value',
			'Email save with the secret absent keeps it too');

		admin_settings_logic(array($probe => 'a-rotated-secret'));
		$forget($probe);
		check($raw($probe) === 'a-rotated-secret', 'text replaces it');

		admin_settings_logic(array($probe => ''));
		$forget($probe);
		check($raw($probe) === '', 'unlocked and blank removes it');

		admin_settings_logic(array($probe => ''));
		$forget($probe);
		check($raw($probe) === '', 'blank with nothing stored writes nothing (and stays empty)');

		// A stale or crafted clear__ name is never mistaken for a setting.
		$clear = 'clear__' . $probe;
		$put($probe, 'planted-again');
		admin_settings_logic(array($clear => '1'));
		$forget($probe);
		check($raw($probe) === 'planted-again', "$clear is ignored: it removes nothing");
		check(Setting::isReservedName($clear), "$clear is never a setting");
		$count = $db->prepare("SELECT COUNT(*) FROM stg_settings WHERE stg_name = ?");
		$count->execute(array($clear));
		check((int)$count->fetchColumn() === 0, 'and no row was minted for it');

		// A non-secret setting must still be clearable — keep-when-absent is
		// scoped to credentials, not to empty strings in general.
		$plain = 'totp_issuer_name';
		if ($restore_later($plain)) {
			$put($plain, 'something');
			admin_settings_logic(array($plain => ''));
			$forget($plain);
			check($raw($plain) === '', 'a non-secret setting can still be cleared');
		} else {
			harness_skip("$plain has no row to test the negative case with");
		}
	}

	// ── OAuth2ProviderConfig (the OAuth providers page and the mailbox connect page) ──
	$oauth_id = 'oauth_dnsimple_client_id';
	$oauth_secret = 'oauth_dnsimple_client_secret';
	$oauth_restorable = $restore_later($oauth_id) && $restore_later($oauth_secret);
	if (!$oauth_restorable) {
		harness_skip('the DNSimple OAuth settings have no rows on this deployment');
	} else {
		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderConfig.php'));
		$oauth_session = SessionControl::get_instance();
		$open = function () use ($raw, $oauth_secret) {
			$v = (string)$raw($oauth_secret);
			if ($v === '') return '';
			try {
				return (string)((new SecretBox())->open($v)['value'] ?? '');
			} catch (Throwable $e) {
				return $v;   // zero-config: stored plain
			}
		};
		$put($oauth_secret, '');
		OAuth2ProviderConfig::save('DnsimpleOAuthProvider', array($oauth_id => 'cid', $oauth_secret => 'first-secret'), '', $oauth_session);
		$forget($oauth_secret); $forget($oauth_id);
		check($open() === 'first-secret', 'OAuth: text stores the client secret');
		OAuth2ProviderConfig::save('DnsimpleOAuthProvider', array($oauth_id => 'cid-2'), '', $oauth_session);
		$forget($oauth_secret); $forget($oauth_id);
		check($open() === 'first-secret' && $raw($oauth_id) === 'cid-2',
			'OAuth: the secret absent keeps it while the client id changes');
		OAuth2ProviderConfig::save('DnsimpleOAuthProvider', array($oauth_id => 'cid-2', $oauth_secret => ''), '', $oauth_session);
		$forget($oauth_secret);
		check((string)$raw($oauth_secret) === '', 'OAuth: unlocked and blank removes it');
	}

	// ── The provisioning setup card (server_manager) ──
	if (!PluginHelper::isPluginActive('server_manager')) {
		harness_skip('server_manager is not active: the provisioning card is not reachable');
	} else {
		$promo = 'server_manager_namecheap_promotion_code';
		if (!$restore_later($promo)) {
			harness_skip("$promo has no row on this deployment");
		} else {
			$keep_fields = array(
				'server_manager_namecheap_api_user', 'server_manager_namecheap_client_ip',
				'server_manager_namecheap_sandbox', 'server_manager_domain_tlds',
				'server_manager_namecheap_api_key',
			);
			foreach ($keep_fields as $f) { $restore_later($f); }
			$card = function (array $extra) use ($raw) {
				return array_merge(array(
					'action'        => 'save_domains',
					'ncp_api_user'  => (string)$raw('server_manager_namecheap_api_user'),
					'ncp_client_ip' => (string)$raw('server_manager_namecheap_client_ip'),
					'domain_tlds'   => (string)$raw('server_manager_domain_tlds'),
					'ncp_sandbox'   => (string)$raw('server_manager_namecheap_sandbox'),
				), $extra);
			};
			$logic = 'plugins/server_manager/logic/admin_provisioning_setup_logic.php';
			$fn = 'admin_provisioning_setup_logic';
			$promo_now = function () use ($forget, $promo) {
				$forget($promo);
				return trim(ProvisioningSetup::readSecret($promo));
			};

			$put($promo, '');
			harness_call_logic($logic, $fn, $card(array('ncp_promotion_code' => 'SAVE10')));
			check($promo_now() === 'SAVE10', 'Provisioning: text stores the promotion code');
			harness_call_logic($logic, $fn, $card(array()));
			check($promo_now() === 'SAVE10', 'Provisioning: absent keeps it when the rest of the card is saved');
			harness_call_logic($logic, $fn, $card(array('ncp_promotion_code' => '')));
			check($promo_now() === '', 'Provisioning: unlocked and blank removes it');
		}
	}

	// ── The core backup target form ──
	require_once(PathHelper::getIncludePath('adm/logic/admin_backups_logic.php'));
	$bk_session = SessionControl::get_instance();
	$bk_post = function (array $extra, int $id) {
		return array_merge(array(
			'bkt_backup_target_id' => $id ?: '',
			'bkt_name'        => 'stored-secret probe target',
			'bkt_provider'    => 's3',
			'bkt_bucket'      => 'probe-bucket',
			'bkt_path_prefix' => 'joinery-backups',
			'access_key'      => 'AKIAPROBE',
			'region'          => 'us-east-1',
			'endpoint'        => 's3.us-east-1.amazonaws.com',
		), $extra);   // bkt_enabled absent: saved untested
	};
	_admin_backups_handle('save_target', $bk_post(array('secret_key' => 'probe-secret-1'), 0), $bk_session);
	$bk_id = (int)$db->query("SELECT bkt_backup_target_id FROM bkt_backup_targets
		WHERE bkt_name = 'stored-secret probe target' AND bkt_delete_time IS NULL
		ORDER BY bkt_backup_target_id DESC LIMIT 1")->fetchColumn();
	if (!$bk_id) {
		check(false, 'Backups: a disabled probe target could be created');
	} else {
		harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $bk_id);
		$bk_secret = function () use ($bk_id) {
			return (string)((new BackupTarget($bk_id, TRUE))->get_credentials()['secret_key'] ?? '');
		};
		check($bk_secret() === 'probe-secret-1', 'Backups: text stores the secret');
		_admin_backups_handle('save_target', $bk_post(array('bkt_bucket' => 'probe-bucket-2'), $bk_id), $bk_session);
		check($bk_secret() === 'probe-secret-1', 'Backups: absent keeps it when the bucket changes');
		_admin_backups_handle('save_target', $bk_post(array('secret_key' => 'probe-secret-2'), $bk_id), $bk_session);
		check($bk_secret() === 'probe-secret-2', 'Backups: text replaces it');
		_admin_backups_handle('save_target', $bk_post(array('secret_key' => ''), $bk_id), $bk_session);
		check($bk_secret() === '', 'Backups: unlocked and blank removes it');
	}
}


// =========================================================================
section('G. Nothing outside FormWriter draws the lock');
// =========================================================================

$root = rtrim(PathHelper::getIncludePath(''), '/');
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$lock_outside = array();
$leave_blank = array();
foreach ($iter as $file) {
	$path = $file->getPathname();
	$rel = substr($path, strlen($root) + 1);
	if (!preg_match('/\.(php|js)$/', $rel)) continue;
	if (preg_match('#^(tests|specs|docs|vendor|node_modules|utils/forms_example)#', $rel)) continue;
	if (strpos($rel, '/tests/') !== false) continue;
	$src = @file_get_contents($path);
	if ($src === false) continue;
	$is_formwriter = preg_match('#^includes/FormWriterV2(Base|HTML5|JSON)\.php$#', $rel)
		|| $rel === 'assets/js/stored-secret.js';
	if (!$is_formwriter && (strpos($src, 'data-stored-secret') !== false || strpos($src, 'stored-secret.js') !== false)) {
		$lock_outside[] = $rel;
	}
	// Changelog lines record what a file used to do; they are history, not copy.
	$current = preg_replace('/^.*@(changelog|version)\b.*$/m', '', $src);
	if (preg_match('/leave (this |it )?blank (when editing )?to keep|leave blank to keep/i', $current)) {
		$leave_blank[] = $rel;
	}
}
check(empty($lock_outside), 'no file outside FormWriter draws the stored-credential lock',
	implode(', ', $lock_outside));
check(empty($leave_blank), 'no page tells people to leave a credential blank to keep it',
	implode(', ', $leave_blank));

harness_finish();
