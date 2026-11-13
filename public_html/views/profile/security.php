<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('security_logic.php', 'logic'));

	$page_vars = process_logic(security_logic(array_merge($_GET, $_POST, $params ?? [])));

	$page = new PublicPage();
	$page->needs_vault_rotation();
	// Setting up the vault makes its codes and its root vault here, in the
	// browser (specs/one_vault_experience.md § R4, R6).
	$page->needs_vault_client();
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
                echo $page->render_messages('securitybox');
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
                    <?php
                    $cadence_fw = $page->getFormWriter('cadence_form', ['action' => '/profile/security', 'method' => 'POST',
                        'class' => 'jy-mt-2', 'data-stepup' => 'set_cadence']);
                    $cadence_fw->begin_form();
                    $cadence_fw->hiddeninput('action', '', ['value' => 'set_cadence']);
                    $cadence_fw->radioinput('cadence', 'Ask for your second factor', [
                        'options' => [
                            'every_login'    => 'Ask for it at every sign-in',
                            'sensitive_only' => 'Ask for it only at sensitive actions (less secure)',
                        ],
                        'value' => $cadence,
                    ]);
                    $cadence_fw->submitbutton('btn_cadence', 'Save', ['class' => 'btn btn-secondary']);
                    $cadence_fw->end_form();
                    ?>

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

                    <form action="/profile/security" method="POST" class="d-none" id="totp-regen-form" data-stepup="regenerate_backup_codes"
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
                    <form action="/profile/security" method="POST" data-jy-confirm="Remove your recovery address?" data-stepup="remove_recovery_email">
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
                    $recovery_formwriter = $page->getFormWriter('recovery_form', ['action' => '/profile/security', 'method' => 'POST', 'data-stepup' => 'set_recovery_email']);
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
                    <input type="password" id="passkey-current-password" autocomplete="current-password" class="form-control">
                    <div class="jy-mt-2">
                        <button type="button" class="btn btn-primary" id="passkey-password-continue">Continue</button>
                        <button type="button" class="btn btn-secondary" id="passkey-password-cancel">Cancel</button>
                    </div>
                </div>

                <button type="button" class="btn btn-primary jy-mt-2" id="passkey-add-btn">Add a Passkey</button>
                <p class="jy-auth-hint jy-mt-2 d-none" id="passkey-flow-hint"></p>
            </div>

            <?php endif; ?>

            <?php if ($page_vars['settings']->get_setting('passkeys_enabled')): ?>
            <div class="jy-panel jy-mt-4 d-none" id="vault-panel">
                <div class="d-flex justify-content-between align-items-center">
                    <h2>Encrypted Vault</h2>
                    <details class="jy-actions-dropdown d-none" id="vault-actions-dropdown">
                        <summary class="btn btn-secondary">Actions</summary>
                        <div class="jy-actions-menu">
                            <button type="button" id="vault-regenerate-codes-btn">Regenerate Recovery Codes</button>
                            <button type="button" class="d-none" id="vault-passphrase-enroll-btn">Change Passphrase</button>
                            <button type="button" class="d-none" id="vault-passphrase-remove-btn">Remove Passphrase</button>
                            <button type="button" id="vault-download-keyfile-btn">Download Key File (optional)</button>
                            <button type="button" class="jy-action-danger" id="vault-rotate-btn">Rotate Vault Key</button>
                        </div>
                    </details>
                </div>

                <div id="vault-not-set-up">
                    <p>Seal your mail and chat content so it's readable only when you unlock it with a passkey or a recovery code.</p>
                    <p class="d-none" id="vault-needs-passkey"><strong>Add a passkey first.</strong> Your vault opens with a passkey, so set one up under Passkeys above; you can then set up your vault here.</p>
                    <button type="button" class="btn btn-primary" id="vault-setup-btn">Set Up Your Vault</button>
                </div>

                <div class="d-none" id="vault-locked">
                    <p><strong>Status:</strong> Locked</p>
                    <button type="button" class="btn btn-primary" id="vault-unlock-btn">Unlock</button>
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
                    <button type="button" class="btn btn-primary" id="vault-codes-done-btn">Done</button>
                </div>

                <!-- Where this member's Private content may be read by AI
                     (PrivateContentConsent): one answer for Private chats and
                     everything they open — Drive, calendar, mail. Saved through
                     the private_ai_consent action; loosening asks a step-up. -->
                <div class="jy-mt-4" id="private-ai-consent-block">
                    <h3>Private content and AI</h3>
                    <div id="private-ai-consent-alert" hidden></div>
