<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'About — Joinery',
    'description' => 'About the Joinery project, its creator, and the philosophy behind building membership software differently.',
    'showheader' => true,
]);
?>

<!-- Hero -->
<section class="hero">
    <h1>About Joinery</h1>
    <p>An independent, source-available membership platform built to last.</p>
</section>

<!-- About the Project -->
<section class="section">
    <div class="container" style="max-width: 800px;">
        <div class="about-section">
            <h2>The project</h2>
            <p>Joinery is a full-featured membership and event management platform. It handles everything a small-to-medium organization needs: member management, event registration, payment processing, email communications, e-commerce, and more.</p>
            <p>What makes it different is the approach. Joinery is source-available. You can read every line of code. You can self-host it on your own infrastructure. You can export all your data at any time. There is no vendor lock-in, no data harvesting, and no transaction fees.</p>
            <p>The platform includes a theme system with multiple CSS framework options, a plugin system for extensibility, and a full REST API. It runs on PHP and PostgreSQL — a proven, well-understood stack.</p>
        </div>
    </div>
</section>

<!-- About the Creator -->
<section class="section section-alt">
    <div class="container" style="max-width: 800px;">
        <div class="about-grid">
            <div>
                <h2>The creator</h2>
                <p>Joinery is built and maintained by Jeremy Tunnell — a software developer who believes membership organizations deserve better tools than what the SaaS industry currently offers.</p>
                <p>Being a solo developer is a feature, not a bug. It means opinionated design, fast iteration, and no design-by-committee. Every feature exists because it solves a real problem, not because a product manager needed to justify their headcount.</p>
                <p>The goal is to build something sustainable and lasting — software that organizations can rely on for years, not a startup looking for an exit.</p>
            </div>
            <div style="background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 250px;">
                <span style="font-family: var(--font-heading); color: var(--text-muted); opacity: 0.5; font-size: 0.85rem;">Photo coming soon</span>
            </div>
        </div>
    </div>
</section>

<!-- Why Solo -->
<section class="section">
    <div class="container" style="max-width: 800px;">
        <div class="about-section">
            <h2>Why a solo developer?</h2>
            <p>Most software companies raise venture capital, hire fast, and optimize for growth metrics. This creates misaligned incentives — the company needs to maximize revenue, which means maximizing what they extract from customers.</p>
            <p>Joinery has no investors to satisfy. No board of directors. No growth targets. This means every decision can be made in the interest of the people who actually use the software.</p>
            <p>It also means the codebase stays coherent. One developer means one vision, one architectural style, and zero "we inherited this from the team that left." Every line of code has a clear owner and a clear purpose.</p>
        </div>
    </div>
</section>

<!-- Contact -->
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

<!-- CTA -->
<?php
echo ComponentRenderer::render(null, 'cta_section', [
    'heading' => 'Ready to get started?',
    'subheading' => 'Try Joinery free for 14 days. No credit card required.',
    'button_text' => 'Start Free Trial',
    'button_url' => '#',
    'secondary_text' => 'See Pricing',
    'secondary_url' => '/pricing',
    'style' => 'dark',
]);

$page->public_footer();
?>
