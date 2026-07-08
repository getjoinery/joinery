<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('recovery_verify_logic.php', 'logic'));

    $page_vars = process_logic(recovery_verify_logic(array_merge($_GET, $_POST, $params ?? [])));
    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Confirm Recovery Address',
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
            <?php if (!empty($page_vars['ok'])): ?>
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#198754" stroke-width="1.5" class="jy-auth-success-icon" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h3>Recovery Address Confirmed</h3>
            <?php else: ?>
                <h3>Recovery Address</h3>
            <?php endif; ?>
            <p class="text-muted"><?php echo htmlspecialchars($page_vars['message'] ?? ''); ?></p>
            <a href="/profile/security" class="btn btn-primary">Go to Security Settings</a>
        </div>

    </div>
</div>
</div>

<?php
    $page->public_footer(['header_only' => true]);
?>
