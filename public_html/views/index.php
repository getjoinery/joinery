<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings  = Globalvars::get_instance();
$site_name = $settings->get_setting('site_name') ?: 'Joinery';

$page = new PublicPage();
$page->public_header([
    'title'      => 'Welcome to ' . htmlspecialchars($site_name),
    'showheader' => true,
]);
?>
<div class="jy-ui">

<!-- Hero Section -->
<section class="jy-content-section section-muted jy-home-hero">
    <div class="jy-container">
        <h1 class="jy-home-h1">
            Welcome to <?php echo htmlspecialchars($site_name); ?>
        </h1>
        <p class="jy-home-lead">
            Your site has been successfully installed and is ready for configuration.
        </p>
        <div class="jy-home-cta">
            <a href="/login" class="btn btn-primary jy-home-btn">Sign In</a>
            <a href="/register" class="btn btn-outline jy-home-btn">Register</a>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="jy-content-section jy-home-section">
    <div class="jy-container">
        <div class="jy-home-sectionhead">
            <h2>Platform Features</h2>
            <p class="jy-muted">Everything you need to manage your membership organization</p>
        </div>
        <div class="grid-3 jy-home-grid">
            <?php
            $features = [
                ['title' => 'Member Management',     'desc' => 'Manage member profiles, subscriptions, and communications all in one place.'],
                ['title' => 'Event Management',       'desc' => 'Create and manage events with registration, ticketing, and attendance tracking.'],
                ['title' => 'Payment Processing',     'desc' => 'Accept payments securely with Stripe and PayPal integration.'],
                ['title' => 'Email Communications',   'desc' => 'Send newsletters, announcements, and automated notifications to members.'],
                ['title' => 'E-Commerce',             'desc' => 'Sell products, memberships, and digital goods with built-in shopping cart.'],
                ['title' => 'Reports &amp; Analytics','desc' => 'Track membership growth, revenue, and engagement with detailed reports.'],
            ];
            foreach ($features as $f): ?>
            <div class="jy-home-feature">
                <h5 class="jy-home-feature-title"><?php echo $f['title']; ?></h5>
                <p class="jy-muted jy-tight"><?php echo $f['desc']; ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Getting Started Section -->
<section class="jy-content-section section-muted">
    <div class="jy-container">
        <div class="grid-2 jy-home-gs-grid">

            <div>
                <h2>Getting Started</h2>
                <p class="jy-home-gs-lead">Follow these steps to configure your new Joinery installation:</p>

                <?php
                $steps = [
                    ['title' => 'Sign in to Admin Panel',      'desc' => 'Use the admin password set during installation to access the administration area.'],
                    ['title' => 'Configure Site Settings',      'desc' => 'Update your organization name, contact details, and branding.'],
                    ['title' => 'Set Up Payment Processing',    'desc' => 'Connect Stripe or PayPal to start accepting payments.'],
                    ['title' => 'Customize This Page',          'desc' => 'Replace this welcome page with your own content in <code>views/index.php</code>.'],
                ];
                foreach ($steps as $i => $step): ?>
                <div class="jy-home-step">
                    <div class="jy-home-step-num">
                        <?php echo $i + 1; ?>
                    </div>
                    <div>
                        <strong><?php echo $step['title']; ?></strong>
                        <p class="jy-home-step-desc"><?php echo $step['desc']; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="jy-home-admin-card">
                <div class="jy-home-admin-head">
                    Admin Access
                </div>
                <div class="jy-home-admin-body">
                    <p>Administrator login:</p>
                    <div class="jy-home-creds">
                        <div class="jy-home-cred-row">
                            <span class="jy-home-cred-label">Email:</span>
                            <code>admin@example.com</code>
                        </div>
                        <div class="jy-home-cred-row">
                            <span class="jy-home-cred-label">Password:</span>
                            <span>set when this site was installed</span>
                        </div>
                    </div>
                    <div class="alert alert-info jy-home-alert">
                        The installer printed the password when it finished and saved it to
                        <code>config/admin_credentials.txt</code> on the server. If you chose one
                        on a deploy form, use that. You will be asked to change it at first login.
                    </div>
                    <a href="/admin" class="btn btn-primary jy-w-full">Go to Admin Panel</a>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- Footer CTA -->
<section class="jy-content-section jy-section-dark jy-home-cta-section">
    <div class="jy-container">
        <h3 class="jy-home-cta-title">Ready to get started?</h3>
        <p class="jy-home-cta-text">Sign in to begin configuring your membership platform.</p>
        <a href="/login" class="btn jy-home-cta-btn">Get Started</a>
    </div>
</section>

</div>
<?php
$page->public_footer();
?>
