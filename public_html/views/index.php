<?php
/**
 * The built-in welcome page: what a visitor who is not signed in sees at the
 * root of a site that has no home page of its own (alternate_homepage =
 * /welcome, the factory default). The site's name, one line saying it is
 * private, and the way in. Registration is offered only where the site takes
 * sign-ups. A theme that ships its own views/index.php renders instead.
 *
 * @version 2.0 - a plain front door for a private site, in place of the install notice and feature list
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings  = Globalvars::get_instance();
$session   = SessionControl::get_instance();
$site_name = $settings->get_setting('site_name') ?: 'Joinery';

$page = new PublicPage();
$page->public_header([
    'is_valid_page' => $is_valid_page ?? true,
    'title'         => 'Welcome to ' . $site_name,
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
            <h3>Welcome to <?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></h3>
            <p class="text-muted">This is a private Joinery site.</p>

            <?php if ($session->is_logged_in()): ?>
                <a href="/profile" class="btn btn-primary">Go to your account</a>
            <?php else: ?>
                <a href="/login" class="btn btn-primary">Sign in</a>
                <?php if ($settings->get_setting('register_active')): ?>
                <div class="auth-footer-text">
                    Don't have an account yet? <a href="/register">Register for an Account</a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>
</div>
<?php
$page->public_footer(['header_only' => true]);