<?php
require_once(PathHelper::getIncludePath('includes/PrivateContentConsent.php'));
$pac_writer = $page->getFormWriter('private-ai-consent-form', array('action' => '/profile/security'));
$pac_writer->begin_form();
$pac_writer->radioinput('private_ai_consent', 'Where your private content may be read by AI', array(
    'options'  => PrivateContentConsent::options(),
    'value'    => PrivateContentConsent::forUser((int)SessionControl::get_instance()->get_user_id()),
    'helptext' => 'Applies to Private chats and to everything they open: your Private files, calendar entries and mail. '
        . 'On the first, a Private chat can only use a model running on hardware this site controls, and asking it to '
        . 'use a cloud model is refused and says why. Loosening it lets that content be sent to such a model, which '
        . 'then holds it in the clear. Tightening it back stops those chats at their next message.',
));
$pac_writer->end_form();
?>
                </div>
            </div>

            <?php endif; ?>

            <?php
            $sync_devices = $page_vars['sync_devices'] ?? null;
            $has_sync_devices = $sync_devices && count($sync_devices);
            $tz = SessionControl::get_instance()->get_timezone();
            ?>
            <?php if ($has_sync_devices): ?>
            <div class="jy-panel jy-mt-4">
                <h2>Linked Devices</h2>
                <p>Computers syncing your Drive and phones holding your end-to-end keys. Unlinking one signs it out straight away.</p>

                <table class="jy-table jy-w-full">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Linked</th>
                            <th>Last seen</th>
                            <th>Vaults</th>
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
                                <span class="jy-text-muted"><?php echo htmlspecialchars(SyncDevice::platform_label((string)$sync_device->get('sde_platform'))); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(LibraryFunctions::convert_time($sync_device->get('sde_create_time'), 'UTC', $tz, 'M j, Y')); ?></td>
                            <td><?php echo $seen
                                ? htmlspecialchars(LibraryFunctions::convert_time($seen, 'UTC', $tz, 'M j, Y g:i A'))
                                : 'Not yet'; ?></td>
                            <td><?php
                                $held = array_map(function ($scope) {
                                    return htmlspecialchars(VaultScopes::labelFor($scope));
                                }, $sync_device->vault_scopes());
                                echo $held ? implode('<br>', $held) : 'None';
                            ?></td>
                            <td class="text-end">
                                <form action="/profile/security" method="POST" class="jy-inline"
                                      data-jy-confirm="Unlink this device? It is signed out straight away.">
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
                <p>Devices and programs signed in to your account. Revoke one to sign it out.</p>

                <table class="jy-table jy-w-full">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>For</th>
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
                            <td><?php
                                // An app credential names its purpose and how it signs in;
                                // a session key is the person's own signed-in app.
                                if ($app_session->is_app_credential()) {
                                    echo htmlspecialchars(AppAccess::label($app_session->purpose()))
                                        . ($app_session->kind_label() !== null
                                            ? ' <span class="jy-text-muted">(' . htmlspecialchars($app_session->kind_label()) . ')</span>' : '');
                                } else {
                                    echo 'Signed-in app';
                                }
                            ?></td>
                            <td><?php echo htmlspecialchars(LibraryFunctions::convert_time($app_session->get('apk_create_time'), 'UTC',
                                SessionControl::get_instance()->get_timezone(), 'M j, Y')); ?></td>
                            <td><?php echo $app_session->get('apk_last_used_time')
                                ? htmlspecialchars(LibraryFunctions::convert_time($app_session->get('apk_last_used_time'), 'UTC',
                                    SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A'))
                                    . ($app_session->get('apk_last_used_ip')
                                        ? '<br><span class="jy-text-muted">from ' . htmlspecialchars($app_session->get('apk_last_used_ip')) . '</span>' : '')
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
            $settings = $page_vars['settings'];
            $user = $page_vars['user'];
            $session = SessionControl::get_instance();
            $through_root_vaults = $page_vars['through_root_vaults'] ?? [];
            $rr_client_configs = [];
            if (!empty($recovery_items) || $through_root_vaults): ?>
            <?php if (!empty($recovery_items)): ?>
            <div class="jy-panel jy-mt-4">
                <h2>Recovery Codes</h2>
                <p class="jy-text-muted">These codes are the way back into your encrypted content if you lose your
                    other sign-in methods. Check one from your saved set now and then — checking never uses a code up.</p>
                <?php
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
                            <div class="jy-alert jy-alert-warning">Last checked <?php echo htmlspecialchars(LibraryFunctions::convert_time($rr_item['last_verified'], 'UTC', $session->get_timezone(), 'M j, Y')); ?><?php echo !empty($rr_item['codes_changed']) ? ', before these codes were made' : ''; ?> — check a code again.</div>
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
                            <form action="/profile/security" method="POST" data-stepup="vault_code_check_client:<?php echo htmlspecialchars($rr_item['scope'], ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="vault_code_check_client">
                                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($rr_item['scope'], ENT_QUOTES); ?>">
                                <input type="hidden" name="passed" value="">
                            </form>
                        </div>
                        <?php
                        $rr_vault = UserEncryptionVault::loadForUser((int)$user->key, $rr_item['scope']);
                        $rr_pending = $rr_vault && $rr_vault->get('uev_pending_key_generation') !== null;
                        ?>
                        <?php if ($rr_item['scope'] !== VaultScopes::ROOT_SCOPE): /* the root's key is not rotated here: it would orphan every content vault */ ?>
                        <div class="jy-mt-3" data-vault-rotate="<?php echo htmlspecialchars($rr_item['scope'], ENT_QUOTES); ?>">
                            <?php if ($rr_pending): ?>
                                <div class="jy-alert jy-alert-warning">A rotation of this vault's key stopped part way. What it already moved to the new key will not open until the rotation is finished, so finish it now.</div>
                            <?php else: ?>
                                <p class="jy-text-muted">Rotating makes a new key for this vault and moves everything onto it, in this browser. It costs new recovery codes, your passphrase again, one tap for each passkey, and linking your computers again.</p>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary" data-vault-rotate-btn><?php echo $rr_pending ? 'Finish rotating this vault\'s key' : 'Rotate this vault\'s key'; ?></button>
                            <div class="jy-mt-1" data-vault-rotate-status role="status"></div>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php
                        $rr_fw = $page->getFormWriter('rr_code_check_' . $rr_i, ['action' => '/profile/security', 'method' => 'POST',
                            'data-stepup' => 'vault_code_check:' . $rr_item['scope']]);
                        $rr_fw->begin_form();
                        $rr_fw->hiddeninput('action', '', ['value' => 'vault_code_check']);
                        $rr_fw->hiddeninput('scope', '', ['value' => $rr_item['scope']]);
                        $rr_fw->passwordinput('code', 'Enter one code (checked, not used up)', ['autocomplete' => 'off',
                            'validation' => ['required' => true, 'pattern' => SealedBox::RECOVERY_CODE_PATTERN,
                                'messages' => ['pattern' => SealedBox::RECOVERY_CODE_SHAPE_MESSAGE]]]);
                        $rr_fw->submitbutton('btn_rr_check_' . $rr_i, 'Check code', ['class' => 'btn btn-primary']);
                        $rr_fw->end_form();
                        ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php
            // A content vault that opens through the root has no card above (the
            // root's codes are its way back in). Rotating its key needs only the
            // root open; the rotate handler below serves both panels.
            if ($through_root_vaults): ?>
            <div class="jy-panel jy-mt-4">
                <h2>Vault Keys</h2>
                <p class="jy-text-muted">Each part of your vault has its own key. Rotating one makes a new key and moves everything
                    under it onto the new key, in this browser. Your vault opens the new key as before: your passkeys, passphrase and
                    recovery codes do not change. Computers linked to it must be linked again.</p>
                <?php foreach ($through_root_vaults as $tr_vault): ?>
                <div class="jy-mt-3" data-vault-rotate="<?php echo htmlspecialchars($tr_vault['scope'], ENT_QUOTES); ?>">
                    <h3><?php echo htmlspecialchars($tr_vault['label']); ?></h3>
                    <?php if ($tr_vault['pending']): ?>
                        <div class="jy-alert jy-alert-warning">A rotation of this key stopped part way. What it already moved to the new key will not open until the rotation is finished, so finish it now.</div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-vault-rotate-btn><?php echo $tr_vault['pending'] ? 'Finish rotating this key' : 'Rotate this key'; ?></button>
                    <div class="jy-mt-1" data-vault-rotate-status role="status"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php echo VaultCrypto::browserScriptTag(); ?>
            <?php echo PageScript::json('rr-page-config', array('client_configs' => (object)$rr_client_configs)); ?>
            <script defer src="/assets/js/recovery-readiness.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1'; ?>"></script>
            <?php endif; ?>
            <?php echo PageScript::json('profile-security-config', array(
                'user_id'  => (int)SessionControl::get_instance()->get_user_id(),
                'lab_hint' => Access::permits(10),
                'stepup'   => $page_vars['stepup'] ?? array('needed' => false, 'passkey' => false),
            )), PageScript::src('assets/js/profile-security.js'); ?>
            <?php echo PublicPage::settings_layout_end(); ?>
        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
