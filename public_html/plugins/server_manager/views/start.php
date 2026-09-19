<?php
/**
 * Set up your site, step 1: your account — /server_manager/start
 *
 * Sign in and create an account, side by side and without preference, under
 * a step strip that says this is step 1 of three. Both forms post back here;
 * start_logic hands them to the platform's own sign-in and sign-up handlers
 * and every success lands on the configure page (step 2).
 *
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §4.1 (the account step)
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('start_logic.php', 'logic', 'system', null, 'server_manager'));
require_once(PathHelper::getThemeFilePath('register_logic.php', 'logic'));

$page_vars = process_logic(start_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$page->public_header(array(
	'is_valid_page' => $is_valid_page ?? false,
	'title'         => 'Set up your site',
	'header_only'   => true,
));
?>

<div class="jy-ui">
<div class="auth-page sms-start-page">
	<div class="auth-card sms-start-card">

		<div class="auth-logo">
			<a href="/"><?php $page->get_logo(); ?></a>
		</div>

		<?php echo $steps_html; ?>

		<h3>Set up your site</h3>
		<p class="sms-start-lead">Your site starts with an account. It is where your new site's admin password is shown to
			you, once, and where you manage the site afterwards. Sign in if you already have one, or create one &mdash;
			either takes a minute, and then you describe the site you want.<?php if ($price_sentence !== ''): ?>
			Managed hosting is <strong><?php echo htmlspecialchars($price_sentence); ?></strong>; nothing is charged until
			you have seen the whole price.<?php endif; ?></p>

		<?php echo $page->render_messages('loginbox'); ?>

		<div class="sms-start-cols">

			<section class="sms-start-col" aria-labelledby="sms-signin-h">
				<h4 id="sms-signin-h">I have an account</h4>
				<?php if ($error !== '' && $form === 'login') { echo PublicPage::alert('Sign in', $error, 'warn'); } ?>
				<?php
				$fw_login = $page->getFormWriter('sms_login', array('action' => ManagedSiteDraft::START_URL, 'method' => 'POST'));
				$fw_login->begin_form();
				$fw_login->hiddeninput('form', '', array('value' => 'login'));
				// The sign-in handler's alternate field names (lbx_*), so the two
				// forms on this page never share an element id and every label
				// points at its own field.
				$fw_login->textinput('lbx_email', 'Email:', array(
					'type'     => 'email',
					'required' => true,
					'value'    => ($form === 'login') ? (string)($values['lbx_email'] ?? '') : '',
				));
				$fw_login->passwordinput('lbx_password', 'Password:', array('required' => true));
				$fw_login->checkboxinput('lbx_setcookie', 'Remember Me', array('value' => 'yes', 'checked_value' => 'yes'));
				?>
				<div class="jy-form-actions">
					<?php $fw_login->submitbutton('btn_signin', 'Sign in and continue', array('class' => 'btn btn-primary')); ?>
				</div>
				<div class="auth-links"><a href="/password-reset-1">Forgot your password?</a></div>
				<?php $fw_login->end_form(); ?>

				<?php if ($passkeys_enabled): ?>
				<div class="jy-passkey-signin d-none" id="passkey-signin">
					<button type="button" class="btn btn-secondary jy-w-full" id="passkey-signin-btn">Sign in with a passkey</button>
				</div>
				<?php endif; ?>
			</section>

			<section class="sms-start-col" aria-labelledby="sms-signup-h">
				<h4 id="sms-signup-h">I am new here</h4>
				<?php if (!$register_active): ?>
					<p>Sign-ups are closed on this site. Sign in on the left, or contact us for an account.</p>
				<?php else: ?>
					<?php if ($error !== '' && $form === 'register') { echo PublicPage::alert('Create an account', $error, 'warn'); } ?>
					<?php
					$fw_reg = $page->getFormWriter('sms_register', array('action' => ManagedSiteDraft::START_URL, 'method' => 'POST'));
					$fw_reg->antispam_question_validate(array());
					$fw_reg->begin_form();
					$fw_reg->hiddeninput('form', '', array('value' => 'register'));
					$fw_reg->hiddeninput('prevformname', '', array('value' => 'register'));
					// The same bot defences as the sign-up page; the handler checks them.
					$fw_reg->antispam_question_input();
					$fw_reg->honeypot_hidden_input();
					$fw_reg->captcha_hidden_input();
					// The one shared definition of the sign-up form.
					register_logic_form($fw_reg, null, ($form === 'register') ? $values : array());
					$fw_reg->end_form();
					?>
				<?php endif; ?>
			</section>

		</div>
	</div>
</div>
</div>

<style>
.jy-ui .sms-start-page { align-items: flex-start; }
.jy-ui .auth-page .auth-card.sms-start-card { width: 100%; max-width: 960px; }
.jy-ui .sms-start-card .btn-primary { margin-top: 0; }
.sms-start-lead { color: #374151; margin: 0 0 1.25rem; }
.sms-start-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; align-items: start; }
.sms-start-col { padding: 1.25rem; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
.sms-start-col h4 { margin: 0 0 1rem; }
@media (max-width: 760px) { .sms-start-cols { grid-template-columns: 1fr; } }
</style>

<?php if ($passkeys_enabled): ?>
<script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function () {
	if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) return;
	var wrap = document.getElementById('passkey-signin');
	var btn = document.getElementById('passkey-signin-btn');
	if (!wrap || !btn) return;
	wrap.classList.remove('d-none');
	btn.addEventListener('click', async function () {
		btn.disabled = true;
		try {
			var emailField = document.querySelector('input[name="lbx_email"]');
			var email = emailField ? emailField.value.trim() : '';
			var data = await JoineryPasskeys.runFlow(
				'/api/v1/action/passkey_login_options',
				'/api/v1/action/passkey_login_verify',
				{ email: email }
			);
			window.location.href = data.redirect || <?php echo json_encode($next); ?>;
		} catch (e) {
			btn.disabled = false;
			if (e && e.message && e.name !== 'NotAllowedError') { alert(e.message); }
		}
	});
});
</script>
<?php endif; ?>

<?php $page->public_footer(array('header_only' => true, 'track' => true)); ?>
