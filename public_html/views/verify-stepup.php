<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('verify_stepup_logic.php', 'logic'));

    $page_vars = process_logic(verify_stepup_logic(array_merge($_GET, $_POST, $params ?? [])));
    extract($page_vars);

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page ?? false,
        'title'         => 'Confirm it\'s you',
        'header_only'   => true,
    ]);

    $return_qs = '?return=' . rawurlencode($return);
?>

<div class="jy-ui">
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <a href="/"><?php $page->get_logo(); ?></a>
        </div>

        <h3>Confirm it's you</h3>
        <p>This is a sensitive change, so please re-confirm your identity with your second factor.</p>

        <?php if (!empty($error)) { echo PublicPage::alert('Verification failed', htmlspecialchars($error), 'danger'); } ?>

        <?php if (!empty($has_passkey)) { ?>
        <div class="jy-form-actions">
            <button type="button" id="stepup-passkey-btn" class="btn btn-primary">Confirm with a passkey</button>
        </div>
        <div id="stepup-passkey-error" class="jy-auth-hint" role="alert"></div>
        <?php } ?>

        <?php if (!empty($has_totp)) { ?>
        <?php if (!empty($has_passkey)) { echo '<div class="auth-divider">or</div>'; } ?>
        <?php
        $formwriter = $page->getFormWriter('form1', ['action' => '/verify-stepup' . $return_qs, 'method' => 'POST']);
        $formwriter->begin_form();
        $formwriter->hiddeninput('return', '', ['value' => $return]);
        $formwriter->textinput('totp_code', 'Authenticator code', [
            'required'     => true,
            'autocomplete' => 'one-time-code',
            'inputmode'    => 'text',
            'autofocus'    => empty($has_passkey),
            'helptext'     => 'A 6-digit code from your authenticator app, or an 8-character backup code.',
        ]);
        ?>
        <div class="jy-form-actions">
            <?php $formwriter->submitbutton('stepup-form-submit', 'Confirm', ['class' => 'btn btn-primary']); ?>
        </div>
        <?php $formwriter->end_form(); ?>
        <?php } ?>

        <div class="auth-links">
            <a href="<?php echo htmlspecialchars($return); ?>">Cancel</a>
        </div>

    </div>
</div>
</div>

<?php if (!empty($has_passkey)) { ?>
<script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script>
(function () {
    var btn = document.getElementById('stepup-passkey-btn');
    if (!btn) return;
    var errEl = document.getElementById('stepup-passkey-error');
    var RETURN = <?php echo json_encode($return); ?>;
    function csrf() {
        var m = document.querySelector('meta[name="joinery-api-csrf"]');
        return m ? m.content : '';
    }
    function apiV1(action, payload) {
        return fetch('/api/v1/action/' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf() },
            body: JSON.stringify(payload || {})
        }).then(function (r) { return r.json(); });
    }
    btn.addEventListener('click', async function () {
        errEl.textContent = '';
        btn.disabled = true;
        try {
            var opt = await apiV1('passkey_stepup_options', {});
            if (!opt || !opt.data || !opt.data.options) {
                throw new Error((opt && (opt.message || opt.error)) || 'Could not start confirmation.');
            }
            var credential = (await JoineryPasskeys.derive(opt.data.options)).response;
            var res = await apiV1('passkey_stepup_verify', { credential: credential });
            if (res && (res.error || res.success === false)) {
                throw new Error(res.message || 'Confirmation failed.');
            }
            // The passkey step-up stamped the shared marker server-side — return.
            window.location = RETURN;
        } catch (e) {
            btn.disabled = false;
            errEl.textContent = e.message || 'Could not confirm with your passkey.';
        }
    });
})();
</script>
<?php } ?>
