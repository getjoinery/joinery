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
 * @version 1.1
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

    // Is a stored secret present? (Drives the masked "saved" affordance below.)
    $google_secret_set    = (string)$settings->get_setting('oauth_google_client_secret', false, true) !== '';
    $microsoft_secret_set = (string)$settings->get_setting('oauth_microsoft_client_secret', false, true) !== '';

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

    // Render a client-secret field. When a secret is already stored we show a
    // masked "saved" note with an Edit link and keep the real password input
    // hidden until the admin opts to replace it. A blank submit keeps the stored
    // value (see the logic), so hiding the field is purely cosmetic.
    $secret_field = function ($input_name, $label, $is_set) use ($formwriter) {
        if (!$is_set) {
            $formwriter->passwordinput($input_name, $label, [
                'autocomplete' => 'new-password',
                'helptext'     => 'Not set',
            ]);
            return;
        }
        echo '<div id="' . htmlspecialchars($input_name) . '_note" class="form-group">';
        echo '<label>' . htmlspecialchars($label) . '</label>';
        echo '<div style="display:flex;align-items:center;gap:.75rem;">';
        echo '<span style="color:#2e7d32;">•••••••• saved</span>';
        echo '<button type="button" class="oauth-secret-edit" data-target="' . htmlspecialchars($input_name) . '"'
           . ' style="background:none;border:none;color:#1565c0;cursor:pointer;padding:0;text-decoration:underline;">Edit</button>';
        echo '</div></div>';
        echo '<div id="' . htmlspecialchars($input_name) . '_wrap" style="display:none;">';
        $formwriter->passwordinput($input_name, $label, [
            'autocomplete' => 'new-password',
            'helptext'     => 'Enter a new secret to replace the stored one. Leave blank to keep the current one.',
        ]);
        echo '</div>';
    };

    // ----- Google -----
    echo '<h3>Google ' . ($google_ok
        ? '<span style="font-size:.7em;color:#2e7d32;">(configured)</span>'
        : '<span style="font-size:.7em;color:#b71c1c;">(not configured)</span>') . '</h3>';

    $formwriter->textinput('oauth_google_client_id', 'Google Client ID', [
        'value' => $settings->get_setting('oauth_google_client_id', false, true),
    ]);
    $secret_field('oauth_google_client_secret_input', 'Google Client Secret', $google_secret_set);

    echo '<hr>';

    // ----- Microsoft -----
    echo '<h3>Microsoft ' . ($microsoft_ok
        ? '<span style="font-size:.7em;color:#2e7d32;">(configured)</span>'
        : '<span style="font-size:.7em;color:#b71c1c;">(not configured)</span>') . '</h3>';

    $formwriter->textinput('oauth_microsoft_client_id', 'Microsoft Client ID', [
        'value' => $settings->get_setting('oauth_microsoft_client_id', false, true),
    ]);
    $secret_field('oauth_microsoft_client_secret_input', 'Microsoft Client Secret', $microsoft_secret_set);
    $formwriter->textinput('oauth_microsoft_tenant', 'Microsoft Tenant', [
        'value'    => $settings->get_setting('oauth_microsoft_tenant', false, true) ?: 'common',
        'helptext' => 'common | organizations | consumers | a specific tenant id',
    ]);

    $formwriter->submitbutton('submit_button', 'Save');
    $formwriter->end_form();
?>

<script>
// Reveal a hidden client-secret input when its "Edit" link is clicked.
document.querySelectorAll('.oauth-secret-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-target');
        var note = document.getElementById(target + '_note');
        var wrap = document.getElementById(target + '_wrap');
        if (note) { note.style.display = 'none'; }
        if (wrap) { wrap.style.display = ''; }
    });
});
</script>

<?php
    $page->end_box();
    $page->admin_footer();
?>
