<?php
/**
 * Setup wizard step: Sign-in security (specs/setup_wizard.md § Step 1).
 * Passkey only: mounts passkey_register_options/_verify over the API.
 * Authenticator codes are set up from the security page, not here. Included
 * by views/setup.php with $page, $page_vars, $viewer, $settings, $next_key in
 * scope.
 *
 * @version 1.9 - passkey only: the authenticator app and its backup codes are left to the security page
 * @version 1.8 - says so when the site requires its administrators to have a second factor: skipping does not skip it
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

// This site asks its administrators for a second factor: until one is set
// up, every page but this one and the security page sends them back here.
// Said up front, because Skip for now does not skip that.
$setup_factor_required = SessionControl::get_instance()->must_enable_totp_for_admin();
?>

<?php if ($setup_factor_required) { ?>
	<p class="alert alert-warning"><strong>This one is needed.</strong> As this site's administrator you need a second way
		to sign in before you can use the rest of the site. You can skip the other steps; without one, every page
		brings you back to your security settings.</p>
<?php } ?>

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
<?php } else { ?>
	<p>Passkeys are turned off on this site. Set up a second way to sign in from your <a href="/profile/security">security page</a>.</p>
<?php } ?>

<?php if ($setup_passkeys_enabled) { ?>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<?php } ?>
