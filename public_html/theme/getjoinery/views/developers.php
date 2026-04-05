<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Developers — Joinery',
    'description' => 'PostgreSQL. PHP 8.x. REST API. Plugin system. Theme engine. No magic, no lock-in, no nonsense.',
    'showheader' => true,
]);
?>

<!-- Hero -->
<section class="hero">
    <h1>Built by a developer, for developers</h1>
    <p>PostgreSQL. PHP 8.x. REST API. Plugin system. Theme engine. No magic, no lock-in, no nonsense.</p>
    <div class="btn-group btn-group-center">
        <a href="https://github.com/getjoinery/joinery" class="btn btn-primary" target="_blank">View on GitHub</a>
        <a href="#api" class="btn btn-secondary">API Docs</a>
    </div>
</section>

<!-- Architecture Overview -->
<section class="section section-alt">
    <div class="container">
        <div class="section-label">The Stack</div>
        <h2 class="section-title">Architecture overview</h2>
        <p class="section-subtitle">A clean, well-structured PHP application. No framework magic — just patterns that work.</p>

        <div class="arch-grid">
            <div class="arch-card">
                <h4>Database</h4>
                <p>PostgreSQL with PDO prepared statements everywhere. Active Record pattern for data models. Version-controlled migrations.</p>
            </div>
            <div class="arch-card">
                <h4>Backend</h4>
                <p>PHP 8.x, MVC-like architecture. Front-controller routing through serve.php. Logic layer with LogicResult pattern.</p>
            </div>
            <div class="arch-card">
                <h4>Frontend</h4>
                <p>Your choice — Tailwind, Bootstrap, or zero-dependency HTML5. No jQuery. Modern vanilla JavaScript throughout.</p>
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
                <p>Override chain — theme &rarr; plugin &rarr; base. Customize anything without forking. Component system for reusable sections.</p>
            </div>
        </div>
    </div>
</section>

<!-- REST API -->
<section class="section" id="api">
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

<!-- Plugin System -->
<section class="section section-alt">
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
  ├── plugin.json
  ├─��� data/
  │   └── bookings_class.php
  ├── views/
  │   └── booking.php
  ├── admin/
  │   └── admin_bookings.php
  ├── logic/
  │   └── booking_logic.php
  └── assets/
      └── css/style.css</div>
            </div>
        </div>
    </div>
</section>

<!-- Theme System -->
<section class="section">
    <div class="container">
        <div class="feature-showcase">
            <div class="feature-showcase-content">
                <h3>Theme System</h3>
                <p>Themes control the entire visual presentation. The override chain lets you customize any view, template, or asset without modifying core files.</p>
                <ul>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Override chain: theme &rarr; plugin &rarr; base</li>
                    <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 20+ included themes</li>
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

<!-- Self-Hosting -->
<section class="section section-alt">
    <div class="container">
        <h2 class="section-title">Self-hosting</h2>
        <p class="section-subtitle">Run Joinery on your own infrastructure. Same software, complete control.</p>

        <div class="arch-grid" style="max-width: 800px; margin: 0 auto;">
            <div class="arch-card">
                <h4>Requirements</h4>
                <p>PHP 8.x, PostgreSQL, Apache or Nginx. Standard LAMP/LEMP stack — nothing exotic.</p>
            </div>
            <div class="arch-card">
                <h4>Installation</h4>
                <p>Clone the repo, run the installer, configure your database. Or let us do it with White Glove Install ($249).</p>
            </div>
            <div class="arch-card">
                <h4>Updates</h4>
                <p>Automated upgrade system. Run one command to pull the latest version and apply migrations.</p>
            </div>
        </div>
    </div>
</section>

<!-- Code Quality -->
<section class="section">
    <div class="container">
        <h2 class="section-title">Code you can trust</h2>
        <p class="section-subtitle">Clean architecture, security by default, and no unnecessary dependencies.</p>

        <div class="diff-cards">
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> No jQuery — modern vanilla JavaScript</div>
                <div class="diff-theirs">Lighter pages, fewer dependencies, easier to audit</div>
            </div>
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> PDO prepared statements everywhere</div>
                <div class="diff-theirs">SQL injection protection is not optional — it is structural</div>
            </div>
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Active Record pattern for all data models</div>
                <div class="diff-theirs">Consistent, predictable data access across the codebase</div>
            </div>
            <div class="diff-card">
                <div class="diff-ours"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Automated deployment and upgrade system</div>
                <div class="diff-theirs">One command to publish, one command to upgrade</div>
            </div>
        </div>
    </div>
</section>

<!-- GitHub / Source -->
<?php
echo ComponentRenderer::render(null, 'cta_section', [
    'heading' => 'Explore the source',
    'subheading' => 'Joinery is source-available under the PolyForm Noncommercial license. Read the code, file issues, or contribute.',
    'button_text' => 'View on GitHub',
    'button_url' => 'https://github.com/getjoinery/joinery',
    'secondary_text' => 'Read the Docs',
    'secondary_url' => '/docs',
    'style' => 'dark',
]);

$page->public_footer();
?>
