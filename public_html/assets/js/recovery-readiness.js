/**
 * Recovery Readiness — in-page verify tools.
 *
 * Ceremony: the operator pastes a recovery private key; the challenge the
 * server sealed to the configured public key is opened right here (an X-Wing
 * v2 seal under the challenge's own scope, BackupRecoveryKey::browser_challenge,
 * through VaultCrypto). The key never leaves the page: no fetch, no form
 * field, cleared the moment it is used. Only the recovered proof string —
 * public by construction — goes into the form.
 *
 * Generation: the same code makes the keypair in the first place, so
 * setting up backup recovery needs no shell. The private half (a 32-byte
 * X-Wing seed) is shown once, copied or downloaded by the operator, and never
 * sent anywhere. Setup is one
 * screen: the operator pastes the key back from wherever they saved it, and
 * one button saves the public half (backup_recovery_save) and proves the
 * pasted copy against the challenge the server sealed to what it actually
 * stored (backup_recovery_prove). The page deliberately does NOT use its
 * in-memory copy for that proof — the ceremony's job is proving the copy that
 * was SAVED works, and auto-proving would pass just as happily for someone who
 * closed the tab without saving.
 *
 * Client vault checks: for client-custody vaults the recovery code must never
 * reach the server, so the dry run happens here too — derive the KEK, attempt
 * the unwrap against the user's own wrapping rows, report pass/fail. Nothing
 * is consumed and nothing secret is transmitted.
 *
 * Backup key box: a restore of a backup another server made carries the box
 * its key is sealed in to the recovery key. The approval works out, from the
 * same pasted key (or the earlier key the backup was sealed to, pasted in a
 * box of its own), the one value that opens that box, which the agent
 * finishes opening (VaultCrypto.halfOpenKeyBox: the dek-scope key of a v2 box,
 * the X25519 of a v1 one). That value and the key's public half go into the
 * form; the key does not.
 *
 * Needs assets/js/vault-crypto.js on the page before it (every emitter puts
 * it there).
 *
 * @version 2.0.0 - X-Wing (specs/post_quantum_sealing.md WP2): the keypair, the challenge and the key box
 *                  go through VaultCrypto; this file has no crypto of its own
 * @version 1.4.0 - the approval opens a backup's key box with the pasted key (openKeyBox)
 * @version 1.3.1 - a comment names ApprovalChallengePanel, the one approval screen for every scope
 * @version 1.3.0 - the save call carries the generator config's rotate flag, so the
 *                  rotation screen replaces a proven key through the same ceremony
 * @version 1.2.1 - a failed generation probe reveals the no-WebCrypto line
 *                  instead of staying silent (the by-hand fold is gone)
 * @version 1.2.0 - one-screen setup: paste-back match + save/prove via the
 *                  backup_recovery_save / backup_recovery_prove API actions
 * @version 1.1.0 - keypair generation in the page (generateKeypair/attachGenerator)
 *                  and self-attachment from window.rrPanel
 * @version 1.0.0
 */
