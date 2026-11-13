<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('password_reset_2fa_logic.php', 'logic'));

    $page_vars = process_logic(password_reset_2fa_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Confirm Your Second Factor',
        'header_only'   => true,
    ]);
    $passkeys_enabled = $page_vars['settings']->get_setting('passkeys_enabled');
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>One more step</h3>
        <p class="text-muted jy-auth-lead">Your account protects sealed mail, so a reset needs a second factor in addition to your passkey.</p>

        <?php if (!empty($page_vars['error'])): ?>
            <?php echo PublicPage::alert('Try again', htmlspecialchars($page_vars['error']), 'warn'); ?>
        <?php endif; ?>

        <?php if (!empty($page_vars['has_totp'])): ?>
        <?php
            $formwriter = $page->getFormWriter('form1', ['action' => '/password-reset-2fa', 'method' => 'POST']);
            $formwriter->begin_form();
            $formwriter->textinput('totp_code', 'Authenticator code', [
                'required'     => true,
                'autocomplete' => 'one-time-code',
                'autofocus'    => true,
                'placeholder'  => '6-digit or backup code',
            ]);
        ?>
            <div class="jy-form-actions jy-mt-2">
                <?php $formwriter->submitbutton('btn_confirm', 'Confirm', ['class' => 'btn btn-primary']); ?>
            </div>
        <?php $formwriter->end_form(); ?>
        <?php endif; ?>

        <?php if (!empty($page_vars['has_passkey']) && $passkeys_enabled): ?>
            <?php if (!empty($page_vars['has_totp'])): ?><p class="jy-auth-hint jy-mt-2">or</p><?php endif; ?>
            <div class="jy-mt-2">
                <button type="button" class="btn btn-secondary jy-w-full" id="pwreset-2fa-passkey-btn">Confirm with another passkey</button>
            </div>
        <?php endif; ?>

        <div class="auth-links jy-mt-2">
            <a href="/password-reset-1">Cancel</a>
        </div>

    </div>
</div>
</div>

<?php if (!empty($page_vars['has_passkey']) && $passkeys_enabled): ?>
<?php echo PageScript::src('assets/js/passkeys.js'), PageScript::src('assets/js/passkey-pages.js'); ?>
<?php endif; ?>

<?php
    $page->public_footer(['header_only' => true]);
?>
