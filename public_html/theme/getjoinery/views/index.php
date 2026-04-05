<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Joinery — Membership software you can trust with your data',
    'description' => 'All-in-one platform for managing members, events, payments, and communications. Hosted for you or self-hosted — your choice, your data.',
    'showheader' => true,
]);

// --- Hero ---
echo ComponentRenderer::render(null, 'marketing_hero', [
    'heading' => 'Membership software you can trust with your data',
    'subheading' => 'Manage members, events, payments, and communications — all in one place. We host it for you, or you host it yourself.',
    'primary_button_text' => 'Start Free Trial',
    'primary_button_url' => '#',
    'secondary_button_text' => 'See How It Works',
    'secondary_button_url' => '/features',
]);

// --- Trust Badges ---
echo ComponentRenderer::render(null, 'trust_badges', [
    'badges' => [
        ['icon' => 'shield', 'text' => 'Source Available'],
        ['icon' => 'check', 'text' => '0% Transaction Fees'],
        ['icon' => 'lock', 'text' => 'Self-Host Option'],
        ['icon' => 'download', 'text' => 'Full Data Export'],
        ['icon' => 'heart', 'text' => 'Free for Personal Use'],
    ],
]);

// --- Feature Grid ---
echo ComponentRenderer::render(null, 'gj_feature_grid', [
    'label' => 'Everything You Need',
    'heading' => 'One platform, no duct tape',
    'subheading' => 'Stop juggling five different services. Joinery handles members, events, payments, email, and more.',
    'features' => [
        ['icon' => 'members', 'title' => 'Member Management', 'description' => 'Profiles, permissions, subscription tiers, and groups. Everything you need to organize your people.'],
        ['icon' => 'calendar', 'title' => 'Events & Registration', 'description' => 'Create events, manage signups, handle waitlists. Supports recurring schedules and custom questions.'],
        ['icon' => 'payments', 'title' => 'Payments & E-Commerce', 'description' => 'Stripe and PayPal built in. Sell memberships, products, and event tickets with zero platform fees.'],
        ['icon' => 'email', 'title' => 'Email & Communications', 'description' => 'Newsletters, mailing lists, and notifications. Works with Mailgun or self-hosted — your choice.'],
        ['icon' => 'plugins', 'title' => 'Plugins & Themes', 'description' => 'Extend with plugins, customize with themes. 20+ themes included, from Bootstrap to zero-dependency HTML5.'],
        ['icon' => 'shield', 'title' => 'Your Data, Your Rules', 'description' => 'Self-host anytime. Export everything. No lock-in, no data selling. Source available for full transparency.'],
    ],
    'link_text' => 'See All Features',
    'link_url' => '/features',
]);

// --- How It's Different ---
echo ComponentRenderer::render(null, 'comparison_cards', [
    'heading' => 'How Joinery is different',
    'comparisons' => [
        ['ours' => 'Your data is yours — export, self-host, or delete anytime', 'theirs' => 'Others keep your data on their servers, governed by their terms'],
        ['ours' => 'Source available — read every line that touches your data', 'theirs' => 'Others use opaque code you can never see or audit'],
        ['ours' => 'No lock-in — we earn your business every month', 'theirs' => 'Others design switching costs to keep you trapped'],
        ['ours' => 'Zero platform transaction fees — keep what you earn', 'theirs' => 'Others charge 2-5% platform fees on every transaction'],
    ],
]);

// --- Who It's For ---
echo ComponentRenderer::render(null, 'audience_grid', [
    'heading' => 'Built for real organizations',
    'audiences' => [
        ['icon' => 'members', 'title' => 'Clubs & Associations', 'description' => 'Manage members, collect dues, and organize events for your community.'],
        ['icon' => 'heart', 'title' => 'Nonprofits', 'description' => 'Track supporters, run fundraisers, and communicate with donors and volunteers.'],
        ['icon' => 'globe', 'title' => 'Community Groups', 'description' => 'Build your community on your terms, not someone else\'s platform.'],
        ['icon' => 'briefcase', 'title' => 'Small Businesses', 'description' => 'Membership programs, subscriptions, and customer management without enterprise pricing.'],
    ],
]);

// --- Pricing Teaser ---
echo ComponentRenderer::render(null, 'pricing_teaser', [
    'heading' => 'Simple, honest pricing',
    'subheading' => 'All features included on every plan. No transaction fees. No surprises.',
    'tier1_name' => 'Starter', 'tier1_price' => '$29', 'tier1_note' => 'Up to 250 members',
    'tier2_name' => 'Organization', 'tier2_price' => '$59', 'tier2_note' => 'Up to 2,000 members',
    'tier3_name' => 'Network', 'tier3_price' => '$99', 'tier3_note' => 'Up to 10,000 members',
    'link_text' => 'See full pricing',
    'link_url' => '/pricing',
]);

// --- Bottom CTA ---
echo ComponentRenderer::render(null, 'cta_section', [
    'heading' => 'Ready to own your membership platform?',
    'subheading' => 'Start your free trial today. No credit card required.',
    'button_text' => 'Start Free Trial',
    'button_url' => '#',
    'style' => 'dark',
]);

$page->public_footer();
?>
