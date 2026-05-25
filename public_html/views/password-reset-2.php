<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('password_reset_2_logic.php', 'logic'));

    $page_vars = process_logic(password_reset_2_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Password Reset',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <?php if ($page_vars['message']): ?>

            <div class="text-center">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#198754" stroke-width="1.5" style="margin-bottom: 1rem;" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h3>Password Successfully Reset</h3>
                <p class="text-muted">Continue to log in with your new password.</p>
                <a href="/login" class="btn btn-primary">Continue to Login</a>
            </div>

        <?php else: ?>

            <h3>Set New Password</h3>

            <?php
            $formwriter = $page->getFormWriter('form1', [
                'action' => '/password-reset-2',
            ]);
            $formwriter->begin_form();
            $formwriter->hiddeninput('act_code', '', ['value' => $page_vars['act_code']]);
            ?>

            <div class="form-group">
                <label for="usr_password" class="form-label">New Password:</label>
                <input type="password" name="usr_password" id="usr_password" class="form-control" autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="usr_password_again" class="form-label">Confirm Password:</label>
                <input type="password" name="usr_password_again" id="usr_password_again" class="form-control" autocomplete="new-password">
            </div>

            <?php if (empty($page_vars['terms_already_accepted'])):
                $settings_page = Globalvars::get_instance();
                $terms_url   = trim((string)$settings_page->get_setting('terms_url'));
                $privacy_url = trim((string)$settings_page->get_setting('privacy_url'));
                $terms_link   = $terms_url   !== '' ? '<a href="' . htmlspecialchars($terms_url,   ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Terms of Use</a>'   : 'Terms of Use';
                $privacy_link = $privacy_url !== '' ? '<a href="' . htmlspecialchars($privacy_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Privacy Policy</a>' : 'Privacy Policy';
            ?>
            <div class="form-check" style="margin-top: 1rem;">
                <input type="checkbox" name="accept_terms" id="accept_terms" class="form-check-input" required>
                <label for="accept_terms" class="form-check-label">
                    I agree to the <?php echo $terms_link; ?> and <?php echo $privacy_link; ?>.
                </label>
            </div>
            <?php endif; ?>

            <div style="margin-top: 1rem;">
                <button type="submit" name="submit" class="btn btn-primary btn-block">Set Password</button>
            </div>

            <?php echo $formwriter->end_form(); ?>

        <?php endif; ?>

    </div>
</div>
</div>

<?php
    $page->public_footer(['track' => true, 'header_only' => true]);
?>
