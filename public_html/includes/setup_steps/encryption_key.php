<?php
/**
 * Setup wizard step: Your encryption key (specs/setup_wizard.md § Step 2).
 * Mounts the existing vault ceremony (vault_setup_options / vault_setup_verify)
 * over the API. Included by views/setup.php with $page, $viewer, $settings,
 * $next_key in scope.
 *
 * @version 1.4
 */
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));

// The key is unlocked by a passkey that can derive a PRF secret. A U2F-only
// authenticator answers 'incapable' and can never hold a wrapping, so an
// account holding only those cannot make a key however willing it is — that,
// and a missing account password, are the only ways past this step. The
// branch question is the ceremony's own gate, asked of the same predicate —
// this page never hand-rolls a second copy of it.
$setup_vault_passkey_count = (new MultiPasskey(array('user_id' => (int)$viewer->key)))->count_all();
$setup_vault_blocked = Passkey::userNeedsPassphraseFallback((int)$viewer->key);
$setup_vault_phrase_min = (int)SealedBox::PASSPHRASE_MIN_CHARS;
?>

<?php if ($setup_vault_passkey_count === 0) { ?>
	<div class="jy-callout jy-callout-info">
		<div class="jy-callout-title">A passkey comes first</div>
		<p>Your key is unlocked with a passkey, so you need at least one before this step.</p>
	</div>
	<div class="jy-mt-2">
		<a class="btn btn-primary" href="/setup?step=signin_security">&larr; Add a passkey</a>
	</div>
<?php } elseif ($setup_vault_blocked) { ?>
	<div id="setup-vault-phrase-branch">
	<div class="jy-callout jy-callout-warning">
		<div class="jy-callout-title">This device can't hold your key</div>
		<p>Your passkeys sign you in, but they cannot derive an encryption key — a limit of the device or security key itself, not a setting you can change. Common on iPhones before iOS 18, Windows 10, and older security keys.</p>
		<p>If you have a newer phone, laptop or a password manager, adding a passkey there is the better route. Otherwise you can unlock with a phrase you memorise.</p>
	</div>
	<div class="setup-choice jy-mt-2">
		<a class="btn btn-primary" href="/setup?step=signin_security">&larr; Add a passkey elsewhere</a>
		<button type="button" class="btn btn-secondary" id="setup-vault-phrase-open">Use a bypass phrase instead</button>
	</div>

	<div id="setup-vault-phrase" class="d-none jy-mt-3">
		<p class="jy-muted">A phrase you type is weaker than a passkey you tap — it can be guessed, and it can be phished. It is here because your device leaves no better option. Use something long and unique, and store it in a password manager if you have one. Minimum <?php echo $setup_vault_phrase_min; ?> characters.</p>
		<label class="setup-field">
			<span>Bypass phrase</span>
			<input type="password" id="setup-vault-phrase-1" autocomplete="new-password">
		</label>
		<label class="setup-field jy-mt-2">
			<span>Type it again</span>
			<input type="password" id="setup-vault-phrase-2" autocomplete="new-password">
		</label>
		<label class="jy-check jy-mt-2">
			<input type="checkbox" id="setup-vault-phrase-ack">
			<span>I understand that if I forget this phrase and lose my recovery codes, this data is <strong>gone for good</strong> — nobody can recover it for me.</span>
		</label>
		<div class="jy-mt-2">
			<button type="button" class="btn btn-primary" id="setup-vault-phrase-create" disabled>Create my encryption key</button>
		</div>
		<p class="jy-muted" id="setup-vault-phrase-hint"></p>
	</div>
	<?php if (!SetupSteps::hasDecision('encryption_key', (int)$viewer->key)) { ?>
	<form method="POST" action="/setup" class="jy-mt-3" id="setup-vault-blocked-form">
		<input type="hidden" name="action" value="decline_step">
		<input type="hidden" name="step_key" value="encryption_key">
		<button type="submit" class="btn btn-link jy-muted">Continue without one</button>
	</form>
	<?php } ?>
	</div><!-- /#setup-vault-phrase-branch -->

	<?php require(PathHelper::getIncludePath('includes/setup_steps/vault_shown_once.php')); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
	var open = document.getElementById('setup-vault-phrase-open');
	var panel = document.getElementById('setup-vault-phrase');
	var one = document.getElementById('setup-vault-phrase-1');
	var two = document.getElementById('setup-vault-phrase-2');
	var ack = document.getElementById('setup-vault-phrase-ack');
	var create = document.getElementById('setup-vault-phrase-create');
	var hint = document.getElementById('setup-vault-phrase-hint');
	if (!open || !panel) { return; }

	open.addEventListener('click', function () {
		panel.classList.remove('d-none');
		open.classList.add('d-none');
		one.focus();
	});

	function sync() {
		create.disabled = !(ack.checked && one.value.length >= <?php echo $setup_vault_phrase_min; ?> && one.value === two.value);
	}
	[one, two].forEach(function (el) { el.addEventListener('input', sync); });
	ack.addEventListener('change', sync);

	create.addEventListener('click', async function () {
		hint.textContent = '';
		create.disabled = true;
		try {
			var result = await joineryApi.post('vault_setup_passphrase', {
				passphrase: one.value,
				passphrase_confirm: two.value,
				acknowledged: 1
			});
			// Shared second-factor step-up handling: a 2xx render carrying the
			// flag redirects to the ceremony, then back to this step.
			if (result && result.second_factor_required) {
				window.location = '/verify-stepup?return=' + encodeURIComponent('/setup?step=encryption_key');
				return;
			}
			// The whole step collapses, not just the phrase panel — the
			// "add a passkey elsewhere" route above it is moot now.
			window.setupVaultShowResult(result, 'setup-vault-phrase-branch');
		} catch (e) {
			hint.textContent = e.message || 'The key could not be created.';
			sync();
		}
	});
});
</script>
<?php } else { ?>

	<div id="setup-vault-start">
		<div class="jy-callout jy-callout-info">
			<div class="jy-callout-title">What it will protect, if you want it to</div>
			<ul>
				<li><strong>Private mail</strong> — stored locked, unreadable to anyone with server or backup access.</li>
				<li><strong>Private files</strong> — Drive folders only you can open.</li>
				<li><strong>Saved passwords</strong> — every entry in the password manager.</li>
				<li><strong>Encrypted chats</strong> — taking part in Private or Guarded conversations, here and on other Joinery sites.</li>
			</ul>
		</div>
		<!-- .jy-check is a flex row: the label text needs to be one element,
		     or inline emphasis inside it becomes its own column. -->
		<label class="jy-check">
			<input type="checkbox" id="setup-vault-ack">
			<span>I understand that if I lose my passkeys, recovery codes, and key file, this data is <strong>gone for good</strong> — nobody can recover it for me.</span>
		</label>
		<!-- No decline: every account gets a key. The only ways past this step
		     are the two the platform cannot solve for the user — hardware that
		     cannot derive one, and an account with no password yet. -->
		<div class="jy-mt-2">
			<button type="button" class="btn btn-primary" id="setup-vault-create" disabled>Create my encryption key</button>
		</div>
		<p class="jy-muted" id="setup-vault-hint"></p>
		<!-- Revealed when a derivation fails. Only the server can say what is
		     left — another credential to try, or the bypass phrase — so the way
		     forward is to re-enter the step and let it decide again with the
		     failure now on record. -->
		<div id="setup-vault-retry" class="d-none jy-mt-2">
			<a class="btn btn-primary" href="/setup?step=encryption_key">Show my options</a>
		</div>
		<?php if (!SetupSteps::hasDecision('encryption_key', (int)$viewer->key)) { ?>
		<!-- Revealed only when the ceremony refuses for a reason this page
		     cannot fix, so a blocked account is never stuck on this step. -->
		<form method="POST" action="/setup" id="setup-vault-blocked" class="d-none">
			<input type="hidden" name="action" value="decline_step">
			<input type="hidden" name="step_key" value="encryption_key">
			<button type="submit" class="btn btn-secondary">Continue without one</button>
		</form>
		<?php } ?>
	</div>

	<?php require(PathHelper::getIncludePath('includes/setup_steps/vault_shown_once.php')); ?>

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
			window.setupVaultShowResult(result, 'setup-vault-start');
		} catch (e) {
			// Every refusal this page cannot talk the user out of reveals the
			// fallback: a mandatory step must never become a dead end. PRF
			// support is only provable by trying, so this is where an
			// authenticator that cannot derive a key finally announces itself.
			var blocked = document.getElementById('setup-vault-blocked');
			if (e.data && e.data.requires_password) {
				hint.textContent = 'Your account needs a password before a key can be created — set one on your security page first.';
				if (blocked) { blocked.classList.remove('d-none'); }
			} else if (e.data && e.data.prf_unsupported) {
				hint.textContent = 'This passkey cannot hold an encryption key — that is a limit of the device or security key, not a setting. '
					+ 'A passkey from a phone, laptop or password manager can, and there is another way if none of yours can.';
				var retry = document.getElementById('setup-vault-retry');
				if (retry) { retry.classList.remove('d-none'); }
				if (blocked) { blocked.classList.remove('d-none'); }
			} else {
				hint.textContent = e.message || 'The key could not be created.';
			}
			create.disabled = !ack.checked;
		}
	});
});
</script>
<?php } ?>
