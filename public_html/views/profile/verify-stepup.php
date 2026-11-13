<?php
    /**
     * Second-factor step-up page. The visitor is always signed in, so it uses
     * the member page layout (site header and menu), not the bare sign-in card.
     *
     * @version 1.2 - the passkey button's script is assets/js/passkey-pages.js (no inline script)
     * @version 1.1
     */
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('verify_stepup_logic.php', 'logic'));

    $page_vars = process_logic(verify_stepup_logic(array_merge($_GET, $_POST, $params ?? [])));
    extract($page_vars);

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Confirm it\'s you',
    ]);

    $return_qs = '?return=' . rawurlencode($return);
?>

<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-narrow-sm">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>Confirm it's you</h1>
            </div>
        </div>

        <div class="jy-panel">
        <p>This is a sensitive change, so please re-confirm your identity with your second factor.</p>

        <?php if (!empty($error)) { echo PublicPage::alert('Verification failed', htmlspecialchars($error), 'danger'); } ?>

        <?php if (!empty($has_passkey)) { ?>
        <div class="jy-form-actions">
            <button type="button" id="stepup-passkey-btn" class="btn btn-primary" data-jy-next="<?php echo htmlspecialchars($return); ?>">Confirm with a passkey</button>
        </div>
        <div id="stepup-passkey-error" class="jy-auth-hint" role="alert"></div>
        <?php } ?>

        <?php if (!empty($has_totp)) { ?>
        <?php if (!empty($has_passkey)) { echo '<div class="auth-divider">or</div>'; } ?>
        <?php
        $formwriter = $page->getFormWriter('form1', ['action' => '/profile/verify-stepup' . $return_qs, 'method' => 'POST']);
        $formwriter->begin_form();
        $formwriter->hiddeninput('return', '', ['value' => $return]);
        $formwriter->textinput('totp_code', 'Authenticator code', [
            'required'     => true,
            'autocomplete' => 'one-time-code',
            'inputmode'    => 'text',
            'autofocus'    => empty($has_passkey),
            'helptext'     => 'A 6-digit code from your authenticator app, or an 8-character backup code.',
        ]);
        ?>
        <div class="jy-form-actions">
            <?php $formwriter->submitbutton('stepup-form-submit', 'Confirm', ['class' => 'btn btn-primary']); ?>
        </div>
        <?php $formwriter->end_form(); ?>
        <?php } ?>

        <div class="auth-links">
            <a href="<?php echo htmlspecialchars($return); ?>">Cancel</a>
        </div>

        </div><!-- /.jy-panel -->

        </div>
    </div>
</section>
</div>

<?php if (!empty($has_passkey)) { ?>
<?php echo PageScript::src('assets/js/passkeys.js'), PageScript::src('assets/js/passkey-pages.js'); ?>
<?php } ?>

<?php
    $page->public_footer();
?>
