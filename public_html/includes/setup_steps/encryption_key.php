<?php
/**
 * Setup wizard step: Your encryption key (specs/setup_wizard.md § Step 2).
 * Mounts the vault setup ceremony (VaultKeyring.setupVault, over
 * vault_setup_options / vault_setup_verify / vault_setup_passphrase). Included by views/setup.php with $page, $viewer, $settings,
 * $next_key in scope.
 *
 * @version 1.8 - its scripts are assets/js/setup/encryption_key.js; the phrase minimum is data-min-chars (no inline script)
 * @version 1.7 - the shown-once panel offers the codes only
 * @version 1.6 - one vault: the codes, the phrase's keys and the root vault are made in the browser
 * @version 1.5 - "Add a passkey elsewhere" goes to the security page (the wizard interrupt
 *                exempts it); the bypass-phrase route steps up BEFORE the phrase is typed
 * @version 1.4
 */
require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));

// The key is unlocked by a passkey that can derive a PRF secret. A U2F-only
// authenticator answers 'incapable' and can never hold a wrapping, so an
// account holding only those cannot make a key however willing it is — that,
// and a missing account password, are the only ways past this step. The
// branch question is the ceremony's own gate, asked of the same predicate —
// this page never hand-rolls a second copy of it.
$setup_vault_passkey_count = (new MultiPasskey(array('user_id' => (int)$viewer->key)))->count_all();
$setup_vault_blocked = Passkey::userNeedsPassphraseFallback((int)$viewer->key);
$setup_vault_phrase_min = (int)SealedBox::PASSPHRASE_MIN_CHARS;
// Minting a key under a phrase demands the account's second factor
// (vault_setup_passphrase). Asked BEFORE the phrase panel opens, so the
// ceremony never discards a phrase already typed: the button becomes the
// step-up link, which returns here with ?phrase=1 to open the panel.
$setup_vault_stepup_first = SessionControl::get_instance()->step_up_outstanding($viewer);
$setup_vault_phrase_return = '/setup?step=encryption_key&phrase=1';
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
		<a class="btn btn-primary" href="/profile/security">&larr; Add a passkey elsewhere</a>
<?php if ($setup_vault_stepup_first) { ?>
		<a class="btn btn-secondary" href="/profile/verify-stepup?return=<?php echo urlencode($setup_vault_phrase_return); ?>">Use a passphrase instead</a>
<?php } else { ?>
		<button type="button" class="btn btn-secondary" id="setup-vault-phrase-open">Use a passphrase instead</button>
<?php } ?>
	</div>
	<p class="jy-muted">Adding a passkey happens on your security page; the "Finish setup" reminder in the header brings you back here.</p>

	<div id="setup-vault-phrase" class="d-none jy-mt-3">
		<p class="jy-muted">A phrase you type is weaker than a passkey you tap — it can be guessed, and it can be phished. It is here because your device leaves no better option. Use something long and unique, and store it in a password manager if you have one. Minimum <?php echo $setup_vault_phrase_min; ?> characters.</p>
		<label class="setup-field">
			<span>Passphrase</span>
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
			<button type="button" class="btn btn-primary" id="setup-vault-phrase-create" data-min-chars="<?php echo (int)$setup_vault_phrase_min; ?>" disabled>Create my encryption key</button>
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

<?php } else { ?>

	<div id="setup-vault-start">
		<div class="jy-callout jy-callout-info">
			<div class="jy-callout-title">What it will protect, if you want it to</div>
			<ul>
				<li><strong>Private mail</strong> — stored locked, unreadable to anyone with server or backup access.</li>
				<li><strong>Private files</strong> — Drive folders only you can open.</li>
				<li><strong>Saved passwords</strong> — every entry in the password manager.</li>
				<li><strong>Encrypted chats</strong> — taking part in Private conversations, here and on other Joinery sites.</li>
			</ul>
		</div>
		<!-- .jy-check is a flex row: the label text needs to be one element,
		     or inline emphasis inside it becomes its own column. -->
		<label class="jy-check">
			<input type="checkbox" id="setup-vault-ack">
			<span>I understand that if I lose my passkeys and recovery codes, this data is <strong>gone for good</strong> — nobody can recover it for me.</span>
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

<?php } ?>
