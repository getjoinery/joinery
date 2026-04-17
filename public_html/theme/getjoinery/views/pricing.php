<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Pricing — Joinery',
    'description' => 'Simple, honest pricing. All features included on every plan. No transaction fees. Self-hosting is free.',
    'showheader' => true,
]);

$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
$dash_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:0.3"><line x1="5" y1="12" x2="19" y2="12"/></svg>';
?>

<!-- Hero -->
<section class="hero" style="padding-bottom: 2rem;">
    <h1>Simple, honest pricing</h1>
    <p>All features included on every plan. No transaction fees. No surprises.</p>
</section>

<!-- Billing Toggle -->
<div class="pricing-toggle">
    <span id="label-monthly">Monthly</span>
    <div class="toggle-switch active" id="billing-toggle" role="button" tabindex="0" aria-label="Toggle annual/monthly pricing"></div>
    <span id="label-annual" class="active">Annual <span class="badge badge-accent">Save ~25%</span></span>
</div>

<!-- Pricing Tiers -->
<section class="section" style="padding-top: 0;">
    <div class="container">
        <div class="pricing-grid">

            <!-- Starter -->
            <div class="pricing-tier">
                <div class="tier-name">Starter</div>
                <div class="monthly-price" data-show="annual">$39/mo</div>
                <div class="price" data-annual="$29" data-monthly="$39"><span data-annual="$29" data-monthly="$39">$29</span><span>/mo</span></div>
                <div class="price-note" data-annual="$348 billed annually" data-monthly="Billed monthly">$348 billed annually</div>
                <ul>
                    <li><?= $check_svg ?> Up to 250 members</li>
                    <li><?= $check_svg ?> 2 admin users</li>
                    <li><?= $check_svg ?> Unlimited events</li>
                    <li><?= $check_svg ?> Unlimited products</li>
                    <li><?= $check_svg ?> Email via your Mailgun account</li>
                    <li><?= $check_svg ?> 2 GB storage</li>
                    <li><?= $check_svg ?> All themes & plugins</li>
                    <li><?= $check_svg ?> Full API access</li>
                    <li><?= $check_svg ?> 0% transaction fees</li>
                    <li><?= $check_svg ?> Custom domain</li>
                    <li><?= $dash_svg ?> Priority support</li>
                </ul>
                <a href="#" class="btn btn-secondary">Start Free Trial</a>
            </div>

            <!-- Organization (featured) -->
            <div class="pricing-tier featured">
                <div class="badge">Most Popular</div>
                <div class="tier-name">Organization</div>
                <div class="monthly-price" data-show="annual">$79/mo</div>
                <div class="price" data-annual="$59" data-monthly="$79"><span data-annual="$59" data-monthly="$79">$59</span><span>/mo</span></div>
                <div class="price-note" data-annual="$708 billed annually" data-monthly="Billed monthly">$708 billed annually</div>
                <ul>
                    <li><?= $check_svg ?> Up to 2,000 members</li>
                    <li><?= $check_svg ?> 5 admin users</li>
                    <li><?= $check_svg ?> Unlimited events</li>
                    <li><?= $check_svg ?> Unlimited products</li>
                    <li><?= $check_svg ?> Email via your Mailgun account</li>
                    <li><?= $check_svg ?> 10 GB storage</li>
                    <li><?= $check_svg ?> All themes & plugins</li>
                    <li><?= $check_svg ?> Full API access</li>
                    <li><?= $check_svg ?> 0% transaction fees</li>
                    <li><?= $check_svg ?> Custom domain</li>
                    <li><?= $check_svg ?> Priority support</li>
                </ul>
                <a href="#" class="btn btn-primary">Start Free Trial</a>
            </div>

            <!-- Network -->
            <div class="pricing-tier">
                <div class="tier-name">Network</div>
                <div class="monthly-price" data-show="annual">$129/mo</div>
                <div class="price" data-annual="$99" data-monthly="$129"><span data-annual="$99" data-monthly="$129">$99</span><span>/mo</span></div>
                <div class="price-note" data-annual="$1,188 billed annually" data-monthly="Billed monthly">$1,188 billed annually</div>
                <ul>
                    <li><?= $check_svg ?> Up to 10,000 members</li>
                    <li><?= $check_svg ?> Unlimited admins</li>
                    <li><?= $check_svg ?> Unlimited events</li>
                    <li><?= $check_svg ?> Unlimited products</li>
                    <li><?= $check_svg ?> Email via your Mailgun account</li>
                    <li><?= $check_svg ?> 50 GB storage</li>
                    <li><?= $check_svg ?> All themes & plugins</li>
                    <li><?= $check_svg ?> Full API access</li>
                    <li><?= $check_svg ?> 0% transaction fees</li>
                    <li><?= $check_svg ?> Custom domain</li>
                    <li><?= $check_svg ?> Priority support</li>
                </ul>
                <a href="#" class="btn btn-secondary">Start Free Trial</a>
            </div>

        </div>
    </div>
