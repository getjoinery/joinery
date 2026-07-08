<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('security_logic.php', 'logic'));

	$page_vars = process_logic(security_logic(array_merge($_GET, $_POST, $params ?? [])));

	$page = new PublicPage();
	$page->public_header([
		'title' => 'Security Settings',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-narrow">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Security Settings</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Security</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::tab_menu($page_vars['tab_menus'] ?? [], 'Security'); ?>

            <div class="jy-panel jy-mt-4">

                <?php
                foreach ($page_vars['display_messages'] ?? [] as $display_message) {
                    if ($display_message->identifier == 'securitybox') {
                        echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
                    }
                }
                ?>

                <h2>Two-Factor Authentication</h2>

                <?php if (!empty($page_vars['just_enabled']) && !empty($page_vars['backup_codes'])): ?>
                    <div class="jy-alert jy-alert-success">
                        <strong>Two-factor authentication is now enabled.</strong>
                        Save the backup codes below — you'll need one if you lose access to your authenticator app. They're shown only once.
                    </div>

                    <h3>Backup codes</h3>
                    <p>Store these somewhere safe. Each can be used once.</p>
                    <pre class="jy-security-codes"><?php
                        foreach ($page_vars['backup_codes'] as $code) {
                            echo htmlspecialchars($code) . "\n";
                        }
                    ?></pre>

                    <p><a href="/profile/security" class="btn btn-primary">Done</a></p>

                <?php elseif (!empty($page_vars['totp_enabled']) && !empty($page_vars['backup_codes'])): ?>
                    <div class="jy-alert jy-alert-success">
                        <strong>New backup codes generated.</strong>
                        Your previous backup codes are no longer valid.
                    </div>

                    <h3>Backup codes</h3>
                    <p>Store these somewhere safe. Each can be used once.</p>
                    <pre class="jy-security-codes"><?php
                        foreach ($page_vars['backup_codes'] as $code) {
                            echo htmlspecialchars($code) . "\n";
                        }
                    ?></pre>

                    <p><a href="/profile/security" class="btn btn-primary">Done</a></p>

                <?php elseif (!empty($page_vars['totp_enabled'])): ?>
                    <p><strong>Status:</strong> Enabled
                    <?php if (!empty($page_vars['totp_enabled_time'])): ?>
                        (since <?php echo htmlspecialchars(LibraryFunctions::convert_time($page_vars['totp_enabled_time'], 'UTC',
                            SessionControl::get_instance()->get_timezone(), 'M j, Y')); ?>)
                    <?php endif; ?>
                    </p>

                    <h3>Backup codes</h3>
                    <p>Generate a fresh set of 10 single-use codes. This invalidates any previous codes.</p>
                    <form action="/profile/security" method="POST" class="jy-inline">
                        <input type="hidden" name="action" value="regenerate_backup_codes">
                        <button type="submit" class="btn btn-secondary">Regenerate Backup Codes</button>
                    </form>

                    <h3 class="jy-mt-4">Disable 2FA</h3>
                    <p>Confirm with a current 6-digit code or an 8-character backup code. Disabling will also invalidate any trusted devices.</p>
                    <form action="/profile/security" method="POST" onsubmit="return confirm('Disable two-factor authentication for your account?');">
                        <input type="hidden" name="action" value="disable">
                        <input type="text" name="confirm_code" placeholder="6-digit or backup code" autocomplete="one-time-code" required>
                        <button type="submit" class="btn btn-danger">Disable 2FA</button>
                    </form>

                    <p class="jy-security-note">
                        <strong>Lost a trusted device?</strong> To revoke trusted-device cookies on other devices,
                        disable and re-enable 2FA — this rotates the device-trust key.
                    </p>

                <?php elseif (!empty($page_vars['setup_in_progress'])): ?>
                    <p>Scan this QR code with your authenticator app
                    (Google Authenticator, Authy, 1Password, etc.):</p>

                    <div class="jy-security-qr">
                        <img src="<?php echo htmlspecialchars($page_vars['qr_uri']); ?>" alt="2FA setup QR code" class="jy-security-qr-img">
                    </div>

                    <p>If you can't scan, enter this key manually:</p>
                    <pre class="jy-security-secret"><?php echo htmlspecialchars($page_vars['secret']); ?></pre>

                    <p>Once added to your app, enter the current 6-digit code to confirm:</p>
                    <form action="/profile/security" method="POST">
                        <input type="hidden" name="action" value="confirm_enable">
                        <input type="text" name="totp_code" placeholder="6-digit code" autocomplete="one-time-code" inputmode="numeric" required autofocus>
                        <button type="submit" class="btn btn-primary">Confirm and Enable</button>
                    </form>

                    <form action="/profile/security" method="POST" class="jy-mt-2">
                        <input type="hidden" name="action" value="cancel_enable">
                        <button type="submit" class="btn btn-secondary">Cancel Setup</button>
                    </form>

                <?php else: ?>
                    <p><strong>Status:</strong> Not enabled</p>
                    <p>Two-factor authentication adds a second step when logging in: a 6-digit code from an authenticator app on your phone. This protects your account even if your password is compromised.</p>

                    <form action="/profile/security" method="POST">
                        <input type="hidden" name="action" value="start_enable">
                        <button type="submit" class="btn btn-primary">Enable Two-Factor Authentication</button>
                    </form>
                <?php endif; ?>

            </div>

            <?php if (!empty($page_vars['has_second_factor'])): $cadence = $page_vars['cadence'] ?? 'every_login'; ?>
            <div class="jy-panel jy-mt-4">
                <h2>When your second factor is asked</h2>
                <form action="/profile/security" method="POST" class="jy-mt-2">
                    <input type="hidden" name="action" value="set_cadence">
                    <label class="jy-block jy-mt-2">
                        <input type="radio" name="cadence" value="every_login" <?php echo $cadence === 'every_login' ? 'checked' : ''; ?>>
                        At every sign-in
                    </label>
                    <label class="jy-block jy-mt-2">
                        <input type="radio" name="cadence" value="sensitive_only" <?php echo $cadence === 'sensitive_only' ? 'checked' : ''; ?>>
                        Only at sensitive actions (password-only sign-in)
                        <small class="jy-auth-hint jy-block">Faster sign-in, but a phished password can then see your Standard mail and mailbox metadata until a sensitive action asks for your factor.</small>
                    </label>
                    <div class="jy-mt-2">
                        <button type="submit" class="btn btn-secondary">Save</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!empty($page_vars['separation_nudge'])): ?>
            <div class="jy-alert jy-alert-warning jy-mt-4">
                One of your passkeys both signs you in and unlocks your vault, so a single stolen device could hold both. Consider keeping them separate — a phone authenticator app for your login second factor, and a laptop or hardware-key passkey for your vault.
            </div>
            <?php endif; ?>

            <div class="jy-panel jy-mt-4">
                <h2>Recovery address</h2>
                <?php if (!empty($page_vars['recovery_email']) && !empty($page_vars['recovery_email_verified'])): ?>
                    <p><strong>Status:</strong> Active — <?php echo htmlspecialchars($page_vars['recovery_email']); ?></p>
                    <p class="jy-auth-hint">Password reset links are also sent here. Anyone who controls this inbox can start a reset of your account session (they still cannot open your sealed mail).</p>
                    <form action="/profile/security" method="POST" onsubmit="return confirm('Remove your recovery address?');">
                        <input type="hidden" name="action" value="remove_recovery_email">
                        <button type="submit" class="btn btn-secondary">Remove Recovery Address</button>
                    </form>
                <?php else: ?>
                    <?php if (!empty($page_vars['recovery_email'])): ?>
                        <p><strong>Status:</strong> Pending confirmation — <?php echo htmlspecialchars($page_vars['recovery_email']); ?></p>
                        <p class="jy-auth-hint">Open the confirmation link we sent to that inbox. Until then it is not yet a reset path.</p>
                    <?php else: ?>
                        <p>An out-of-band address that also receives your password reset links — the way back in if your login email is a mailbox you could be locked out of. Anyone who controls this inbox can start a reset of your account session, so pick one only you reach.</p>
                    <?php endif; ?>
                    <?php
                    $recovery_formwriter = $page->getFormWriter('recovery_form', ['action' => '/profile/security', 'method' => 'POST']);
                    $recovery_formwriter->begin_form();
                    $recovery_formwriter->hiddeninput('action', '', ['value' => 'set_recovery_email']);
                    $recovery_formwriter->textinput('recovery_email', 'Recovery email address', [
                        'type'         => 'email',
                        'required'     => true,
                        'maxlength'    => 64,
                        'autocomplete' => 'email',
                        'placeholder'  => 'you@example.com',
                    ]);
                    $recovery_formwriter->submitbutton('btn_recovery', !empty($page_vars['recovery_email']) ? 'Resend / Change' : 'Add Recovery Address', ['class' => 'btn btn-primary']);
                    $recovery_formwriter->end_form();
                    ?>
                <?php endif; ?>
            </div>

            <?php if ($page_vars['settings']->get_setting('passkeys_enabled')): ?>
            <div class="jy-panel jy-mt-4 d-none" id="passkeys-panel">
                <h2>Passkeys</h2>

                <table class="jy-table jy-w-full d-none" id="passkeys-table">
                    <thead>
                        <tr>
                            <th>Passkey</th>
                            <th>Added</th>
                            <th>Last used</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="passkeys-table-body"></tbody>
                </table>
                <p id="passkeys-empty" class="d-none">No passkeys are enrolled on this account yet.</p>

                <div class="jy-mt-2 d-none" id="passkey-password-row">
                    <label for="passkey-current-password">Confirm your password to add your first passkey</label>
                    <input type="password" id="passkey-current-password" autocomplete="current-password">
                    <button type="button" class="btn btn-primary" id="passkey-password-continue">Continue</button>
                    <button type="button" class="btn btn-secondary" id="passkey-password-cancel">Cancel</button>
                </div>

                <button type="button" class="btn btn-primary jy-mt-2" id="passkey-add-btn">Add a Passkey</button>
            </div>

            <script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
            <script defer>
            document.addEventListener('DOMContentLoaded', function () {
                var panel = document.getElementById('passkeys-panel');
                if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) return;
                panel.classList.remove('d-none');

                var csrf = document.querySelector('meta[name="joinery-api-csrf"]').content;
                var tableBody = document.getElementById('passkeys-table-body');
                var table = document.getElementById('passkeys-table');
                var empty = document.getElementById('passkeys-empty');
                var addBtn = document.getElementById('passkey-add-btn');
                var credentialCount = 0;

                function apiFetch(url, options) {
                    options = options || {};
                    options.headers = Object.assign({ 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf }, options.headers || {});
                    return fetch(url, options).then(async function (res) {
                        var json = await res.json();
                        if (!res.ok) throw new Error(json.error || 'Request failed.');
                        // Sensitive action needs a fresh second-factor step-up (a 2xx
                        // render carrying the flag, not an error): confirm identity, then
                        // return here to retry (specs/mailbox_security_levels.md § 5.5).
                        // Halt the chain with a never-resolving promise — the page is
                        // navigating away, so no caller's catch should fire an alert.
                        if (json.data && json.data.second_factor_required) {
                            window.location = '/verify-stepup?return=' + encodeURIComponent('/profile/security');
                            return new Promise(function () {});
                        }
                        return json;
                    });
                }

                function renderRow(passkey) {
                    var tr = document.createElement('tr');

                    var nameTd = document.createElement('td');
                    nameTd.textContent = passkey.pkc_label || 'Passkey';
                    tr.appendChild(nameTd);

                    var createdTd = document.createElement('td');
                    createdTd.textContent = passkey.pkc_created_time ? new Date(passkey.pkc_created_time + 'Z').toLocaleDateString() : '';
                    tr.appendChild(createdTd);

                    var lastUsedTd = document.createElement('td');
                    lastUsedTd.textContent = passkey.pkc_last_used_time ? new Date(passkey.pkc_last_used_time + 'Z').toLocaleString() : 'Never';
                    tr.appendChild(lastUsedTd);

                    var actionsTd = document.createElement('td');
                    actionsTd.className = 'text-end';

                    var renameBtn = document.createElement('button');
                    renameBtn.type = 'button';
                    renameBtn.className = 'btn btn-secondary';
                    renameBtn.textContent = 'Rename';
                    renameBtn.addEventListener('click', function () { renamePasskey(passkey); });
                    actionsTd.appendChild(renameBtn);

                    var revokeBtn = document.createElement('button');
                    revokeBtn.type = 'button';
                    revokeBtn.className = 'btn btn-danger';
                    revokeBtn.textContent = 'Revoke';
                    revokeBtn.addEventListener('click', function () { revokePasskey(passkey); });
                    actionsTd.appendChild(revokeBtn);

                    tr.appendChild(actionsTd);
                    return tr;
                }

                function loadPasskeys() {
                    return apiFetch('/api/v1/Passkeys?user_id=<?php echo (int)SessionControl::get_instance()->get_user_id(); ?>').then(function (json) {
                        var credentials = json.data || [];
                        credentialCount = credentials.length;
                        tableBody.innerHTML = '';
                        credentials.forEach(function (passkey) { tableBody.appendChild(renderRow(passkey)); });
                        table.classList.toggle('d-none', !credentials.length);
                        empty.classList.toggle('d-none', !!credentials.length);
                    });
                }

                async function renamePasskey(passkey) {
                    var label = prompt('Rename this passkey:', passkey.pkc_label || '');
                    if (label === null || label.trim() === '') return;
                    try {
                        await apiFetch('/api/v1/action/passkey_rename', {
                            method: 'POST',
                            body: JSON.stringify({ credential_id: passkey.pkc_passkey_credential_id, label: label.trim() }),
                        });
                        await loadPasskeys();
                    } catch (e) {
                        alert(e.message || 'Could not rename passkey.');
                    }
                }

                async function revokePasskey(passkey) {
                    if (!confirm('Revoke "' + (passkey.pkc_label || 'this passkey') + '"? It will no longer be able to sign in to this account.')) return;
                    try {
                        // Revoking is a sensitive action — re-confirm the second factor
                        // first (the server also enforces this).
                        await stepUp();
                        await apiFetch('/api/v1/action/passkey_revoke', {
                            method: 'POST',
                            body: JSON.stringify({ credential_id: passkey.pkc_passkey_credential_id }),
                        });
                        await loadPasskeys();
                    } catch (e) {
                        alert(e.message || 'Could not revoke passkey.');
                    }
                }

                async function stepUp() {
                    var options = await apiFetch('/api/v1/action/passkey_stepup_options', { method: 'POST', body: '{}' });
                    var credential = await JoineryPasskeys.authenticate(options.data.options);
                    await apiFetch('/api/v1/action/passkey_stepup_verify', {
                        method: 'POST',
                        body: JSON.stringify({ credential: credential }),
                    });
                }

                var pwRow = document.getElementById('passkey-password-row');
                var pwInput = document.getElementById('passkey-current-password');

                async function addPasskey(currentPassword) {
                    var label = prompt('Label this passkey (e.g. "MacBook Touch ID"):', '');
                    if (label === null) return;
                    addBtn.disabled = true;
                    try {
                        if (credentialCount > 0) {
                            await stepUp();
                        }
                        // Always request PRF: the extension can only be enabled at
                        // creation time, and vault consumers need PRF-capable
                        // credentials. Harmless when the authenticator lacks it.
                        var body = { prf_capable_requested: 1 };
                        if (currentPassword) body.current_password = currentPassword;
                        var options = await apiFetch('/api/v1/action/passkey_register_options', { method: 'POST', body: JSON.stringify(body) });
                        var credential = await JoineryPasskeys.register(options.data.options);
                        await apiFetch('/api/v1/action/passkey_register_verify', {
                            method: 'POST',
                            body: JSON.stringify({ credential: credential, label: label }),
                        });
                        pwRow.classList.add('d-none');
                        pwInput.value = '';
                        await loadPasskeys();
                    } catch (e) {
                        alert(e.message || 'Could not add passkey.');
                    } finally {
                        addBtn.disabled = false;
                    }
                }

                addBtn.addEventListener('click', function () {
                    // First passkey: confirm the account password (there is no
                    // existing credential to step up with). Later passkeys step up.
                    if (credentialCount === 0 && pwRow.classList.contains('d-none')) {
                        pwRow.classList.remove('d-none');
                        pwInput.focus();
                        return;
                    }
                    if (credentialCount > 0) addPasskey('');
                });
                document.getElementById('passkey-password-continue').addEventListener('click', function () {
                    addPasskey(pwInput.value);
                });
                document.getElementById('passkey-password-cancel').addEventListener('click', function () {
                    pwRow.classList.add('d-none');
                    pwInput.value = '';
                });
                loadPasskeys();
            });
            </script>
            <?php endif; ?>

            <?php if ($page_vars['settings']->get_setting('passkeys_enabled')): ?>
            <div class="jy-panel jy-mt-4 d-none" id="vault-panel">
                <h2>Encrypted Vault</h2>

                <div id="vault-not-set-up">
                    <p>Seal your mail and chat content so it's readable only when you unlock it with a passkey, a recovery code, or a passphrase.</p>
                    <button type="button" class="btn btn-primary" id="vault-setup-btn">Set Up Your Vault</button>
                </div>

                <div class="d-none" id="vault-locked">
                    <p><strong>Status:</strong> Locked</p>
                    <button type="button" class="btn btn-primary" id="vault-unlock-passkey-btn">Unlock with Passkey</button>
                    <button type="button" class="btn btn-secondary" id="vault-unlock-recovery-btn">Unlock with Recovery Code</button>
                    <button type="button" class="btn btn-secondary" id="vault-unlock-passphrase-btn">Unlock with Passphrase</button>
                </div>

                <div class="d-none" id="vault-unlocked">
                    <p><strong>Status:</strong> Unlocked <span id="vault-regen-note" class="d-none">— fewer than 3 unused recovery codes remain, consider regenerating.</span></p>
                    <button type="button" class="btn btn-secondary" id="vault-lock-btn">Lock Now</button>

                    <table class="jy-table jy-w-full jy-mt-2" id="vault-wrappings-table">
                        <thead>
                            <tr><th>Unlocker</th><th>Added</th><th>Status</th></tr>
                        </thead>
                        <tbody id="vault-wrappings-body"></tbody>
                    </table>

                    <h3 class="jy-mt-4">Manage Unlockers</h3>
                    <button type="button" class="btn btn-secondary" id="vault-add-passkey-btn">Add Another Passkey</button>
                    <button type="button" class="btn btn-secondary" id="vault-regenerate-codes-btn">Regenerate Recovery Codes</button>
                    <button type="button" class="btn btn-secondary" id="vault-passphrase-enroll-btn">Enroll/Replace Passphrase</button>
                    <button type="button" class="btn btn-secondary" id="vault-passphrase-remove-btn">Remove Passphrase</button>

                    <h3 class="jy-mt-4">Rotate Vault Key</h3>
                    <p class="jy-security-note">Generates a fresh vault key and re-seals your content. Only the passkey you rotate with (and a passphrase you re-enter) carry forward — other passkeys need re-adding afterward. Recovery codes are always replaced.</p>
                    <button type="button" class="btn btn-danger" id="vault-rotate-btn">Rotate Vault Key</button>
                </div>

                <div class="d-none" id="vault-codes-display">
                    <h3 class="jy-mt-4">Recovery Codes</h3>
                    <p>Store these somewhere safe. Each can be used once. They will not be shown again.</p>
                    <pre class="jy-security-codes" id="vault-codes-pre"></pre>
                    <button type="button" class="btn btn-secondary" id="vault-download-keyfile-btn">Download Key File</button>
                    <button type="button" class="btn btn-primary" id="vault-codes-done-btn">Done</button>
                </div>
            </div>

            <script defer src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
            <script defer>
            document.addEventListener('DOMContentLoaded', function () {
                var panel = document.getElementById('vault-panel');
                if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) return;
                panel.classList.remove('d-none');

                var csrf = document.querySelector('meta[name="joinery-api-csrf"]').content;
                var notSetUp = document.getElementById('vault-not-set-up');
                var locked = document.getElementById('vault-locked');
                var unlocked = document.getElementById('vault-unlocked');
                var codesDisplay = document.getElementById('vault-codes-display');
                var codesPre = document.getElementById('vault-codes-pre');
                var lastKeyFile = null;

                function apiFetch(url, options) {
                    options = options || {};
                    options.headers = Object.assign({ 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf }, options.headers || {});
                    return fetch(url, options).then(async function (res) {
                        var json = await res.json();
                        if (!res.ok) {
                            var err = new Error(json.error || 'Request failed.');
                            err.data = json.data || {};
                            throw err;
                        }
                        // Shared second-factor step-up handling (§ 5.5): a 2xx render
                        // carrying the flag redirects to the ceremony, then back here.
                        // Never-resolving promise halts the chain during navigation.
                        if (json.data && json.data.second_factor_required) {
                            window.location = '/verify-stepup?return=' + encodeURIComponent('/profile/security');
                            return new Promise(function () {});
                        }
                        return json;
                    });
                }

                function showCodes(recoveryCodes, keyFile) {
                    codesPre.textContent = recoveryCodes.join('\n');
                    lastKeyFile = keyFile || null;
                    notSetUp.classList.add('d-none');
                    locked.classList.add('d-none');
                    unlocked.classList.add('d-none');
                    codesDisplay.classList.remove('d-none');
                }

                function renderWrappings(status) {
                    var body = document.getElementById('vault-wrappings-body');
                    body.innerHTML = '';
                    (status.wrappings || []).forEach(function (w) {
                        var tr = document.createElement('tr');
                        var nameTd = document.createElement('td');
                        nameTd.textContent = w.unlocker_type + (w.label ? ' (' + w.label + ')' : '');
                        var addedTd = document.createElement('td');
                        addedTd.textContent = w.created_time ? new Date(w.created_time + 'Z').toLocaleDateString() : '';
                        var statusTd = document.createElement('td');
                        statusTd.textContent = w.is_used ? 'Used' : 'Active';
                        tr.appendChild(nameTd); tr.appendChild(addedTd); tr.appendChild(statusTd);
                        body.appendChild(tr);
                    });
                    document.getElementById('vault-regen-note').classList.toggle('d-none', !status.regenerate_recommended);
                }

                function refresh() {
                    return apiFetch('/api/v1/action/vault_status', { method: 'POST', body: '{}' }).then(function (json) {
                        var status = json.data;
                        // Presence beacon (assets/js/vault-presence.js): follow the
                        // window state, so an unlock on this page starts site-wide
                        // presence without a reload and a lock stops it.
                        if (window.JoineryVaultPresence) {
                            if (status.set_up && status.unlocked) { JoineryVaultPresence.start(); }
                            else { JoineryVaultPresence.stop(); }
                        }
                        codesDisplay.classList.add('d-none');
                        if (!status.set_up) {
                            notSetUp.classList.remove('d-none');
                            locked.classList.add('d-none');
                            unlocked.classList.add('d-none');
                        } else if (!status.unlocked) {
                            notSetUp.classList.add('d-none');
                            locked.classList.remove('d-none');
                            unlocked.classList.add('d-none');
                        } else {
                            notSetUp.classList.add('d-none');
                            locked.classList.add('d-none');
                            unlocked.classList.remove('d-none');
                            renderWrappings(status);
                        }
                    });
                }

                document.getElementById('vault-setup-btn').addEventListener('click', async function () {
                    try {
                        await apiFetch('/api/v1/action/vault_setup_options', { method: 'POST', body: '{}' });
                    } catch (e) {
                        if (e.data && e.data.requires_password) {
                            alert('Set an account password first (see the top of this page), then try again.');
                        } else {
                            alert(e.message || 'Could not begin vault setup.');
                        }
                        return;
                    }
                    if (!confirm('If you lose every unlocker (passkey, recovery codes, and passphrase), everything sealed in your vault is permanently lost - there is no support-desk recovery. Continue?')) return;
                    var passphrase = prompt('Optional: set a vault passphrase now (12+ characters), or leave blank to skip:', '') || '';
                    try {
                        var options = await apiFetch('/api/v1/action/vault_setup_options', { method: 'POST', body: '{}' });
                        var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                        var body = { credential: credential, acknowledged: true };
                        if (passphrase) body.passphrase = passphrase;
                        var result = await apiFetch('/api/v1/action/vault_setup_verify', { method: 'POST', body: JSON.stringify(body) });
                        showCodes(result.data.recovery_codes, result.data.key_file);
                    } catch (e) {
                        alert(e.message || 'Could not set up your vault.');
                    }
                });

                document.getElementById('vault-unlock-passkey-btn').addEventListener('click', async function () {
                    try {
                        var options = await apiFetch('/api/v1/action/vault_unlock_options', { method: 'POST', body: '{}' });
                        var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                        await apiFetch('/api/v1/action/vault_unlock_passkey', { method: 'POST', body: JSON.stringify({ credential: credential }) });
                        await refresh();
                    } catch (e) {
                        alert(e.message || 'Could not unlock your vault.');
                    }
                });

                document.getElementById('vault-unlock-recovery-btn').addEventListener('click', async function () {
                    var code = prompt('Enter a recovery code:', '');
                    if (!code) return;
                    try {
                        // A second_factor_required render (§ 5.6, recovery-code unlock)
                        // is handled centrally by apiFetch (redirects to the step-up
                        // ceremony), so it never resolves here.
                        var result = await apiFetch('/api/v1/action/vault_unlock_recovery', { method: 'POST', body: JSON.stringify({ code: code }) });
                        if (result.data && result.data.regenerate_recommended) {
                            alert('Unlocked. Fewer than 3 unused recovery codes remain - consider regenerating them.');
                        }
                        await refresh();
                    } catch (e) {
                        alert(e.message || 'Could not unlock your vault.');
                    }
                });

                document.getElementById('vault-unlock-passphrase-btn').addEventListener('click', async function () {
                    var passphrase = prompt('Enter your vault passphrase:', '');
                    if (!passphrase) return;
                    try {
                        await apiFetch('/api/v1/action/vault_unlock_passphrase', { method: 'POST', body: JSON.stringify({ passphrase: passphrase }) });
                        await refresh();
                    } catch (e) {
                        alert(e.message || 'Could not unlock your vault.');
                    }
                });

                document.getElementById('vault-lock-btn').addEventListener('click', async function () {
                    try {
                        await apiFetch('/api/v1/action/vault_lock', { method: 'POST', body: '{}' });
                        await refresh();
                    } catch (e) {
                        alert(e.message || 'Could not lock your vault.');
                    }
                });

                document.getElementById('vault-add-passkey-btn').addEventListener('click', async function () {
                    try {
                        var options = await apiFetch('/api/v1/action/vault_add_passkey_options', { method: 'POST', body: '{}' });
                        var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                        await apiFetch('/api/v1/action/vault_add_passkey_verify', { method: 'POST', body: JSON.stringify({ credential: credential }) });
                        await refresh();
                        alert('Passkey added to your vault.');
                    } catch (e) {
                        alert(e.message || 'Could not add this passkey to your vault.');
                    }
                });

                document.getElementById('vault-regenerate-codes-btn').addEventListener('click', async function () {
                    if (!confirm('This invalidates all existing recovery codes. Continue?')) return;
                    try {
                        var result = await apiFetch('/api/v1/action/vault_regenerate_codes', { method: 'POST', body: '{}' });
                        showCodes(result.data.recovery_codes, null);
                    } catch (e) {
                        alert(e.message || 'Could not regenerate recovery codes.');
                    }
                });

                document.getElementById('vault-passphrase-enroll-btn').addEventListener('click', async function () {
                    var passphrase = prompt('Set a vault passphrase (12+ characters):', '');
                    if (!passphrase) return;
                    try {
                        await apiFetch('/api/v1/action/vault_passphrase_enroll', { method: 'POST', body: JSON.stringify({ passphrase: passphrase }) });
                        await refresh();
                        alert('Vault passphrase enrolled.');
                    } catch (e) {
                        alert(e.message || 'Could not enroll a vault passphrase.');
                    }
                });

                document.getElementById('vault-passphrase-remove-btn').addEventListener('click', async function () {
                    if (!confirm('Remove your vault passphrase?')) return;
                    try {
                        await apiFetch('/api/v1/action/vault_passphrase_remove', { method: 'POST', body: '{}' });
                        await refresh();
                    } catch (e) {
                        alert(e.message || 'Could not remove your vault passphrase.');
                    }
                });

                document.getElementById('vault-rotate-btn').addEventListener('click', async function () {
                    if (!confirm('Rotate your vault key now? Passkeys other than the one you use here, and your passphrase unless re-entered, will need to be re-added afterward. Continue?')) return;
                    var passphrase = prompt('Re-enter your vault passphrase to carry it forward, or leave blank to drop it:', '') || '';
                    try {
                        var options = await apiFetch('/api/v1/action/vault_rotate_options', { method: 'POST', body: '{}' });
                        var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                        var body = { credential: credential, acknowledged: true };
                        if (passphrase) body.passphrase = passphrase;
                        var result = await apiFetch('/api/v1/action/vault_rotate_verify', { method: 'POST', body: JSON.stringify(body) });
                        showCodes(result.data.recovery_codes, null);
                        if (result.data.dropped_passkeys && result.data.dropped_passkeys.length) {
                            alert('These passkeys need to be re-added to your vault: ' + result.data.dropped_passkeys.map(function (p) { return p.label || 'Passkey'; }).join(', '));
                        }
                    } catch (e) {
                        alert(e.message || 'Could not rotate your vault key.');
                    }
                });

                document.getElementById('vault-download-keyfile-btn').addEventListener('click', function () {
                    if (!lastKeyFile) { alert('No key file available for this action.'); return; }
                    var blob = new Blob([JSON.stringify(lastKeyFile, null, 2)], { type: 'application/json' });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'vault-key-file.json';
                    a.click();
                    URL.revokeObjectURL(url);
                });

                document.getElementById('vault-codes-done-btn').addEventListener('click', function () {
                    codesDisplay.classList.add('d-none');
                    refresh();
                });

                refresh();
            });
            </script>
            <?php endif; ?>

            <?php if (!empty($page_vars['app_sessions']) && count($page_vars['app_sessions'])): ?>
            <div class="jy-panel jy-mt-4">
                <h2>App Sessions</h2>
                <p>Devices signed in to your account. Revoke a session to sign that device out.</p>

                <table class="jy-table jy-w-full">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Signed in</th>
                            <th>Last used</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($page_vars['app_sessions'] as $app_session): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($app_session->get('apk_name')); ?></td>
                            <td><?php echo htmlspecialchars(LibraryFunctions::convert_time($app_session->get('apk_create_time'), 'UTC',
                                SessionControl::get_instance()->get_timezone(), 'M j, Y')); ?></td>
                            <td><?php echo $app_session->get('apk_last_used_time')
                                ? htmlspecialchars(LibraryFunctions::convert_time($app_session->get('apk_last_used_time'), 'UTC',
                                    SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A'))
                                : 'Never'; ?></td>
                            <td class="text-end">
                                <form action="/profile/security" method="POST" class="jy-inline">
                                    <input type="hidden" name="action" value="revoke_app_session">
                                    <input type="hidden" name="apk_api_key_id" value="<?php echo (int)$app_session->key; ?>">
                                    <button type="submit" class="btn btn-danger">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <form action="/profile/security" method="POST" class="jy-mt-2"
                      onsubmit="return confirm('Sign out every device signed in to your account?');">
                    <input type="hidden" name="action" value="revoke_all_app_sessions">
                    <button type="submit" class="btn btn-secondary">Revoke All</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
