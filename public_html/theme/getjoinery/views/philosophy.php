<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Philosophy — Joinery',
    'description' => 'Why Joinery exists. Privacy, data ownership, transparency, and building software that respects the people who use it.',
    'showheader' => true,
]);

$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
?>

<!-- Hero -->
<section class="hero">
    <h1>Why Joinery exists</h1>
    <p>Most membership platforms treat your members' data as their asset. We think that's wrong.</p>
</section>

<!-- The Problem -->
<section class="section">
    <div class="container" style="max-width: 700px;">
        <h2 style="text-align: left; margin-bottom: 1.5rem;">The problem</h2>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            When you use a membership platform, your members' data — their names, emails, payment information, activity history — lives on someone else's servers, governed by someone else's terms.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.25rem;">
            Most platforms make it hard to leave. Your data becomes their leverage. Switching costs are the business model. And you can't see what the software is actually doing with your members' information because the code is a black box.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8;">
            This isn't a hypothetical concern. It's the standard operating model of the software industry. And it's not good enough for organizations that are trusted with their members' personal information.
        </p>
    </div>
</section>

<!-- Why I Built Joinery -->
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

<!-- The Commitments -->
<section class="section">
    <div class="container">
        <h2 class="section-title">The commitments</h2>
        <p class="section-subtitle">These are specific, concrete commitments — not vague promises.</p>

        <div class="commitment-list">
            <div class="commitment">
                <h3><?= $check_svg ?> Source available, always.</h3>
                <p>You can read every line of code that touches your data. We will never obscure what the software does. If you have a question about how something works, the answer is in the source.</p>
            </div>
            <div class="commitment">
                <h3><?= $check_svg ?> Self-hostable, always.</h3>
                <p>Every feature we offer as a hosted service, we will make every effort to offer as a self-hostable option. If we can't, we'll explain why publicly.</p>
            </div>
            <div class="commitment">
                <h3><?= $check_svg ?> Your data is yours.</h3>
                <p>Full export. No lock-in. If you leave, you take everything with you. We will never hold your data hostage or make it difficult to switch away.</p>
            </div>
            <div class="commitment">
                <h3><?= $check_svg ?> No data selling. No tracking. No ads.</h3>
                <p>We make money from subscriptions, installation services, support contracts, and referral partnerships with infrastructure providers &mdash; not from your members' data. We don't run analytics on your members' behavior. We don't sell access to your mailing lists. We don't show ads.</p>
            </div>
            <div class="commitment">
                <h3><?= $check_svg ?> Free for personal use.</h3>
                <p>Individuals, students, and nonprofits can self-host Joinery at no cost under the PolyForm Noncommercial license. We believe access to good software shouldn't require a budget.</p>
            </div>
            <div class="commitment">
                <h3><?= $check_svg ?> Commercial licenses available.</h3>
                <p>Businesses that want to self-host can purchase a commercial license. We want to be sustainable, not extractive. Fair pricing for fair use.</p>
            </div>
        </div>
    </div>
</section>

<!-- The Business Model -->
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
            There is no venture capital. There is no pressure to "maximize engagement" or "monetize the user base." There are no growth-at-all-costs metrics driving product decisions.
        </p>
        <p style="color: var(--text-muted); font-size: 1.05rem; line-height: 1.8;">
            We want to be sustainable, not explosive. We want to be around in 20 years, not acquired in 3.
        </p>
    </div>
</section>

<!-- CTA -->
<?php
echo ComponentRenderer::render(null, 'cta_section', [
    'heading' => 'Software that respects you',
    'subheading' => 'Try Joinery free and see what membership software should look like.',
    'button_text' => 'Start Free Trial',
    'button_url' => '#',
    'secondary_text' => 'View Source on GitHub',
    'secondary_url' => 'https://github.com/getjoinery/joinery',
    'style' => 'dark',
]);

$page->public_footer();
?>
