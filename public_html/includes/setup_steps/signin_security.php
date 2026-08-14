<?php
/**
 * Setup wizard step: Sign-in security (specs/setup_wizard.md § Step 1).
 * Mounts the existing ceremonies: passkey_register_options/_verify over the
 * API, and the security page's TOTP enrollment forwarded through setup_logic
 * (start_enable / confirm_enable). Included by views/setup.php with $page,
 * $page_vars, $viewer, $settings, $next_key in scope.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));

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
	<script>
	(function () {
		var codes = document.getElementById('setup-totp-codes').textContent;
		var saved = document.getElementById('setup-codes-saved');
		var cont = document.getElementById('setup-codes-continue');
		cont.style.pointerEvents = 'none'; cont.style.opacity = '0.5';
		saved.addEventListener('change', function () {
			var on = saved.checked;
			cont.style.pointerEvents = on ? '' : 'none';
			cont.style.opacity = on ? '' : '0.5';
			cont.setAttribute('aria-disabled', on ? 'false' : 'true');
		});
		document.getElementById('setup-codes-copy').addEventListener('click', function () {
			navigator.clipboard.writeText(codes);
		});
		document.getElementById('setup-codes-download').addEventListener('click', function () {
			var a = document.createElement('a');
			a.href = URL.createObjectURL(new Blob([codes], { type: 'text/plain' }));
			a.download = 'backup-codes.txt';
			a.click();
			URL.revokeObjectURL(a.href);
		});
	})();
	</script>
<?php return; } ?>

<?php if ($setup_passkeys_enabled) { ?>
	<div class="jy-fieldset">
		<h4>Passkey</h4>
<?php if ($setup_passkey_count > 0) { ?>
		<p class="jy-muted">You already have <?php echo (int)$setup_passkey_count; ?> passkey<?php echo $setup_passkey_count === 1 ? '' : 's'; ?>.</p>
<?php } else { ?>
<?php if ($setup_has_password) { ?>
		<div id="setup-pk-password-row">
			<label for="setup-pk-password">Confirm your password to add your first passkey</label>
			<input type="password" id="setup-pk-password" autocomplete="current-password" class="jy-w-full">
		</div>
<?php } ?>
<?php } ?>
		<div class="jy-mt-2">
			<button type="button" class="btn btn-primary" id="setup-pk-add">Add a passkey</button>
		</div>
		<p class="jy-muted" id="setup-pk-hint"></p>
	</div>
<?php } ?>

	<div class="jy-fieldset jy-mt-3">
		<h4>Authenticator app</h4>
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
		<form method="POST" action="/setup">
			<input type="hidden" name="action" value="start_enable">
			<input type="hidden" name="step" value="signin_security">
			<button type="submit" class="btn btn-secondary">Enable authenticator codes</button>
		</form>
<?php } ?>
	</div>

<?php if ($setup_passkeys_enabled) { ?>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
	var btn = document.getElementById('setup-pk-add');
	var hint = document.getElementById('setup-pk-hint');
	if (!btn) { return; }
	if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) {
		btn.disabled = true;
		hint.textContent = 'This browser does not support passkeys — use the authenticator app below.';
		return;
	}
	btn.addEventListener('click', async function () {
		hint.textContent = '';
		btn.disabled = true;
		try {
			var body = {};
			var pw = document.getElementById('setup-pk-password');
			if (pw) { body.current_password = pw.value; }
			var opts = await joineryApi.post('passkey_register_options', body);
			if (opts.second_factor_required) {
				hint.textContent = opts.error || 'Please re-confirm with an existing passkey on the security page first.';
				btn.disabled = false;
				return;
			}
			var credential = await JoineryPasskeys.register(opts.options);
			var res = await joineryApi.post('passkey_register_verify', {
				credential: credential,
				label: 'Added during setup'
			});
			// If a vault already exists and is unlocked, wrap it for the new
			// passkey — the same auto-activation the security page attempts.
			try {
				var vs = await joineryApi.post('vault_status', {});
				if (vs.set_up && vs.unlocked && res.passkey && res.passkey.vault_capability !== 'incapable') {
					var o2 = await joineryApi.post('vault_add_passkey_options', { credential_id: res.passkey.pkc_passkey_credential_id });
					var d = await JoineryPasskeys.derive(o2.options);
					await joineryApi.post('vault_add_passkey_verify', { credential: d.response });
				}
			} catch (e) { /* activation is best-effort here; the security page can finish it */ }
			window.location = '/setup?step=signin_security';
		} catch (e) {
			hint.textContent = e.message || 'The passkey could not be added.';
			btn.disabled = false;
		}
	});
});
</script>
<?php } ?>
