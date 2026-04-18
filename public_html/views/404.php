<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

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

<section style="min-height: 75vh; display: flex; align-items: center; padding: 4rem 0;">
    <div class="jy-container">
        <div class="grid-2" style="align-items: center; gap: 3rem;">

            <!-- 404 Visual -->
            <div style="text-align: center;">
                <div style="font-size: 8rem; font-weight: 900; color: var(--jy-color-primary); opacity: 0.15; line-height: 1;">404</div>
                <div style="margin-top: -2rem; font-size: 4rem;">&#9888;</div>
            </div>

            <!-- 404 Content -->
            <div>
                <?php if ($settings->get_setting('logo_link')): ?>
                <div style="margin-bottom: 1.5rem;">
                    <img src="<?php echo htmlspecialchars($settings->get_setting('logo_link')); ?>" alt="Logo" style="max-height: 40px; vertical-align: middle; margin-right: 0.75rem;">
                    <span style="font-size: 1.25rem; font-weight: 700; color: var(--jy-color-primary);"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
                </div>
                <?php else: ?>
                <div style="margin-bottom: 1.5rem;">
                    <span style="font-size: 1.25rem; font-weight: 700; color: var(--jy-color-primary);"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
                </div>
                <?php endif; ?>

                <h1 style="margin-bottom: 1rem;">Oops! Page Not Found</h1>
                <p style="font-size: 1.0625rem; color: var(--jy-color-text-muted); margin-bottom: 2rem;">
                    The page you're looking for couldn't be found. It might have been moved, deleted, or the URL might be incorrect.
                </p>

                <!-- Search -->
                <form action="/search" method="get" style="margin-bottom: 2rem;">
                    <div style="display: flex; gap: 0;">
                        <input type="text" name="q" placeholder="Search our site..." style="flex: 1; padding: 0.75rem 1rem; border: 1px solid var(--jy-color-border); border-right: none; border-radius: 4px 0 0 4px; font-size: 1rem;">
                        <button type="submit" class="btn btn-primary" style="border-radius: 0 4px 4px 0;">Search</button>
                    </div>
                </form>

                <!-- Buttons -->
                <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem;">
                    <a href="/" class="btn btn-primary">&#8962; Go Home</a>
                    <a href="/contact" class="btn btn-outline">Contact Support</a>
                </div>

                <!-- Helpful Links -->
                <div>
                    <h5 style="margin-bottom: 1rem;">You might be looking for:</h5>
                    <div class="grid-2" style="gap: 0.5rem;">
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            <li style="margin-bottom: 0.5rem;"><a href="/blog">&#8250; Blog</a></li>
                            <li style="margin-bottom: 0.5rem;"><a href="/products">&#8250; Products</a></li>
                            <li style="margin-bottom: 0.5rem;"><a href="/pricing">&#8250; Pricing</a></li>
                        </ul>
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            <li style="margin-bottom: 0.5rem;"><a href="/contact">&#8250; Contact</a></li>
                            <li style="margin-bottom: 0.5rem;"><a href="/login">&#8250; Login</a></li>
                            <li style="margin-bottom: 0.5rem;"><a href="/register">&#8250; Register</a></li>
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
