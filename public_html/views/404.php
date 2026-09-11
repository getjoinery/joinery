<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
if (!class_exists('PublicPage', false)) {
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
}

$settings     = Globalvars::get_instance();
$page         = new PublicPage();
$is_valid_page = false;

$page->public_header([
    'is_valid_page' => $is_valid_page,
    'title'         => 'Page not found',
    'is_404'        => 1,
    'header_only'   => true,
]);
?>
<div class="jy-ui">

<section class="jy-e404-section">
    <div class="jy-container">
        <div class="grid-2 jy-e404-grid">

            <!-- 404 Visual -->
            <div class="jy-e404-visual">
                <div class="jy-e404-num">404</div>
                <div class="jy-e404-icon">&#9888;</div>
            </div>

            <!-- 404 Content -->
            <div>
                <?php if ($settings->get_setting('logo_link')): ?>
                <div class="jy-e404-brand">
                    <img src="<?php echo htmlspecialchars($settings->get_setting('logo_link')); ?>" alt="Logo" class="jy-e404-logo">
                    <span class="jy-e404-brandname"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
                </div>
                <?php else: ?>
                <div class="jy-e404-brand">
                    <span class="jy-e404-brandname"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
                </div>
                <?php endif; ?>

                <h1 class="jy-e404-h1">Oops! Page Not Found</h1>
                <p class="jy-e404-lead">
                    The page you're looking for couldn't be found. It might have been moved, deleted, or the URL might be incorrect.
                </p>

                <!-- Search -->
                <form action="/search" method="get" class="jy-e404-search">
                    <div class="jy-e404-search-row">
                        <input type="text" name="q" placeholder="Search our site..." class="jy-e404-search-input">
                        <button type="submit" class="btn btn-primary jy-e404-search-btn">Search</button>
                    </div>
                </form>

                <!-- Buttons -->
                <div class="jy-e404-btns">
                    <a href="/" class="btn btn-primary">&#8962; Go Home</a>
                    <a href="/contact" class="btn btn-outline">Contact Support</a>
                </div>

                <!-- Helpful Links -->
                <div>
                    <h5 class="jy-e404-links-title">You might be looking for:</h5>
                    <div class="grid-2 jy-e404-links-grid">
                        <ul class="jy-e404-list">
                            <li class="jy-e404-li"><a href="/blog">&#8250; Blog</a></li>
                            <li class="jy-e404-li"><a href="/products">&#8250; Products</a></li>
                            <li class="jy-e404-li"><a href="/pricing">&#8250; Pricing</a></li>
                        </ul>
                        <ul class="jy-e404-list">
                            <li class="jy-e404-li"><a href="/contact">&#8250; Contact</a></li>
                            <li class="jy-e404-li"><a href="/login">&#8250; Login</a></li>
                            <li class="jy-e404-li"><a href="/register">&#8250; Register</a></li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

</div>
<?php
$page->public_footer(['track' => true, 'header_only' => true, 'is_404' => 1]);
?>
