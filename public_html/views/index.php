<?php
// Joinery Welcome Page
// Clean landing page with no external image dependencies
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$site_name = $settings->get_setting('site_name') ?: 'Joinery';

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Welcome to ' . htmlspecialchars($site_name),
    'showheader' => true
));
?>

<!-- Hero Section -->
<section class="py-5 bg-light">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-lg-8 mx-auto text-center">
                <h1 class="display-4 fw-bold mb-4">Welcome to <?php echo htmlspecialchars($site_name); ?></h1>
                <p class="lead text-muted mb-4">
                    Your site has been successfully installed and is ready for configuration.
                </p>
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <a href="/login" class="btn btn-primary btn-lg px-4">
                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                    </a>
                    <a href="/register" class="btn btn-outline-primary btn-lg px-4">
                        <i class="fas fa-user-plus me-2"></i>Register
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="py-5">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Platform Features</h2>
            <p class="text-muted">Everything you need to manage your membership organization</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                            <i class="fas fa-users fa-2x text-primary"></i>
                        </div>
                        <h5 class="card-title">Member Management</h5>
                        <p class="card-text text-muted">Manage member profiles, subscriptions, and communications all in one place.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                            <i class="fas fa-calendar-alt fa-2x text-success"></i>
                        </div>
                        <h5 class="card-title">Event Management</h5>
                        <p class="card-text text-muted">Create and manage events with registration, ticketing, and attendance tracking.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="rounded-circle bg-info bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                            <i class="fas fa-credit-card fa-2x text-info"></i>
                        </div>
                        <h5 class="card-title">Payment Processing</h5>
                        <p class="card-text text-muted">Accept payments securely with Stripe and PayPal integration.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="rounded-circle bg-warning bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                            <i class="fas fa-envelope fa-2x text-warning"></i>
                        </div>
                        <h5 class="card-title">Email Communications</h5>
                        <p class="card-text text-muted">Send newsletters, announcements, and automated notifications to members.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                            <i class="fas fa-shopping-cart fa-2x text-danger"></i>
                        </div>
                        <h5 class="card-title">E-Commerce</h5>
                        <p class="card-text text-muted">Sell products, memberships, and digital goods with built-in shopping cart.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <div class="rounded-circle bg-secondary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                            <i class="fas fa-chart-bar fa-2x text-secondary"></i>
                        </div>
                        <h5 class="card-title">Reports & Analytics</h5>
                        <p class="card-text text-muted">Track membership growth, revenue, and engagement with detailed reports.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Getting Started Section -->
<section class="py-5 bg-light">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 mb-4 mb-lg-0">
                <h2 class="fw-bold mb-4">Getting Started</h2>
                <p class="text-muted mb-4">Follow these steps to configure your new Joinery installation:</p>

                <div class="d-flex mb-3">
                    <div class="flex-shrink-0">
                        <span class="badge bg-primary rounded-circle p-2" style="width: 32px; height: 32px;">1</span>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="mb-1">Sign in to Admin Panel</h6>
                        <p class="text-muted small mb-0">Use the default admin credentials to access the administration area.</p>
                    </div>
                </div>

                <div class="d-flex mb-3">
                    <div class="flex-shrink-0">
                        <span class="badge bg-primary rounded-circle p-2" style="width: 32px; height: 32px;">2</span>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="mb-1">Configure Site Settings</h6>
                        <p class="text-muted small mb-0">Update your organization name, contact details, and branding.</p>
                    </div>
                </div>

                <div class="d-flex mb-3">
                    <div class="flex-shrink-0">
                        <span class="badge bg-primary rounded-circle p-2" style="width: 32px; height: 32px;">3</span>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="mb-1">Set Up Payment Processing</h6>
                        <p class="text-muted small mb-0">Connect Stripe or PayPal to start accepting payments.</p>
                    </div>
                </div>

                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <span class="badge bg-primary rounded-circle p-2" style="width: 32px; height: 32px;">4</span>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="mb-1">Customize This Page</h6>
                        <p class="text-muted small mb-0">Replace this welcome page with your own content in <code>views/index.php</code>.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow">
                    <div class="card-header bg-dark text-white">
                        <i class="fas fa-terminal me-2"></i>Admin Access
                    </div>
                    <div class="card-body">
                        <p class="mb-3">Default administrator login:</p>
                        <div class="bg-light p-3 rounded mb-3">
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Email:</div>
                                <div class="col-8"><code>admin@example.com</code></div>
                            </div>
                            <div class="row">
                                <div class="col-4 text-muted">Password:</div>
                                <div class="col-8"><code>changeme123</code></div>
                            </div>
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            You will be prompted to change the default password on first login.
                        </div>
                    </div>
                    <div class="card-footer bg-transparent">
                        <a href="/admin" class="btn btn-dark w-100">
                            <i class="fas fa-cog me-2"></i>Go to Admin Panel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer CTA -->
<section class="py-5 bg-primary text-white">
    <div class="container text-center">
        <h3 class="mb-3">Ready to get started?</h3>
        <p class="mb-4 opacity-75">Sign in to begin configuring your membership platform.</p>
        <a href="/login" class="btn btn-light btn-lg px-5">
            <i class="fas fa-arrow-right me-2"></i>Get Started
        </a>
    </div>
</section>

<?php
$page->public_footer();
?>