</section>

<!-- Self-Hosted Section -->
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
                    <li><?= $check_svg ?> Full source code access</li>
                    <li><?= $check_svg ?> Same features as hosted</li>
                    <li><?= $check_svg ?> Community support via GitHub</li>
                    <li><?= $check_svg ?> You manage the infrastructure</li>
                </ul>
                <p style="margin-top: 1rem;">
                    <a href="https://github.com/getjoinery/joinery" class="link-arrow" target="_blank">View on GitHub &rarr;</a>
                </p>
            </div>

            <div class="self-host-card" style="border-color: var(--accent);">
                <h3>White Glove Install</h3>
                <div class="price-tag">$249 <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted);">one-time</span></div>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">We provision and configure everything for you. Automated setup via Linode — your server, your control.</p>
                <ul>
                    <li><?= $check_svg ?> Automated server provisioning</li>
                    <li><?= $check_svg ?> Full Joinery installation</li>
                    <li><?= $check_svg ?> DNS and SSL configuration</li>
                    <li><?= $check_svg ?> Your domain, your server</li>
                    <li><?= $check_svg ?> Community support via GitHub</li>
                    <li><?= $check_svg ?> No recurring charges</li>
                </ul>
                <p style="margin-top: 1rem;">
                    <a href="#" class="btn btn-primary btn-sm">Get Started</a>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Commercial License -->
<section class="section">
    <div class="container">
        <div class="commercial-license-card">
            <div class="commercial-license-content">
                <div class="section-label">For Businesses</div>
                <h2>Commercial Self-Hosting License</h2>
                <p>Running Joinery for a commercial organization? The PolyForm Noncommercial license covers personal, educational, and nonprofit use. For everything else, a commercial license gives you full rights to run Joinery on your own infrastructure — with no recurring fees.</p>

                <div class="commercial-license-features">
                    <div class="commercial-feature-col">
                        <ul>
                            <li><?= $check_svg ?> Perpetual license — pay once, use forever</li>
                            <li><?= $check_svg ?> All current features and themes included</li>
                            <li><?= $check_svg ?> Run on your own servers with full control</li>
                        </ul>
                    </div>
                    <div class="commercial-feature-col">
                        <ul>
                            <li><?= $check_svg ?> No per-member or per-transaction fees</li>
                            <li><?= $check_svg ?> Optional annual updates subscription</li>
                            <li><?= $check_svg ?> Priority support available as add-on</li>
                        </ul>
                    </div>
                </div>

                <p class="commercial-pitch">Most membership platforms charge $50–200/month and take a cut of every transaction. A Joinery commercial license pays for itself in months — and then it's free forever.</p>

                <div class="btn-group" style="margin-top: 1.5rem;">
                    <a href="mailto:hello@getjoinery.com?subject=Commercial%20License%20Inquiry" class="btn btn-primary">Get a Quote</a>
                    <a href="/philosophy" class="btn btn-secondary">Why We Price This Way</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Bottom CTA -->
<?php
echo ComponentRenderer::render(null, 'cta_section', [
    'heading' => 'Try it free for 14 days',
    'subheading' => 'No credit card required. All features included. Cancel anytime.',
    'button_text' => 'Start Free Trial',
    'button_url' => '#',
    'secondary_text' => 'Talk to Us',
    'secondary_url' => 'mailto:hello@getjoinery.com',
    'style' => 'dark',
]);

$page->public_footer();
?>
