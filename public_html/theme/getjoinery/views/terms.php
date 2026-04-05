<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Terms of Service — Joinery',
    'description' => 'Terms of service for Joinery. Plain language, fair terms, no surprises.',
    'showheader' => true,
]);
?>

<section class="section">
    <div class="container legal-content">

        <h1>Terms of Service</h1>
        <p class="legal-updated">Last updated: April 2026</p>

        <!-- The short version -->
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

        <!-- 1. The Service -->
        <h2>1. The service</h2>
        <p>Joinery is a membership management platform. It provides tools for managing members, events, payments, email, content, and related functions for organizations. We offer Joinery in two ways:</p>
        <ul>
            <li><strong>Hosted Joinery</strong> &mdash; we run the software on our servers, you access it through a web browser. This is a paid subscription.</li>
            <li><strong>Self-hosted Joinery</strong> &mdash; you download the source code and run it on your own server, under either the PolyForm Noncommercial license (free) or a commercial license (paid).</li>
        </ul>
        <p>These terms apply primarily to hosted Joinery. Self-hosted usage is governed by your applicable license (see Section 10).</p>

        <!-- 2. Your Account -->
        <h2>2. Your account</h2>
        <ul>
            <li>You must provide accurate account information.</li>
            <li>You are responsible for maintaining the security of your account credentials.</li>
            <li>You are responsible for all activity under your account, including actions taken by admin users you authorize.</li>
            <li>You must be at least 18 years old (or the age of majority in your jurisdiction) to create an account.</li>
            <li>One organization per account. If you run multiple organizations, each needs its own account.</li>
        </ul>

        <!-- 3. Your Content and Data -->
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

        <!-- 4. Our Content and IP -->
        <h2>4. Joinery's intellectual property</h2>
        <p>The Joinery software, design, documentation, branding, and name are the property of Joinery, Inc. Your subscription gives you the right to use the hosted service. It does not grant you rights to the Joinery source code, trademarks, or brand assets beyond normal use of the service.</p>
        <p>The Joinery source code is available under the PolyForm Noncommercial license for inspection, personal use, and nonprofit use. Commercial self-hosting requires a separate commercial license.</p>

        <!-- 5. Payment and Billing -->
        <h2>5. Payment and billing</h2>
        <ul>
            <li><strong>Pricing</strong> &mdash; current pricing is listed at <a href="/pricing">getjoinery.com/pricing</a>. All prices are in US dollars.</li>
            <li><strong>Billing cycle</strong> &mdash; monthly plans are billed monthly. Annual plans are billed once per year at a discounted rate.</li>
            <li><strong>Payment processing</strong> &mdash; payments are processed by Stripe or PayPal. We do not store credit card numbers or bank account details (see our <a href="/privacy">Privacy Policy</a>).</li>
            <li><strong>Price changes</strong> &mdash; if we change pricing, existing customers keep their current rate until the end of their billing cycle (monthly) or term (annual). We will give at least 30 days' notice before any price increase takes effect on your account.</li>
            <li><strong>No transaction fees</strong> &mdash; Joinery does not charge transaction fees on payments you process through the platform. Standard fees from Stripe or PayPal still apply &mdash; those are between you and your payment processor.</li>
            <li><strong>Failed payments</strong> &mdash; if a payment fails, we'll notify you and give you a reasonable window to resolve it before suspending service.</li>
        </ul>

        <!-- 6. Free Trial -->
        <h2>6. Free trial</h2>
        <p>New accounts include a free trial period. During the trial, you have access to all features. No credit card is required to start a trial. At the end of the trial, you can choose a paid plan or your account will be deactivated. Trial data is retained for 30 days after the trial ends, then deleted.</p>

        <!-- 7. Cancellation -->
        <h2>7. Cancellation</h2>
        <p>You can cancel your subscription at any time. Here's what happens:</p>
        <ul>
            <li><strong>No cancellation fees.</strong> No penalties. No early termination charges. No questions asked.</li>
            <li><strong>Service continues through the paid period.</strong> If you cancel mid-cycle, you keep access until the end of your current billing period.</li>
            <li><strong>Data retention.</strong> After your service ends, we retain your data for 30 days so you can export or reactivate. After 30 days, your data is deleted from active systems. Backups are purged within 60 days after that.</li>
            <li><strong>Immediate deletion.</strong> If you want your data deleted immediately without the 30-day window, email us and we'll process it within 14 days.</li>
        </ul>
        <p>We may also cancel your account if you violate these terms (see Section 8). If we cancel your account for reasons other than abuse, we'll provide at least 30 days' notice and an opportunity to export your data.</p>

        <!-- 8. Acceptable Use -->
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

        <!-- 9. API Usage -->
        <h2>9. API usage</h2>
        <p>The Joinery API is available to all plans. API usage is subject to rate limiting to ensure fair access for all customers. Current rate limits are documented in the API documentation. We reserve the right to adjust rate limits, but will provide notice before reducing them for existing customers.</p>

        <!-- 10. Third-Party Integrations -->
        <h2>10. Third-party integrations</h2>
        <p>Joinery uses a <strong>bring-your-own-keys</strong> model for third-party services. Features like payment processing (Stripe, PayPal), email delivery (Mailgun, SMTP), mailing list sync (Mailchimp), bot protection (hCaptcha, reCAPTCHA), and scheduling (Acuity, Calendly) require you to create your own account with the relevant provider and enter your API keys in Joinery's settings.</p>
        <ul>
            <li>You are responsible for your own accounts with these services, including their terms, pricing, and usage limits.</li>
            <li>Joinery does not charge any markup, commission, or referral fee on third-party services.</li>
            <li>We do not have access to your third-party accounts. If you need support with a third-party service, contact that provider directly.</li>
            <li>If a third-party service changes its terms, pricing, or availability, that is between you and the provider. We will make reasonable efforts to maintain compatibility with supported integrations, but we cannot guarantee uninterrupted operation of services we don't control.</li>
        </ul>

        <!-- 11. Self-Hosting -->
        <h2>11. Self-hosting licenses</h2>

        <h3>PolyForm Noncommercial</h3>
        <p>The Joinery source code is available under the <a href="https://polyformproject.org/licenses/noncommercial/1.0.0/" target="_blank" rel="noopener">PolyForm Noncommercial License 1.0.0</a>. This permits personal, educational, and nonprofit use at no cost. The full license text governs &mdash; these terms don't modify it.</p>

        <h3>Commercial license</h3>
        <p>Commercial organizations that want to self-host Joinery need a commercial license. Commercial licenses are perpetual (pay once, use forever), cover all current features, and have no per-member or per-transaction fees. Terms are agreed individually &mdash; contact <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a> for details.</p>

        <h3>Plugins and themes</h3>
        <p>The Joinery license includes a <strong>plugin and theme exception</strong>. If you build a plugin or theme for Joinery, it is yours &mdash; you may license it under any terms you choose, including commercial terms. The PolyForm Noncommercial license covers Joinery's core code, not your extensions. Plugins and themes that ship as part of the official Joinery distribution remain under the project license.</p>

        <!-- 12. Uptime and Availability -->
        <h2>12. Availability</h2>
        <p>We work to keep hosted Joinery available and reliable, but we don't guarantee 100% uptime. Planned maintenance will be announced in advance when possible. We are not liable for downtime caused by factors outside our control (hosting provider outages, DNS issues, internet disruptions, etc.).</p>

        <!-- 13. Limitation of Liability -->
        <h2>13. Limitation of liability</h2>
        <p>To the maximum extent permitted by law:</p>
        <ul>
            <li>Joinery is provided "as is" without warranties of any kind, express or implied, including warranties of merchantability, fitness for a particular purpose, and non-infringement.</li>
            <li>Our total liability for any claim arising from your use of the service is limited to the amount you paid us in the <strong>12 months</strong> preceding the claim. This is a standard industry cap &mdash; we mention it because some competitors cap liability at $1,000 total regardless of what you pay them.</li>
            <li>We are not liable for indirect, incidental, special, consequential, or punitive damages, including lost profits, lost data (beyond our obligation to maintain backups as described), or business interruption.</li>
        </ul>
        <p>These limitations apply to the fullest extent permitted by applicable law. Some jurisdictions don't allow certain limitations, so some of these may not apply to you.</p>

        <!-- 14. Indemnification -->
        <h2>14. Indemnification</h2>
        <p>You agree to indemnify and hold harmless Joinery, Inc. from claims, damages, and expenses (including reasonable legal fees) arising from your use of the service, your content, your violation of these terms, or your violation of any third-party rights. This is standard &mdash; it means if someone sues us because of something you did with your account, that's your responsibility to resolve.</p>

        <!-- 15. Dispute Resolution -->
        <h2>15. Dispute resolution</h2>
        <p>These terms are governed by the laws of the State of Delaware, without regard to conflict of law principles.</p>
        <p>If a dispute arises, we'd prefer to resolve it by talking. Contact us at <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a> and we'll work on it. If we can't resolve it informally within 30 days, either party may pursue resolution through the courts of Delaware.</p>
        <p>We do not require mandatory arbitration. We do not include a class action waiver. You retain your full legal rights.</p>

        <!-- 16. Changes -->
        <h2>16. Changes to these terms</h2>
        <p>We may update these terms as the service evolves. When we make material changes:</p>
        <ul>
            <li>We will notify active customers by email at least <strong>30 days</strong> before changes take effect.</li>
            <li>We will clearly describe what changed and why.</li>
            <li>If you disagree with the changes, you can cancel your account and export your data before the new terms take effect.</li>
        </ul>
        <p>We will not retroactively change terms that reduce your rights without notice. Continued use of the service after changes take effect constitutes acceptance.</p>

        <!-- 17. Contact -->
        <h2>17. Contact</h2>
        <p>Questions about these terms:</p>
        <ul class="contact-list">
            <li><strong>Email:</strong> <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a></li>
        </ul>

        <p style="margin-top: 2rem; color: var(--text-muted); font-size: 0.9rem;">Joinery, Inc. is a Delaware corporation.</p>

    </div>
</section>

<?php $page->public_footer(); ?>
