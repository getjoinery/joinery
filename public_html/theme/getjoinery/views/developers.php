<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Developers — Joinery',
    'description' => 'PostgreSQL. PHP 8.x. REST API. Plugin system. Theme engine. Readable code, no lock-in.',
    'showheader' => true,
]);
?>

<!-- Hero -->
<section class="hero">
    <h1>Built by a developer, for developers</h1>
    <p>PostgreSQL. PHP 8.x. REST API. Plugin system. Theme engine. Readable code, no lock-in.</p>
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
                <p>Override chain — theme &rarr; plugin &rarr; base. Customize anything without forking. Component system for reusable sections.</p>
            </div>
        </div>
    </div>
</section>

<!-- Security -->
<section class="section">
    <div class="container">
        <h2 class="section-title">Security</h2>
        <p class="section-subtitle">Membership platforms hold sensitive data — names, emails, payment info, personal details. Security is not a feature here. It is the baseline.</p>

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
                <h4>Authentication & Permissions</h4>
                <p>Session-based authentication with role-based access control. Permission checks happen at the controller level before any data is loaded or rendered.</p>
            </div>
            <div class="arch-card">
                <h4>CSRF Protection</h4>
                <p>CSRF token generation is built into the FormWriter. Available on every form out of the box — no extra setup required.</p>
            </div>
            <div class="arch-card">
                <h4>Password Hashing</h4>
                <p>Passwords are hashed with Argon2id — the current best practice. Legacy bcrypt hashes are automatically upgraded on next login. No plaintext, no MD5, no SHA.</p>
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
                <p>File uploads are validated by type and size, stored outside the web root where possible, and served through controlled handlers — not direct URLs.</p>
            </div>
        </div>
    </div>
</section>

<!-- REST API -->
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

<!-- Plugin System -->
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

<!-- Self-Hosting -->
<section class="section">
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
                <p>Clone the repo, run the installer, configure your database. Docker supported. Or let us do it with White Glove Install ($249).</p>
            </div>
            <div class="arch-card">
                <h4>Updates</h4>
                <p>Automated upgrade system. Run one command to pull the latest version and apply migrations.</p>
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
