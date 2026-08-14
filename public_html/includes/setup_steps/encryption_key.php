<?php
/**
 * Setup wizard step: Your encryption key (specs/setup_wizard.md § Step 2).
 * Mounts the existing vault ceremony (vault_setup_options / vault_setup_verify)
 * over the API. Included by views/setup.php with $page, $viewer, $settings,
 * $next_key in scope.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));

$setup_vault_passkeys = new MultiPasskey(array('user_id' => (int)$viewer->key));
$setup_vault_passkey_count = $setup_vault_passkeys->count_all();
?>

<?php if ($setup_vault_passkey_count === 0) { ?>
	<div class="jy-callout jy-callout-info">
		<div class="jy-callout-title">A passkey comes first</div>
		<p>Your encryption key is unlocked with a passkey, so you need at least one before this step.</p>
	</div>
	<div class="jy-mt-2">
		<a class="btn btn-primary" href="/setup?step=signin_security">&larr; Add a passkey</a>
	</div>
<?php } else { ?>

	<div id="setup-vault-start">
		<p class="jy-muted">Turning this on also turns off passwordless sign-in for your account — with a key this valuable, sign-in always asks for verification.</p>
		<label class="jy-check">
			<input type="checkbox" id="setup-vault-ack">
			I understand that if I lose my passkeys, recovery codes, and key file, this data is <strong>gone for good</strong> — nobody can recover it for me.
		</label>
		<div class="jy-mt-2">
			<button type="button" class="btn btn-primary" id="setup-vault-create" disabled>Create my encryption key</button>
		</div>
		<p class="jy-muted" id="setup-vault-hint"></p>
	</div>

	<div id="setup-vault-result" class="d-none">
		<div class="jy-callout jy-callout-warning">
			<div class="jy-callout-title">Save your recovery codes and key file</div>
			<p>Either one can unlock your data if you lose your passkeys. They are shown only now.</p>
			<pre class="jy-security-codes" id="setup-vault-codes"></pre>
			<button type="button" class="btn btn-secondary" id="setup-vault-copy">Copy codes</button>
			<button type="button" class="btn btn-secondary" id="setup-vault-codes-download">Download codes</button>
			<button type="button" class="btn btn-secondary" id="setup-vault-keyfile-download">Download key file</button>
		</div>
		<label class="jy-check jy-mt-2">
			<input type="checkbox" id="setup-vault-saved"> I've saved these somewhere safe — not on this server.
		</label>
		<div class="jy-mt-2">
			<a class="btn btn-primary" id="setup-vault-continue" href="/setup?step=<?php echo urlencode($next_key); ?>" aria-disabled="true">Continue &rarr;</a>
		</div>
	</div>

<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
	var ack = document.getElementById('setup-vault-ack');
	var create = document.getElementById('setup-vault-create');
	var hint = document.getElementById('setup-vault-hint');
	if (!ack || !create) { return; }

	ack.addEventListener('change', function () { create.disabled = !ack.checked; });

	create.addEventListener('click', async function () {
		hint.textContent = '';
		create.disabled = true;
		try {
			var opts = await joineryApi.post('vault_setup_options', {});
			var derived = await JoineryPasskeys.derive(opts.options);
			var result = await joineryApi.post('vault_setup_verify', {
				credential: derived.response,
				acknowledged: 1
			});
			var codes = (result.recovery_codes || []).join('\n');
			document.getElementById('setup-vault-codes').textContent = codes;
			document.getElementById('setup-vault-start').classList.add('d-none');
			document.getElementById('setup-vault-result').classList.remove('d-none');

			document.getElementById('setup-vault-copy').addEventListener('click', function () {
				navigator.clipboard.writeText(codes);
			});
			document.getElementById('setup-vault-codes-download').addEventListener('click', function () {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([codes], { type: 'text/plain' }));
				a.download = 'vault-recovery-codes.txt';
				a.click();
				URL.revokeObjectURL(a.href);
			});
			document.getElementById('setup-vault-keyfile-download').addEventListener('click', function () {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([JSON.stringify(result.key_file, null, 2)], { type: 'application/json' }));
				a.download = 'vault-key-file.json';
				a.click();
				URL.revokeObjectURL(a.href);
			});

			var saved = document.getElementById('setup-vault-saved');
			var cont = document.getElementById('setup-vault-continue');
			cont.style.pointerEvents = 'none'; cont.style.opacity = '0.5';
			saved.addEventListener('change', function () {
				var on = saved.checked;
				cont.style.pointerEvents = on ? '' : 'none';
				cont.style.opacity = on ? '' : '0.5';
				cont.setAttribute('aria-disabled', on ? 'false' : 'true');
			});
		} catch (e) {
			if (e.data && e.data.requires_password) {
				hint.textContent = 'Your account needs a password before a vault can be created — set one on the security page first.';
			} else {
				hint.textContent = e.message || 'The vault could not be created.';
			}
			create.disabled = !ack.checked;
		}
	});
});
</script>
<?php } ?>
