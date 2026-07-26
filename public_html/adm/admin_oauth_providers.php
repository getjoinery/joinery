<?php
/**
 * admin_oauth_providers - Enter OAuth app credentials once per provider.
 *
 * Permission 10. Every provider in the registry renders from its own
 * configFields() declaration, so a new provider appears here with no edit to
 * this file. Client ids are plain settings; client secrets are written through
 * SecretBox and never rendered back (a "set" affordance is shown instead of the
 * value). The read-only Redirect URI is the exact string to paste into each
 * provider's console — derived from the same helper exchangeCode() uses, so it
 * matches byte-for-byte.
 *
 * @version 2.0
 * @changelog 2.0 - Registry-driven fields and per-provider registration guides; the previous hardcoded field list omitted DigitalOcean and DNSimple, leaving them unconfigurable anywhere
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

    // Render a client-secret field. When a secret is already stored we show a
    // masked "saved" note with an Edit link and keep the real password input
    // hidden until the admin opts to replace it. A blank submit keeps the stored
    // value (see the logic), so hiding the field is purely cosmetic.
    $secret_field = function ($input_name, $label, $is_set, $options = []) use ($formwriter) {
        if (!$is_set) {
            $formwriter->passwordinput($input_name, $label, array_merge([
                'autocomplete' => 'new-password',
                'helptext'     => 'Not set',
            ], $options));
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
        $formwriter->passwordinput($input_name, $label, array_merge([
            'autocomplete' => 'new-password',
            'helptext'     => 'Enter a new secret to replace the stored one. Leave blank to keep the current one.',
        ], $options));
        echo '</div>';
    };

    // One block per provider, from the registry. The registration guide hangs off
    // the provider's first field, so "how do I get this?" is next to the thing it
    // answers rather than in a doc the admin has to go and find.
    $first_provider = true;
    foreach ($providers as $key => $provider_class) {
        if (!$first_provider) {
            echo '<hr>';
        }
        $first_provider = false;

        echo '<h3>' . htmlspecialchars($provider_class::getLabel()) . ' ' . (isset($configured[$key])
            ? '<span style="font-size:.7em;color:#2e7d32;">(configured)</span>'
            : '<span style="font-size:.7em;color:#b71c1c;">(not configured)</span>') . '</h3>';

        $guide = $provider_class::configGuide();
        foreach ($provider_class::configFields() as $setting => $spec) {
            $label   = $spec['label'] ?? $setting;
            $options = [];
            if (!empty($spec['help'])) {
                $options['helptext'] = $spec['help'];
            }
            if ($guide !== null) {
                $options['help_modal'] = $guide;
                $guide = null;
            }

            if (!empty($spec['secret'])) {
                $is_set = (string)$settings->get_setting($setting, false, true) !== '';
                $secret_field($setting, $label, $is_set, $options);
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
