<?php
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('test_mode_logic.php', 'logic'));

    $page_vars = process_logic(test_mode_logic(array_merge($_GET, $_POST)));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => true,
        'title'         => 'Test mode',
        'header_only'   => true,
    ]);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Test mode</h3>
        <?php if ($page_vars['message'] !== ''): ?>
            <p><strong><?php echo htmlspecialchars($page_vars['message']); ?></strong></p>
        <?php endif; ?>

        <?php if ($page_vars['site_wide']): ?>
            <p>This whole site is in test mode: its debug setting is on. Every visitor's payments go to Stripe's and PayPal's test accounts.</p>
        <?php elseif ($page_vars['on']): ?>
            <p>Test mode is <strong>on</strong> in this browser. Your payments go to Stripe's and PayPal's test accounts and nothing is charged; pay with a test card such as 4242 4242 4242 4242. Every other visitor still pays for real.</p>
            <p>It stays on until you turn it off or sign out. Orders made in it are marked as test orders, and a hosted site bought in it is named with test_ at the cloud and mail providers.</p>
            <?php
            $formwriter = $page->getFormWriter('test_mode_off', ['action' => '/test_mode']);
            $formwriter->begin_form();
            $formwriter->hiddeninput('action', '', ['value' => 'off']);
            $formwriter->submitbutton('btn_off', 'Turn off test mode');
            echo $formwriter->end_form();
            ?>
        <?php else: ?>
            <p>Test mode is <strong>off</strong> in this browser. Payments are real.</p>
            <?php if ($page_vars['can_turn_on']): ?>
                <p>Turn it on to try a purchase on this live site with a test card: only this browser's payments go to Stripe's and PayPal's test accounts, and every other visitor still pays for real. A banner shows on every page while it is on.</p>
                <?php
                $formwriter = $page->getFormWriter('test_mode_on', ['action' => '/test_mode']);
                $formwriter->begin_form();
                $formwriter->hiddeninput('action', '', ['value' => 'on']);
                $formwriter->submitbutton('btn_on', 'Turn on test mode for this browser');
                echo $formwriter->end_form();
                ?>
            <?php else: ?>
                <p>A superadmin can turn it on for their own browser.</p>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
</div>

<?php
    $page->public_footer(['header_only' => true]);
?>
