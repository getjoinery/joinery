<?php
/**
 * Setup wizard step: Sign-in security (specs/setup_wizard.md § Step 1).
 * Mounts the existing ceremonies: passkey_register_options/_verify over the
 * API, and the security page's TOTP enrollment forwarded through setup_logic
 * (start_enable / confirm_enable). Included by views/setup.php with $page,
 * $page_vars, $viewer, $settings, $next_key in scope.
 *
 * @version 1.7 - its scripts are assets/js/setup/signin_security.js (no inline script)
 * @version 1.6
 * @changelog 1.6 - An account whose second factor must be confirmed first is
 *   taken to /profile/verify-stepup and brought back, not left at a message.
 * @changelog 1.5 - The authenticator-app copy names extra mail protection as
 *   what requires it.
 * @changelog 1.4 - Renders once the step is green too: an existing passkey shows
 *   as done and points at the security page for more; the authenticator app
 *   stays offered as an optional extra (extra mail protection needs it).
 * @changelog 1.3 - The authenticator-app section says what it is for; the
 *   password prompt says why a signed-in browser is not enough.
 * @changelog 1.2 - The insecure-context hint names the Secure connection step
 *   instead of protocol jargon.
 * @changelog 1.1 - Password-confirm input uses the jy-ui form-control style;
 *   when the passkey ceremony is unavailable the password row hides and the
 *   hint distinguishes an insecure (non-HTTPS) context from a browser that
 *   lacks WebAuthn.
 */
require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));

$setup_passkeys_enabled = (string)$settings->get_setting('passkeys_enabled') === '1';
$setup_has_password = trim((string)$viewer->get('usr_password')) !== '';
$setup_passkeys = new MultiPasskey(array('user_id' => (int)$viewer->key));
$setup_passkey_count = $setup_passkeys->count_all();
$setup_totp_enabled = $viewer->has_totp_enabled();

$totp_just_enabled = !empty($page_vars['totp_just_enabled']);
$totp_in_progress = !empty($page_vars['totp_setup_in_progress']);
$totp_backup_codes = $page_vars['totp_backup_codes'] ?? array();
?>

<?php if ($totp_just_enabled) { ?>
	<div class="jy-callout jy-callout-warning">
		<div class="jy-callout-title">Save your backup codes</div>
		<p>Each code signs you in once if you lose your authenticator. They are shown only now.</p>
		<pre class="jy-security-codes" id="setup-totp-codes"><?php echo htmlspecialchars(implode("\n", $totp_backup_codes)); ?></pre>
		<button type="button" class="btn btn-secondary" id="setup-codes-copy">Copy</button>
		<button type="button" class="btn btn-secondary" id="setup-codes-download">Download</button>
	</div>
	<label class="jy-check jy-mt-2">
		<input type="checkbox" id="setup-codes-saved"> I've saved these codes somewhere safe.
	</label>
	<div class="jy-mt-2">
		<a class="btn btn-primary" id="setup-codes-continue" href="/setup?step=<?php echo urlencode($next_key); ?>" aria-disabled="true">Continue &rarr;</a>
	</div>
<?php return; } ?>

<?php if ($setup_passkeys_enabled) { ?>
	<div class="jy-fieldset">
		<h4>Passkey</h4>
<?php if ($setup_passkey_count > 0) { ?>
		<p><span class="badge badge-success">On</span> You have <?php echo (int)$setup_passkey_count; ?> passkey<?php echo $setup_passkey_count === 1 ? '' : 's'; ?>. Add more from your <a href="/profile/security">security page</a>.</p>
<?php } else { ?>
<?php if ($setup_has_password) { ?>
		<div id="setup-pk-password-row">
			<label for="setup-pk-password">Confirm your password to add your first passkey</label>
			<p class="jy-muted">A passkey signs you in on its own, so adding one always asks for more than a signed-in browser.</p>
			<input type="password" id="setup-pk-password" autocomplete="current-password" class="form-control">
		</div>
<?php } ?>
		<div class="jy-mt-2">
			<button type="button" class="btn btn-primary" id="setup-pk-add">Add a passkey</button>
		</div>
		<p class="jy-muted" id="setup-pk-hint"></p>
<?php } ?>
	</div>
<?php } ?>

	<div class="jy-fieldset jy-mt-3">
		<h4>Authenticator app <span class="jy-muted" style="font-weight: normal; font-size: 0.85em;">(optional)</span></h4>
<?php if ($setup_totp_enabled) { ?>
		<p><span class="badge badge-success">On</span> Authenticator codes are enabled for your account.</p>
<?php } elseif ($totp_in_progress) { ?>
		<p>Scan this with your authenticator app, then enter the 6-digit code it shows.</p>
<?php if (!empty($page_vars['totp_qr_uri'])) { ?>
		<img src="<?php echo htmlspecialchars($page_vars['totp_qr_uri']); ?>" alt="Authenticator QR code" width="180" height="180">
<?php } ?>
		<p class="jy-muted">Can't scan? Enter this key manually: <code><?php echo htmlspecialchars($page_vars['totp_secret'] ?? ''); ?></code></p>
<?php
		$formwriter = $page->getFormWriter('setup-totp-confirm', array('action' => '/setup', 'method' => 'POST'));
		$formwriter->begin_form();
		$formwriter->hiddeninput('action', '', array('value' => 'confirm_enable'));
		$formwriter->hiddeninput('step', '', array('value' => 'signin_security'));
		echo $formwriter->textinput('totp_code', 'Code from your app', array(
			'required' => true,
			'autocomplete' => 'one-time-code',
			'inputmode' => 'numeric',
		));
		echo $formwriter->submitbutton('btn_totp_confirm', 'Turn on codes', array('class' => 'btn btn-primary'));
		$formwriter->end_form();
?>
<?php } else { ?>
		<p class="jy-muted">A second way to sign in, using 6-digit codes from an app such as Google Authenticator, Authy or 1Password. Codes work in any browser, and they get you back in if you lose the device that holds your passkey. Not required now — you can turn them on later from your security page — but an account with extra mail protection needs them, or a second passkey.</p>
		<form method="POST" action="/setup">
			<input type="hidden" name="action" value="start_enable">
			<input type="hidden" name="step" value="signin_security">
			<button type="submit" class="btn btn-secondary">Enable authenticator codes</button>
		</form>
<?php } ?>
	</div>

<?php if ($setup_passkeys_enabled) { ?>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<?php } ?>
