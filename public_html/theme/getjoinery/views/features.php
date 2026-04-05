<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Features — Joinery',
    'description' => 'Members, events, payments, email, themes, plugins, API, and more — all included in every plan.',
    'showheader' => true,
]);

// --- Hero ---
echo ComponentRenderer::render(null, 'marketing_hero', [
    'heading' => 'Everything your organization needs',
    'subheading' => 'Members, events, payments, email, themes, plugins, and a full API. All included in every plan.',
    'primary_button_text' => 'Start Free Trial',
    'primary_button_url' => '#',
    'secondary_button_text' => 'See Pricing',
    'secondary_button_url' => '/pricing',
]);

// --- Feature categories as alternating showcase sections ---
$features = [
    [
        'title' => 'Member Management',
        'description' => 'Organize your people with powerful, flexible member management. From basic contact lists to complex permission hierarchies.',
        'bullets' => [
            ['text' => 'User profiles with custom fields'],
            ['text' => 'Permission-based access control (member, moderator, admin)'],
            ['text' => 'Subscription tiers with feature-gating'],
            ['text' => 'Groups and group management'],
            ['text' => 'Member directory with search'],
            ['text' => 'Activity tracking and analytics'],
        ],
    ],
    [
        'title' => 'Events & Registration',
        'description' => 'Create and manage events of any size, from weekly meetups to annual conferences.',
        'bullets' => [
            ['text' => 'Event creation with rich details and media'],
            ['text' => 'Online registration with capacity management'],
            ['text' => 'Recurring events (weekly classes, monthly meetups)'],
            ['text' => 'Waitlists with automatic promotion'],
            ['text' => 'Custom registration questions'],
            ['text' => 'Calendar integration (iCal export)'],
        ],
    ],
    [
        'title' => 'Payments & E-Commerce',
        'description' => 'Accept payments and sell products without cobbling together three different services. Zero platform transaction fees.',
        'bullets' => [
            ['text' => 'Stripe and PayPal integration built in'],
            ['text' => 'Subscription billing with recurring payments'],
            ['text' => 'Product catalog and shopping cart'],
            ['text' => 'Coupon codes and discounts'],
            ['text' => 'Order management and history'],
            ['text' => '0% platform transaction fees — we never take a cut'],
        ],
    ],
    [
        'title' => 'Email & Communications',
        'description' => 'Communicate with your members through newsletters, announcements, and automated notifications.',
        'bullets' => [
            ['text' => 'Newsletter sending with templates'],
            ['text' => 'Mailing lists with subscriber management'],
            ['text' => 'Notification system for events and updates'],
            ['text' => 'Works with Mailgun or self-hosted email'],
            ['text' => 'No third-party dependency required'],
        ],
    ],
    [
        'title' => 'Content & Pages',
        'description' => 'A built-in CMS for publishing blog posts, building pages, and managing your site content — no external tools needed.',
        'bullets' => [
            ['text' => 'Blog posts with categories, scheduling, and rich text'],
            ['text' => 'Page builder with drag-and-drop component blocks'],
            ['text' => 'WYSIWYG editor for easy content creation'],
            ['text' => 'SEO-friendly URLs and meta descriptions'],
            ['text' => 'Media management for images and files'],
        ],
    ],
    [
        'title' => 'Admin Dashboard',
        'description' => 'A comprehensive management interface that puts you in control of every aspect of your organization.',
        'bullets' => [
            ['text' => 'Full management interface for all features'],
            ['text' => 'Analytics and reporting'],
            ['text' => 'Bulk operations for efficiency'],
            ['text' => 'Settings management for every feature'],
            ['text' => 'Error logging and diagnostics'],
        ],
    ],
    [
        'title' => 'Themes & Customization',
        'description' => 'Make it yours. Choose from included themes or build your own with the theme override system.',
        'bullets' => [
            ['text' => 'Multiple included themes'],
            ['text' => 'Multiple CSS framework options (Tailwind, Bootstrap, zero-dependency HTML5)'],
            ['text' => 'Theme override system for deep customization'],
            ['text' => 'Mobile-responsive out of the box'],
            ['text' => 'Component system for reusable page sections'],
        ],
    ],
    [
        'title' => 'API & Integrations',
        'description' => 'A full REST API lets you build integrations, automate workflows, and extend the platform however you need.',
        'bullets' => [
            ['text' => 'Full REST API with key authentication'],
            ['text' => 'CRUD operations for all data models'],
            ['text' => 'Webhook support for external integrations'],
            ['text' => 'Rate limiting and security built in'],
            ['text' => '40+ model endpoints'],
        ],
    ],
    [
        'title' => 'Privacy & Data Ownership',
        'description' => 'Your data belongs to you. Period. We built Joinery for organizations that take member privacy seriously.',
        'bullets' => [
            ['text' => 'All data stored in your PostgreSQL database'],
            ['text' => 'Full data export — take everything with you'],
            ['text' => 'No third-party tracking or analytics'],
            ['text' => 'Self-hosting option for complete control'],
            ['text' => 'Source available for inspection and audit'],
        ],
    ],
];
?>

<?php foreach ($features as $index => $feature): ?>
<section class="section<?= $index % 2 === 1 ? ' section-alt' : '' ?>">
    <div class="container">
        <?php
        echo ComponentRenderer::render(null, 'feature_showcase', [
            'title' => $feature['title'],
            'description' => $feature['description'],
            'bullets' => $feature['bullets'],
            'placeholder_text' => 'Screenshot coming soon',
            'reverse' => $index % 2 === 1 ? 'yes' : 'no',
        ]);
        ?>
    </div>
</section>
<?php endforeach; ?>

<?php
// --- Bottom CTA ---
echo ComponentRenderer::render(null, 'cta_section', [
    'heading' => 'See it in action',
    'subheading' => 'Start your free trial and explore every feature. No credit card required.',
    'button_text' => 'Start Free Trial',
    'button_url' => '#',
    'secondary_text' => 'See Pricing',
    'secondary_url' => '/pricing',
    'style' => 'dark',
]);

$page->public_footer();
?>
