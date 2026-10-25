<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('email_change_logic.php', 'logic'));

    $page_vars = process_logic(email_change_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Confirm Email Change',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <div class="text-center">
            <?php if ($page_vars['state'] === 'confirm'): ?>
                <h3>Change Your Email</h3>
                <p class="text-muted">Make <strong><?php echo htmlspecialchars($page_vars['new_email']); ?></strong> the email you sign in with?</p>
                <?php
                $formwriter = $page->getFormWriter('form1', ['action' => '/email_change']);
                $formwriter->begin_form();
                $formwriter->hiddeninput('act_code', '', ['value' => $page_vars['act_code']]);
                $formwriter->submitbutton('btn_submit', 'Confirm');
                echo $formwriter->end_form();
                ?>
            <?php elseif ($page_vars['state'] === 'done'): ?>
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#198754" stroke-width="1.5" class="jy-auth-success-icon" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h3>Email Changed</h3>
                <p class="text-muted"><?php echo htmlspecialchars($page_vars['message']); ?></p>
                <a href="/profile" class="btn btn-primary">Go to Your Account</a>
            <?php else: ?>
                <h3>Change Your Email</h3>
                <p class="text-muted"><?php echo htmlspecialchars($page_vars['message']); ?></p>
                <a href="/profile/account_edit" class="btn btn-primary">Go to Your Account</a>
            <?php endif; ?>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['header_only' => true]);
?>
