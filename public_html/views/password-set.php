<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('password_set_logic.php', 'logic'));

    $page_vars = process_logic(password_set_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Password Set',
    ]);
    echo PublicPage::BeginPage('Set a Password');
?>

<div class="jy-ui"><div class="jy-container">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-xl-5">

            <div class="text-center mb-4">
                <p class="text-muted">Create a secure password for your account</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $page_vars['message_type'] == 'error' ? 'danger' : ($page_vars['message_type'] == 'success' ? 'success' : 'info'); ?>" role="alert">
                    <?php if ($page_vars['message_title']): ?>
                        <h5 class="alert-heading"><?php echo $page_vars['message_title']; ?></h5>
                    <?php endif; ?>
                    <?php echo $page_vars['message']; ?>
                </div>
            <?php else: ?>

                <div class="card shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <?php
                        $formwriter = $page->getFormWriter('form1', ['action' => '/password-set']);
                        $formwriter->begin_form();
                        ?>

                        <div class="form-group">
                            <label for="usr_password" class="form-label fw-semibold">New Password</label>
                            <input type="password" name="usr_password" id="usr_password" class="form-control" placeholder="Enter new password" autocomplete="new-password">
                            <span class="form-text">Must be at least 5 characters.</span>
                        </div>

                        <div class="form-group">
                            <label for="usr_password_again" class="form-label fw-semibold">Retype New Password</label>
                            <input type="password" name="usr_password_again" id="usr_password_again" class="form-control" placeholder="Confirm new password" autocomplete="new-password">
                        </div>

                        <?php if (empty($page_vars['terms_already_accepted'])):
                            $settings_page = Globalvars::get_instance();
                            $terms_url   = trim((string)$settings_page->get_setting('terms_url'));
                            $privacy_url = trim((string)$settings_page->get_setting('privacy_url'));
                            $terms_link   = $terms_url   !== '' ? '<a href="' . htmlspecialchars($terms_url,   ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Terms of Use</a>'   : 'Terms of Use';
                            $privacy_link = $privacy_url !== '' ? '<a href="' . htmlspecialchars($privacy_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Privacy Policy</a>' : 'Privacy Policy';
                        ?>
                        <div class="form-check mt-3">
                            <input type="checkbox" name="accept_terms" id="accept_terms" class="form-check-input" required>
                            <label for="accept_terms" class="form-check-label">
                                I agree to the <?php echo $terms_link; ?> and <?php echo $privacy_link; ?>.
                            </label>
                        </div>
                        <?php endif; ?>

                        <div class="d-grid mt-3">
                            <button type="submit" class="btn btn-primary">Set Password</button>
                        </div>

                        <?php echo $formwriter->end_form(); ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>
</div>

<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true, 'formvalidate' => true]);
?>
