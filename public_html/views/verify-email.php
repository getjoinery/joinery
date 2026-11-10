<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('verify_email_logic.php', 'logic'));

    $page_vars = process_logic(verify_email_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Verify Your Email',
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
            <h3>Verify Your Email</h3>
            <?php if ($page_vars['state'] === 'confirm'): ?>
                <p class="text-muted">Press the button to confirm this is your email address.</p>
                <?php
                $formwriter = $page->getFormWriter('form1', ['action' => '/verify-email']);
                $formwriter->begin_form();
                $formwriter->hiddeninput('act_code', '', ['value' => $page_vars['act_code']]);
                if ($page_vars['then'] !== '') {
                    $formwriter->hiddeninput('then', '', ['value' => $page_vars['then']]);
                }
                $formwriter->submitbutton('btn_submit', 'Verify my email');
                echo $formwriter->end_form();
                ?>
            <?php else: ?>
                <p class="text-muted"><?php echo htmlspecialchars($page_vars['message']); ?></p>
                <a href="/login" class="btn btn-primary">Sign In</a>
            <?php endif; ?>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['header_only' => true]);
?>
