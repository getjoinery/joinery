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

$config = [
	'clipboardClearSeconds'  => (int)$clipboard_clear_seconds,
	'scope'                  => 'passwords',
];

$page = new PublicPage();
$page->needs_vault_client();
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

	<!-- Closed: setup and unlock are the core vault ceremony (a modal); this is
	     what shows when the person closed it, or after the vault locks. -->
	<section id="jy-vault-locked" class="jy-vault-centered" hidden>
		<h2>Your password vault is closed</h2>
		<p>Open it to see and add passwords. It closes again on its own after a while away.</p>
		<div class="jy-vault-actions" style="justify-content:center">
			<button type="button" id="jy-vault-open" class="jy-btn jy-btn-primary">Open your vault</button>
		</div>
		<p class="jy-vault-error" id="jy-vault-locked-error" role="alert" hidden></p>
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

<script defer src="<?php echo htmlspecialchars($asset('js/vault-manager.js')); ?>"></script>
<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
