<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('verify_totp_logic.php', 'logic'));

    $page_vars = process_logic(verify_totp_logic($_GET, $_POST));

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Two-Factor Verification',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Two-Factor Verification</h3>
        <p>Enter the 6-digit code from your authenticator app, or an 8-character backup code.</p>

        <?php
        foreach ($page_vars['display_messages'] ?? [] as $display_message) {
            if ($display_message->identifier == 'loginbox' || $display_message->identifier == 'topbox') {
                echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
            }
        }

        $formwriter = $page->getFormWriter('form1', ['action' => '/verify-totp', 'method' => 'POST']);
        $formwriter->begin_form();

        $formwriter->textinput('totp_code', 'Code', [
            'required'     => true,
            'autocomplete' => 'one-time-code',
            'inputmode'    => 'text',
            'autofocus'    => true,
        ]);
        ?>

        <div style="margin-top: var(--jy-space-4);">
            <?php $formwriter->submitbutton('verify-form-submit', 'Verify', ['class' => 'btn btn-primary']); ?>
        </div>
        <div class="auth-links">
            <a href="/logout">Cancel</a>
        </div>

        <?php $formwriter->end_form(); ?>

        <div class="auth-footer-text" style="font-size: 0.9em;">
            Lost access to your authenticator?
            Use one of your backup codes, or contact your administrator to reset 2FA.
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['header_only' => true]);
?>
