<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('vault_home_logic.php', 'logic', 'system', null, 'vault'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

$page_vars = process_logic(vault_home_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$asset = function ($rel) {
	$path = PathHelper::getIncludePath('plugins/vault/assets/' . $rel);
	return '/plugins/vault/assets/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
};

// ---- FormWriter fields, rendered WITHOUT a <form> wrapper ------------------
// The active theme wraps page content in its own <form>, and HTML forbids
// nested forms - a <form> here would be hoisted out of the app and break the
// theme's form. So we emit FormWriter's field markup (labels, inputs,
// validation styling, and the self-contained visibility_rules script all come
// with each field) inside plain <div>s, and drive save/unlock with explicit
// buttons. The manager JS reads the fields by id and does all crypto locally.

$fw = new FormWriterV2HTML5('jy_vault_fields', ['method' => 'post', 'novalidate' => true]);

// FormWriter field methods echo into the buffer begin_form() opens; here there
// is no <form> (see above), so we capture each field's markup with our own
// output buffer instead.
$capture = function (callable $build) use ($fw) {
	ob_start();
	$build($fw);
	return ob_get_clean();
};

$entry_html = $capture(function ($fw) {
	$fw->hiddeninput('entry_id', '');
	$fw->dropinput('entry_type', 'Type', [
		'options' => ['login' => 'Login', 'note' => 'Secure Note'],
		'value'   => 'login',
		'visibility_rules' => [
			'login' => ['show' => ['entry_username', 'entry_password', 'entry_url', 'entry_totp_seed']],
			'note'  => ['hide' => ['entry_username', 'entry_password', 'entry_url', 'entry_totp_seed']],
		],
	]);
	$fw->textinput('entry_title', 'Title', ['required' => true, 'placeholder' => 'e.g. Gmail']);
	$fw->textinput('entry_username', 'Username', ['placeholder' => 'name@example.com']);
	$fw->passwordinput('entry_password', 'Password', ['autocomplete' => 'new-password']);
	$fw->textinput('entry_url', 'Website', ['placeholder' => 'https://']);
	$fw->textinput('entry_totp_seed', 'Authenticator key (TOTP)', ['placeholder' => 'Base32 secret']);
	$fw->textarea('entry_notes', 'Notes', ['rows' => 4]);
});

$setup_html = $capture(function ($fw) {
	$fw->checkboxinput('ack_loss', 'I understand that if I lose every unlocker (passkey, recovery key, and passphrase), everything in my vault is permanently gone - there is no support-desk recovery.', ['required' => true]);
});

$setup_pp_html = $capture(function ($fw) {
	$fw->passwordinput('setup_passphrase', 'Passphrase', ['autocomplete' => 'new-password', 'validation' => ['minlength' => 10]]);
	$fw->passwordinput('setup_passphrase_confirm', 'Confirm passphrase', ['autocomplete' => 'new-password']);
});

$unlock_pp_html = $capture(function ($fw) {
	$fw->passwordinput('unlock_passphrase', 'Passphrase', ['autocomplete' => 'current-password']);
});

$unlock_rec_html = $capture(function ($fw) {
	$fw->textinput('recovery_code', 'Recovery key', ['placeholder' => 'XXXX-XXXX-XXXX-...', 'autocomplete' => 'off']);
});

$config = [
	'passkeysEnabled'        => (bool)$passkeys_enabled,
	'autolockMinutes'        => (int)$autolock_minutes,
	'clipboardClearSeconds'  => (int)$clipboard_clear_seconds,
	'scope'                  => 'passwords',
];

$page = new PublicPage();
$hoptions = ['title' => 'Passwords', 'breadcrumbs' => ['Passwords' => '']];
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Passwords', $hoptions);
?>
<div id="jy-vault-app" class="jy-ui jy-vault" data-config='<?php echo htmlspecialchars(json_encode($config), ENT_QUOTES); ?>'>

	<div id="jy-vault-loading" class="jy-vault-centered">
		<div class="jy-vault-spinner" aria-hidden="true"></div>
		<p>Opening your vault…</p>
	</div>

	<div id="jy-vault-unsupported" class="jy-vault-centered" hidden>
		<h2>This browser can't open your vault</h2>
		<p>Your password vault is encrypted and decrypted entirely in your browser, which needs modern
		WebCrypto (including X25519). Please use an up-to-date browser.</p>
	</div>

	<!-- First-run ceremony -->
	<section id="jy-vault-ceremony" class="jy-vault-ceremony" hidden>
		<div class="jy-vault-ceremony-inner">
			<ol class="jy-vault-steps" aria-hidden="true">
				<li data-step="method" class="is-active">Unlock method</li>
				<li data-step="recovery">Recovery key</li>
				<li data-step="done">Done</li>
			</ol>

			<div class="jy-vault-step" data-step="method">
				<h1>Set up your password vault</h1>
				<p>Your vault is protected by a key only your devices ever see. Choose how you'll unlock it.</p>
				<?php echo $setup_html; ?>
				<div id="jy-vault-setup-passphrase-fields" hidden><?php echo $setup_pp_html; ?></div>
				<div class="jy-vault-actions">
					<button type="button" id="jy-vault-setup-passkey" class="jy-btn jy-btn-primary">Set up with a passkey</button>
					<button type="button" id="jy-vault-setup-passphrase-toggle" class="jy-btn jy-btn-link">Use a passphrase instead</button>
					<button type="button" id="jy-vault-setup-passphrase" class="jy-btn jy-btn-primary" hidden>Set up with a passphrase</button>
				</div>
				<p class="jy-vault-error" id="jy-vault-setup-error" role="alert" hidden></p>
			</div>

			<div class="jy-vault-step" data-step="recovery" hidden>
				<h1>Save your recovery key</h1>
				<p>If you ever lose your passkey and passphrase, a recovery key is the <strong>only</strong>
				way back in. We show these once and never again.</p>
				<div id="jy-vault-recovery-codes" class="jy-vault-recovery-codes" aria-label="Recovery keys"></div>
				<div class="jy-vault-actions">
					<button type="button" id="jy-vault-download-recovery" class="jy-btn">Download recovery file</button>
				</div>
				<label class="jy-vault-proof">
					<span>To confirm you've saved it, re-type the <strong>last</strong> recovery key:</span>
					<input type="text" id="jy-vault-recovery-proof" class="jy-input" autocomplete="off" spellcheck="false">
				</label>
				<div class="jy-vault-actions">
					<button type="button" id="jy-vault-recovery-finish" class="jy-btn jy-btn-primary" disabled>Continue</button>
				</div>
				<p class="jy-vault-error" id="jy-vault-recovery-error" role="alert" hidden></p>
			</div>

			<div class="jy-vault-step" data-step="done" hidden>
				<h1>Your vault is ready</h1>
				<p>Add your first password, and it'll be encrypted before it ever leaves this device.</p>
				<div class="jy-vault-actions">
					<button type="button" id="jy-vault-ceremony-add" class="jy-btn jy-btn-primary">Add your first entry</button>
				</div>
			</div>
		</div>
	</section>

	<!-- Locked -->
	<section id="jy-vault-unlock" class="jy-vault-unlock" hidden>
		<div class="jy-vault-unlock-inner">
			<h1>Unlock your vault</h1>
			<button type="button" id="jy-vault-unlock-passkey" class="jy-btn jy-btn-primary jy-btn-block">Unlock with a passkey</button>

			<div id="jy-vault-unlock-passphrase-wrap" class="jy-vault-unlock-alt" hidden>
				<div class="jy-vault-or">or</div>
				<?php echo $unlock_pp_html; ?>
				<button type="button" id="jy-vault-unlock-passphrase-btn" class="jy-btn jy-btn-block">Unlock</button>
			</div>

			<div id="jy-vault-unlock-recovery-wrap" class="jy-vault-unlock-alt" hidden>
				<?php echo $unlock_rec_html; ?>
				<button type="button" id="jy-vault-unlock-recovery-btn" class="jy-btn jy-btn-block">Unlock</button>
			</div>

			<div class="jy-vault-unlock-links">
				<button type="button" id="jy-vault-show-recovery" class="jy-btn jy-btn-link">Use a recovery key</button>
			</div>
			<p class="jy-vault-error" id="jy-vault-unlock-error" role="alert" hidden></p>
		</div>
	</section>

	<!-- Unlocked manager -->
	<section id="jy-vault-manager" class="jy-vault-manager" hidden>
		<p id="jy-vault-decrypt-warning" class="jy-vault-decrypt-warning" role="alert" hidden></p>
		<aside class="jy-vault-list-pane">
			<div class="jy-vault-list-toolbar">
				<input type="search" id="jy-vault-search" class="jy-input" placeholder="Search" autocomplete="off" spellcheck="false">
				<button type="button" id="jy-vault-add" class="jy-btn jy-btn-primary" title="Add entry">+</button>
			</div>
			<ul id="jy-vault-list" class="jy-vault-list"></ul>
			<div class="jy-vault-list-footer">
				<label class="jy-vault-autolock">Auto-lock
					<select id="jy-vault-autolock-select" class="jy-input">
						<option value="5">5 min</option>
						<option value="15">15 min</option>
						<option value="30">30 min</option>
						<option value="60">60 min</option>
					</select>
				</label>
				<div class="jy-vault-tools">
					<button type="button" id="jy-vault-trash" class="jy-btn jy-btn-link">Trash</button>
					<button type="button" id="jy-vault-export" class="jy-btn jy-btn-link">Export</button>
					<button type="button" id="jy-vault-import" class="jy-btn jy-btn-link">Import</button>
					<input type="file" id="jy-vault-import-file" accept=".json,.csv" hidden>
					<button type="button" id="jy-vault-lock" class="jy-btn jy-btn-link">Lock now</button>
				</div>
			</div>
		</aside>
		<div class="jy-vault-detail-pane" id="jy-vault-detail">
			<div class="jy-vault-empty-detail" id="jy-vault-detail-empty">
				<p>Select an entry, or add a new one.</p>
			</div>
			<div class="jy-vault-detail-view" id="jy-vault-detail-view" hidden></div>
			<div class="jy-vault-detail-edit" id="jy-vault-detail-edit" hidden>
				<div id="jy_vault_entry_form" class="jy-vault-fields"><?php echo $entry_html; ?></div>
				<div class="jy-vault-actions">
					<button type="button" id="jy-vault-entry-save" class="jy-btn jy-btn-primary">Save</button>
					<button type="button" id="jy-vault-entry-cancel" class="jy-btn jy-btn-link">Cancel</button>
					<button type="button" id="jy-vault-entry-delete" class="jy-btn jy-btn-danger" hidden>Move to trash</button>
				</div>
			</div>
		</div>
	</section>

	<div id="jy-vault-toast" class="jy-vault-toast" role="status" aria-live="polite" hidden></div>
</div>

<script defer src="/assets/js/passkeys.js?v=<?php echo filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')); ?>"></script>
<script defer src="/assets/js/vault-crypto.js?v=<?php echo filemtime(PathHelper::getIncludePath('assets/js/vault-crypto.js')); ?>"></script>
<script defer src="/assets/js/vault-keyring.js?v=<?php echo filemtime(PathHelper::getIncludePath('assets/js/vault-keyring.js')); ?>"></script>
<script defer src="<?php echo htmlspecialchars($asset('js/vault-manager.js')); ?>"></script>
<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
