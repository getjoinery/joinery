<?php
/**
 * admin_oauth_providers - Enter OAuth app credentials once per provider.
 *
 * Permission 10. Every provider in the registry renders from its own
 * configFields() declaration, so a new provider appears here with no edit to
 * this file. The fields themselves are drawn by SettingsFieldRenderer, since
 * they are declared settings; this page decides which ones to show, in what
 * order, under which heading, and with which registration guide beside them.
 * Client ids are plain settings; client secrets are written through SecretBox
 * and never rendered back. The read-only Redirect URI is the exact string to
 * paste into each provider's console — derived from the same helper
 * exchangeCode() uses, so it matches byte-for-byte.
 *
 * @version 2.1
 * @changelog 2.1 - Fields come from SettingsFieldRenderer. Hand-drawing them stopped the whole page on any deployment with debug on, because these settings are declared in settings.json
 * @changelog 2.0 - Registry-driven fields and per-provider registration guides; the previous hardcoded field list omitted DigitalOcean and DNSimple, leaving them unconfigurable anywhere
 */

    require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('data/settings_class.php'));
    require_once(PathHelper::getIncludePath('adm/logic/admin_oauth_providers_logic.php'));
    require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
    require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
    require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
    require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

    $page_vars = process_logic(admin_oauth_providers_logic(array_merge($_GET, $_POST)));

    $session  = SessionControl::get_instance();
    $settings = Globalvars::get_instance();

    $error_message = $page_vars['error_message'] ?? null;
    $return_url    = $page_vars['return_url'] ?? '';
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

    $providers  = OAuth2ProviderRegistry::all();
    $configured = OAuth2ProviderRegistry::configured();
    ksort($providers);
?>

<p>Enter the OAuth app credentials for each provider once. They are shared across every
feature that uses OAuth (inbound IMAP, social login, outbound send, DNS publishing).
Client secrets are stored encrypted and are never displayed back. Each provider&rsquo;s
field carries a link explaining where to register the app.</p>

<div class="alert alert-info" style="margin-bottom:1.25rem;">
    <strong>Redirect URI</strong> &mdash; paste this exact value into each provider&rsquo;s console.
    It must match byte-for-byte.
    <div style="margin-top:.5rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <code><?php echo htmlspecialchars($redirect_uri); ?></code>
        <button type="button" class="btn btn-sm btn-secondary"
                data-jy-copy="<?php echo htmlspecialchars($redirect_uri); ?>">Copy</button>
    </div>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<?php
    $formwriter = $page->getFormWriter('form1');
    $formwriter->begin_form();

    // Whoever sent the admin here is waiting for one of these credentials; saving
    // hands them back rather than ending the errand on this page.
    if ($return_url !== '') {
        $formwriter->hiddeninput('return', '', array('value' => $return_url));
    }

    // One block per provider, from the registry. The registration guide hangs off
    // the provider's first field, so "how do I get this?" is next to the thing it
    // answers rather than in a doc the admin has to go and find.
    $first_provider = true;
    foreach ($providers as $key => $provider_class) {
        if (!$first_provider) {
            echo '<hr>';
        }
        $first_provider = false;

        // Anchored by key so a feature that needs this provider can link straight
        // at it (e.g. an unconnectable Gmail feed on the mailbox Accounts page).
        echo '<h3 id="oauth-' . htmlspecialchars($key) . '">'
            . htmlspecialchars($provider_class::getLabel()) . ' ' . (isset($configured[$key])
            ? '<span style="font-size:.7em;color:#2e7d32;">(configured)</span>'
            : '<span style="font-size:.7em;color:#b71c1c;">(not configured)</span>') . '</h3>';

        $guide = $provider_class::configGuide();
        foreach ($provider_class::configFields() as $setting => $spec) {
            $label   = $spec['label'] ?? $setting;
            $options = [];
            if (!empty($spec['help'])) {
                // Added to whatever the manifest says rather than replacing it:
                // the declaration owns the wording, the provider adds its own note.
                $options['helptext_append'] = $spec['help'];
            }
            if ($guide !== null) {
                $options['help_modal'] = $guide;
                $guide = null;
            }
            // These are declared settings, so the field belongs to the manifest
            // and is drawn by the one renderer that knows how — the page supplies
            // only the context around it. A provider whose settings are declared
            // nowhere still has to be configurable, so it falls back to a
            // hand-drawn field, which the manifest rule permits.
            $declaration = SettingsDeclarations::get($setting);
            $is_secret = !empty($declaration['secret']) || !empty($spec['secret']);
            if ($is_secret) {
                // Credentials here are written by OAuth2ProviderConfig, not
                // SettingsWriter, so the renderer's Clear box would do nothing.
                // (Only a secret field has one; offering the option elsewhere is
                // a field option nothing reads, which FormWriter refuses.)
                $options['clearable'] = false;
            }
            if ($declaration !== null) {
                SettingsFieldRenderer::renderGroup($formwriter, $declaration['_group'], [
                    'only'          => [$setting],
                    'field_options' => [$setting => $options],
                ]);
                continue;
            }

            unset($options['helptext_append']);
            if (!empty($spec['help'])) {
                $options['helptext'] = $spec['help'];
            }
            if ($is_secret) {
                SettingsFieldRenderer::secretField($formwriter, $setting, $label,
                    $settings->get_setting($setting, false, true), $options);
                continue;
            }
            $formwriter->textinput($setting, $label, array_merge([
                'value' => $settings->get_setting($setting, false, true),
            ], $options));
        }
    }

    $formwriter->submitbutton('submit_button', 'Save');
    $formwriter->end_form();
?>

<?php
    $page->end_box();
    $page->admin_footer();
?>
