<?php
/**
 * admin_oauth_providers - Enter OAuth app credentials once per provider.
 *
 * Permission 10. Client ids and the Microsoft tenant are plain settings; client
 * secrets are written through SecretBox and never rendered back (a "set"
 * affordance is shown instead of the value). The read-only Redirect URI is the
 * exact string to paste into the Google/Azure console — derived from the same
 * helper exchangeCode() uses, so it matches byte-for-byte.
 *
 * @version 1.0
 */

    require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('data/settings_class.php'));
    require_once(PathHelper::getIncludePath('adm/logic/admin_oauth_providers_logic.php'));
    require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
    require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));

    $page_vars = process_logic(admin_oauth_providers_logic(array_merge($_GET, $_POST)));

    $session  = SessionControl::get_instance();
    $settings = Globalvars::get_instance();

    $error_message = $page_vars['error_message'] ?? null;
    $redirect_uri  = OAuth2Client::redirectUri();

    $page = new AdminPage();
    $page->admin_header(array(
        'menu-id'        => NULL,
        'page_title'     => 'OAuth Providers',
        'readable_title' => 'OAuth Providers',
        'breadcrumbs'    => array('OAuth Providers' => ''),
        'session'        => $session,
    ));

    $page->begin_box(array('title' => 'OAuth Providers'));

    // Helper: render a "set / not set" affordance for a stored secret.
    $secret_affordance = function ($setting_name) use ($settings) {
        $stored = (string)$settings->get_setting($setting_name, false, true);
        return $stored !== ''
            ? '•••• set — leave blank to keep'
            : 'Not set';
    };

    $configured = OAuth2ProviderRegistry::configured();
    $google_ok    = isset($configured['google']);
    $microsoft_ok = isset($configured['microsoft']);
?>

<p>Enter the OAuth app credentials for each provider once. They are shared across every
feature that uses OAuth (inbound IMAP, social login, outbound send). Client secrets are
stored encrypted and are never displayed back. See
<a href="/docs/oauth2" target="_blank">docs/oauth2</a> for step-by-step app registration.</p>

<div class="alert alert-info" style="margin-bottom:1.25rem;">
    <strong>Redirect URI</strong> &mdash; paste this exact value into each provider&rsquo;s cloud console
    (Google Cloud / Azure). It must match exactly.
    <div style="margin-top:.5rem;"><code><?php echo htmlspecialchars($redirect_uri); ?></code></div>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<?php
    $formwriter = $page->getFormWriter('form1');
    $formwriter->begin_form();

    // ----- Google -----
    echo '<h3>Google ' . ($google_ok
        ? '<span style="font-size:.7em;color:#2e7d32;">(configured)</span>'
        : '<span style="font-size:.7em;color:#b71c1c;">(not configured)</span>') . '</h3>';

    $formwriter->textinput('oauth_google_client_id', 'Google Client ID', [
        'value' => $settings->get_setting('oauth_google_client_id', false, true),
    ]);
    $formwriter->passwordinput('oauth_google_client_secret_input', 'Google Client Secret', [
        'autocomplete' => 'new-password',
        'helptext'     => $secret_affordance('oauth_google_client_secret'),
    ]);

    echo '<hr>';

    // ----- Microsoft -----
    echo '<h3>Microsoft ' . ($microsoft_ok
        ? '<span style="font-size:.7em;color:#2e7d32;">(configured)</span>'
        : '<span style="font-size:.7em;color:#b71c1c;">(not configured)</span>') . '</h3>';

    $formwriter->textinput('oauth_microsoft_client_id', 'Microsoft Client ID', [
        'value' => $settings->get_setting('oauth_microsoft_client_id', false, true),
    ]);
    $formwriter->passwordinput('oauth_microsoft_client_secret_input', 'Microsoft Client Secret', [
        'autocomplete' => 'new-password',
        'helptext'     => $secret_affordance('oauth_microsoft_client_secret'),
    ]);
    $formwriter->textinput('oauth_microsoft_tenant', 'Microsoft Tenant', [
        'value'    => $settings->get_setting('oauth_microsoft_tenant', false, true) ?: 'common',
        'helptext' => 'common | organizations | consumers | a specific tenant id',
    ]);

    $formwriter->submitbutton('submit_button', 'Save');
    $formwriter->end_form();

    $page->end_box();
    $page->admin_footer();
?>
