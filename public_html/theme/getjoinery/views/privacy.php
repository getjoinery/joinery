<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page = new PublicPage();
$page->public_header([
    'title' => 'Privacy Policy — Joinery',
    'description' => 'How Joinery handles your data: what we collect, what we never do, and your rights.',
    'showheader' => true,
]);
?>

<section class="section">
    <div class="container legal-content">

        <h1>Privacy Policy</h1>
        <p class="legal-updated">Last updated: April 2026</p>

        <!-- The short version -->
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

        <!-- 1. What We Collect -->
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

        <!-- 2. How We Use Your Data -->
        <h2>2. How we use your data</h2>
        <ul>
            <li><strong>To operate the service</strong> &mdash; storing your data, running features, processing payments through Stripe/PayPal, sending emails through your Mailgun account.</li>
            <li><strong>To improve the platform</strong> &mdash; we may analyze aggregate, non-identifying usage patterns (which features are used, page load times, error rates) to improve Joinery. This is never used to identify, profile, or target individual users.</li>
            <li><strong>To communicate with you</strong> &mdash; account notifications, service updates, and responses to your support requests. We don't send marketing email unless you opt in.</li>
            <li><strong>To maintain security</strong> &mdash; monitoring for abuse, unauthorized access, and system integrity.</li>
        </ul>

        <!-- 3. What We Never Do -->
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

        <!-- 4. Third-Party Services -->
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

        <!-- 5. Cookies -->
        <h2>5. Cookies and tracking</h2>
        <p>We use cookies only for essential functionality:</p>
        <ul>
            <li><strong>Session cookies</strong> &mdash; these keep you logged in. They are HttpOnly (not accessible to JavaScript), set with SameSite=Lax (no cross-site request abuse), and marked Secure (HTTPS only). They expire when your session ends or after a reasonable inactivity period.</li>
            <li><strong>CSRF tokens</strong> &mdash; these prevent cross-site request forgery attacks on forms. They are a security measure, not a tracking mechanism.</li>
        </ul>
        <p>We do not use persistent tracking cookies, third-party cookies, pixel trackers, fingerprinting, or any other mechanism designed to follow you across websites.</p>

        <!-- 6. Your Members' Data -->
        <h2>6. Your members' data</h2>
        <p>This is important enough to say directly: <strong>your members' data belongs to you and your members, not to us.</strong></p>
        <ul>
            <li>We process member data solely to provide the service you're paying for.</li>
            <li>We do not access member accounts or data unless you request it for support purposes.</li>
            <li>We do not use member data for our own analytics, marketing, machine learning, or product development.</li>
            <li>We do not share member data with any third party except as necessary to operate the service (Stripe for payments, Mailgun for email delivery via your own account).</li>
            <li>Your members can contact you to exercise their data rights. As the data controller, you decide how to respond. We provide the tools (data export, deletion) to help you comply.</li>
        </ul>

        <!-- 7. Self-Hosted Instances -->
        <h2>7. Self-hosted instances</h2>
        <p>If you run Joinery on your own server (under the PolyForm Noncommercial license or a commercial license), your data stays on your infrastructure. We have no access to it, no telemetry, and no connection to your instance unless you initiate one (for example, checking for updates).</p>
        <p>Self-hosted Joinery does not phone home. We do not collect usage data, crash reports, or any other information from self-hosted installations.</p>
        <p>Your privacy obligations to your own members are your responsibility when self-hosting. We recommend publishing your own privacy policy for your site.</p>

        <!-- 8. Data Retention and Deletion -->
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

        <!-- 9. Security -->
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

        <!-- 10. Your Rights -->
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

        <!-- 11. Children -->
        <h2>11. Children's privacy</h2>
        <p>Joinery is not directed at children under 16. We do not knowingly collect personal data from children. If you believe a child has provided us with personal data, contact us and we will delete it.</p>

        <!-- 12. Changes -->
        <h2>12. Changes to this policy</h2>
        <p>We may update this policy to reflect changes in our practices or legal requirements. When we make material changes, we will notify active customers by email before the changes take effect. We will not reduce your privacy protections without giving you notice and the opportunity to export your data and leave.</p>

        <!-- 13. Contact -->
        <h2>13. Contact</h2>
        <p>Questions about this policy or your data:</p>
        <ul class="contact-list">
            <li><strong>Email:</strong> <a href="mailto:privacy@getjoinery.com">privacy@getjoinery.com</a></li>
            <li><strong>General:</strong> <a href="mailto:hello@getjoinery.com">hello@getjoinery.com</a></li>
        </ul>

        <p style="margin-top: 2rem; color: var(--text-muted); font-size: 0.9rem;">Joinery, Inc. is a Delaware corporation.</p>

    </div>
</section>

<?php $page->public_footer(); ?>
