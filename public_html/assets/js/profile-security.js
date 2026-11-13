/*
 * /profile/security: the two-step, passkey, vault, private-AI and key-rotation
 * panels (views/profile/security.php). Each part does nothing when its panel
 * is not on the page. The page's values come from #profile-security-config:
 *   user_id   the signed-in user, for the passkey list;
 *   lab_hint  whether a failed activation may point at /admin/admin_passkey_lab;
 *   stepup    the step-up state handed to JoineryPasskeys.confirmForms.
 *
 * Loaded deferred, after passkeys.js, vault-crypto.js and recovery-readiness.js.
 *
 * @version 1.0
 */
(function () {
'use strict';
var CFG = (window.joineryPageData && joineryPageData('profile-security-config')) || {};

// ── Two-step: the menu's Regenerate and Turn off ──────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('totp-regen-menu-btn')) return;
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

// ── Guided-flow return ────────────────────────────────────────────────────
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

// ── Passkeys ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    var panel = document.getElementById('passkeys-panel');
    if (!panel) return;
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
            // A proxy in front of the site answers with its own web
            // page when the server is slow or down: say that, not a
            // JSON parse error.
            var json;
            try {
                json = await res.json();
            } catch (parseError) {
                throw new Error(window.joineryApi ? joineryApi.notJsonMessage(res.status)
                    : 'The server answered with a web page instead of a result (' + res.status + ').');
            }
            if (!res.ok) {
                // A server refusal carries its one precise reason;
                // the flag lets failure surfaces show it alone
                // instead of guessing at browser-prompt causes.
                var err = new Error(json.error || 'Request failed.');
                err.fromServer = true;
                throw err;
            }
            // Sensitive action needs a fresh second-factor step-up (a 2xx
            // render carrying the flag, not an error): confirm identity, then
            // return here to retry (specs/mailbox_security_levels.md § 5.5).
            // Halt the chain with a never-resolving promise — the page is
            // navigating away, so no caller's catch should fire an alert.
            // withSecondFactor() takes the flag back to try a passkey here first.
            if (json.data && json.data.second_factor_required && !options.stepUpHere) {
                return goVerifyStepUp();
            }
            return json;
        });
    }

    function goVerifyStepUp() {
        window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/profile/security');
        return new Promise(function () {});
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
        createdTd.textContent = passkey.pkc_create_time ? new Date(passkey.pkc_create_time + 'Z').toLocaleDateString() : '';
        tr.appendChild(createdTd);

        var lastUsedTd = document.createElement('td');
        lastUsedTd.textContent = passkey.pkc_last_used_time ? new Date(passkey.pkc_last_used_time + 'Z').toLocaleString() : 'Never';
        tr.appendChild(lastUsedTd);

        var active = !!activeIds[passkey.pkc_passkey_credential_id];
        var capability = passkey.vault_capability || 'unknown';
        if (vaultStatus) {
            var vaultTd = document.createElement('td');
            var badge = document.createElement('span');
            if (active) {
                badge.className = 'badge badge-success';
                badge.textContent = 'Vault active';
            } else if (capability === 'incapable') {
                // Not a warning: nothing is wrong and there is nothing
                // to do. This key does its whole job already.
                badge.className = 'badge badge-muted';
                badge.textContent = 'Sign-in only';
                badge.title = 'This is a U2F-only security key. It signs you in, but it cannot unlock your vault — no setting or PIN changes that.';
            } else {
                badge.className = 'badge badge-warning';
                badge.textContent = 'Not activated';
                badge.title = capability === 'capable'
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
            } else if (capability !== 'incapable') {
                // Offered for 'unknown' too: registration-time reporting
                // is not proof, and the ceremony is the real test. Only
                // a credential that provably cannot derive a secret has
                // the action withheld, because it would fail by
                // construction.
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
        // numperpage is REQUIRED, not a tuning knob: an API collection
        // read defaults to 3 rows per page (docs/api.md), so without it
        // a fourth passkey enrolls successfully and then never appears —
        // unrenamable, unrevokable, and unactivatable, with the browser
        // still refusing to enroll it again because it is already there.
        // This list is never paged, so it asks for all of them.
        return apiFetch('/api/v1/Passkeys?numperpage=200&user_id=' + encodeURIComponent(CFG.user_id || 0) + '').then(function (json) {
            var credentials = json.data || [];
            // A collection read applies no ORDER BY unless one is asked
            // for, so without this the rows arrive in whatever order the
            // database returns them. Oldest first, matching what the
            // Added column implies.
            credentials.sort(function (a, b) {
                return String(a.pkc_create_time || '').localeCompare(String(b.pkc_create_time || ''));
            });
            credentialCount = credentials.length;
            // The vault panel offers setup only to an account with a
            // passkey to wrap the key under; it reads the count here.
            window.joineryPasskeyCount = credentials.length;
            document.dispatchEvent(new CustomEvent('joinery:passkeys-loaded', { detail: { count: credentials.length } }));
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
            // Revoking is a sensitive action: the server asks for a fresh
            // second-factor confirmation.
            await withSecondFactor('/api/v1/action/passkey_revoke',
                JSON.stringify({ credential_id: passkey.pkc_passkey_credential_id }));
            await loadPasskeys();
        } catch (e) {
            JoineryModal.alert(e.message || 'Could not revoke passkey.');
        }
    }

    // Run an action the server answers with second_factor_required until
    // a step-up is fresh. A passkey on this device confirms it right here;
    // when none answers (the one being replaced or revoked may be the
    // lost one), /profile/verify-stepup takes the authenticator app instead and
    // comes back, and the next try needs no confirmation.
    async function withSecondFactor(url, body, hint) {
        var json = await apiFetch(url, { method: 'POST', body: body, stepUpHere: true });
        if (!(json.data && json.data.second_factor_required)) return json;
        if (credentialCount === 0) return goVerifyStepUp();
        if (hint) showFlowHint(hint);
        try {
            await JoineryPasskeys.stepUp();
        } catch (e) {
            return goVerifyStepUp();
        }
        return apiFetch(url, { method: 'POST', body: body });
    }

    // Wrap the vault key under one named passkey (an unlock-capable
    // "activation"). The credential id scopes the ceremony to that
    // passkey — without it the browser would accept any enrolled
    // credential and activate whichever one the user happened to
    // touch, leaving the row they clicked still reading "Not
    // activated". Two prompts: the NEW passkey derives its key,
    // then an unlocker the vault already has confirms it's you —
    // the wrapping is produced only in the request that presents
    // both. Returns the label the server actually activated.
    async function runVaultActivation(credentialId) {
        var options = await apiFetch('/api/v1/action/vault_add_passkey_options', {
            method: 'POST',
            body: JSON.stringify({ credential_id: credentialId, with_root: 1 }),
        });
        // The same touch gives the root vault's output, kept here
        // (specs/one_vault_experience.md § R1).
        var derived = await JoineryPasskeys.derive(options.data.options);
        var unlocker = await JoineryVaultLock.collectUnlocker('to let this passkey open your vault');
        if (!unlocker) { throw new Error('Confirmation was cancelled.'); }
        var result = await apiFetch('/api/v1/action/vault_add_passkey_verify', { method: 'POST', body: JSON.stringify({ credential: derived.response, unlocker: unlocker }) });
        await rootLearnsPasskey(derived.response.rawId || derived.response.id, derived.secondOutput);
        document.dispatchEvent(new CustomEvent('joinery:vault-changed'));
        return (result.data && result.data.label) || 'Passkey';
    }

    // A passkey that opens the account vault also opens the root, so
    // one touch keeps opening everything. Needs the root open here and
    // that passkey's root output; best effort — a passkey the root
    // does not know yet catches up at its first unlock.
    async function rootLearnsPasskey(credentialB64, secondOutput) {
        if (!window.JoinerySealed || !JoinerySealed.rootSession() || !credentialB64) return;
        try {
            if (!secondOutput) return;
            await VaultKeyring.addRootPasskey(JoinerySealed.rootSession(), credentialB64, secondOutput);
        } catch (e) { /* caught up at its first unlock */ }
    }

    // The root output of a passkey registered just now: one touch of
    // it, asked for only while the root is open.
    async function rootLearnsNewPasskey(internalId) {
        if (!window.JoinerySealed || !JoinerySealed.rootSession()) return;
        try {
            var options = await apiFetch('/api/v1/action/vault_add_passkey_options', {
                method: 'POST', body: JSON.stringify({ credential_id: internalId, with_root: 1 }) });
            var derived = await JoineryPasskeys.derive(options.data.options);
            await rootLearnsPasskey(derived.response.rawId || derived.response.id, derived.secondOutput);
        } catch (e) { /* caught up at its first unlock */ }
    }

    // A server refusal (no unlock window, no PRF output, step-up
    // needed) states its one reason — show that reason alone. Only
    // the browser prompt itself is opaque: it reports refusal,
    // cancel, wrong authenticator and a PIN-less key identically,
    // by design, so only that path names the live possibilities —
    // and says which passkey was expected, since tapping the wrong
    // authenticator fails here rather than silently activating it.
    function activationFailureMessage(passkey, err) {
        var label = passkey.pkc_label || 'this passkey';
        var labHint = CFG.lab_hint ? ' Test the authenticator one variable at a time at /admin/admin_passkey_lab.' : '';
        if (err && err.fromServer) {
            return 'Could not activate "' + label + '" for your vault: ' + err.message + labHint;
        }
        return 'Could not activate "' + label + '" for your vault — the browser reported '
            + 'a failed prompt without saying why (it never does). Make sure you answer the '
            + 'prompt with "' + label + '" itself, not another passkey; a security key also '
            + 'needs a PIN set. If you cancelled, just try again.'
            + (err && err.message ? ' (' + err.message + ')' : '')
            + labHint;
    }

    async function activateForVault(passkey) {
        if (!await JoineryModal.confirmAsync('Activate "' + (passkey.pkc_label || 'this passkey') + '" for your vault? Use that passkey when the browser prompts.', { confirmLabel: 'Activate', confirmStyle: 'primary' })) return;
        try {
            var activated = await runVaultActivation(passkey.pkc_passkey_credential_id);
            JoineryModal.alert('"' + activated + '" can now unlock your vault.');
        } catch (e) {
            JoineryModal.alert(activationFailureMessage(passkey, e));
        }
    }

    async function deactivateForVault(passkey) {
        if (!await JoineryModal.confirmAsync('Deactivate "' + (passkey.pkc_label || 'this passkey') + '" for your vault? It will still sign you in, but can no longer unlock your sealed content.', { confirmLabel: 'Deactivate' })) return;
        try {
            // Sensitive: the server demands a recent step-up.
            await withSecondFactor('/api/v1/action/vault_passkey_deactivate',
                JSON.stringify({ credential_id: passkey.pkc_passkey_credential_id }));
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
            var body = {};
            if (currentPassword) body.current_password = currentPassword;
            var options = await withSecondFactor('/api/v1/action/passkey_register_options', JSON.stringify(body),
                'Step 1 of 2 — Confirm it\'s you with a passkey you already have. The prompt to create the new one comes next.');
            if (credentialCount > 0) {
                showFlowHint('Step 2 of 2 — Now create the new passkey. This is where you pick a security key or another device.');
            }
            var credential = await JoineryPasskeys.register(options.data.options);
            // Vault-active by default: the creation just derived the
            // vault key, and the same request can write its wrapping
            // when an unlocker the vault already has confirms it's
            // you. Backing out here just leaves the passkey enrolled
            // and not yet activated — the Activate action remains.
            var unlocker = null;
            if (vaultStatus && window.JoineryVaultLock) {
                showFlowHint('One more step — confirm with a passkey, your passphrase or a recovery code you already have, so the new passkey can open your vault too.');
                try {
                    unlocker = await JoineryVaultLock.collectUnlocker('to let the new passkey open your vault');
                } catch (e) {
                    unlocker = null;
                }
            }
            var regResult = await apiFetch('/api/v1/action/passkey_register_verify', {
                method: 'POST',
                body: JSON.stringify({ credential: credential, label: label, unlocker: unlocker }),
            });
            // First factor enrolled: sign-in behavior just changed — say so
            // inline, once, right where the enrollment happened.
            if (regResult.data && regResult.data.became_second_factor) {
                document.getElementById('passkey-second-factor-notice').classList.remove('d-none');
            }
            pwRow.classList.add('d-none');
            pwInput.value = '';
            await reloadAll();

            var newPasskey = (regResult.data && regResult.data.passkey) || {};
            var newId = newPasskey.pkc_passkey_credential_id;

            // A U2F-only key can never unlock a vault, so running the
            // activation ceremony on it would only produce the browser's
            // opaque "this security key can't be used". Say so here
            // instead, and make the user answer for it.
            //
            // The modal necessarily lands AFTER the credential exists —
            // capability is only knowable from the registration response,
            // so there is nothing to warn about until the key is
            // enrolled. That is why the second button removes it rather
            // than cancelling: the choice offered has to be a real one.
            if (vaultStatus && newPasskey.vault_capability === 'incapable') {
                var remove = await JoineryModal.confirmAsync(
                    'Your passkey was added and will sign you in. But this is a U2F-only security key: '
                    + 'it cannot unlock your encrypted vault, and no PIN or setting will change that. '
                    + 'Keep it as a sign-in key, or remove it?',
                    { confirmLabel: 'Remove it', cancelLabel: 'Keep for sign-in', confirmStyle: 'danger' });
                if (remove) {
                    try {
                        // The step-up at the top of this flow already
                        // satisfies the server's recent-second-factor
                        // demand, so there is no second prompt here.
                        await apiFetch('/api/v1/action/passkey_revoke', {
                            method: 'POST',
                            body: JSON.stringify({ credential_id: newId }),
                        });
                        await reloadAll();
                    } catch (e) {
                        JoineryModal.alert(e.message || 'Could not remove the passkey. You can remove it from its Actions menu.');
                    }
                    // Nothing was enrolled in the end, so a guided flow
                    // that sent the user here has not been satisfied —
                    // stay put rather than returning as if it had.
                    return;
                }
                if (window.jyMaybeReturn && jyMaybeReturn()) return;
                return;
            }

            // The activation rode inside passkey_register_verify
            // (the reload above already painted the Vault active
            // badge). A refusal says why; a cancelled confirmation
            // says nothing and leaves the Activate action in the
            // passkey's menu.
            if (regResult.data && !regResult.data.vault_activated && regResult.data.vault_activation_error) {
                JoineryModal.alert('The passkey was added, but is not activated for your vault yet: '
                    + regResult.data.vault_activation_error + ' You can activate it any time from its Actions menu.');
            } else if (regResult.data && regResult.data.vault_activated && newId) {
                showFlowHint('Last step — tap the new passkey once more so it opens your end-to-end content too.');
                await rootLearnsNewPasskey(newId);
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

// ── Private AI consent, then the vault ────────────────────────────────────
// Where private content may be read by AI: a card click saves at
// once; loosening confirms it is you (a passkey in place, or the
// confirmation page), then saves.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('private-ai-consent-form');
    var note = document.getElementById('private-ai-consent-alert');
    if (!form || !window.joineryApi) return;
    var radios = Array.prototype.slice.call(form.querySelectorAll('[name="private_ai_consent"]'));
    var current = (form.querySelector('[name="private_ai_consent"]:checked') || {}).value || 'local';
    function say(msg, kind) { note.hidden = !msg; note.className = 'alert alert-' + (kind || 'info'); note.textContent = msg || ''; }
    function reset() { radios.forEach(function (r) { r.checked = (r.value === current); }); }
    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked || radio.value === current) return;
            var call = function () { return joineryApi.post('private_ai_consent', { action: 'save', consent: radio.value }); };
            var run = (window.JoineryPasskeys && JoineryPasskeys.withStepUp)
                ? JoineryPasskeys.withStepUp(call, location.pathname + '#private-ai-consent-block') : call();
            run.then(function (d) {
                current = (d && d.consent) || radio.value;
                reset();
                say('Saved. Private chats follow this from their next message.', 'success');
            }).catch(function (err) {
                reset();
                say((err && err.message) || 'The change could not be saved.', 'danger');
            });
        });
    });
});
document.addEventListener('DOMContentLoaded', function () {
    var panel = document.getElementById('vault-panel');
    if (!panel) return;
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

    // Setup wraps the key under a passkey: with none enrolled, say
    // so in place of a button that could only fail.
    function applyPasskeyGate(count) {
        document.getElementById('vault-needs-passkey').classList.toggle('d-none', count > 0);
        document.getElementById('vault-setup-btn').classList.toggle('d-none', count === 0);
    }
    document.addEventListener('joinery:passkeys-loaded', function (e) { applyPasskeyGate(e.detail.count); });
    if (typeof window.joineryPasskeyCount === 'number') { applyPasskeyGate(window.joineryPasskeyCount); }

    function apiFetch(url, options) {
        options = options || {};
        options.headers = Object.assign({ 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf }, options.headers || {});
        return fetch(url, options).then(async function (res) {
            // A proxy in front of the site answers with its own web
            // page when the server is slow or down: say that, not a
            // JSON parse error.
            var json;
            try {
                json = await res.json();
            } catch (parseError) {
                throw new Error(window.joineryApi ? joineryApi.notJsonMessage(res.status)
                    : 'The server answered with a web page instead of a result (' + res.status + ').');
            }
            if (!res.ok) {
                var err = new Error(json.error || 'Request failed.');
                err.data = json.data || {};
                throw err;
            }
            // Shared second-factor step-up handling (§ 5.5): a 2xx render
            // carrying the flag redirects to the ceremony, then back here.
            // Never-resolving promise halts the chain during navigation.
            if (json.data && json.data.second_factor_required) {
                window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/profile/security');
                return new Promise(function () {});
            }
            return json;
        });
    }

    function showCodes(recoveryCodes) {
        codesPre.textContent = recoveryCodes.join('\n');
        notSetUp.classList.add('d-none');
        locked.classList.add('d-none');
        unlocked.classList.add('d-none');
        setActionsVisible(false);
        codesDisplay.classList.remove('d-none');
    }

    var unlockerNames = { passkey: 'Passkey', recovery: 'Recovery code', passphrase: 'Passphrase' };
    var hasPassphrase = false;
    var lastStatus = null;

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
            lastStatus = status;
            hasPassphrase = !!status.has_passphrase;
            // Presence beacon (assets/js/vault-presence.js): follow the
            // window state, so an unlock on this page starts site-wide
            // presence without a reload and a lock stops it.
            if (window.JoineryVaultPresence) {
                if (status.set_up && status.unlocked) { JoineryVaultPresence.start(); }
                else { JoineryVaultPresence.stop(); }
            }
            codesDisplay.classList.add('d-none');
            // A passphrase exists only where no passkey can hold the key
            // (specs/one_vault_experience.md § R8): offered only then.
            document.getElementById('vault-passphrase-enroll-btn').classList.toggle('d-none', !status.passphrase_allowed);
            document.getElementById('vault-passphrase-remove-btn').classList.toggle('d-none', !status.has_passphrase);
            if (!status.set_up) {
                notSetUp.classList.remove('d-none');
                locked.classList.add('d-none');
                unlocked.classList.add('d-none');
                setActionsVisible(false);
            } else if (!status.unlocked) {
                notSetUp.classList.add('d-none');
                locked.classList.remove('d-none');
                unlocked.classList.add('d-none');
                // Every action here confirms with an unlocker of its
                // own, so none needs the window open first.
                setActionsVisible(true);
            } else {
                notSetUp.classList.add('d-none');
                locked.classList.add('d-none');
                unlocked.classList.remove('d-none');
                setActionsVisible(true);
                renderWrappings(status);
            }
        });
    }

    // The root vault's session, opening it (the one unlock) when shut:
    // new codes and a new phrase are made for both halves at once.
    // Always BEFORE the action's own passkey ceremony: every vault-kek
    // ceremony keeps one pending challenge per session, so an unlock
    // run between an assertion and its post would replace the
    // challenge that assertion was signed against.
    async function rootOpen() {
        if (JoinerySealed.rootSession()) return JoinerySealed.rootSession();
        return JoinerySealed.session(VaultKeyring.ROOT, { reason: 'to change what opens it' });
    }

    // Code regeneration and a passphrase change need a recent step-up
    // as well as an unlocker. Ask for it FIRST: refused at the end, the
    // person has done every other ceremony for nothing and the page
    // leaves for /profile/verify-stepup, dropping the click. A passkey here
    // confirms it in place; otherwise the authenticator app on
    // /profile/verify-stepup, which comes back to this page.
    async function stepUpFirst() {
        var st = (await apiFetch('/api/v1/action/vault_status', { method: 'POST', body: '{}' })).data;
        if (!st.step_up_outstanding) return true;
        try {
            await JoineryPasskeys.stepUp();
            return true;
        } catch (e) {
            window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/profile/security');
            return false;
        }
    }

    // The fresh unlocker an enrolment presents, and the root it is made
    // with. The unlocker FIRST: its own ceremony opens the root on the
    // way (one touch, not two), and that root comes from the server's
    // current wrapping. Only when it could not (a passkey with no root
    // wrapping) is the root opened by its own ceremony, and the unlocker
    // collected again: that ceremony replaced the one the first unlocker
    // was signed against.
    async function unlockerAndRoot(purpose) {
        var unlocker = await JoineryVaultLock.collectUnlocker(purpose);
        if (!unlocker) return null;
        var root = JoinerySealed.rootSession();
        if (!root) {
            root = await rootOpen();
            unlocker = await JoineryVaultLock.collectUnlocker(purpose);
            if (!unlocker) return null;
        }
        return { unlocker: unlocker, root: root };
    }

    function secondFactorRedirect(data) {
        if (data && data.second_factor_required) {
            window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/profile/security');
            return true;
        }
        return false;
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
            var made = await VaultKeyring.setupVault({ acknowledged: true });
            JoinerySealed.adopt(VaultKeyring.ROOT, made.rootSession);
            showCodes(made.codes);
        } catch (e) {
            // The hardware-limit refusal reveals the compatibility
            // fallback here too — this page is the step's permanent
            // home. Eligibility stays server-decided: the action
            // refuses an account with any working passkey route.
            if (e.data && e.data.prf_unsupported) {
                if (!await JoineryModal.confirmAsync('This passkey cannot hold an encryption key - a limit of the device or security key itself, not a setting. A passkey from a newer phone, laptop or password manager is the better route. If none of your passkeys can, you can unlock with a memorized passphrase instead - weaker than a passkey: everything in your vault, end-to-end content included, is then as safe from a broken-into server as the passphrase is hard to guess. If you forget it and lose your recovery codes, the data is gone for good. Use a passphrase?', { confirmLabel: 'Use a passphrase' })) return;
                var phrase = await JoineryModal.promptAsync('Set a passphrase (12+ characters):', { inputType: 'password', confirmLabel: 'Create' });
                if (!phrase) return;
                if (phrase.length < 12) { JoineryModal.alert('Use a passphrase of at least 12 characters.'); return; }
                try {
                    var created = await VaultKeyring.setupVault({ acknowledged: true, passphrase: phrase });
                    if (secondFactorRedirect(created.result)) return;
                    JoinerySealed.adopt(VaultKeyring.ROOT, created.rootSession);
                    showCodes(created.codes);
                } catch (e2) {
                    JoineryModal.alert(e2.message || 'Could not set up your vault.');
                }
                return;
            }
            JoineryModal.alert(e.message || 'Could not set up your vault.');
        }
    });

    document.getElementById('vault-unlock-btn').addEventListener('click', async function () {
        if (await JoineryVaultLock.unlock()) { await refresh(); }
    });

    document.getElementById('vault-lock-btn').addEventListener('click', async function () {
        await JoineryVaultLock.lock();
        await refresh();
    });

    document.getElementById('vault-regenerate-codes-btn').addEventListener('click', async function () {
        if (!await JoineryModal.confirmAsync('This replaces all your recovery codes, for all of your vault. Continue?', { confirmLabel: 'Regenerate' })) return;
        try {
            if (!await stepUpFirst()) return;
            var got = await unlockerAndRoot('to replace your recovery codes');
            if (!got) return;
            var st = (await apiFetch('/api/v1/action/vault_status', { method: 'POST', body: '{}' })).data;
            var fresh = await VaultKeyring.replacementCodes(st.root, got.root);
            await apiFetch('/api/v1/action/vault_regenerate_codes', { method: 'POST', body: JSON.stringify({
                unlocker: got.unlocker, code_set: fresh.code_set, root_wrappings: fresh.root_wrappings }) });
            showCodes(fresh.codes);
        } catch (e) {
            JoineryModal.alert(e.message || 'Could not regenerate recovery codes.');
        }
    });

    document.getElementById('vault-passphrase-enroll-btn').addEventListener('click', async function () {
        if (!await JoineryModal.confirmAsync('Your passkeys cannot hold an encryption key, so your vault opens with a passphrase. It opens everything in your vault, end-to-end content included, which is then as safe from a broken-into server as the passphrase is hard to guess. Use something long and unique.', { confirmLabel: 'I understand' })) return;
        var passphrase = await JoineryModal.promptAsync('Set a new passphrase (12+ characters):', { inputType: 'password', confirmLabel: 'Save' });
        if (!passphrase) return;
        if (passphrase.length < 12) { JoineryModal.alert('Use a passphrase of at least 12 characters.'); return; }
        try {
            if (!await stepUpFirst()) return;
            var got = await unlockerAndRoot('to change your passphrase');
            if (!got) return;
            var st = (await apiFetch('/api/v1/action/vault_status', { method: 'POST', body: '{}' })).data;
            var halves = await VaultKeyring.passphraseEnrolment(st.root, got.root, passphrase);
            await apiFetch('/api/v1/action/vault_passphrase_enroll', { method: 'POST', body: JSON.stringify({
                unlocker: got.unlocker, passphrase_kek: halves.passphrase_kek, root_passphrase: halves.root_passphrase }) });
            await refresh();
            JoineryModal.alert('Passphrase changed.');
        } catch (e) {
            JoineryModal.alert(e.message || 'Could not change your passphrase.');
        }
    });

    document.getElementById('vault-passphrase-remove-btn').addEventListener('click', async function () {
        if (!await JoineryModal.confirmAsync('Remove your passphrase?', { confirmLabel: 'Remove' })) return;
        try {
            await apiFetch('/api/v1/action/vault_passphrase_remove', { method: 'POST', body: '{}' });
            await refresh();
        } catch (e) {
            JoineryModal.alert(e.message || 'Could not remove your passphrase.');
        }
    });

    document.getElementById('vault-rotate-btn').addEventListener('click', async function () {
        var keepPhrase = hasPassphrase && lastStatus && lastStatus.passphrase_allowed;
        if (!await JoineryModal.confirmAsync('Rotate your vault key now? Passkeys other than the one you use here' + (keepPhrase ? ', and your passphrase unless re-entered,' : '') + ' will need to be re-added afterward, and you get new recovery codes. Continue?', { confirmLabel: 'Rotate' })) return;
        var passphrase = '';
        if (keepPhrase) {
            passphrase = await JoineryModal.promptAsync('Re-enter your passphrase to carry it forward, or leave blank to drop it:', { inputType: 'password', confirmLabel: 'Continue' });
            if (passphrase === null) return;
        }
        try {
            var root = await rootOpen();
            var st = (await apiFetch('/api/v1/action/vault_status', { method: 'POST', body: '{}' })).data;
            var options = await apiFetch('/api/v1/action/vault_rotate_options', { method: 'POST', body: '{}' });
            var derived = await JoineryPasskeys.derive(options.data.options);
            var fresh = await VaultKeyring.replacementCodes(st.root, root);
            var body = { credential: derived.response, acknowledged: true, code_set: fresh.code_set,
                root_unlockers: { recovery: fresh.root_wrappings } };
            if (passphrase) {
                var halves = await VaultKeyring.passphraseEnrolment(st.root, root, passphrase);
                body.passphrase_kek = halves.passphrase_kek;
                body.root_unlockers.passphrase = [halves.root_passphrase];
            }
            var result = await apiFetch('/api/v1/action/vault_rotate_verify', { method: 'POST', body: JSON.stringify(body) });
            if (result.data.completed_pending) {
                // This finished a rotation that had stopped: the codes made
                // here were not used, and the ones the stopped attempt made
                // were never shown.
                await refresh();
                JoineryModal.alert('Your key rotation is finished. Its recovery codes were never shown, so make a new set now: Actions → Regenerate Recovery Codes.');
            } else {
                showCodes(fresh.codes);
            }
            if (result.data.resealing_in_background) {
                JoineryModal.alert('Your vault is on its new key. Save the recovery codes shown now. '
                    + 'Your stored content is being re-secured under the new key in the background; '
                    + 'with a lot of mail this takes a few minutes, and until it finishes some older items may not open.');
            }
            if (result.data.dropped_passkeys && result.data.dropped_passkeys.length) {
                JoineryModal.alert('These passkeys need to be re-added to your vault: ' + result.data.dropped_passkeys.map(function (p) { return p.label || 'Passkey'; }).join(', '));
            }
        } catch (e) {
            JoineryModal.alert(e.message || 'Could not rotate your vault key.');
        }
    });

    // An offline copy of the locked key: with one recovery code it
    // rebuilds the key without this server. Nothing in it is secret,
    // so it is built fresh on request and always matches the vault.
    document.getElementById('vault-download-keyfile-btn').addEventListener('click', async function () {
        actionsDd.removeAttribute('open');
        var keyFile;
        try {
            keyFile = (await apiFetch('/api/v1/action/vault_key_file', { method: 'POST', body: '{}' })).data.key_file;
        } catch (e) {
            JoineryModal.alert(e.message || 'Could not build your key file.');
            return;
        }
        var blob = new Blob([JSON.stringify(keyFile, null, 2)], { type: 'application/json' });
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

// ── Rotating a browser-held vault's key ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // recovery-readiness.js wires the code checks from #rr-page-config itself.
    // Rotating a browser-held vault's key runs here, in the browser.
    var ROTATE_RESUME = 'joinery_vault_rotate_resume';
    document.querySelectorAll('[data-vault-rotate]').forEach(function (card) {
        var scope = card.getAttribute('data-vault-rotate');
        var btn = card.querySelector('[data-vault-rotate-btn]');
        var status = card.querySelector('[data-vault-rotate-status]');
        // Back from /profile/verify-stepup for this rotation: the confirmation is
        // given, and the rotation's own passkey prompts need a click.
        try {
            var back = JSON.parse(sessionStorage.getItem(ROTATE_RESUME) || 'null');
            if (back && back.scope === scope && Date.now() - back.t < 180000) {
                sessionStorage.removeItem(ROTATE_RESUME);
                status.textContent = 'Confirmed. Click the button again to rotate.';
                btn.focus();
            }
        } catch (x) {}
        btn.addEventListener('click', function rotate(ev, confirmed) {
            if (!window.JoinerySealed) { status.textContent = 'Rotating is unavailable on this page.'; return; }
            btn.disabled = true;
            status.textContent = '';
            JoinerySealed.resealScope(scope, { progress: function (t) { status.textContent = t; } })
                .then(function (r) {
                    var skipped = (r && r.skipped) || [];
                    status.textContent = 'Done. The vault is on its new key' + (r && r.key_generation ? ' (generation ' + r.key_generation + ')' : '') + '. Link your computers again to give them the new key.'
                        + (skipped.length ? ' ' + skipped.length + ' item' + (skipped.length === 1 ? '' : 's') + ' could not be opened with the old key either and ' + (skipped.length === 1 ? 'was' : 'were') + ' left as ' + (skipped.length === 1 ? 'it was' : 'they were') + ' (' + skipped.slice(0, 5).join(', ') + (skipped.length > 5 ? ', …' : '') + ').' : '');
                    if (!skipped.length) setTimeout(function () { window.location.reload(); }, 2500);
                })
                .catch(function (e) {
                    btn.disabled = false;
                    if (e && e.data && e.data.requires_stepup && !confirmed) {
                        // Confirm with a passkey here and carry on; without
                        // one, the trip to /profile/verify-stepup remembers the scope.
                        status.textContent = 'Confirm it is you to rotate.';
                        JoineryPasskeys.stepUp().then(function () { rotate(ev, true); }, function () {
                            try { sessionStorage.setItem(ROTATE_RESUME, JSON.stringify({ t: Date.now(), scope: scope })); } catch (x) {}
                            window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/profile/security');
                        });
                        return;
                    }
                    var msg = (e && e.message) || 'The rotation stopped.';
                    status.textContent = /cancel/i.test(msg) ? '' : msg;
                });
        });
    });
});

// ── Step-up forms ─────────────────────────────────────────────────────────
// Every form here that posts a step-up-gated action is marked
// data-stepup: confirmed with a passkey before it posts, and carried
// across a trip to /profile/verify-stepup.
document.addEventListener('DOMContentLoaded', function () {
    if (window.JoineryPasskeys && JoineryPasskeys.confirmForms) {
        JoineryPasskeys.confirmForms(CFG.stepup || { needed: false, passkey: false });
    }
});
})();
