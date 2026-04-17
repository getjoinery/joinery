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

<!-- Hero Section -->
<section class="content-section section-muted" style="padding: 5rem 0; text-align: center;">
    <div class="container">
        <h1 style="font-size: 2.5rem; font-weight: 700; margin-bottom: 1.5rem;">
            Welcome to <?php echo htmlspecialchars($site_name); ?>
        </h1>
        <p style="font-size: 1.125rem; color: var(--color-muted, #6c757d); margin-bottom: 2rem; max-width: 600px; margin-left: auto; margin-right: auto;">
            Your site has been successfully installed and is ready for configuration.
        </p>
        <div style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
            <a href="/login" class="btn btn-primary" style="font-size: 1.0625rem; padding: 0.75rem 2rem;">Sign In</a>
            <a href="/register" class="btn btn-outline" style="font-size: 1.0625rem; padding: 0.75rem 2rem;">Register</a>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="content-section">
    <div class="container">
        <div style="text-align: center; margin-bottom: 3rem;">
            <h2>Platform Features</h2>
            <p style="color: var(--color-muted, #6c757d);">Everything you need to manage your membership organization</p>
        </div>
        <div class="grid-3" style="gap: 1.5rem;">
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
            <div style="background: #fff; border: 1px solid var(--color-border, #eee); border-radius: 8px; padding: 2rem; text-align: center;">
                <h5 style="margin-top: 0; margin-bottom: 0.75rem;"><?php echo $f['title']; ?></h5>
                <p style="color: var(--color-muted, #6c757d); margin: 0;"><?php echo $f['desc']; ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Getting Started Section -->
<section class="content-section section-muted">
    <div class="container">
        <div class="grid-2" style="gap: 3rem; align-items: start;">

            <div>
                <h2>Getting Started</h2>
                <p style="color: var(--color-muted, #6c757d); margin-bottom: 2rem;">Follow these steps to configure your new Joinery installation:</p>

                <?php
                $steps = [
                    ['title' => 'Sign in to Admin Panel',      'desc' => 'Use the default admin credentials to access the administration area.'],
                    ['title' => 'Configure Site Settings',      'desc' => 'Update your organization name, contact details, and branding.'],
                    ['title' => 'Set Up Payment Processing',    'desc' => 'Connect Stripe or PayPal to start accepting payments.'],
                    ['title' => 'Customize This Page',          'desc' => 'Replace this welcome page with your own content in <code>views/index.php</code>.'],
                ];
                foreach ($steps as $i => $step): ?>
                <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: flex-start;">
                    <div style="flex-shrink: 0; width: 32px; height: 32px; background: var(--color-primary, #1abc9c); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.875rem;">
                        <?php echo $i + 1; ?>
                    </div>
                    <div>
                        <strong><?php echo $step['title']; ?></strong>
                        <p style="margin: 0.25rem 0 0; color: var(--color-muted, #6c757d); font-size: 0.9rem;"><?php echo $step['desc']; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.1);">
                <div style="background: #212529; color: #fff; padding: 1rem 1.5rem; font-weight: 600;">
                    Admin Access
                </div>
                <div style="padding: 1.5rem;">
                    <p>Default administrator login:</p>
                    <div style="background: var(--color-light, #f8f9fa); border-radius: 4px; padding: 1rem; margin-bottom: 1rem;">
                        <div style="display: flex; margin-bottom: 0.5rem;">
                            <span style="width: 90px; color: var(--color-muted);">Email:</span>
                            <code>admin@example.com</code>
                        </div>
                        <div style="display: flex;">
                            <span style="width: 90px; color: var(--color-muted);">Password:</span>
                            <code>changeme123</code>
                        </div>
                    </div>
                    <div class="alert alert-info" style="margin-bottom: 1rem;">
                        You will be prompted to change the default password on first login.
                    </div>
                    <a href="/admin" class="btn btn-primary" style="display: block; text-align: center;">Go to Admin Panel</a>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- Footer CTA -->
<section class="content-section section-dark" style="text-align: center;">
    <div class="container">
        <h3 style="color: #fff; margin-bottom: 1rem;">Ready to get started?</h3>
        <p style="color: rgba(255,255,255,0.8); margin-bottom: 2rem;">Sign in to begin configuring your membership platform.</p>
        <a href="/login" class="btn" style="background: #fff; color: var(--color-dark, #333); font-size: 1.0625rem; padding: 0.75rem 2.5rem;">Get Started</a>
    </div>
</section>

<?php
$page->public_footer();
?>
