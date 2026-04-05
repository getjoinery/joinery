<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Showcase — Joinery',
    'description' => 'Applications and services built on the Joinery platform. See what you can build.',
    'showheader' => true,
]);
?>

<!-- Hero -->
<section class="hero">
    <h1>Built with Joinery</h1>
    <p>Joinery is an application framework. Membership management is one thing you can build with it &mdash; but it's not the only thing. Here's what's running on the platform today.</p>
</section>

<!-- Showcase Projects -->
<section class="section">
    <div class="container">

        <!-- ScrollDaddy -->
        <div class="showcase-card">
            <div class="showcase-image">
                <a href="https://scrolldaddy.app" target="_blank" rel="noopener">
                    <img src="/theme/getjoinery/assets/img/showcase-scrolldaddy.png" alt="ScrollDaddy — DNS-based web filtering" loading="lazy">
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

<!-- More Coming -->
<section class="section section-alt">
    <div class="container" style="text-align: center; max-width: 700px;">
        <h2 class="section-title">More projects coming</h2>
        <p class="section-subtitle">Joinery's plugin system, theme engine, and data model layer make it possible to build complete web applications &mdash; not just membership sites. If you've built something with Joinery, we'd love to feature it.</p>
        <div class="btn-group btn-group-center" style="margin-top: 1.5rem;">
            <a href="mailto:hello@getjoinery.com?subject=Showcase%20Submission" class="btn btn-secondary">Submit Your Project</a>
        </div>
    </div>
</section>

<!-- CTA -->
<?php
echo ComponentRenderer::render(null, 'cta_section', [
    'heading' => 'Build something with Joinery',
    'subheading' => 'A plugin system, theme engine, data models, REST API, and user management — all ready to go. Start with the framework, build what you want.',
    'button_text' => 'View on GitHub',
    'button_url' => 'https://github.com/getjoinery/joinery',
    'secondary_text' => 'Developer Docs',
    'secondary_url' => '/developers',
    'style' => 'dark',
]);

$page->public_footer();
?>
