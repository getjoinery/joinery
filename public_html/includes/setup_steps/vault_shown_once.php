<?php
/**
 * The shown-once panel after a vault is created: the recovery codes and the
 * "I've saved these" gate on Continue. The optional key file is not offered
 * here; it is a download on the security page, built fresh on request.
 *
 * Shared by both routes into a key — the passkey ceremony and the bypass-phrase
 * compatibility fallback — because what has to be saved, and the fact that it
 * is shown exactly once, is identical either way. Included by
 * includes/setup_steps/encryption_key.php with $next_key in scope.
 *
 * Defines window.setupVaultShowResult(result), which swaps the step's form for
 * this panel and wires the copy/download buttons to that result.
 *
 * @version 1.1 - codes only; the key file moved to the security page
 * @version 1.0
 */
?>
<div id="setup-vault-result" class="d-none">
	<div class="jy-callout jy-callout-warning">
		<div class="jy-callout-title">Save your recovery codes</div>
		<p>Each code unlocks your data once if you lose your passkeys. They are shown only now.</p>
		<pre class="jy-security-codes" id="setup-vault-codes"></pre>
		<button type="button" class="btn btn-secondary" id="setup-vault-copy">Copy codes</button>
		<button type="button" class="btn btn-secondary" id="setup-vault-codes-download">Download codes</button>
	</div>
	<label class="jy-check jy-mt-2">
		<input type="checkbox" id="setup-vault-saved">
		<span>I've saved these somewhere safe — not on this server.</span>
	</label>
	<div class="jy-mt-2">
		<a class="btn btn-primary" id="setup-vault-continue" href="/setup?step=<?php echo urlencode($next_key); ?>" aria-disabled="true">Continue &rarr;</a>
	</div>
</div>

<script>
window.setupVaultShowResult = function (result, hideElementId) {
	var codes = (result.recovery_codes || []).join('\n');
	document.getElementById('setup-vault-codes').textContent = codes;
	var hide = document.getElementById(hideElementId);
	if (hide) { hide.classList.add('d-none'); }
	document.getElementById('setup-vault-result').classList.remove('d-none');

	function download(name, blob) {
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = name;
		a.click();
		URL.revokeObjectURL(a.href);
	}

	document.getElementById('setup-vault-copy').addEventListener('click', function () {
		navigator.clipboard.writeText(codes);
	});
	document.getElementById('setup-vault-codes-download').addEventListener('click', function () {
		download('vault-recovery-codes.txt', new Blob([codes], { type: 'text/plain' }));
	});

	// Continue stays inert until the codes are acknowledged — this screen is
	// the only time they exist.
	var saved = document.getElementById('setup-vault-saved');
	var cont = document.getElementById('setup-vault-continue');
	cont.style.pointerEvents = 'none';
	cont.style.opacity = '0.5';
	saved.addEventListener('change', function () {
		var on = saved.checked;
		cont.style.pointerEvents = on ? '' : 'none';
		cont.style.opacity = on ? '' : '0.5';
		cont.setAttribute('aria-disabled', on ? 'false' : 'true');
	});
};
</script>
