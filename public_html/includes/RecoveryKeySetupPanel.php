<?php
/**
 * RecoveryKeySetupPanel — the one rendering of backup recovery key setup.
 *
 * Setup is a four-state machine (nothing set / a value that cannot be read /
 * set but unproven / proven), and each state has exactly one thing the operator
 * should do next. Every surface that offers the setup renders it from here, so
 * a second copy of the box cannot grow a different explanation, a different
 * button, or — the one that matters — a weaker gate.
 *
 * The default path is one screen: the keypair is made in the browser (the
 * private half never touches the server or the network), the operator puts it
 * in their password manager, and then pastes it back to prove the saved copy
 * works. One button saves and verifies: the page calls backup_recovery_save,
 * opens the challenge the server sealed to what it actually STORED, and
 * records the proof with backup_recovery_prove. Pasting the key back from
 * storage is the confirmation — proving it with the copy still on screen would
 * pass for someone who closed the tab without saving anything, and every
 * backup afterwards would be sealed to a key that exists nowhere.
 *
 * The unproven state remains as the fallback screen: a session that died
 * between save and proof lands there and finishes the same ceremony.
 *
 * @version 2.1 - the by-hand fold is gone: generate-in-browser is the one
 *                setup path, with a plain line for a browser that cannot
 * @version 2.0 - one-screen setup: paste-back replaces the confirm checkbox and
 *                the separate verify visit; the public key is never shown on the
 *                generate path (it rides the API call)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

class RecoveryKeySetupPanel {

	/**
	 * Render the outstanding step for the current state.
	 *
	 * @param object $page    Anything with getFormWriter() — AdminPage or PublicPage.
	 * @param array  $options {
	 *   @type array $state  Pre-read setup_state(), when the page already has it.
	 * }
	 */
	public static function render($page, array $options = array()): void {
		$state = $options['state'] ?? BackupRecoveryKey::setup_state();

		switch ($state['state']) {
			case 'unconfigured':
			case 'invalid':
				self::renderSetup($page, $state);
				return;
			case 'unproven':
				self::renderProve($page, $state);
				return;
			default:
				self::renderReady($state);
		}
	}

	/**
	 * Nothing usable is configured: make a keypair, save the private half, and
	 * paste it back to prove the saved copy works — all on this screen.
	 */
	private static function renderSetup($page, array $state): void {
		if ($state['state'] === 'invalid') {
			echo '<div class="alert alert-danger">' . htmlspecialchars($state['error']) . '</div>';
		}

		echo '<p>Backups are encrypted, and this one key opens them.</p>';

		// Generation happens in the page. The button starts hidden and the
		// script reveals it once it has confirmed this browser can actually do
		// X25519 — an enabled button that fails on click is worse than one that
		// was never offered, on the page whose whole job is recovery.
		echo '<div id="rk-gen-box" hidden>';
		echo '<button type="button" id="rk-generate" class="btn btn-primary">Generate a recovery key</button>';
		echo '<p class="text-muted small mt-2 mb-0">Made in this browser. It is never sent anywhere '
		   . '— not to this site, not to Joinery.</p>';
		echo '</div>';

		echo '<div id="rk-gen-result" hidden>';
		echo '<div class="alert alert-warning mt-2"><strong>Save this now — it is shown once.</strong> '
		   . 'This is the only copy; without it these backups cannot be opened.</div>';
		echo '<label for="rk-generated-private" class="form-label"><strong>Your recovery key</strong></label>';
		echo '<textarea id="rk-generated-private" class="form-control" rows="2" readonly '
		   . 'spellcheck="false" autocomplete="off"></textarea>';
		echo '<div class="mt-2">';
		echo '<button type="button" id="rk-copy" class="btn btn-sm btn-primary">Copy</button> ';
		echo '<a id="rk-download" class="btn btn-sm btn-outline-secondary" download="recovery.key" href="#">'
		   . 'Download recovery.key</a>';
		echo ' <span id="rk-gen-status" class="small"></span>';
		echo '</div>';

		// The possession ceremony, inline. The script refuses a paste that does
		// not match the key above, then proves the pasted copy against the
		// challenge the server seals to what it stored.
		echo '<hr class="mt-3">';
		echo '<label for="rk-paste-back" class="form-label"><strong>Now paste it back</strong></label>';
		echo '<p class="text-muted small mb-1">From your password manager — that is the copy that has to '
		   . 'work in a disaster.</p>';
		echo '<input type="password" id="rk-paste-back" class="form-control" autocomplete="off" spellcheck="false">';
		echo '<div class="mt-2">';
		echo '<button type="button" id="rk-save" class="btn btn-primary">Save and verify</button>';
		echo ' <span id="rk-save-status" class="small"></span>';
		echo '</div>';
		echo '</div>';

		// Revealed by the script only when the probe fails, so a browser that
		// cannot make the key says so instead of showing a heading over nothing.
		echo '<p id="rk-no-crypto" class="text-muted small" hidden>This browser cannot generate the key '
		   . '— open this page in a current browser (Chrome, Firefox, Safari, Edge).</p>';

		self::emitScript(array('generator' => array(
			'buttonId'     => 'rk-generate',
			'boxId'        => 'rk-gen-box',
			'resultId'     => 'rk-gen-result',
			'privateOutId' => 'rk-generated-private',
			'copyButtonId' => 'rk-copy',
			'downloadId'   => 'rk-download',
			'statusId'     => 'rk-gen-status',
			'pasteId'      => 'rk-paste-back',
			'saveId'       => 'rk-save',
			'saveStatusId' => 'rk-save-status',
			'noCryptoId'   => 'rk-no-crypto',
		)));
	}

	/**
	 * A key is saved but unproven: open the challenge with the copy that was
	 * saved. Nothing is sealed to the key until this succeeds.
	 */
	private static function renderProve($page, array $state): void {
		echo '<p>Key ' . htmlspecialchars($state['fingerprint']) . '… is saved but not yet verified. '
		   . 'Nothing is sealed to it until it is: a mistyped key would seal happily and produce backups '
		   . 'nobody could ever open.</p>';

		echo '<label for="rk-privkey" class="form-label"><strong>Paste your recovery key</strong></label>';
		echo '<p class="text-muted small mb-1">It is used in your browser and never sent anywhere.</p>';
		echo '<input type="password" id="rk-privkey" class="form-control" autocomplete="off" spellcheck="false">';
		echo '<button type="button" id="rk-open" class="btn btn-primary btn-sm mt-2">Verify</button>';
		echo '<div id="rk-status" class="small mt-2"></div>';

		// The ceremony's form: the script fills the recovered proof and submits.
		$fw = $page->getFormWriter('recovery_proof_form');
		$fw->begin_form();
		$fw->hiddeninput('action', '', array('value' => 'verify_recovery_key'));
		$fw->hiddeninput('recovery_proof', '', array('value' => '', 'id' => 'rk-proof'));
		$fw->end_form();

		echo '<details class="mt-2"><summary class="small">Or verify at the command line</summary>';
		echo '<pre class="border rounded p-2 small mt-2">echo \''
		   . htmlspecialchars(BackupRecoveryKey::possession_challenge())
		   . '\' | php ' . htmlspecialchars(PathHelper::getSiteRoot())
		   . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php unseal --private ~/recovery.key</pre>';
		$fw3 = $page->getFormWriter('recovery_cli_proof_form');
		$fw3->begin_form();
		$fw3->hiddeninput('action', '', array('value' => 'verify_recovery_key', 'id' => 'rk-cli-action'));
		$fw3->textinput('recovery_proof', 'The sentence it prints',
			array('autocomplete' => 'off', 'id' => 'rk-cli-proof'));
		$fw3->submitbutton('btn_verify_recovery', 'Verify');
		$fw3->end_form();
		echo '</details>';

		self::emitScript(array('ceremony' => array(
			'keyInputId' => 'rk-privkey',
			'buttonId'   => 'rk-open',
			'statusId'   => 'rk-status',
			'proofId'    => 'rk-proof',
			'challenge'  => BackupRecoveryKey::browser_challenge(),
			'publicKey'  => base64_encode(BackupRecoveryKey::parse_public_key()),
			'infoPrefix' => BackupRecoveryKey::BROWSER_INFO,
		)));

		$fw2 = $page->getFormWriter('recovery_clear_form');
		$fw2->begin_form();
		$fw2->hiddeninput('action', '', array('value' => 'clear_recovery_key'));
		$fw2->submitbutton('btn_clear_recovery', 'Use a different key',
			array('class' => 'btn btn-sm btn-outline-secondary mt-2'));
		$fw2->end_form();
	}

	/** Proven — say so, and say where to re-check it. */
	private static function renderReady(array $state): void {
		echo '<p class="mb-1"><strong>Verified.</strong> Key ' . htmlspecialchars($state['fingerprint'])
		   . '… opens every backup this site makes.</p>';
		echo '<p class="text-muted small mb-0">Re-check it any time from '
		   . '<a href="/admin/admin_recovery_readiness">Recovery Readiness</a>.</p>';
	}

	/**
	 * Load the shared script and hand it this panel's element ids. Both configs
	 * go through window.rrPanel; recovery-readiness.js attaches whichever is
	 * present on DOMContentLoaded.
	 */
	private static function emitScript(array $config): void {
		echo '<script defer src="/assets/js/recovery-readiness.js?v='
		   . (@filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1') . '"></script>';
		echo '<script>window.rrPanel = ' . json_encode($config,
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
	}
}
