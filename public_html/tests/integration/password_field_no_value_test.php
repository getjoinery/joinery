<?php
/** @joinery-test
 * name: password_field_no_value
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A stored credential must never reach the page, and a blank credential field
 * must never wipe the stored one.
 *
 * These two rules only work together. `renderPasswordInput()` delegates to
 * `renderTextInput()`, which emits `value="..."`, so any caller passing the
 * stored secret as the field value put that secret in the page source. Fixing
 * only that half is worse than leaving it: those fields round-tripped their
 * value on every save, so a field that renders empty and a write path that
 * takes an empty submission literally blanks every credential on the page the
 * first time an admin presses Save.
 *
 * Guarded here:
 *   A. No password field emits a value, whatever the caller passes.
 *   B. A field with something stored says so, so the admin can tell "empty"
 *      from "hidden".
 *   C. Every credential rendered on a settings page is declared `secret`, which
 *      is what makes the write paths treat blank as "keep".
 *   D. A blank submission keeps the stored value; a non-empty one replaces it;
 *      and a blank one with its Clear box ticked wipes it. Removal has to be
 *      said out loud, because a field that renders empty cannot distinguish
 *      "I did not touch this" from "delete this".
 *
 * Run: php tests/integration/password_field_no_value_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
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


// =========================================================================
section('A. A password field never emits a value');
// =========================================================================

// Every way a value can reach the field: passed explicitly, bound through
// set_values(), and bound through a model. All three must come out empty.
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
$html2 = $render($fw2, 'passwordinput', 'probe_bound', 'Probe');
check(strpos($html2, 'hunter2-bound') === false,
	'a value bound with set_values() does not reach the HTML either', $html2);

// A text field on the same form still round-trips, so the rule is scoped to
// credentials rather than being a blanket "never emit a value".
$fw3 = new FormWriterV2HTML5('pwtest3');
$html3 = $render($fw3, 'textinput', 'probe_text', 'Probe', array(
	'value' => 'ordinary-value',
));
check(strpos($html3, 'ordinary-value') !== false,
	'a plain text field is unaffected', $html3);


// =========================================================================
section('B. A stored credential says so');
// =========================================================================

$fw4 = new FormWriterV2HTML5('pwtest4');
$stored_html = $render($fw4, 'passwordinput', 'probe_stored', 'Probe', array(
	'value' => 'something-is-stored',
));
check(strpos($stored_html, 'leave blank to keep') !== false,
	'a field with a stored value gets the leave-blank placeholder', $stored_html);

$fw5 = new FormWriterV2HTML5('pwtest5');
$empty_html = $render($fw5, 'passwordinput', 'probe_empty', 'Probe');
check(strpos($empty_html, 'leave blank to keep') === false,
	'a field with nothing stored does not claim otherwise', $empty_html);

// An explicit placeholder is the caller's, not ours.
$fw6 = new FormWriterV2HTML5('pwtest6');
$custom_html = $render($fw6, 'passwordinput', 'probe_custom', 'Probe', array(
	'value' => 'stored',
	'placeholder' => 'paste the key here',
));
check(strpos($custom_html, 'paste the key here') !== false,
	'an explicit placeholder wins', $custom_html);


// =========================================================================
section('C. Every settings credential is declared secret');
// =========================================================================

// The write paths key off the declaration, not off the field type, so a
// credential the manifest does not mark is one a blank save will wipe.
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
// visible, and hiding it behind a masked field with a Clear box is friction for
// nothing. The store's naming is inverted: stripe_api_key is the PUBLISHABLE key
// and stripe_api_pkey is the secret; paypal_api_key is the client id.
foreach (array('stripe_api_key', 'stripe_api_key_test', 'paypal_api_key', 'paypal_api_key_test',
               'ses_access_key_id', 'mailjet_api_key', 'smtp_username') as $name) {
	check(!SettingsDeclarations::isSecret($name), "$name is public and stays writable-to-empty");
}

// The list above is a checklist, and a checklist only covers what someone
// remembered to add. This is the rule: a setting whose NAME says credential is
// one unless somebody says otherwise in writing. Without it, the next
// `myplugin_api_token` renders its value in the page source and nothing
// complains — which is exactly how this started.
//
// Adding a name here is the exception, and each one owes a reason.
$public_by_design = array(
	// The visible half of a credential pair. Masking these buys nothing and
	// makes them unclearable, and the store's naming is confusingly inverted:
	// *_api_key is the publishable key, *_api_pkey is the secret.
	'stripe_api_key'      => 'Stripe PUBLISHABLE key (pk_...); the secret is stripe_api_pkey',
	'stripe_api_key_test' => 'Stripe test publishable key',
	'paypal_api_key'      => 'PayPal Client ID; the secret is paypal_api_secret',
	'paypal_api_key_test' => 'PayPal test Client ID',
	'mailjet_api_key'     => 'Mailjet documents this as the public part of the pair',
);

$credential_shaped = '/(secret|password|passwd|_token$|api_key|apikey|_pkey|_private$|private_key|signing_key|service_account|credential)/i';
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
	// Managed values never reach a form, so the naming rule does not govern
	// them either way — file_signed_url_key is minted by code and read by code.
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


// =========================================================================
section('D. Blank keeps, non-empty replaces, Clear wipes');
// =========================================================================

$admin_uid = (int)$db->query(
	"SELECT usr_user_id FROM usr_users
	  WHERE usr_permission >= 10 AND usr_delete_time IS NULL
	  ORDER BY usr_user_id LIMIT 1"
)->fetchColumn();

if (!$admin_uid) {
	harness_skip('no superadmin account to act as');
} else {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $admin_uid;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = 10;

	// smtp_password is a declared core secret that both settings logics can
	// reach. Snapshot and restore it whatever happens.
	$probe = 'smtp_password';
	$read = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($probe));
	$original = $read->fetchColumn();

	if ($original === false) {
		harness_skip("$probe has no row on this deployment");
	} else {
		harness_defer(function () use ($db, $probe, $original) {
			$db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?")
			   ->execute(array($original, $probe));
		});

		$db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?")
		   ->execute(array('planted-secret-value', $probe));

		admin_settings_logic(array($probe => ''));
		$read->execute(array($probe));
		check($read->fetchColumn() === 'planted-secret-value',
			'a blank submission on the General save keeps the stored secret');

		admin_settings_email_logic(array($probe => ''));
		$read->execute(array($probe));
		check($read->fetchColumn() === 'planted-secret-value',
			'a blank submission on the Email save keeps it too');

		admin_settings_logic(array($probe => 'a-rotated-secret'));
		$read->execute(array($probe));
		check($read->fetchColumn() === 'a-rotated-secret',
			'a non-empty submission still replaces it');

		// Removal has to be said out loud, because a blank field cannot mean
		// both "I did not touch this" and "delete this".
		$clear = SettingsFieldRenderer::CLEAR_PREFIX . $probe;

		admin_settings_logic(array($probe => 'typed-a-new-one', $clear => '1'));
		$read->execute(array($probe));
		check($read->fetchColumn() === 'typed-a-new-one',
			'a typed value wins over a ticked Clear box, so a pasted key is never lost');

		admin_settings_logic(array($probe => '', $clear => '1'));
		$read->execute(array($probe));
		check($read->fetchColumn() === '',
			'blank plus Clear wipes the stored credential');

		// And the checkbox itself is never mistaken for a setting.
		check(Setting::isReservedName($clear), "$clear is never a setting");
		$count = $db->prepare("SELECT COUNT(*) FROM stg_settings WHERE stg_name = ?");
		$count->execute(array($clear));
		check((int)$count->fetchColumn() === 0, 'and no row was minted for it');

		// A non-secret setting must still be clearable — the keep rule is scoped
		// to credentials, not to empty strings in general.
		$plain = 'totp_issuer_name';
		$read->execute(array($plain));
		$plain_original = $read->fetchColumn();
		if ($plain_original === false) {
			harness_skip("$plain has no row to test the negative case with");
		} else {
			harness_defer(function () use ($db, $plain, $plain_original) {
				$db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?")
				   ->execute(array($plain_original, $plain));
			});
			$db->prepare("UPDATE stg_settings SET stg_value = 'something' WHERE stg_name = ?")
			   ->execute(array($plain));
			admin_settings_logic(array($plain => ''));
			$read->execute(array($plain));
			check($read->fetchColumn() === '',
				'a non-secret setting can still be cleared');
		}
	}
}

harness_finish();
