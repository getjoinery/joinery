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
        <div class="jy-settings-shell">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Security Settings</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">Dashboard</a></li>
                            <li class="active">Security</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php echo PublicPage::settings_layout_start(); ?>

            <div class="jy-panel jy-mt-4">

                <?php
                foreach ($page_vars['display_messages'] ?? [] as $display_message) {
                    if ($display_message->identifier == 'securitybox') {
                        echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
                    }
                }
                ?>

                <?php
                // One panel, one vocabulary: the account has a second factor (or
                // not); TOTP and passkeys are methods of it. The summary comes from
                // the same predicate the sign-in divert and step-up gates use, so
                // this line can never contradict what enforcement does.
                $fs = $page_vars['factor_summary'] ?? array('active' => false, 'totp' => false, 'passkey_count' => 0);
                $summary_parts = array();
                if ($fs['passkey_count'] > 0) {
                    $summary_parts[] = $fs['passkey_count'] . ' passkey' . ($fs['passkey_count'] == 1 ? '' : 's');
                }
                if ($fs['totp']) {
                    $summary_parts[] = 'authenticator app';
                } elseif ($fs['passkey_count'] > 0) {
                    $summary_parts[] = 'authenticator app off';
                }
                ?>
                <h2>Second-Factor Sign-In</h2>
                <p><strong>Status:</strong> <?php echo $fs['active']
                    ? 'Active — ' . htmlspecialchars(implode(' · ', $summary_parts))
                    : 'Off'; ?></p>

                <?php if ($fs['active']): $cadence = $page_vars['cadence'] ?? 'every_login'; ?>
                    <form action="/profile/security" method="POST" class="jy-mt-2">
                        <input type="hidden" name="action" value="set_cadence">
                        <label class="jy-block jy-mt-2">
                            <input type="radio" name="cadence" value="every_login" <?php echo $cadence === 'every_login' ? 'checked' : ''; ?>>
                            Ask for it at every sign-in
                        </label>
                        <label class="jy-block jy-mt-2">
                            <input type="radio" name="cadence" value="sensitive_only" <?php echo $cadence === 'sensitive_only' ? 'checked' : ''; ?>>
                            Ask for it only at sensitive actions (less secure)
                        </label>
                        <div class="jy-mt-3">
                            <button type="submit" class="btn btn-secondary">Save</button>
                        </div>
                    </form>

                    <form action="/profile/security" method="POST" class="jy-mt-3"
                          data-jy-confirm="Forget all trusted devices? No one is signed out - each device, including this one, will simply be asked for your second factor at its next sign-in.">
                        <input type="hidden" name="action" value="revoke_trusted_devices">
                        <button type="submit" class="btn btn-secondary">Forget Trusted Devices</button>
                    </form>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center jy-mt-4">
                    <h3>Authenticator app</h3>
                    <?php if (!empty($page_vars['totp_enabled']) && empty($page_vars['backup_codes'])): ?>
                    <details class="jy-actions-dropdown">
                        <summary class="btn btn-secondary">Actions</summary>
                        <div class="jy-actions-menu">
                            <a href="/profile/test-authenticator">Test Authenticator App</a>
                            <button type="button" id="totp-regen-menu-btn">Regenerate Backup Codes</button>
                            <button type="button" class="jy-action-danger" id="totp-disable-menu-btn">Turn Off Authenticator App…</button>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>

                <?php if (!empty($page_vars['just_enabled']) && !empty($page_vars['backup_codes'])): ?>
                    <div class="jy-alert jy-alert-success">
                        <strong>Your authenticator app is set up.</strong>
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
                    <p>On
                    <?php if (!empty($page_vars['totp_enabled_time'])): ?>
                        (since <?php echo htmlspecialchars(LibraryFunctions::convert_time($page_vars['totp_enabled_time'], 'UTC',
                            SessionControl::get_instance()->get_timezone(), 'M j, Y')); ?>)
                    <?php endif; ?>
                    </p>

                    <form action="/profile/security" method="POST" class="d-none" id="totp-regen-form"
                          data-jy-confirm="Generate a fresh set of 10 single-use backup codes? This invalidates any previous codes.">
                        <input type="hidden" name="action" value="regenerate_backup_codes">
                    </form>

                    <div class="d-none jy-mt-2" id="totp-disable-block">
                        <p>Confirm with a current 6-digit code or an 8-character backup code. Turning it off also invalidates any trusted devices.
                        <?php if ($fs['passkey_count'] > 0): ?>
                            Sign-ins will still ask for your passkey.
                        <?php endif; ?></p>
                        <form action="/profile/security" method="POST">
                            <input type="hidden" name="action" value="disable">
                            <input type="text" name="confirm_code" placeholder="6-digit or backup code" autocomplete="one-time-code" required>
                            <button type="submit" class="btn btn-danger">Turn Off Authenticator App</button>
                        </form>
                    </div>

                    <script defer>
                    document.addEventListener('DOMContentLoaded', function () {
                        document.getElementById('totp-regen-menu-btn').addEventListener('click', function () {
                            var f = document.getElementById('totp-regen-form');
                            if (f.requestSubmit) f.requestSubmit(); else f.submit();
                        });
                        document.getElementById('totp-disable-menu-btn').addEventListener('click', function () {
                            var block = document.getElementById('totp-disable-block');
                            block.classList.remove('d-none');
                            block.querySelector('input[name="confirm_code"]').focus();
                        });
                    });
                    </script>

                <?php elseif (!empty($page_vars['setup_in_progress'])): ?>
                    <p>Scan this QR code with your authenticator app
                    (Google Authenticator, Authy, 1Password, etc.):</p>

                    <div class="jy-security-qr">
                        <img src="<?php echo htmlspecialchars($page_vars['qr_uri']); ?>" alt="2FA setup QR code" class="jy-security-qr-img">
                    </div>

                    <p>If you can't scan, enter this key manually:</p>
                    <pre class="jy-security-secret"><?php echo htmlspecialchars(trim(chunk_split($page_vars['secret'], 4, ' '))); ?></pre>

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
                    <p>Off</p>
                    <p>An authenticator app on your phone generates a 6-digit code that confirms sign-ins as a second-factor method. This protects your account even if your password is compromised.</p>

                    <form action="/profile/security" method="POST">
                        <input type="hidden" name="action" value="start_enable">
                        <button type="submit" class="btn btn-primary">Set Up Authenticator App</button>
                    </form>
                <?php endif; ?>

            </div>

            <div class="jy-panel jy-mt-4">
                <h2>Recovery address</h2>
                <?php if (!empty($page_vars['recovery_email']) && !empty($page_vars['recovery_email_verified'])): ?>
                    <p><strong>Status:</strong> Active — <?php echo htmlspecialchars($page_vars['recovery_email']); ?></p>
                    <p class="jy-auth-hint">Password reset links are also sent here. Anyone who controls this inbox can start a reset of your account session (they still cannot open your sealed mail).</p>
                    <form action="/profile/security" method="POST" data-jy-confirm="Remove your recovery address?">
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
            <script>
            // Guided-flow return (specs/mailbox_protection_ceremony.md): a page that
            // sent the user here to set up a vault or passkey passes ?return=<path>;
            // completing that setup navigates straight back. Same-origin paths only.
            window.jyMaybeReturn = function () {
                var target = new URLSearchParams(window.location.search).get('return') || '';
                if (target.charAt(0) !== '/' || target.charAt(1) === '/' || target.indexOf(':') !== -1) {
                    return false;
                }
                window.location.assign(target);
                return true;
            };
            </script>
            <div class="jy-panel jy-mt-4 d-none" id="passkeys-panel">
                <h2>Passkeys</h2>

                <div class="jy-alert jy-alert-success d-none" id="passkey-second-factor-notice">
                    Your passkey now also protects password sign-ins — you'll confirm it when signing in with your password.
                </div>

                <table class="jy-table jy-w-full d-none" id="passkeys-table">
                    <thead>
                        <tr>
                            <th>Passkey</th>
                            <th>Added</th>
                            <th>Last used</th>
                            <th class="d-none" id="passkeys-vault-th">Vault</th>
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
                <p class="jy-auth-hint jy-mt-2 d-none" id="passkey-flow-hint"></p>
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

                // Vault activation state for the badge column. null = no vault
                // set up (column hidden, no vault menu items).
                var vaultStatus = null;
                function loadVaultStatus() {
                    return apiFetch('/api/v1/action/vault_status', { method: 'POST', body: '{}' }).then(function (json) {
                        vaultStatus = (json.data && json.data.set_up) ? json.data : null;
                    }).catch(function () { vaultStatus = null; });
                }
                function vaultActiveIds() {
                    var ids = {};
                    if (vaultStatus) {
                        (vaultStatus.wrappings || []).forEach(function (w) {
                            if (w.unlocker_type === 'passkey' && w.credential_id) ids[w.credential_id] = true;
                        });
                    }
                    return ids;
                }

                function menuItem(label, handler, danger) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    if (danger) b.className = 'jy-action-danger';
                    b.textContent = label;
                    b.addEventListener('click', handler);
                    return b;
                }

                function renderRow(passkey, activeIds) {
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

                    var active = !!activeIds[passkey.pkc_passkey_credential_id];
                    if (vaultStatus) {
                        var vaultTd = document.createElement('td');
                        var badge = document.createElement('span');
                        if (active) {
                            badge.className = 'badge badge-success';
                            badge.textContent = 'Vault active';
                        } else {
                            badge.className = 'badge badge-warning';
                            badge.textContent = 'Not activated';
                            badge.title = passkey.pkc_prf_capable
                                ? 'This passkey signs you in but cannot unlock your vault yet — activate it from Actions.'
                                : 'This passkey signs you in but cannot unlock your vault yet. Activating will test whether this authenticator supports vault unlock.';
                        }
                        vaultTd.appendChild(badge);
                        tr.appendChild(vaultTd);
                    }

                    var actionsTd = document.createElement('td');
                    actionsTd.className = 'text-end';

                    var dd = document.createElement('details');
                    dd.className = 'jy-actions-dropdown';
                    var summary = document.createElement('summary');
                    summary.className = 'btn btn-secondary';
                    summary.textContent = 'Actions';
                    dd.appendChild(summary);
                    var menu = document.createElement('div');
                    menu.className = 'jy-actions-menu';
                    menu.appendChild(menuItem('Rename', function () { renamePasskey(passkey); }));
                    if (vaultStatus) {
                        if (active) {
                            menu.appendChild(menuItem('Deactivate for vault', function () { deactivateForVault(passkey); }));
                        } else {
                            menu.appendChild(menuItem('Activate for vault', function () { activateForVault(passkey); }));
                        }
                    }
                    menu.appendChild(menuItem('Revoke', function () { revokePasskey(passkey); }, true));
                    dd.appendChild(menu);
                    actionsTd.appendChild(dd);

                    tr.appendChild(actionsTd);
                    return tr;
                }

                function loadPasskeys() {
                    return apiFetch('/api/v1/Passkeys?user_id=<?php echo (int)SessionControl::get_instance()->get_user_id(); ?>').then(function (json) {
                        var credentials = json.data || [];
                        credentialCount = credentials.length;
                        var activeIds = vaultActiveIds();
                        document.getElementById('passkeys-vault-th').classList.toggle('d-none', !vaultStatus);
                        tableBody.innerHTML = '';
                        credentials.forEach(function (passkey) { tableBody.appendChild(renderRow(passkey, activeIds)); });
                        table.classList.toggle('d-none', !credentials.length);
                        empty.classList.toggle('d-none', !!credentials.length);
                    });
                }
                function reloadAll() {
                    return loadVaultStatus().then(loadPasskeys);
                }
                document.addEventListener('joinery:vault-changed', reloadAll);

                async function renamePasskey(passkey) {
                    var label = await JoineryModal.promptAsync('Rename this passkey:', { defaultValue: passkey.pkc_label || '', confirmLabel: 'Rename' });
                    if (label === null || label.trim() === '') return;
                    try {
                        await apiFetch('/api/v1/action/passkey_rename', {
                            method: 'POST',
                            body: JSON.stringify({ credential_id: passkey.pkc_passkey_credential_id, label: label.trim() }),
                        });
                        await loadPasskeys();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not rename passkey.');
                    }
                }

                async function revokePasskey(passkey) {
                    if (!await JoineryModal.confirmAsync('Revoke "' + (passkey.pkc_label || 'this passkey') + '"? It will no longer be able to sign in to this account.', { confirmLabel: 'Revoke' })) return;
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
                        JoineryModal.alert(e.message || 'Could not revoke passkey.');
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

                // Wrap the vault key under a passkey (an unlock-capable
                // "activation"). The derivation ceremony decides which passkey
                // gets activated — the one the user actually touches.
                async function runVaultActivation() {
                    var options = await apiFetch('/api/v1/action/vault_add_passkey_options', { method: 'POST', body: '{}' });
                    var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                    await apiFetch('/api/v1/action/vault_add_passkey_verify', { method: 'POST', body: JSON.stringify({ credential: credential }) });
                    document.dispatchEvent(new CustomEvent('joinery:vault-changed'));
                }

                async function activateForVault(passkey) {
                    if (!await JoineryModal.confirmAsync('Activate "' + (passkey.pkc_label || 'this passkey') + '" for your vault? Use that passkey when the browser prompts.', { confirmLabel: 'Activate', confirmStyle: 'primary' })) return;
                    try {
                        await runVaultActivation();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not activate this passkey for your vault.');
                    }
                }

                async function deactivateForVault(passkey) {
                    if (!await JoineryModal.confirmAsync('Deactivate "' + (passkey.pkc_label || 'this passkey') + '" for your vault? It will still sign you in, but can no longer unlock your sealed content.', { confirmLabel: 'Deactivate' })) return;
                    try {
                        // Sensitive: the server demands a recent step-up.
                        await stepUp();
                        await apiFetch('/api/v1/action/vault_passkey_deactivate', {
                            method: 'POST',
                            body: JSON.stringify({ credential_id: passkey.pkc_passkey_credential_id }),
                        });
                        document.dispatchEvent(new CustomEvent('joinery:vault-changed'));
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not deactivate this passkey for your vault.');
                    }
                }

                var pwRow = document.getElementById('passkey-password-row');
                var pwInput = document.getElementById('passkey-current-password');

                var flowHint = document.getElementById('passkey-flow-hint');
                function showFlowHint(text) {
                    flowHint.textContent = text;
                    flowHint.classList.remove('d-none');
                }
                function clearFlowHint() {
                    flowHint.classList.add('d-none');
                    flowHint.textContent = '';
                }

                async function addPasskey(currentPassword) {
                    var label = await JoineryModal.promptAsync('Label this passkey (e.g. "MacBook Touch ID"):', { confirmLabel: 'Continue' });
                    if (label === null) return;
                    addBtn.disabled = true;
                    try {
                        if (credentialCount > 0) {
                            showFlowHint('Step 1 of 2 — Confirm it\'s you with a passkey you already have. The prompt to create the new one comes next.');
                            await stepUp();
                            showFlowHint('Step 2 of 2 — Now create the new passkey. This is where you pick a security key or another device.');
                        }
                        // Always request PRF: the extension can only be enabled at
                        // creation time, and vault consumers need PRF-capable
                        // credentials. Harmless when the authenticator lacks it.
                        var body = { prf_capable_requested: 1 };
                        if (currentPassword) body.current_password = currentPassword;
                        var options = await apiFetch('/api/v1/action/passkey_register_options', { method: 'POST', body: JSON.stringify(body) });
                        var credential = await JoineryPasskeys.register(options.data.options);
                        var regResult = await apiFetch('/api/v1/action/passkey_register_verify', {
                            method: 'POST',
                            body: JSON.stringify({ credential: credential, label: label }),
                        });
                        // First factor enrolled: sign-in behavior just changed — say so
                        // inline, once, right where the enrollment happened.
                        if (regResult.data && regResult.data.became_second_factor) {
                            document.getElementById('passkey-second-factor-notice').classList.remove('d-none');
                        }
                        pwRow.classList.add('d-none');
                        pwInput.value = '';
                        await reloadAll();
                        // Vault-active by default: when a vault exists and is
                        // unlocked, chain straight into activation so the new
                        // passkey can unlock it too — one more touch, of the
                        // new passkey. Skipped/failed just leaves the badge on
                        // "Not activated" with the action in its menu.
                        if (vaultStatus && vaultStatus.unlocked) {
                            showFlowHint('One more touch — use the NEW passkey again to let it unlock your vault.');
                            try {
                                await runVaultActivation();
                            } catch (e) {
                                JoineryModal.alert('The passkey was added, but is not activated for your vault yet: '
                                    + (e.message || 'activation failed.') + ' You can activate it any time from its Actions menu.');
                            }
                        }
                        if (window.jyMaybeReturn && jyMaybeReturn()) return;
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not add passkey.');
                    } finally {
                        clearFlowHint();
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
                reloadAll();
            });
            </script>
            <?php endif; ?>

            <?php if ($page_vars['settings']->get_setting('passkeys_enabled')): ?>
            <div class="jy-panel jy-mt-4 d-none" id="vault-panel">
                <div class="d-flex justify-content-between align-items-center">
                    <h2>Encrypted Vault</h2>
                    <details class="jy-actions-dropdown d-none" id="vault-actions-dropdown">
                        <summary class="btn btn-secondary">Actions</summary>
                        <div class="jy-actions-menu">
                            <button type="button" id="vault-regenerate-codes-btn">Regenerate Recovery Codes</button>
                            <button type="button" id="vault-passphrase-enroll-btn">Add/Replace Bypass Phrase</button>
                            <button type="button" class="d-none" id="vault-passphrase-remove-btn">Remove Bypass Phrase</button>
                            <button type="button" class="jy-action-danger" id="vault-rotate-btn">Rotate Vault Key</button>
                        </div>
                    </details>
                </div>

                <div id="vault-not-set-up">
                    <p>Seal your mail and chat content so it's readable only when you unlock it with a passkey or a recovery code.</p>
                    <button type="button" class="btn btn-primary" id="vault-setup-btn">Set Up Your Vault</button>
                </div>

                <div class="d-none" id="vault-locked">
                    <p><strong>Status:</strong> Locked</p>
                    <button type="button" class="btn btn-primary" id="vault-unlock-passkey-btn">Unlock with Passkey</button>
                    <button type="button" class="btn btn-secondary" id="vault-unlock-recovery-btn">Unlock with Recovery Code</button>
                    <button type="button" class="btn btn-secondary d-none" id="vault-unlock-passphrase-btn">Unlock with Bypass Phrase</button>
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

                    <p class="jy-security-note jy-mt-2">Passkey unlockers are managed from each passkey's Actions menu in the Passkeys section above.</p>
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
                var actionsDd = document.getElementById('vault-actions-dropdown');
                function setActionsVisible(visible) {
                    actionsDd.classList.toggle('d-none', !visible);
                    if (!visible) actionsDd.removeAttribute('open');
                }
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
                    setActionsVisible(false);
                    codesDisplay.classList.remove('d-none');
                }

                var unlockerNames = { passkey: 'Passkey', recovery: 'Recovery code', passphrase: 'Bypass phrase' };
                var hasPassphrase = false;

                function renderWrappings(status) {
                    var body = document.getElementById('vault-wrappings-body');
                    body.innerHTML = '';
                    (status.wrappings || []).forEach(function (w) {
                        var tr = document.createElement('tr');
                        var nameTd = document.createElement('td');
                        nameTd.textContent = (unlockerNames[w.unlocker_type] || w.unlocker_type) + (w.label ? ' (' + w.label + ')' : '');
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
                        hasPassphrase = !!status.has_passphrase;
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
                            setActionsVisible(false);
                        } else if (!status.unlocked) {
                            notSetUp.classList.add('d-none');
                            locked.classList.remove('d-none');
                            unlocked.classList.add('d-none');
                            setActionsVisible(false);
                            document.getElementById('vault-unlock-passphrase-btn').classList.toggle('d-none', !status.has_passphrase);
                        } else {
                            notSetUp.classList.add('d-none');
                            locked.classList.add('d-none');
                            unlocked.classList.remove('d-none');
                            setActionsVisible(true);
                            document.getElementById('vault-passphrase-remove-btn').classList.toggle('d-none', !status.has_passphrase);
                            renderWrappings(status);
                        }
                    });
                }

                document.getElementById('vault-setup-btn').addEventListener('click', async function () {
                    try {
                        await apiFetch('/api/v1/action/vault_setup_options', { method: 'POST', body: '{}' });
                    } catch (e) {
                        if (e.data && e.data.requires_password) {
                            JoineryModal.alert('Set an account password first (see the top of this page), then try again.');
                        } else {
                            JoineryModal.alert(e.message || 'Could not begin vault setup.');
                        }
                        return;
                    }
                    if (!await JoineryModal.confirmAsync('If you lose every unlocker (your passkey and your recovery codes), everything sealed in your vault is permanently lost - there is no support-desk recovery. Continue?', { confirmLabel: 'I understand' })) return;
                    try {
                        var options = await apiFetch('/api/v1/action/vault_setup_options', { method: 'POST', body: '{}' });
                        var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                        var body = { credential: credential, acknowledged: true };
                        var result = await apiFetch('/api/v1/action/vault_setup_verify', { method: 'POST', body: JSON.stringify(body) });
                        showCodes(result.data.recovery_codes, result.data.key_file);
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not set up your vault.');
                    }
                });

                document.getElementById('vault-unlock-passkey-btn').addEventListener('click', async function () {
                    try {
                        var options = await apiFetch('/api/v1/action/vault_unlock_options', { method: 'POST', body: '{}' });
                        var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                        await apiFetch('/api/v1/action/vault_unlock_passkey', { method: 'POST', body: JSON.stringify({ credential: credential }) });
                        await refresh();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not unlock your vault.');
                    }
                });

                document.getElementById('vault-unlock-recovery-btn').addEventListener('click', async function () {
                    var code = await JoineryModal.promptAsync('Enter a recovery code:', { confirmLabel: 'Unlock' });
                    if (!code) return;
                    try {
                        // A second_factor_required render (§ 5.6, recovery-code unlock)
                        // is handled centrally by apiFetch (redirects to the step-up
                        // ceremony), so it never resolves here.
                        var result = await apiFetch('/api/v1/action/vault_unlock_recovery', { method: 'POST', body: JSON.stringify({ code: code }) });
                        if (result.data && result.data.regenerate_recommended) {
                            JoineryModal.alert('Unlocked. Fewer than 3 unused recovery codes remain - consider regenerating them.');
                        }
                        await refresh();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not unlock your vault.');
                    }
                });

                document.getElementById('vault-unlock-passphrase-btn').addEventListener('click', async function () {
                    var passphrase = await JoineryModal.promptAsync('Enter your bypass phrase:', { inputType: 'password', confirmLabel: 'Unlock' });
                    if (!passphrase) return;
                    try {
                        await apiFetch('/api/v1/action/vault_unlock_passphrase', { method: 'POST', body: JSON.stringify({ passphrase: passphrase }) });
                        await refresh();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not unlock your vault.');
                    }
                });

                document.getElementById('vault-lock-btn').addEventListener('click', async function () {
                    try {
                        await apiFetch('/api/v1/action/vault_lock', { method: 'POST', body: '{}' });
                        await refresh();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not lock your vault.');
                    }
                });

                document.getElementById('vault-regenerate-codes-btn').addEventListener('click', async function () {
                    if (!await JoineryModal.confirmAsync('This invalidates all existing recovery codes. Continue?', { confirmLabel: 'Regenerate' })) return;
                    try {
                        var result = await apiFetch('/api/v1/action/vault_regenerate_codes', { method: 'POST', body: '{}' });
                        showCodes(result.data.recovery_codes, null);
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not regenerate recovery codes.');
                    }
                });

                document.getElementById('vault-passphrase-enroll-btn').addEventListener('click', async function () {
                    if (!await JoineryModal.confirmAsync('A bypass phrase is a memorized phrase that opens your vault without your passkey - it is not your login password. It lowers your vault\'s strength to the strength of the phrase: anyone who learns or guesses it can unlock. Add one only if you need to unlock where your passkey is not available.', { confirmLabel: 'I understand' })) return;
                    var passphrase = await JoineryModal.promptAsync('Set a bypass phrase (12+ characters):', { inputType: 'password', confirmLabel: 'Save' });
                    if (!passphrase) return;
                    try {
                        await apiFetch('/api/v1/action/vault_passphrase_enroll', { method: 'POST', body: JSON.stringify({ passphrase: passphrase }) });
                        await refresh();
                        JoineryModal.alert('Bypass phrase added.');
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not add a bypass phrase.');
                    }
                });

                document.getElementById('vault-passphrase-remove-btn').addEventListener('click', async function () {
                    if (!await JoineryModal.confirmAsync('Remove your bypass phrase?', { confirmLabel: 'Remove' })) return;
                    try {
                        await apiFetch('/api/v1/action/vault_passphrase_remove', { method: 'POST', body: '{}' });
                        await refresh();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not remove your bypass phrase.');
                    }
                });

                document.getElementById('vault-rotate-btn').addEventListener('click', async function () {
                    if (!await JoineryModal.confirmAsync('Rotate your vault key now? Passkeys other than the one you use here' + (hasPassphrase ? ', and your bypass phrase unless re-entered,' : '') + ' will need to be re-added afterward. Continue?', { confirmLabel: 'Rotate' })) return;
                    var passphrase = '';
                    if (hasPassphrase) {
                        passphrase = await JoineryModal.promptAsync('Re-enter your bypass phrase to carry it forward, or leave blank to drop it:', { inputType: 'password', confirmLabel: 'Continue' });
                        if (passphrase === null) return;
                    }
                    try {
                        var options = await apiFetch('/api/v1/action/vault_rotate_options', { method: 'POST', body: '{}' });
                        var credential = (await JoineryPasskeys.derive(options.data.options)).response;
                        var body = { credential: credential, acknowledged: true };
                        if (passphrase) body.passphrase = passphrase;
                        var result = await apiFetch('/api/v1/action/vault_rotate_verify', { method: 'POST', body: JSON.stringify(body) });
                        showCodes(result.data.recovery_codes, null);
                        if (result.data.dropped_passkeys && result.data.dropped_passkeys.length) {
                            JoineryModal.alert('These passkeys need to be re-added to your vault: ' + result.data.dropped_passkeys.map(function (p) { return p.label || 'Passkey'; }).join(', '));
                        }
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not rotate your vault key.');
                    }
                });

                document.getElementById('vault-download-keyfile-btn').addEventListener('click', function () {
                    if (!lastKeyFile) { JoineryModal.alert('No key file available for this action.'); return; }
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
                    if (window.jyMaybeReturn && jyMaybeReturn()) return;
                    // One event refreshes both this panel and the passkey
                    // badges (a rotate drops other passkeys' wrappings).
                    document.dispatchEvent(new CustomEvent('joinery:vault-changed'));
                });

                document.addEventListener('joinery:vault-changed', refresh);
                refresh();
            });
            </script>
            <?php endif; ?>

            <?php
            $sync_devices = $page_vars['sync_devices'] ?? null;
            $has_sync_devices = $sync_devices && count($sync_devices);
            $tz = SessionControl::get_instance()->get_timezone();
            ?>
            <?php if ($has_sync_devices): ?>
            <div class="jy-panel jy-mt-4">
                <h2>Sync Devices</h2>
                <p>Computers syncing your Drive. Unlinking one cuts off its access immediately.</p>

                <table class="jy-table jy-w-full">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Linked</th>
                            <th>Last synced</th>
                            <th>Encrypted folders</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sync_devices as $sync_device):
                        $seen = $sync_device->get('sde_last_seen_time');
                    ?>
                        <tr>
                            <td>
                                <form action="/profile/security" method="POST" class="jy-inline">
                                    <input type="hidden" name="action" value="rename_sync_device">
                                    <input type="hidden" name="sde_sync_device_id" value="<?php echo (int)$sync_device->key; ?>">
                                    <input type="text" name="sde_device_name" maxlength="64"
                                           value="<?php echo htmlspecialchars($sync_device->get('sde_device_name')); ?>"
                                           aria-label="Device name">
                                    <button type="submit" class="btn btn-secondary">Rename</button>
                                </form>
                                <span class="jy-text-muted"><?php echo htmlspecialchars($sync_device->get('sde_platform')); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(LibraryFunctions::convert_time($sync_device->get('sde_create_time'), 'UTC', $tz, 'M j, Y')); ?></td>
                            <td><?php echo $seen
                                ? htmlspecialchars(LibraryFunctions::convert_time($seen, 'UTC', $tz, 'M j, Y g:i A'))
                                : 'Not yet'; ?></td>
                            <td><?php echo $sync_device->get('sde_device_pubkey') ? 'Yes' : 'No'; ?></td>
                            <td class="text-end">
                                <form action="/profile/security" method="POST" class="jy-inline"
                                      data-jy-confirm="Unlink this device? It will stop syncing straight away.">
                                    <input type="hidden" name="action" value="revoke_sync_device">
                                    <input type="hidden" name="sde_sync_device_id" value="<?php echo (int)$sync_device->key; ?>">
                                    <button type="submit" class="btn btn-danger">Unlink</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
                    <?php foreach ($page_vars['app_sessions'] as $app_session):
                        // A sync device's key is already shown, with more detail,
                        // in the panel above — listing it twice would look like
                        // two things signed in.
                        if (isset($page_vars['sync_device_key_ids'][(int)$app_session->key])) { continue; }
                    ?>
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
                      data-jy-confirm="Sign out every device signed in to your account?">
                    <input type="hidden" name="action" value="revoke_all_app_sessions">
                    <button type="submit" class="btn btn-secondary">Revoke All</button>
                </form>
            </div>
            <?php endif; ?>
            <?php
            $recovery_items = $page_vars['recovery_items'] ?? [];
            $recovery_stepup = $page_vars['recovery_stepup'] ?? ['needed' => false, 'passkey' => false];
            $settings = $page_vars['settings'];
            $user = $page_vars['user'];
            $session = SessionControl::get_instance();
            if (!empty($recovery_items)): ?>
            <div class="jy-panel jy-mt-4">
                <h2>Recovery Codes</h2>
                <p class="jy-text-muted">These codes are the way back into your encrypted content if you lose your
                    other sign-in methods. Check one from your saved set now and then — checking never uses a code up.</p>
                <?php
                $rr_client_configs = [];
                foreach ($recovery_items as $rr_i => $rr_item):
                    $rr_is_client = ($rr_item['custody'] ?? '') === 'client';
                    if ($rr_is_client) {
                        $rr_client_configs[$rr_item['scope']] = ['wrappings' => $rr_item['client_wrappings']];
                    }
                ?>
                <div class="jy-mt-3">
                    <h3><?php echo htmlspecialchars($rr_item['title']); ?></h3>
                    <?php if ($rr_item['last_verified']): ?>
                        <?php if ($rr_item['stale']): ?>
                            <div class="jy-alert jy-alert-warning">Last checked <?php echo htmlspecialchars(LibraryFunctions::convert_time($rr_item['last_verified'], 'UTC', $session->get_timezone(), 'M j, Y')); ?> — check a code again.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="jy-alert jy-alert-warning">Never checked — confirm you still have these codes saved.</div>
                    <?php endif; ?>
                    <?php foreach ($rr_item['warnings'] as $rr_warning): ?>
                        <div class="jy-alert jy-alert-warning"><?php echo htmlspecialchars($rr_warning); ?></div>
                    <?php endforeach; ?>
                    <p class="jy-text-muted jy-mt-1">
                        <?php echo (int)$rr_item['facts']['Unused recovery codes']; ?> unused codes ·
                        save them as <code><?php echo htmlspecialchars(str_replace(['{site}', '{account}'], [(string)$settings->get_setting('site_name'), (string)$user->get('usr_email')], $rr_item['label'])); ?></code>
                        <button type="button" class="btn btn-secondary btn-sm" data-jy-copy="<?php echo htmlspecialchars(str_replace(['{site}', '{account}'], [(string)$settings->get_setting('site_name'), (string)$user->get('usr_email')], $rr_item['label']), ENT_QUOTES); ?>">Copy</button>
                    </p>
                    <?php if ($rr_is_client): ?>
                        <label for="rr-m-code-<?php echo $rr_i; ?>" class="d-block">Enter one code (checked in your browser, never sent, never used up):</label>
                        <input type="password" id="rr-m-code-<?php echo $rr_i; ?>" class="form-control" style="max-width:22rem;" autocomplete="off">
                        <button type="button" class="btn btn-primary jy-mt-1"
                            data-rr-client-check data-rr-scope="<?php echo htmlspecialchars($rr_item['scope'], ENT_QUOTES); ?>"
                            data-rr-code="rr-m-code-<?php echo $rr_i; ?>" data-rr-status="rr-m-status-<?php echo $rr_i; ?>">Check code</button>
                        <div id="rr-m-status-<?php echo $rr_i; ?>" class="jy-mt-1"></div>
                        <div data-rr-client-form="<?php echo htmlspecialchars($rr_item['scope'], ENT_QUOTES); ?>" hidden>
                            <form action="/profile/security" method="POST">
                                <input type="hidden" name="action" value="vault_code_check_client">
                                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($rr_item['scope'], ENT_QUOTES); ?>">
                                <input type="hidden" name="passed" value="">
                            </form>
                        </div>
                    <?php else: ?>
                        <form action="/profile/security" method="POST">
                            <input type="hidden" name="action" value="vault_code_check">
                            <input type="hidden" name="scope" value="<?php echo htmlspecialchars($rr_item['scope'], ENT_QUOTES); ?>">
                            <label for="rr-m-code-<?php echo $rr_i; ?>" class="d-block">Enter one code (checked, not used up):</label>
                            <input type="password" id="rr-m-code-<?php echo $rr_i; ?>" name="code" class="form-control" style="max-width:22rem;" autocomplete="off">
                            <button type="submit" class="btn btn-primary jy-mt-1">Check code</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($rr_client_configs)): ?>
            <script src="/assets/js/vault-crypto.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/vault-crypto.js')) ?: '1'; ?>"></script>
            <?php endif; ?>
            <?php if (!empty($recovery_stepup['needed']) && !empty($recovery_stepup['passkey'])): ?>
            <script src="/assets/js/joinery-api.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/joinery-api.js')) ?: '1'; ?>"></script>
            <script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
            <?php endif; ?>
            <script defer src="/assets/js/recovery-readiness.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1'; ?>"></script>
            <script>
            window.rrClientConfigs = <?php echo json_encode($rr_client_configs); ?>;
            window.rrStepup = <?php echo json_encode($recovery_stepup); ?>;
            document.addEventListener('DOMContentLoaded', function () {
                if (window.recoveryReadiness) {
                    window.recoveryReadiness.attachClientChecks();
                    window.recoveryReadiness.attachStepUp(window.rrStepup);
                }
            });
            </script>
            <?php endif; ?>
            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
