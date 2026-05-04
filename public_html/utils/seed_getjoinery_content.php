<?php
/**
 * Seed getjoinery theme content into the database.
 *
 * Creates one custom_html PageContent instance per view file. Idempotent — skips
 * any slug that already exists. Run once during initial setup of each deployment.
 *
 * Usage: php utils/seed_getjoinery_content.php
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

$dblink = DbConnector::get_instance()->get_db_link();

// Look up the custom_html component ID
$q = $dblink->prepare("SELECT com_component_id FROM com_components WHERE com_type_key = 'custom_html'");
$q->execute();
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "ERROR: custom_html component type not found in com_components.\n";
    exit(1);
}
$component_id = (int)$row['com_component_id'];

$created = 0;
$skipped = 0;

function seed(string $slug, string $title, string $html): void {
    global $dblink, $component_id, $created, $skipped;
    $q = $dblink->prepare("SELECT pac_page_content_id FROM pac_page_contents WHERE pac_location_name = ?");
    $q->execute([$slug]);
    if ($q->fetch()) {
        echo "[skipped]  $slug\n";
        $skipped++;
        return;
    }
    $config = json_encode(['html' => $html]);
    $q = $dblink->prepare(
        "INSERT INTO pac_page_contents (pac_com_component_id, pac_location_name, pac_title, pac_config, pac_create_time)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $q->execute([$component_id, $slug, $title, $config]);
    echo "[created]  $slug\n";
    $created++;
}

// ---------------------------------------------------------------------------
// gj-home (index.php)
// ---------------------------------------------------------------------------

seed('gj-home', 'Home', <<<'HTML'
<section class="hero">
    <h1>Membership software you can trust with your data</h1>
    <p>Manage members, events, payments, and communications &mdash; all in one place. We host it for you, or you host it yourself.</p>
    <div class="btn-group btn-group-center">
        <a href="#" class="btn btn-primary">Start Free Trial</a>
        <a href="/features" class="btn btn-secondary">See How It Works</a>
    </div>
</section>

<div class="trust-bar">
    <div class="trust-items">
        <div class="trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Source Available
        </div>
        <div class="trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            0% Transaction Fees
        </div>
        <div class="trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Self-Host Option
        </div>
        <div class="trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Full Data Export
        </div>
        <div class="trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            Free for Personal Use
        </div>
    </div>
</div>

<section class="section">
    <div class="container">
        <div class="section-label">Everything You Need</div>
        <h2 class="section-title">One platform, no duct tape</h2>
        <p class="section-subtitle">Stop juggling five different services. Joinery handles members, events, payments, email, and more.</p>
        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <h3>Member Management</h3>
                <p>Profiles, permissions, subscription tiers, and groups. Everything you need to organize your people.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                <h3>Events &amp; Registration</h3>
                <p>Create events, manage signups, handle waitlists. Supports recurring schedules and custom questions.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
                <h3>Payments &amp; E-Commerce</h3>
                <p>Stripe and PayPal built in. Sell memberships, products, and event tickets with zero platform fees.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
                <h3>Email &amp; Communications</h3>
                <p>Newsletters, mailing lists, and notifications. Works with Mailgun or self-hosted &mdash; your choice.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
                <h3>Plugins &amp; Themes</h3>
                <p>Extend with plugins, customize with themes. Full theme override system supports Bootstrap, Tailwind, or zero-dependency HTML5.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
                <h3>Your Data, Your Rules</h3>
                <p>Self-host anytime. Export everything. No lock-in, no data selling. Source available for full transparency.</p>
            </div>
        </div>
        <p style="text-align: center; margin-top: 2rem;">
            <a href="/features" class="link-arrow">See All Features &rarr;</a>
        </p>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <h2 class="section-title">How Joinery is different</h2>
        <div class="diff-cards">
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Your data is yours &mdash; export, self-host, or delete anytime</div>
                <div class="diff-theirs">Others keep your data on their servers, governed by their terms</div>
            </div>
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Source available &mdash; read every line that touches your data</div>
                <div class="diff-theirs">Others use opaque code you can never see or audit</div>
            </div>
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> No lock-in &mdash; we earn your business every month</div>
                <div class="diff-theirs">Others design switching costs to keep you trapped</div>
            </div>
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Zero platform transaction fees &mdash; keep what you earn</div>
                <div class="diff-theirs">Others charge 2-5% platform fees on every transaction</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="section-title">Built for real organizations</h2>
        <div class="audience-grid">
            <div class="audience-card">
                <div class="audience-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <h3>Clubs &amp; Associations</h3>
                <p>Manage members, collect dues, and organize events for your community.</p>
            </div>
            <div class="audience-card">
                <div class="audience-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></div>
                <h3>Nonprofits</h3>
                <p>Track supporters, run fundraisers, and communicate with donors and volunteers.</p>
            </div>
            <div class="audience-card">
                <div class="audience-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div>
                <h3>Community Groups</h3>
                <p>Build your community on your terms, not someone else&#39;s platform.</p>
            </div>
            <div class="audience-card">
                <div class="audience-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></div>
                <h3>Small Businesses</h3>
                <p>Membership programs, subscriptions, and customer management without enterprise pricing.</p>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <h2 class="section-title">Simple, honest pricing</h2>
        <p class="section-subtitle">All features included on every plan. No transaction fees. No surprises.</p>
        <div class="pricing-teaser">
            <div class="pricing-teaser-tier">
                <div class="tier-name">Starter</div>
                <div class="tier-price">$29<span>/mo</span></div>
                <div class="tier-note">Up to 250 members</div>
            </div>
            <div class="pricing-teaser-tier featured">
                <div class="tier-name">Organization</div>
                <div class="tier-price">$59<span>/mo</span></div>
                <div class="tier-note">Up to 2,000 members</div>
            </div>
            <div class="pricing-teaser-tier">
                <div class="tier-name">Network</div>
                <div class="tier-price">$99<span>/mo</span></div>
                <div class="tier-note">Up to 10,000 members</div>
            </div>
        </div>
        <p style="text-align: center; margin-top: 2rem;">
            <a href="/pricing" class="link-arrow">See full pricing &rarr;</a>
        </p>
    </div>
</section>

<section class="section section-dark text-center">
    <div class="container">
        <h2 class="section-title" style="margin-bottom: 1rem;">Ready to own your membership platform?</h2>
        <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;">Start your free trial today. No credit card required.</p>
        <div class="btn-group btn-group-center">
            <a href="#" class="btn btn-primary">Start Free Trial</a>
        </div>
    </div>
</section>
HTML);

// ---------------------------------------------------------------------------
// gj-about (about.php)
// ---------------------------------------------------------------------------

seed('gj-about', 'About', <<<'HTML'
<section class="hero">
    <h1>About Joinery</h1>
    <p>An independent, source-available membership platform built to last.</p>
</section>

<section class="section">
    <div class="container" style="max-width: 800px;">
        <div class="about-section">
            <h2>The project</h2>
            <p>Joinery is a full-featured membership and event management platform. It handles everything a small-to-medium organization needs: member management, event registration, payment processing, email communications, e-commerce, and more.</p>
            <p>What makes it different is the approach. Joinery is source-available. You can read every line of code. You can self-host it on your own infrastructure. You can export all your data at any time. There is no vendor lock-in, no data harvesting, and no transaction fees.</p>
            <p>The platform includes a theme system with multiple CSS framework options, a plugin system for extensibility, and a full REST API. It runs on PHP and PostgreSQL &mdash; a proven, well-understood stack.</p>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container" style="max-width: 800px;">
        <div class="about-grid">
            <div>
                <h2>The creator</h2>
                <p>Joinery is built and maintained by Jeremy Tunnell &mdash; a software developer who believes membership organizations deserve better tools than what the SaaS industry currently offers.</p>
                <p>Being a solo developer is a feature, not a bug. It means opinionated design, fast iteration, and no design-by-committee. Every feature exists because it solves a real problem, not because a product manager needed to justify their headcount.</p>
                <p>The goal is to build something sustainable and lasting &mdash; software that organizations can rely on for years, not a startup looking for an exit.</p>
            </div>
            <div style="background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 250px;">
                <span style="font-family: var(--font-heading); color: var(--text-muted); opacity: 0.5; font-size: 0.85rem;">Photo coming soon</span>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container" style="max-width: 800px;">
        <div class="about-section">
            <h2>Why a solo developer?</h2>
            <p>Most software companies raise venture capital, hire fast, and optimize for growth metrics. This creates misaligned incentives &mdash; the company needs to maximize revenue, which means maximizing what they extract from customers.</p>
            <p>Joinery has no investors to satisfy. No board of directors. No growth targets. This means every decision can be made in the interest of the people who actually use the software.</p>
            <p>It also means the codebase stays coherent. One developer means one vision, one architectural style, and zero &ldquo;we inherited this from the team that left.&rdquo; Every line of code has a clear owner and a clear purpose.</p>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container" style="max-width: 600px; text-align: center;">
        <h2 class="section-title">Get in touch</h2>
        <p class="section-subtitle">Have questions? Want to discuss a project? Just want to say hello?</p>

        <div style="display: flex; flex-direction: column; gap: 1rem; align-items: center;">
            <div>
                <strong style="font-family: var(--font-heading);">Email</strong><br>
                <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a>
            </div>
            <div>
                <strong style="font-family: var(--font-heading);">GitHub</strong><br>
                <a href="https://github.com/getjoinery/joinery" target="_blank">github.com/getjoinery/joinery</a>
            </div>
        </div>
    </div>
</section>

<section class="section section-dark text-center">
    <div class="container">
        <h2 class="section-title" style="margin-bottom: 1rem;">Ready to get started?</h2>
        <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;">Try Joinery free for 14 days. No credit card required.</p>
        <div class="btn-group btn-group-center">
            <a href="#" class="btn btn-primary">Start Free Trial</a>
            <a href="/pricing" class="btn btn-outline" style="color: white; border-color: rgba(255,255,255,0.3);">See Pricing</a>
        </div>
    </div>
</section>
HTML);

// ---------------------------------------------------------------------------
// gj-philosophy (philosophy.php)
// ---------------------------------------------------------------------------

seed('gj-philosophy', 'Philosophy', <<<'HTML'
<section class="hero">
    <h1>Why Joinery exists</h1>
    <p>Most membership platforms treat your members' data as their asset. We think that's wrong.</p>
</section>

<section class="section">
    <div class="container" style="max-width: 700px;">
        <h2 style="text-align: left; margin-bottom: 1.5rem;">The problem</h2>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            When you use a membership platform, your members' data &mdash; their names, emails, payment information, activity history &mdash; lives on someone else's servers, governed by someone else's terms.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            Most platforms make it hard to leave. Your data becomes their leverage. Switching costs are the business model. And you can't see what the software is actually doing with your members' information because the code is a black box.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8;">
            This isn't a hypothetical concern. It's the standard operating model of the software industry. And it's not good enough for organizations that are trusted with their members' personal information.
        </p>
    </div>
</section>

<section class="section section-alt">
    <div class="container" style="max-width: 700px;">
        <h2 style="text-align: left; margin-bottom: 1.5rem;">Why I built Joinery</h2>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            I wanted membership software that treats the organization as the customer, not the product.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            I wanted something I could inspect, modify, and trust. Something where I could look at every line of code that touches member data and know exactly what it does.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8;">
            I wanted members to know their data isn't being harvested, sold, or analyzed for someone else's benefit. So I built it.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="section-title">The commitments</h2>
        <p class="section-subtitle">These are specific, concrete commitments &mdash; not vague promises.</p>

        <div class="commitment-list">
            <div class="commitment">
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Source available, always.</h3>
                <p>You can read every line of code that touches your data. We will never obscure what the software does. If you have a question about how something works, the answer is in the source.</p>
            </div>
            <div class="commitment">
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Self-hostable, always.</h3>
                <p>Every feature we offer as a hosted service, we will make every effort to offer as a self-hostable option. If we can't, we'll explain why publicly.</p>
            </div>
            <div class="commitment">
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Your data is yours.</h3>
                <p>Full export. No lock-in. If you leave, you take everything with you. We will never hold your data hostage or make it difficult to switch away.</p>
            </div>
            <div class="commitment">
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> No data selling. No tracking. No ads.</h3>
                <p>We make money from subscriptions, installation services, support contracts, and referral partnerships with infrastructure providers &mdash; not from your members' data. We don't run analytics on your members' behavior. We don't sell access to your mailing lists. We don't show ads.</p>
            </div>
            <div class="commitment">
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Free for personal use.</h3>
                <p>Individuals, students, and nonprofits can self-host Joinery at no cost under the PolyForm Noncommercial license. We believe access to good software shouldn't require a budget.</p>
            </div>
            <div class="commitment">
                <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Commercial licenses available.</h3>
                <p>Businesses that want to self-host can purchase a commercial license. We want to be sustainable, not extractive. Fair pricing for fair use.</p>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container" style="max-width: 700px;">
        <h2 style="text-align: left; margin-bottom: 1.5rem;">The business model</h2>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            We sell hosted Joinery subscriptions. You pay a monthly or annual fee, we run the infrastructure, handle updates, and provide support. We also earn revenue from installation services (White Glove Install), commercial self-hosting licenses, support contracts, and referral partnerships with infrastructure providers we recommend (like Linode).
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            Every revenue stream comes from providing a service or making a recommendation &mdash; never from selling, sharing, or exploiting your data.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            There is no venture capital. There is no pressure to &ldquo;maximize engagement&rdquo; or &ldquo;monetize the user base.&rdquo; There are no growth-at-all-costs metrics driving product decisions.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8;">
            We want to be sustainable, not explosive. We want to be around in 20 years, not acquired in 3.
        </p>
    </div>
</section>

<section class="section section-dark text-center">
    <div class="container">
        <h2 class="section-title" style="margin-bottom: 1rem;">Software that respects you</h2>
        <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;">Try Joinery free and see what membership software should look like.</p>
        <div class="btn-group btn-group-center">
            <a href="#" class="btn btn-primary">Start Free Trial</a>
            <a href="https://github.com/getjoinery/joinery" class="btn btn-outline" style="color: white; border-color: rgba(255,255,255,0.3);">View Source on GitHub</a>
        </div>
    </div>
</section>
HTML);

// ---------------------------------------------------------------------------
// gj-features (features.php)
// ---------------------------------------------------------------------------

$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';

$features_sections = [
    [
        'title' => 'Member Management',
        'description' => 'Organize your people with powerful, flexible member management. From basic contact lists to complex permission hierarchies.',
        'bullets' => ['User profiles with custom fields', 'Permission-based access control (member, moderator, admin)', 'Subscription tiers with feature-gating', 'Groups and group management', 'Member directory with search', 'Activity tracking and analytics'],
        'alt' => false,
    ],
    [
        'title' => 'Events &amp; Registration',
        'description' => 'Create and manage events of any size, from weekly meetups to annual conferences.',
        'bullets' => ['Event creation with rich details and media', 'Online registration with capacity management', 'Recurring events (weekly classes, monthly meetups)', 'Waitlists with automatic promotion', 'Custom registration questions', 'Calendar integration (iCal export)'],
        'alt' => true,
    ],
    [
        'title' => 'Payments &amp; E-Commerce',
        'description' => 'Accept payments and sell products without cobbling together three different services. Zero platform transaction fees.',
        'bullets' => ['Stripe and PayPal integration built in', 'Subscription billing with recurring payments', 'Product catalog and shopping cart', 'Coupon codes and discounts', 'Order management and history', '0% platform transaction fees &mdash; we never take a cut'],
        'alt' => false,
    ],
    [
        'title' => 'Email &amp; Communications',
        'description' => 'Communicate with your members through newsletters, announcements, and automated notifications.',
        'bullets' => ['Newsletter sending with templates', 'Mailing lists with subscriber management', 'Notification system for events and updates', 'Works with Mailgun or self-hosted email', 'No third-party dependency required'],
        'alt' => true,
    ],
    [
        'title' => 'Content &amp; Pages',
        'description' => 'A built-in CMS for publishing blog posts, building pages, and managing your site content &mdash; no external tools needed.',
        'bullets' => ['Blog posts with categories, scheduling, and rich text', 'Page builder with drag-and-drop component blocks', 'WYSIWYG editor for easy content creation', 'SEO-friendly URLs and meta descriptions', 'Media management for images and files'],
        'alt' => false,
    ],
    [
        'title' => 'Admin Dashboard',
        'description' => 'A comprehensive management interface that puts you in control of every aspect of your organization.',
        'bullets' => ['Full management interface for all features', 'Analytics and reporting', 'Bulk operations for efficiency', 'Settings management for every feature', 'Error logging and diagnostics'],
        'alt' => true,
    ],
    [
        'title' => 'Themes &amp; Customization',
        'description' => 'Make it yours. Choose from included themes or build your own with the theme override system.',
        'bullets' => ['Multiple included themes', 'Multiple CSS framework options (Tailwind, Bootstrap, zero-dependency HTML5)', 'Theme override system for deep customization', 'Mobile-responsive out of the box', 'Component system for reusable page sections'],
        'alt' => false,
    ],
    [
        'title' => 'API &amp; Integrations',
        'description' => 'A full REST API lets you build integrations, automate workflows, and extend the platform however you need.',
        'bullets' => ['Full REST API with key authentication', 'CRUD operations for all data models', 'Webhook support for external integrations', 'Rate limiting and security built in', '40+ model endpoints'],
        'alt' => true,
    ],
    [
        'title' => 'Privacy &amp; Data Ownership',
        'description' => 'Your data belongs to you. Period. We built Joinery for organizations that take member privacy seriously.',
        'bullets' => ['All data stored in your PostgreSQL database', 'Full data export &mdash; take everything with you', 'No third-party tracking or analytics', 'Self-hosting option for complete control', 'Source available for inspection and audit'],
        'alt' => false,
    ],
];

$features_html  = '<section class="hero">' . "\n";
$features_html .= '    <h1>Everything your organization needs</h1>' . "\n";
$features_html .= '    <p>Members, events, payments, email, themes, plugins, and a full API. All included in every plan.</p>' . "\n";
$features_html .= '    <div class="btn-group btn-group-center">' . "\n";
$features_html .= '        <a href="#" class="btn btn-primary">Start Free Trial</a>' . "\n";
$features_html .= '        <a href="/pricing" class="btn btn-secondary">See Pricing</a>' . "\n";
$features_html .= '    </div>' . "\n";
$features_html .= '</section>' . "\n\n";

foreach ($features_sections as $f) {
    $class = 'section' . ($f['alt'] ? ' section-alt' : '');
    $reverse = $f['alt'] ? ' reverse' : '';
    $features_html .= '<section class="' . $class . '">' . "\n";
    $features_html .= '    <div class="container">' . "\n";
    $features_html .= '        <div class="feature-showcase' . $reverse . '">' . "\n";
    $features_html .= '            <div class="feature-showcase-content">' . "\n";
    $features_html .= '                <h3>' . $f['title'] . '</h3>' . "\n";
    $features_html .= '                <p>' . $f['description'] . '</p>' . "\n";
    $features_html .= '                <ul>' . "\n";
    foreach ($f['bullets'] as $bullet) {
        $features_html .= '                    <li>' . $check_svg . ' ' . $bullet . '</li>' . "\n";
    }
    $features_html .= '                </ul>' . "\n";
    $features_html .= '            </div>' . "\n";
    $features_html .= '            <div class="feature-showcase-image">' . "\n";
    $features_html .= '                <span class="placeholder-text">Screenshot coming soon</span>' . "\n";
    $features_html .= '            </div>' . "\n";
    $features_html .= '        </div>' . "\n";
    $features_html .= '    </div>' . "\n";
    $features_html .= '</section>' . "\n\n";
}

$features_html .= '<section class="section section-dark text-center">' . "\n";
$features_html .= '    <div class="container">' . "\n";
$features_html .= '        <h2 class="section-title" style="margin-bottom: 1rem;">See it in action</h2>' . "\n";
$features_html .= '        <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;">Start your free trial and explore every feature. No credit card required.</p>' . "\n";
$features_html .= '        <div class="btn-group btn-group-center">' . "\n";
$features_html .= '            <a href="#" class="btn btn-primary">Start Free Trial</a>' . "\n";
$features_html .= '            <a href="/pricing" class="btn btn-outline" style="color: white; border-color: rgba(255,255,255,0.3);">See Pricing</a>' . "\n";
$features_html .= '        </div>' . "\n";
$features_html .= '    </div>' . "\n";
$features_html .= '</section>';

seed('gj-features', 'Features', $features_html);

// ---------------------------------------------------------------------------
// gj-pricing (pricing.php)
// ---------------------------------------------------------------------------

seed('gj-pricing', 'Pricing', <<<'HTML'
<section class="hero" style="padding-bottom: 2rem;">
    <h1>Simple, honest pricing</h1>
    <p>All features included on every plan. No transaction fees. No surprises.</p>
</section>

<div class="pricing-toggle">
    <span id="label-monthly">Monthly</span>
    <div class="toggle-switch active" id="billing-toggle" role="button" tabindex="0" aria-label="Toggle annual/monthly pricing"></div>
    <span id="label-annual" class="active">Annual <span class="badge badge-accent">Save ~25%</span></span>
</div>

<section class="section" style="padding-top: 0;">
    <div class="container">
        <div class="pricing-grid">

            <div class="pricing-tier">
                <div class="tier-name">Starter</div>
                <div class="monthly-price" data-show="annual">$39/mo</div>
                <div class="price" data-annual="$29" data-monthly="$39"><span data-annual="$29" data-monthly="$39">$29</span><span>/mo</span></div>
                <div class="price-note" data-annual="$348 billed annually" data-monthly="Billed monthly">$348 billed annually</div>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Up to 250 members</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 2 admin users</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Unlimited events</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Unlimited products</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Email via your Mailgun account</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 2 GB storage</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> All themes &amp; plugins</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Full API access</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 0% transaction fees</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Custom domain</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:0.3"><line x1="5" y1="12" x2="19" y2="12"/></svg> Priority support</li>
                </ul>
                <a href="#" class="btn btn-secondary">Start Free Trial</a>
            </div>

            <div class="pricing-tier featured">
                <div class="badge">Most Popular</div>
                <div class="tier-name">Organization</div>
                <div class="monthly-price" data-show="annual">$79/mo</div>
                <div class="price" data-annual="$59" data-monthly="$79"><span data-annual="$59" data-monthly="$79">$59</span><span>/mo</span></div>
                <div class="price-note" data-annual="$708 billed annually" data-monthly="Billed monthly">$708 billed annually</div>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Up to 2,000 members</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 5 admin users</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Unlimited events</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Unlimited products</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Email via your Mailgun account</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 10 GB storage</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> All themes &amp; plugins</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Full API access</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 0% transaction fees</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Custom domain</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Priority support</li>
                </ul>
                <a href="#" class="btn btn-primary">Start Free Trial</a>
            </div>

            <div class="pricing-tier">
                <div class="tier-name">Network</div>
                <div class="monthly-price" data-show="annual">$129/mo</div>
                <div class="price" data-annual="$99" data-monthly="$129"><span data-annual="$99" data-monthly="$129">$99</span><span>/mo</span></div>
                <div class="price-note" data-annual="$1,188 billed annually" data-monthly="Billed monthly">$1,188 billed annually</div>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Up to 10,000 members</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Unlimited admins</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Unlimited events</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Unlimited products</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Email via your Mailgun account</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 50 GB storage</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> All themes &amp; plugins</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Full API access</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 0% transaction fees</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Custom domain</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Priority support</li>
                </ul>
                <a href="#" class="btn btn-secondary">Start Free Trial</a>
            </div>

        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <h2 class="section-title">Prefer to self-host?</h2>
        <p class="section-subtitle">Same software, your infrastructure. Free for personal and nonprofit use.</p>

        <div class="self-host-options">
            <div class="self-host-card">
                <h3>DIY Install</h3>
                <div class="price-tag">Free</div>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Free for personal, educational, and nonprofit use under the PolyForm Noncommercial license.</p>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Full source code access</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Same features as hosted</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Community support via GitHub</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> You manage the infrastructure</li>
                </ul>
                <p style="margin-top: 1rem;">
                    <a href="https://github.com/getjoinery/joinery" class="link-arrow" target="_blank">View on GitHub &rarr;</a>
                </p>
            </div>

            <div class="self-host-card" style="border-color: var(--accent);">
                <h3>White Glove Install</h3>
                <div class="price-tag">$249 <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted);">one-time</span></div>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">We provision and configure everything for you. Automated setup via Linode &mdash; your server, your control.</p>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Automated server provisioning</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Full Joinery installation</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> DNS and SSL configuration</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Your domain, your server</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Community support via GitHub</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> No recurring charges</li>
                </ul>
                <p style="margin-top: 1rem;">
                    <a href="#" class="btn btn-primary btn-sm">Get Started</a>
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="commercial-license-card">
            <div class="commercial-license-content">
                <div class="section-label">For Businesses</div>
                <h2>Commercial Self-Hosting License</h2>
                <p>Running Joinery for a commercial organization? The PolyForm Noncommercial license covers personal, educational, and nonprofit use. For everything else, a commercial license gives you full rights to run Joinery on your own infrastructure &mdash; with no recurring fees.</p>

                <div class="commercial-license-features">
                    <div class="commercial-feature-col">
                        <ul>
                            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Perpetual license &mdash; pay once, use forever</li>
                            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> All current features and themes included</li>
                            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Run on your own servers with full control</li>
                        </ul>
                    </div>
                    <div class="commercial-feature-col">
                        <ul>
                            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> No per-member or per-transaction fees</li>
                            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Optional annual updates subscription</li>
                            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Priority support available as add-on</li>
                        </ul>
                    </div>
                </div>

                <p class="commercial-pitch">Most membership platforms charge $50&ndash;200/month and take a cut of every transaction. A Joinery commercial license pays for itself in months &mdash; and then it's free forever.</p>

                <div class="btn-group" style="margin-top: 1.5rem;">
                    <a href="mailto:hello@getjoinery.com?subject=Commercial%20License%20Inquiry" class="btn btn-primary">Get a Quote</a>
                    <a href="/philosophy" class="btn btn-secondary">Why We Price This Way</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-dark text-center">
    <div class="container">
        <h2 class="section-title" style="margin-bottom: 1rem;">Try it free for 14 days</h2>
        <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;">No credit card required. All features included. Cancel anytime.</p>
        <div class="btn-group btn-group-center">
            <a href="#" class="btn btn-primary">Start Free Trial</a>
            <a href="mailto:hello@getjoinery.com" class="btn btn-outline" style="color: white; border-color: rgba(255,255,255,0.3);">Talk to Us</a>
        </div>
    </div>
</section>

<script>
(function() {
    var toggle = document.getElementById('billing-toggle');
    var labelMonthly = document.getElementById('label-monthly');
    var labelAnnual = document.getElementById('label-annual');
    if (!toggle) return;

    var isAnnual = true;

    function updatePricing() {
        document.querySelectorAll('[data-annual]').forEach(function(el) {
            el.textContent = isAnnual ? el.dataset.annual : el.dataset.monthly;
        });
        document.querySelectorAll('[data-show="annual"]').forEach(function(el) {
            el.style.display = isAnnual ? 'none' : '';
        });
        toggle.classList.toggle('active', isAnnual);
        labelAnnual.classList.toggle('active', isAnnual);
        labelMonthly.classList.toggle('active', !isAnnual);
    }

    toggle.addEventListener('click', function() {
        isAnnual = !isAnnual;
        updatePricing();
    });
    toggle.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            isAnnual = !isAnnual;
            updatePricing();
        }
    });

    updatePricing();
})();
</script>
HTML);

// ---------------------------------------------------------------------------
// gj-developers (developers.php)
// ---------------------------------------------------------------------------

seed('gj-developers', 'Developers', <<<'HTML'
<section class="hero">
    <h1>Built by a developer, for developers</h1>
    <p>PostgreSQL. PHP 8.x. REST API. Plugin system. Theme engine. Readable code, no lock-in.</p>
    <div class="btn-group btn-group-center">
        <a href="https://github.com/getjoinery/joinery" class="btn btn-primary" target="_blank">View on GitHub</a>
        <a href="#api" class="btn btn-secondary">API Docs</a>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-label">The Stack</div>
        <h2 class="section-title">Architecture overview</h2>
        <p class="section-subtitle">A clean, well-structured PHP application. No framework magic &mdash; just patterns that work.</p>

        <div class="arch-grid">
            <div class="arch-card">
                <h4>Database</h4>
                <p>PostgreSQL with PDO prepared statements everywhere. Active Record pattern for data models. Version-controlled migrations.</p>
            </div>
            <div class="arch-card">
                <h4>Backend</h4>
                <p>PHP 8.x, MVC-like architecture. Front-controller routing. Clean separation of data, logic, and views.</p>
            </div>
            <div class="arch-card">
                <h4>Frontend</h4>
                <p>Zero-dependency HTML5 by default. Modern vanilla JavaScript. Bootstrap and Tailwind support also available.</p>
            </div>
            <div class="arch-card">
                <h4>API</h4>
                <p>Full REST API with key-based authentication, rate limiting, CORS support. 40+ model endpoints with CRUD + actions.</p>
            </div>
            <div class="arch-card">
                <h4>Plugins</h4>
                <p>Self-contained modules with their own data models, views, admin pages, routes, and scheduled tasks.</p>
            </div>
            <div class="arch-card">
                <h4>Themes</h4>
                <p>Override chain &mdash; theme &rarr; plugin &rarr; base. Customize anything without forking. Component system for reusable sections.</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="section-title">Security</h2>
        <p class="section-subtitle">Membership platforms hold sensitive data &mdash; names, emails, payment info, personal details. Security is not a feature here. It is the baseline.</p>

        <div class="arch-grid">
            <div class="arch-card">
                <h4>SQL Injection Protection</h4>
                <p>Every database query uses PDO prepared statements. There are no exceptions and no raw string concatenation paths. This is enforced structurally, not by convention.</p>
            </div>
            <div class="arch-card">
                <h4>XSS Prevention</h4>
                <p>All user-generated output is escaped with htmlspecialchars. The FormWriter system handles output encoding automatically so individual views cannot forget.</p>
            </div>
            <div class="arch-card">
                <h4>Authentication &amp; Permissions</h4>
                <p>Session-based authentication with role-based access control. Permission checks happen at the controller level before any data is loaded or rendered.</p>
            </div>
            <div class="arch-card">
                <h4>CSRF Protection</h4>
                <p>CSRF token generation is built into the FormWriter. Available on every form out of the box &mdash; no extra setup required.</p>
            </div>
            <div class="arch-card">
                <h4>Password Hashing</h4>
                <p>Passwords are hashed with Argon2id &mdash; the current best practice. Legacy bcrypt hashes are automatically upgraded on next login. No plaintext, no MD5, no SHA.</p>
            </div>
            <div class="arch-card">
                <h4>Cookie Security</h4>
                <p>All cookies are set with HttpOnly, SameSite=Lax, and Secure flags. Session cookies are not accessible to JavaScript and are scoped to prevent cross-site request attacks.</p>
            </div>
            <div class="arch-card">
                <h4>Source Available</h4>
                <p>You can read every line of code that touches your members' data. No obfuscation, no compiled binaries, no trust-us black boxes.</p>
            </div>
            <div class="arch-card">
                <h4>Secure File Handling</h4>
                <p>File uploads are validated by type and size, stored outside the web root where possible, and served through controlled handlers &mdash; not direct URLs.</p>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt" id="api">
    <div class="container">
        <div class="feature-showcase">
            <div class="feature-showcase-content">
                <h3>REST API</h3>
                <p>Every feature is accessible through the API. Build integrations, automate workflows, or build your own frontend.</p>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Key-based authentication</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 40+ model endpoints</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> CRUD + action operations</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Rate limiting and CORS</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> JSON request/response</li>
                </ul>
            </div>
            <div class="feature-showcase-image" style="padding: 0; background: var(--bg-dark); border: none;">
                <div class="code-block" style="margin: 0; border-radius: var(--radius-lg); width: 100%;">
<span class="comment"># List members</span>
curl -H <span class="string">"X-API-Key: your_key"</span> \
  https://yoursite.com/api/users

<span class="comment"># Get a single member</span>
curl -H <span class="string">"X-API-Key: your_key"</span> \
  https://yoursite.com/api/users/42

<span class="comment"># Create an event</span>
curl -X POST \
  -H <span class="string">"X-API-Key: your_key"</span> \
  -H <span class="string">"Content-Type: application/json"</span> \
  -d <span class="string">'{"evt_name": "Monthly Meetup"}'</span> \
  https://yoursite.com/api/events</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="feature-showcase reverse">
            <div class="feature-showcase-content">
                <h3>Plugin System</h3>
                <p>Plugins are self-contained modules that can add data models, views, admin pages, API endpoints, and scheduled tasks. Each plugin has its own MVC structure.</p>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Own data models with automatic table management</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Admin interface pages</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Custom routes and views</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Scheduled task support</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Activate/deactivate without data loss</li>
                </ul>
            </div>
            <div class="feature-showcase-image" style="padding: 0; background: var(--bg-dark); border: none;">
                <div class="code-block" style="margin: 0; border-radius: var(--radius-lg); width: 100%;">
<span class="comment"># Plugin directory structure</span>
plugins/bookings/
  &#x251C;&#x2500;&#x2500; plugin.json
  &#x251C;&#x2500;&#x2500; data/
  &#x2502;   &#x2514;&#x2500;&#x2500; bookings_class.php
  &#x251C;&#x2500;&#x2500; views/
  &#x2502;   &#x2514;&#x2500;&#x2500; booking.php
  &#x251C;&#x2500;&#x2500; admin/
  &#x2502;   &#x2514;&#x2500;&#x2500; admin_bookings.php
  &#x251C;&#x2500;&#x2500; logic/
  &#x2502;   &#x2514;&#x2500;&#x2500; booking_logic.php
  &#x2514;&#x2500;&#x2500; assets/
      &#x2514;&#x2500;&#x2500; css/style.css</div>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="feature-showcase">
            <div class="feature-showcase-content">
                <h3>Theme System</h3>
                <p>Themes control the entire visual presentation. The override chain lets you customize any view, template, or asset without modifying core files.</p>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Override chain: theme &rarr; plugin &rarr; base</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Multiple included themes</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Bootstrap, Tailwind, or zero-dependency HTML5</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Component system for reusable sections</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> FormWriter adapts to your CSS framework</li>
                </ul>
            </div>
            <div class="feature-showcase-image">
                <span class="placeholder-text">Theme gallery coming soon</span>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="section-title">Self-hosting</h2>
        <p class="section-subtitle">Run Joinery on your own infrastructure. Same software, complete control.</p>

        <div class="arch-grid" style="max-width: 800px; margin: 0 auto;">
            <div class="arch-card">
                <h4>Requirements</h4>
                <p>PHP 8.x, PostgreSQL, Apache or Nginx. Standard LAMP/LEMP stack &mdash; nothing exotic.</p>
            </div>
            <div class="arch-card">
                <h4>Installation</h4>
                <p>Clone the repo, run the installer, configure your database. Docker supported. Or let us do it with White Glove Install ($249).</p>
            </div>
            <div class="arch-card">
                <h4>Updates</h4>
                <p>Automated upgrade system. Run one command to pull the latest version and apply migrations.</p>
            </div>
        </div>
    </div>
</section>

<section class="section section-dark text-center">
    <div class="container">
        <h2 class="section-title" style="margin-bottom: 1rem;">Explore the source</h2>
        <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;">Joinery is source-available under the PolyForm Noncommercial license. Read the code, file issues, or contribute.</p>
        <div class="btn-group btn-group-center">
            <a href="https://github.com/getjoinery/joinery" class="btn btn-primary">View on GitHub</a>
            <a href="/docs" class="btn btn-outline" style="color: white; border-color: rgba(255,255,255,0.3);">Read the Docs</a>
        </div>
    </div>
</section>
HTML);

// ---------------------------------------------------------------------------
// gj-showcase (showcase.php)
// ---------------------------------------------------------------------------

seed('gj-showcase', 'Showcase', <<<'HTML'
<section class="hero">
    <h1>Built with Joinery</h1>
    <p>Joinery is an application framework. Membership management is one thing you can build with it &mdash; but it's not the only thing. Here's what's running on the platform today.</p>
</section>

<section class="section">
    <div class="container">

        <div class="showcase-card">
            <div class="showcase-image">
                <a href="https://scrolldaddy.app" target="_blank" rel="noopener">
                    <img src="/theme/getjoinery/assets/img/showcase-scrolldaddy.png" alt="ScrollDaddy &mdash; DNS-based web filtering" loading="lazy">
                </a>
            </div>
            <div class="showcase-details">
                <h2><a href="https://scrolldaddy.app" target="_blank" rel="noopener">ScrollDaddy</a></h2>
                <p class="showcase-tagline">DNS-based web filtering. Block social media, gambling, porn, news, and more &mdash; before it gets to your device.</p>
                <p>ScrollDaddy is a consumer web filtering service. The web application &mdash; user accounts, device management, filter configuration, subscription billing &mdash; is built on Joinery. A companion Go DNS server handles the actual filtering at the network level, reading device configurations from the shared Joinery database. Both components are available under the same PolyForm Noncommercial license.</p>

                <div class="showcase-built-with">
                    <h4>Joinery features used</h4>
                    <ul>
                        <li>Plugin system &mdash; 11 data models, admin interface, scheduled tasks</li>
                        <li>Custom theme &mdash; full public-facing site with its own design language</li>
                        <li>Subscription tiers &mdash; feature-gated plans with Stripe billing</li>
                        <li>Scheduled tasks &mdash; daily blocklist updates from external sources</li>
                        <li>User accounts &mdash; registration, login, profile, device management</li>
                        <li>REST API &mdash; device configuration served to the DNS server</li>
                    </ul>
                </div>
                <div class="showcase-built-with">
                    <h4>Also includes</h4>
                    <ul>
                        <li>Go DNS server &mdash; DNS-over-HTTPS and DNS-over-TLS filtering engine (same license)</li>
                    </ul>
                </div>

                <a href="https://scrolldaddy.app" class="btn btn-primary" target="_blank" rel="noopener">Visit ScrollDaddy</a>
            </div>
        </div>

    </div>
</section>

<section class="section section-alt">
    <div class="container" style="text-align: center; max-width: 700px;">
        <h2 class="section-title">More projects coming</h2>
        <p class="section-subtitle">Joinery's plugin system, theme engine, and data model layer make it possible to build complete web applications &mdash; not just membership sites. If you've built something with Joinery, we'd love to feature it.</p>
        <div class="btn-group btn-group-center" style="margin-top: 1.5rem;">
            <a href="mailto:hello@getjoinery.com?subject=Showcase%20Submission" class="btn btn-secondary">Submit Your Project</a>
        </div>
    </div>
</section>

<section class="section section-dark text-center">
    <div class="container">
        <h2 class="section-title" style="margin-bottom: 1rem;">Build something with Joinery</h2>
        <p style="max-width: 480px; margin: 0 auto 2rem; font-size: 1.05rem;">A plugin system, theme engine, data models, REST API, and user management &mdash; all ready to go. Start with the framework, build what you want.</p>
        <div class="btn-group btn-group-center">
            <a href="https://github.com/getjoinery/joinery" class="btn btn-primary">View on GitHub</a>
            <a href="/developers" class="btn btn-outline" style="color: white; border-color: rgba(255,255,255,0.3);">Developer Docs</a>
        </div>
    </div>
</section>
HTML);

// ---------------------------------------------------------------------------
// gj-terms (terms.php)
// ---------------------------------------------------------------------------

seed('gj-terms', 'Terms of Service', <<<'HTML'
<section class="section">
    <div class="container legal-content">

        <h1>Terms of Service</h1>
        <p class="legal-updated">Last updated: April 2026</p>

        <div class="legal-summary">
            <h2>The short version</h2>
            <ul>
                <li><strong>Your content is yours.</strong> We don't claim ownership of your data. Our license to it ends when you leave.</li>
                <li><strong>Cancel anytime.</strong> No termination fees. No penalties. Export your data and go.</li>
                <li><strong>All features included.</strong> Every plan includes every feature. The difference is scale (members, storage, admins), not functionality.</li>
                <li><strong>No surprises on price.</strong> We won't raise your price mid-term. Annual plans lock your rate for the year.</li>
                <li><strong>We can read our own terms.</strong> If something in here is confusing, that's a bug. Email us and we'll clarify.</li>
            </ul>
        </div>

        <p>These terms govern your use of Joinery, operated by Joinery, Inc. (a Delaware corporation). By creating an account or using the service, you agree to these terms.</p>

        <h2>1. The service</h2>
        <p>Joinery is a membership management platform. It provides tools for managing members, events, payments, email, content, and related functions for organizations. We offer Joinery in two ways:</p>
        <ul>
            <li><strong>Hosted Joinery</strong> &mdash; we run the software on our servers, you access it through a web browser. This is a paid subscription.</li>
            <li><strong>Self-hosted Joinery</strong> &mdash; you download the source code and run it on your own server, under either the PolyForm Noncommercial license (free) or a commercial license (paid).</li>
        </ul>
        <p>These terms apply primarily to hosted Joinery. Self-hosted usage is governed by your applicable license (see Section 10).</p>

        <h2>2. Your account</h2>
        <ul>
            <li>You must provide accurate account information.</li>
            <li>You are responsible for maintaining the security of your account credentials.</li>
            <li>You are responsible for all activity under your account, including actions taken by admin users you authorize.</li>
            <li>You must be at least 18 years old (or the age of majority in your jurisdiction) to create an account.</li>
            <li>One organization per account. If you run multiple organizations, each needs its own account.</li>
        </ul>

        <h2>3. Your content and data</h2>

        <h3>Ownership</h3>
        <p><strong>You own your content.</strong> Everything you and your members create, upload, or store in Joinery &mdash; member records, event data, blog posts, images, files, and all other content &mdash; belongs to you and your members. We do not claim any ownership rights to your content.</p>

        <h3>Our license</h3>
        <p>To operate the service, we need a limited license to your content. You grant us a <strong>non-exclusive, worldwide, royalty-free license</strong> to store, display, transmit, and process your content <strong>solely to provide the service to you</strong>. For example, we need this license to store your data on our servers, display it in your admin interface, send emails on your behalf, and create backups.</p>
        <p>This license is:</p>
        <ul>
            <li><strong>Purpose-limited</strong> &mdash; we can only use your content to operate the service for you.</li>
            <li><strong>Term-limited</strong> &mdash; the license ends when you cancel your account and your data is deleted (see Section 7).</li>
            <li><strong>Non-transferable for independent use</strong> &mdash; we cannot sell, sublicense, or use your content independently of operating your service.</li>
        </ul>
        <p>We will never use your content to train machine learning models, generate marketing materials, populate other customers' accounts, or any other purpose outside of operating your account.</p>

        <h3>Data export</h3>
        <p>You can export your data at any time through the admin interface or API. We do not charge for data export. We do not artificially restrict export formats or frequency. If you're leaving, we want the transition to be easy, not painful.</p>

        <h2>4. Joinery's intellectual property</h2>
        <p>The Joinery software, design, documentation, branding, and name are the property of Joinery, Inc. Your subscription gives you the right to use the hosted service. It does not grant you rights to the Joinery source code, trademarks, or brand assets beyond normal use of the service.</p>
        <p>The Joinery source code is available under the PolyForm Noncommercial license for inspection, personal use, and nonprofit use. Commercial self-hosting requires a separate commercial license.</p>

        <h2>5. Payment and billing</h2>
        <ul>
            <li><strong>Pricing</strong> &mdash; current pricing is listed at <a href="/pricing">getjoinery.com/pricing</a>. All prices are in US dollars.</li>
            <li><strong>Billing cycle</strong> &mdash; monthly plans are billed monthly. Annual plans are billed once per year at a discounted rate.</li>
            <li><strong>Payment processing</strong> &mdash; payments are processed by Stripe or PayPal. We do not store credit card numbers or bank account details (see our <a href="/privacy">Privacy Policy</a>).</li>
            <li><strong>Price changes</strong> &mdash; if we change pricing, existing customers keep their current rate until the end of their billing cycle (monthly) or term (annual). We will give at least 30 days' notice before any price increase takes effect on your account.</li>
            <li><strong>No transaction fees</strong> &mdash; Joinery does not charge transaction fees on payments you process through the platform. Standard fees from Stripe or PayPal still apply &mdash; those are between you and your payment processor.</li>
            <li><strong>Failed payments</strong> &mdash; if a payment fails, we'll notify you and give you a reasonable window to resolve it before suspending service.</li>
        </ul>

        <h2>6. Free trial</h2>
        <p>New accounts include a free trial period. During the trial, you have access to all features. No credit card is required to start a trial. At the end of the trial, you can choose a paid plan or your account will be deactivated. Trial data is retained for 30 days after the trial ends, then deleted.</p>

        <h2>7. Cancellation</h2>
        <p>You can cancel your subscription at any time. Here's what happens:</p>
        <ul>
            <li><strong>No cancellation fees.</strong> No penalties. No early termination charges. No questions asked.</li>
            <li><strong>Service continues through the paid period.</strong> If you cancel mid-cycle, you keep access until the end of your current billing period.</li>
            <li><strong>Data retention.</strong> After your service ends, we retain your data for 30 days so you can export or reactivate. After 30 days, your data is deleted from active systems. Backups are purged within 60 days after that.</li>
            <li><strong>Immediate deletion.</strong> If you want your data deleted immediately without the 30-day window, email us and we'll process it within 14 days.</li>
        </ul>
        <p>We may also cancel your account if you violate these terms (see Section 8). If we cancel your account for reasons other than abuse, we'll provide at least 30 days' notice and an opportunity to export your data.</p>

        <h2>8. Acceptable use</h2>
        <p>You agree not to use Joinery to:</p>
        <ul>
            <li>Violate any applicable law or regulation.</li>
            <li>Infringe on the intellectual property or privacy rights of others.</li>
            <li>Send unsolicited bulk email (spam) through the platform.</li>
            <li>Store or distribute malware, phishing pages, or other malicious content.</li>
            <li>Attempt to gain unauthorized access to other customers' accounts or our infrastructure.</li>
            <li>Resell the hosted service without authorization.</li>
            <li>Use the service in a way that degrades performance for other customers (excessive API calls, automated scraping, etc.).</li>
        </ul>
        <p>We also reserve the right to refuse or terminate hosted service for content we find unacceptable, at our sole discretion. Joinery is source-available and self-hostable &mdash; declining to host your content on our infrastructure does not prevent you from running the software on your own.</p>
        <p>We handle violations proportionally. A misconfigured email campaign gets a warning and help fixing it. Deliberate abuse gets terminated. We won't shut down your account over an honest mistake without talking to you first.</p>

        <h2>9. API usage</h2>
        <p>The Joinery API is available to all plans. API usage is subject to rate limiting to ensure fair access for all customers. Current rate limits are documented in the API documentation. We reserve the right to adjust rate limits, but will provide notice before reducing them for existing customers.</p>

        <h2>10. Third-party integrations</h2>
        <p>Joinery uses a <strong>bring-your-own-keys</strong> model for third-party services. Features like payment processing (Stripe, PayPal), email delivery (Mailgun, SMTP), mailing list sync (Mailchimp), bot protection (hCaptcha, reCAPTCHA), and scheduling (Acuity, Calendly) require you to create your own account with the relevant provider and enter your API keys in Joinery's settings.</p>
        <ul>
            <li>You are responsible for your own accounts with these services, including their terms, pricing, and usage limits.</li>
            <li>Joinery does not charge any markup, commission, or referral fee on third-party services.</li>
            <li>We do not have access to your third-party accounts. If you need support with a third-party service, contact that provider directly.</li>
            <li>If a third-party service changes its terms, pricing, or availability, that is between you and the provider. We will make reasonable efforts to maintain compatibility with supported integrations, but we cannot guarantee uninterrupted operation of services we don't control.</li>
        </ul>

        <h2>11. Self-hosting licenses</h2>

        <h3>PolyForm Noncommercial</h3>
        <p>The Joinery source code is available under the <a href="https://polyformproject.org/licenses/noncommercial/1.0.0/" target="_blank" rel="noopener">PolyForm Noncommercial License 1.0.0</a>. This permits personal, educational, and nonprofit use at no cost. The full license text governs &mdash; these terms don't modify it.</p>

        <h3>Commercial license</h3>
        <p>Commercial organizations that want to self-host Joinery need a commercial license. Commercial licenses are perpetual (pay once, use forever), cover all current features, and have no per-member or per-transaction fees. Terms are agreed individually &mdash; contact <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a> for details.</p>

        <h3>Plugins and themes</h3>
        <p>The Joinery license includes a <strong>plugin and theme exception</strong>. If you build a plugin or theme for Joinery, it is yours &mdash; you may license it under any terms you choose, including commercial terms. The PolyForm Noncommercial license covers Joinery's core code, not your extensions. Plugins and themes that ship as part of the official Joinery distribution remain under the project license.</p>

        <h2>12. Availability</h2>
        <p>We work to keep hosted Joinery available and reliable, but we don't guarantee 100% uptime. Planned maintenance will be announced in advance when possible. We are not liable for downtime caused by factors outside our control (hosting provider outages, DNS issues, internet disruptions, etc.).</p>

        <h2>13. Limitation of liability</h2>
        <p>To the maximum extent permitted by law:</p>
        <ul>
            <li>Joinery is provided "as is" without warranties of any kind, express or implied, including warranties of merchantability, fitness for a particular purpose, and non-infringement.</li>
            <li>Our total liability for any claim arising from your use of the service is limited to the amount you paid us in the <strong>12 months</strong> preceding the claim. This is a standard industry cap &mdash; we mention it because some competitors cap liability at $1,000 total regardless of what you pay them.</li>
            <li>We are not liable for indirect, incidental, special, consequential, or punitive damages, including lost profits, lost data (beyond our obligation to maintain backups as described), or business interruption.</li>
        </ul>
        <p>These limitations apply to the fullest extent permitted by applicable law. Some jurisdictions don't allow certain limitations, so some of these may not apply to you.</p>

        <h2>14. Indemnification</h2>
        <p>You agree to indemnify and hold harmless Joinery, Inc. from claims, damages, and expenses (including reasonable legal fees) arising from your use of the service, your content, your violation of these terms, or your violation of any third-party rights. This is standard &mdash; it means if someone sues us because of something you did with your account, that's your responsibility to resolve.</p>

        <h2>15. Dispute resolution</h2>
        <p>These terms are governed by the laws of the State of Delaware, without regard to conflict of law principles.</p>
        <p>If a dispute arises, we'd prefer to resolve it by talking. Contact us at <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a> and we'll work on it. If we can't resolve it informally within 30 days, either party may pursue resolution through the courts of Delaware.</p>
        <p>We do not require mandatory arbitration. We do not include a class action waiver. You retain your full legal rights.</p>

        <h2>16. Changes to these terms</h2>
        <p>We may update these terms as the service evolves. When we make material changes:</p>
        <ul>
            <li>We will notify active customers by email at least <strong>30 days</strong> before changes take effect.</li>
            <li>We will clearly describe what changed and why.</li>
            <li>If you disagree with the changes, you can cancel your account and export your data before the new terms take effect.</li>
        </ul>
        <p>We will not retroactively change terms that reduce your rights without notice. Continued use of the service after changes take effect constitutes acceptance.</p>

        <h2>17. Contact</h2>
        <p>Questions about these terms:</p>
        <ul class="contact-list">
            <li><strong>Email:</strong> <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a></li>
        </ul>

        <p style="margin-top: 2rem; color: var(--text-muted); font-size: 0.9rem;">Joinery, Inc. is a Delaware corporation.</p>

    </div>
</section>
HTML);

// ---------------------------------------------------------------------------
// gj-privacy (privacy.php)
// ---------------------------------------------------------------------------

seed('gj-privacy', 'Privacy Policy', <<<'HTML'
<section class="section">
    <div class="container legal-content">

        <h1>Privacy Policy</h1>
        <p class="legal-updated">Last updated: April 2026</p>

        <div class="legal-summary">
            <h2>The short version</h2>
            <ul>
                <li><strong>We don't sell your data.</strong> Not to advertisers, not to data brokers, not to anyone. Ever.</li>
                <li><strong>We don't run ads or ad-tracking.</strong> No third-party advertising networks. No cross-site tracking. No behavioral profiling.</li>
                <li><strong>Your members' data is yours.</strong> We process it only to operate the service. We don't contact your members, mine their data, or use it for our own marketing.</li>
                <li><strong>Payment data stays with the payment processor.</strong> We store only reference IDs from Stripe and PayPal. Credit card numbers never touch our servers.</li>
                <li><strong>You can leave anytime and take everything.</strong> Full data export, no lock-in, no penalties.</li>
                <li><strong>Self-hosted means self-hosted.</strong> If you run Joinery on your own server, your data never passes through ours.</li>
            </ul>
        </div>

        <p>This policy explains how Joinery (operated by Joinery, Inc., a Delaware corporation) collects, uses, and protects data. It covers three groups of people:</p>
        <ol>
            <li><strong>Customers</strong> &mdash; organizations and individuals who sign up for a Joinery account</li>
            <li><strong>Members</strong> &mdash; the people whose data customers store in Joinery</li>
            <li><strong>Visitors</strong> &mdash; people browsing getjoinery.com</li>
        </ol>

        <h2>1. What we collect and why</h2>

        <h3>From customers (you, the account holder)</h3>
        <ul>
            <li><strong>Account information</strong> &mdash; name, email address, organization name. We need this to create and manage your account.</li>
            <li><strong>Billing information</strong> &mdash; payment is processed entirely by Stripe or PayPal. We store only their reference IDs (customer ID, subscription ID, transaction ID). We never receive, transmit, or store credit card numbers, CVVs, or bank account details.</li>
            <li><strong>Support communications</strong> &mdash; emails or messages you send us. We keep these to provide support and improve the product.</li>
        </ul>

        <h3>From your members</h3>
        <p>When you use hosted Joinery, the member data you store &mdash; names, emails, event registrations, payment history, custom fields, and anything else you collect &mdash; is processed on our servers. We act as a <strong>data processor</strong> on your behalf. You are the <strong>data controller</strong> and determine what data to collect from your members and how to use it.</p>
        <p>We access member data only to operate the service (storing it, displaying it to you, running features you've enabled). We do not use member data for our own marketing, analytics, advertising, or any other purpose.</p>

        <h3>From visitors to getjoinery.com</h3>
        <ul>
            <li><strong>Server logs</strong> &mdash; IP address, browser type, pages visited, referring URL, and timestamp. This is standard web server operation. Logs are used for security monitoring and are not shared with third parties.</li>
            <li><strong>Cookies</strong> &mdash; we use session cookies for authentication (keeping you logged in). We do not use third-party advertising cookies or cross-site tracking cookies.</li>
        </ul>

        <h2>2. How we use your data</h2>
        <ul>
            <li><strong>To operate the service</strong> &mdash; storing your data, running features, processing payments through Stripe/PayPal, sending emails through your Mailgun account.</li>
            <li><strong>To improve the platform</strong> &mdash; we may analyze aggregate, non-identifying usage patterns (which features are used, page load times, error rates) to improve Joinery. This is never used to identify, profile, or target individual users.</li>
            <li><strong>To communicate with you</strong> &mdash; account notifications, service updates, and responses to your support requests. We don't send marketing email unless you opt in.</li>
            <li><strong>To maintain security</strong> &mdash; monitoring for abuse, unauthorized access, and system integrity.</li>
        </ul>

        <h2>3. What we never do</h2>
        <p>These aren't aspirational. They are commitments.</p>
        <ul>
            <li>We do not sell personal data to anyone, for any reason.</li>
            <li>We do not share data with advertising networks.</li>
            <li>We do not run behavioral advertising or retargeting.</li>
            <li>We do not build profiles of your members for our own use.</li>
            <li>We do not use cross-site or cross-device tracking.</li>
            <li>We do not contact your members directly for any marketing purpose.</li>
            <li>We do not monetize your data. Our revenue comes from subscriptions, services, and referral partnerships &mdash; never from your data.</li>
            <li>We do not voluntarily disclose your data to law enforcement or government agencies. We comply with valid legal process (subpoenas, court orders, warrants) as required by law, but we do not volunteer information beyond what is legally compelled. If permitted by law, we will notify you before disclosing your data in response to legal process.</li>
        </ul>

        <h2>4. Third-party services and integrations</h2>
        <p>Joinery follows a <strong>bring-your-own-keys</strong> model for third-party integrations. Rather than routing your data through our accounts with these services, you connect your own accounts directly. This means your data flows between your Joinery instance and your service provider &mdash; we don't aggregate it, and we don't have access to your third-party accounts.</p>

        <p>Available integrations include:</p>
        <ul>
            <li><strong>Payment processing</strong> &mdash; Stripe and PayPal, using your own merchant accounts. Payment data (card numbers, bank details) is handled entirely by your payment processor. Joinery stores only reference IDs.</li>
            <li><strong>Email delivery</strong> &mdash; Mailgun (API) or any SMTP provider, using your own account credentials. Email content and recipient addresses pass through your email provider for delivery.</li>
            <li><strong>Mailing list sync</strong> &mdash; Mailchimp, using your own API key. Syncs subscriber data between Joinery and your Mailchimp account.</li>
            <li><strong>Bot protection</strong> &mdash; hCaptcha or Google reCAPTCHA, using your own site keys. Protects forms from automated submissions.</li>
            <li><strong>Scheduling</strong> &mdash; Acuity Scheduling and Calendly, using your own API credentials. Manages appointment booking and calendar integration.</li>
        </ul>

        <p>Each integration is optional and activated only when you provide your own API keys. Your relationship with each service is governed by that service's own terms and privacy policy. We do not receive commissions, referral fees, or data from these services.</p>

        <p><strong>For hosted Joinery</strong>, our servers are hosted in the United States. Your data is stored on infrastructure we manage directly.</p>

        <p>We do not use third-party analytics services (like Google Analytics) that track individual users across sites. Any analytics we run are first-party and aggregate.</p>

        <h2>5. Cookies and tracking</h2>
        <p>We use cookies only for essential functionality:</p>
        <ul>
            <li><strong>Session cookies</strong> &mdash; these keep you logged in. They are HttpOnly (not accessible to JavaScript), set with SameSite=Lax (no cross-site request abuse), and marked Secure (HTTPS only). They expire when your session ends or after a reasonable inactivity period.</li>
            <li><strong>CSRF tokens</strong> &mdash; these prevent cross-site request forgery attacks on forms. They are a security measure, not a tracking mechanism.</li>
        </ul>
        <p>We do not use persistent tracking cookies, third-party cookies, pixel trackers, fingerprinting, or any other mechanism designed to follow you across websites.</p>

        <h2>6. Your members' data</h2>
        <p>This is important enough to say directly: <strong>your members' data belongs to you and your members, not to us.</strong></p>
        <ul>
            <li>We process member data solely to provide the service you're paying for.</li>
            <li>We do not access member accounts or data unless you request it for support purposes.</li>
            <li>We do not use member data for our own analytics, marketing, machine learning, or product development.</li>
            <li>We do not share member data with any third party except as necessary to operate the service (Stripe for payments, Mailgun for email delivery via your own account).</li>
            <li>Your members can contact you to exercise their data rights. As the data controller, you decide how to respond. We provide the tools (data export, deletion) to help you comply.</li>
        </ul>

        <h2>7. Self-hosted instances</h2>
        <p>If you run Joinery on your own server (under the PolyForm Noncommercial license or a commercial license), your data stays on your infrastructure. We have no access to it, no telemetry, and no connection to your instance unless you initiate one (for example, checking for updates).</p>
        <p>Self-hosted Joinery does not phone home. We do not collect usage data, crash reports, or any other information from self-hosted installations.</p>
        <p>Your privacy obligations to your own members are your responsibility when self-hosting. We recommend publishing your own privacy policy for your site.</p>

        <h2>8. Data retention and deletion</h2>

        <h3>While your account is active</h3>
        <p>We retain your data for as long as your account is active and you're using the service. You can export your data at any time through the admin interface or API.</p>

        <h3>When you cancel</h3>
        <p>When you cancel your account:</p>
        <ul>
            <li>Your data remains available for <strong>30 days</strong> after cancellation so you can export anything you need or reactivate if you change your mind.</li>
            <li>After 30 days, we delete your data from our active systems &mdash; your organization data, your members' data, uploaded files, and configuration.</li>
            <li>Backups containing your data are purged within <strong>60 days</strong> of deletion from active systems.</li>
            <li>If you want your data deleted immediately without the 30-day grace period, contact us and we'll process it within 14 days.</li>
        </ul>

        <h3>What we may retain</h3>
        <p>After deletion, we may retain:</p>
        <ul>
            <li>Basic account records (organization name, account holder email, billing history) as required for tax, legal, and accounting obligations.</li>
            <li>Aggregate, non-identifying usage statistics that cannot be traced back to you or your members.</li>
        </ul>
        <p>We do not retain member data after your account is deleted.</p>

        <h2>9. Security</h2>
        <p>We protect your data with:</p>
        <ul>
            <li>All database queries use parameterized prepared statements (no SQL injection paths).</li>
            <li>All user-generated content is escaped on output (XSS prevention).</li>
            <li>Passwords are hashed with Argon2id.</li>
            <li>Sessions use HttpOnly, SameSite, and Secure cookie flags.</li>
            <li>HTTPS is enforced for all connections.</li>
            <li>The source code is available for inspection &mdash; you don't have to trust a black box.</li>
        </ul>
        <p>No system is perfectly secure. If we discover a data breach affecting your account, we will notify you promptly with details of what happened and what we're doing about it.</p>

        <h2>10. Your rights</h2>
        <p>Depending on where you are, you may have specific legal rights regarding your personal data:</p>

        <h3>All customers</h3>
        <ul>
            <li><strong>Access</strong> &mdash; you can view and export all your data at any time through the admin interface or API.</li>
            <li><strong>Correction</strong> &mdash; you can update your information at any time.</li>
            <li><strong>Deletion</strong> &mdash; you can request deletion of your account and data. See "Data retention and deletion" above.</li>
            <li><strong>Portability</strong> &mdash; you can export your data in standard formats. We do not charge for data export.</li>
        </ul>

        <h3>European Economic Area (GDPR)</h3>
        <p>If you are in the EEA, you have additional rights under the General Data Protection Regulation, including the right to restrict processing, object to processing, and lodge a complaint with your local data protection authority. Our legal basis for processing is contractual necessity (we need your data to provide the service you signed up for) and legitimate interest (security monitoring, platform improvement).</p>

        <h3>California (CCPA/CPRA)</h3>
        <p>If you are a California resident: we do not sell your personal information. We do not share your personal information for cross-context behavioral advertising. You have the right to know what data we collect, request deletion, and opt out of sale &mdash; though there is nothing to opt out of, because we don't sell.</p>

        <h2>11. Children's privacy</h2>
        <p>Joinery is not directed at children under 16. We do not knowingly collect personal data from children. If you believe a child has provided us with personal data, contact us and we will delete it.</p>

        <h2>12. Changes to this policy</h2>
        <p>We may update this policy to reflect changes in our practices or legal requirements. When we make material changes, we will notify active customers by email before the changes take effect. We will not reduce your privacy protections without giving you notice and the opportunity to export your data and leave.</p>

        <h2>13. Contact</h2>
        <p>Questions about this policy or your data:</p>
        <ul class="contact-list">
            <li><strong>Email:</strong> <a href="mailto:privacy@getjoinery.com">privacy@getjoinery.com</a></li>
            <li><strong>General:</strong> <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a></li>
        </ul>

        <p style="margin-top: 2rem; color: var(--text-muted); font-size: 0.9rem;">Joinery, Inc. is a Delaware corporation.</p>

    </div>
</section>
HTML);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\nDone. Created: $created  Skipped: $skipped\n";