window.recoveryReadiness = (function () {
	'use strict';

	function VC() {
		if (!window.VaultCrypto) {
			throw new Error('The page\'s encryption script did not load. Reload the page and try again.');
		}
		return window.VaultCrypto;
	}

	/**
	 * Mint an X-Wing recovery keypair in this page.
	 *
	 * The encoding is the contract with everything else that touches this key —
	 * the ceremony below, escrow_keypair.php, BackupEnvelope on the server — so
	 * both halves come out exactly as the CLI writes them: one line of standard
	 * base64 each, the private key the raw 32-byte seed, the public key the
	 * 1216-byte X-Wing key derived from it.
	 *
	 * @returns {Promise<{privateKeyB64: string, publicKeyB64: string}>}
	 */
	async function generateKeypair() {
		var vc = VC();
		if (!(await vc.isSupported())) {
			throw new Error('This browser cannot make a recovery key — use the command shown below instead.');
		}
		var pair = await vc.generateVaultKeypair();
		var out = { privateKeyB64: vc.b64encode(pair.secretKeyBytes), publicKeyB64: pair.publicKeyB64 };
		pair.secretKeyBytes.fill(0);
		return out;
	}

	/**
	 * Open a sealed challenge with a pasted private key: a v2 seal to the
	 * 1216-byte public key under `scope` (the challenge's own label, e.g.
	 * BackupRecoveryKey::BROWSER_INFO or an approval's scope).
	 * @returns {Promise<string>} the recovered proof string
	 */
	async function openChallenge(challengeB64, privateKeyB64, publicKeyB64, scope) {
		var vc = VC();
		var priv;
		try {
			priv = vc.b64decode(String(privateKeyB64).replace(/\s+/g, ''));
		} catch (e) {
			throw new Error('That is not a recovery key — it should be one line of base64.');
		}
		if (priv.length !== 32) {
			throw new Error('That is not a recovery key: expected 32 bytes, got ' + priv.length +
				'. Paste the private key exactly as your password manager holds it.');
		}
		if (vc.b64decode(publicKeyB64).length !== vc.XWING_PUBLIC_BYTES) {
			priv.fill(0);
			throw new Error('The recovery key saved here is an old X25519 key, which nothing opens with any more. ' +
				'Set up a new recovery key first.');
		}
		var plain;
		try {
			plain = await vc.openChallenge(challengeB64, priv, publicKeyB64, scope || 'joinery-backup-recovery-possession:');
		} catch (e) {
			throw new Error('That key does not open this challenge. It is not the private half of the ' +
				'public key saved here — check you copied the right entry.');
		} finally {
			priv.fill(0);
		}
		return new TextDecoder().decode(plain);
	}

	/**
	 * Work out what opens a backup's key box with a pasted recovery key, after
	 * checking the key is the one the box is sealed to.
	 * `box` is { version, ciphertext (v2), ephemeralPublic (v1), fingerprint }.
	 * @returns {Promise<{shared: string, pub: string}>} both base64
	 */
	async function openKeyBox(privateKeyB64, box) {
		return VC().halfOpenKeyBox(box, privateKeyB64, box.fingerprint);
	}

	function attachCeremony(c) {
		var keyInput = document.getElementById(c.keyInputId);
		var button = document.getElementById(c.buttonId);
		var status = document.getElementById(c.statusId);
		var proofInput = document.getElementById(c.proofId);
		if (!keyInput || !button || !status || !proofInput) return;
		var box = c.keyBox || null;
		var boxInput = box && box.keyInputId ? document.getElementById(box.keyInputId) : null;

		function say(message, ok) {
			status.textContent = message;
			status.className = ok ? 'text-success small mt-2' : 'text-danger small mt-2';
		}

		button.addEventListener('click', function () {
			var pasted = keyInput.value.trim();
			if (!pasted) { say('Paste your recovery key first.', false); return; }

			var boxKey = boxInput ? boxInput.value.trim() : pasted;
			if (box && !boxKey) { say('Paste the recovery key this backup was sealed to as well.', false); return; }

			button.disabled = true;
			say('Checking in this browser…', true);
			// The backup's key box first, when there is one: its value goes into
			// the form beside the answer, and the answer submits the form.
			var opened = box ? openKeyBox(boxKey, box).then(function (r) {
				document.getElementById(box.sharedId).value = r.shared;
				document.getElementById(box.publicId).value = r.pub;
				if (boxInput) { boxInput.value = ''; }
			}) : Promise.resolve();
			opened.then(function () {
				return openChallenge(c.challenge, pasted, c.publicKey, c.infoPrefix);
			}).then(function (proof) {
				keyInput.value = ''; // the key is done with — do not keep it around
				proofInput.value = proof;
				say('Your key opened the challenge — recording…', true);
				// One button: the recovered proof (public by construction) is
				// submitted immediately; there is no decision between the two
				// steps. requestSubmit, not submit(), so form listeners run.
				var form = proofInput.form;
				if (form && form.requestSubmit) { form.requestSubmit(); }
				else if (form) { form.submit(); }
			}).catch(function (err) {
				say(err.message || String(err), false);
				button.disabled = false;
			});
		});
	}

	/**
	 * Wire the one-screen recovery setup: generate, save the key somewhere
	 * real, paste it back, one button saves and verifies.
	 *
	 * The Generate button is hidden in the markup and revealed only once a
	 * throwaway keypair has actually been made here — offering a button which
	 * fails on click would be worse than not offering it, on the one page whose
	 * job is making sure recovery works.
	 *
	 * The paste-back IS the save confirmation: it must match the generated key
	 * before anything is sent, and the possession proof is then computed from
	 * the PASTED copy against a challenge the server seals to what it stored —
	 * so a key that never made it into the password manager, or a public half
	 * corrupted anywhere between this page and the settings table, both fail
	 * here instead of at disaster time.
	 */
	function attachGenerator(c) {
		var button = document.getElementById(c.buttonId);
		var box = document.getElementById(c.boxId);
		var result = document.getElementById(c.resultId);
		var privateOut = document.getElementById(c.privateOutId);
		var status = document.getElementById(c.statusId);
		var paste = document.getElementById(c.pasteId);
		var save = document.getElementById(c.saveId);
		var saveStatus = document.getElementById(c.saveStatusId);
		var copyButton = document.getElementById(c.copyButtonId);
		var download = document.getElementById(c.downloadId);
		if (!button || !box || !result || !privateOut || !paste || !save) return;

		var pair = null;

		function say(message, ok) {
			if (!status) return;
			status.textContent = message;
			status.className = ok ? 'text-success small' : 'text-danger small';
		}

		function saySave(message, ok) {
			if (!saveStatus) return;
			saveStatus.textContent = message;
			saveStatus.className = ok ? 'text-success small' : 'text-danger small';
		}

		// Probe before offering. A key made and thrown away here proves the
		// whole path works, the X-Wing module included.
		generateKeypair().then(function () {
			box.hidden = false;
		}).catch(function () {
			// No generation on this browser: say so, plainly — this is the one
			// setup path, so silence would leave a heading over nothing.
			var note = document.getElementById(c.noCryptoId);
			if (note) note.hidden = false;
		});

		button.addEventListener('click', function () {
			button.disabled = true;
			generateKeypair().then(function (p) {
				pair = p;
				privateOut.value = pair.privateKeyB64;
				result.hidden = false;

				if (download) {
					var blob = new Blob([pair.privateKeyB64 + '\n'], { type: 'text/plain' });
					download.href = URL.createObjectURL(blob);
				}
				button.textContent = 'Key generated';
				say('', true);
				privateOut.focus();
				privateOut.select();
			}).catch(function (err) {
				button.disabled = false;
				say(err.message || String(err), false);
			});
		});

		save.addEventListener('click', function () {
			var pasted = paste.value.trim();
			if (!pair) { saySave('Generate a key first.', false); return; }
			if (!pasted) { saySave('Paste the key from your password manager first.', false); return; }
			if (pasted !== pair.privateKeyB64) {
				saySave('That is not the key above — paste the copy your password manager holds.', false);
				return;
			}

			save.disabled = true;
			saySave('Saving…', true);
			// From here the stored key is being replaced: a cancel link promising
			// to keep the current key would lie, so it goes away for good.
			if (c.cancelId) {
				var cancel = document.getElementById(c.cancelId);
				if (cancel) cancel.hidden = true;
			}
			joineryApi.post('backup_recovery_save', { public_key: pair.publicKeyB64, rotate: c.rotate ? 1 : 0 }).then(function (r) {
				// Prove with the pasted copy against what the server STORED.
				return openChallenge(r.challenge, pasted, r.public_key, r.info);
			}).then(function (proof) {
				return joineryApi.post('backup_recovery_prove', { proof: proof });
			}).then(function () {
				paste.value = '';
				privateOut.value = '';
				saySave('Verified.', true);
				window.location.reload();
			}).catch(function (err) {
				save.disabled = false;
				saySave(err.message || String(err), false);
			});
		});

		if (copyButton) {
			copyButton.addEventListener('click', function () {
				var value = privateOut.value;
				if (!value) return;
				var done = function () { say('Copied. Paste it into your password manager now.', true); };
				var failed = function () {
					privateOut.focus();
					privateOut.select();
					say('Could not reach the clipboard — the key is selected, copy it by hand.', false);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(value).then(done).catch(failed);
				} else {
					failed();
				}
			});
		}
	}

	/**
	 * Client-custody vault code dry runs. Configs are emitted by the page as
	 * window.rrClientConfigs = { scope: { wrappings: [{wrapped, salt, ad}] } }.
	 * Requires window.VaultCrypto (vault-crypto.js) for the KEK/unwrap
	 * primitives — the same code the real recovery flow runs.
	 */
	function attachClientChecks() {
		var buttons = document.querySelectorAll('[data-rr-client-check]');
		Array.prototype.forEach.call(buttons, function (button) {
			var codeInput = document.getElementById(button.getAttribute('data-rr-code'));
			var status = document.getElementById(button.getAttribute('data-rr-status'));
			var scope = button.getAttribute('data-rr-scope');
			if (!codeInput || !status) return;

			function say(message, ok) {
				status.textContent = message;
				status.className = ok ? 'text-success small mt-2' : 'text-danger small mt-2';
			}

			button.addEventListener('click', async function () {
				var code = codeInput.value.trim();
				if (!code) { say('Enter a recovery code first.', false); return; }

				var cfg = (window.rrClientConfigs || {})[scope];
				if (!cfg || !window.VaultCrypto) {
					say('The vault crypto scripts did not load — reload the page and try again.', false);
					return;
				}

				button.disabled = true;
				say('Checking…', true);
				var passed = false;
				for (var i = 0; i < cfg.wrappings.length; i++) {
					var w = cfg.wrappings[i];
					try {
						var kek = await window.VaultCrypto.kekFromRecoveryCode(code, w.salt);
						await window.VaultCrypto.unwrapSecretKey(w.wrapped, kek, w.ad);
						passed = true;
						break;
					} catch (e) { /* wrong code for this row — try the next */ }
				}
				codeInput.value = '';

				// Report only pass/fail to the ledger; the code and any derived
				// material stay in this page.
				var wrap = document.querySelector('div[data-rr-client-form="' + scope + '"]');
				var form = wrap ? wrap.querySelector('form') : null;
				if (form) {
					form.querySelector('[name="passed"]').value = passed ? '1' : '0';
					say(passed ? 'That code works. Recording…' : 'That code does not open this vault. Recording…', passed);
					// requestSubmit, not submit(): submit() skips every submit
					// listener (FormWriter validation/CSRF hooks included).
					if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
				} else {
					say(passed ? 'That code works.' : 'That code does not open this vault.', passed);
					button.disabled = false;
				}
			});
		});
	}

	/**
	 * Inline step-up: when the server would demand a second-factor confirmation
	 * for a verify action, run the passkey ceremony right here and then let the
	 * original submit proceed — one click, no redirect that loses the POST.
	 *
	 * Security is unchanged: the ceremony calls the same passkey_stepup_options
	 * / passkey_stepup_verify endpoints and stamps the same server-side marker
	 * the logic gate checks. If anything here fails (no WebAuthn, dismissed
	 * prompt), the form submits anyway and the server redirect flow takes over.
	 */
	function attachStepUp(cfg) {
		if (!cfg || !cfg.needed || !cfg.passkey) return;
		if (!window.joineryApi || !window.JoineryPasskeys) return;

		var VERIFY_ACTIONS = ['verify_item', 'record_client_dry_run'];
		Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
			var action = form.querySelector('input[name="action"]');
			if (!action || VERIFY_ACTIONS.indexOf(action.value) === -1) return;

			form.addEventListener('submit', function (ev) {
				// Idempotent: after one successful ceremony (any form), or after
				// this form already went around once, do not intercept again.
				if (!cfg.needed || form.dataset.rrStepped === '1') return;
				ev.preventDefault();

				JoineryPasskeys.stepUp().then(function () {
					cfg.needed = false; // marker is stamped server-side for the whole session
					form.dataset.rrStepped = '1';
					if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
				}).catch(function () {
					// Fall back to the server's redirect flow rather than dead-ending.
					form.dataset.rrStepped = '1';
					if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
				});
			});
		});
	}

	// RecoveryKeySetupPanel emits window.rrPanel and nothing else — the panel
	// renders in several places and each one wiring its own listeners is how one
	// of them ends up with a button that does nothing.
	function attachPanel() {
		// The approval screen (ApprovalChallengePanel, every scope) uses the same
		// ceremony — open a challenge sealed to the recovery key, submit what
		// came out — under its OWN global. Separate because the two panels can
		// appear on one page, and a second assignment to window.rrPanel would
		// silently replace the first: on the approval screen, that means a
		// button that does nothing on the page where someone is trying to
		// authorize a restore.
		if (window.rrApproval) attachCeremony(window.rrApproval);

		var cfg = window.rrPanel;
		if (!cfg) return;
		if (cfg.generator) attachGenerator(cfg.generator);
		if (cfg.ceremony) attachCeremony(cfg.ceremony);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', attachPanel);
	} else {
		attachPanel();
	}

	return {
		openChallenge: openChallenge,
		openKeyBox: openKeyBox,
		generateKeypair: generateKeypair,
		attachCeremony: attachCeremony,
		attachGenerator: attachGenerator,
		attachClientChecks: attachClientChecks,
		attachStepUp: attachStepUp
	};
})();
