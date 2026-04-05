<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('password_reset_1_logic.php', 'logic'));

    $page_vars = process_logic(password_reset_1_logic($_GET, $_POST));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Password Reset',
        'header_only'   => true,
    ]);
?>

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <?php if (!empty($page_vars['message'])): ?>

            <div class="text-center">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#198754" stroke-width="1.5" style="margin-bottom: 1rem;" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <h3>Check Your Email!</h3>
                <p class="text-muted">An email has been sent to you. Please click on the included link to reset your password.</p>
                <a href="/login" class="btn btn-primary">Return to Login</a>
            </div>

        <?php else: ?>

            <h3>Reset Password</h3>
            <p class="text-muted" style="margin-bottom: 1.5rem;">Enter your email address and we'll send you a link to reset your password.</p>

            <?php
            $formwriter = $page->getFormWriter('form1', ['action' => '/password-reset-1', 'method' => 'POST']);
            $formwriter->begin_form();

            $formwriter->textinput('usr_email', 'Email Address:', [
                'type'      => 'email',
                'required'  => true,
                'maxlength' => 64,
            ]);
            ?>

            <div style="margin-top: 1rem;">
                <?php $formwriter->submitbutton('btn_submit', 'Send Reset Link', ['class' => 'btn btn-primary btn-block']); ?>
            </div>

            <?php $formwriter->end_form(); ?>

            <div class="auth-footer-text">
                Remember your password? <a href="/login">Login to your Account</a>
            </div>

        <?php endif; ?>

    </div>
</div>

<?php
    $page->public_footer(['track' => true, 'header_only' => true]);
?>
