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
 * The keypair is made in the browser: nothing here needs a shell, and the
 * private half never touches the server or the network. What the page cannot
 * do is finish the job for the operator. It holds the private key in memory at
 * the moment it is generated and could silently satisfy the possession
 * ceremony with it, and that would answer the wrong question. The ceremony
 * exists to prove that the copy the operator *saved* works; proving it with the
 * in-memory copy would pass for someone who closed the tab without saving
 * anything, and every backup afterwards would be sealed to a key that exists
 * nowhere. So the key is generated here, and pasted back in the next state.
 *
 * The public key field itself is never drawn here — it is a declared setting,
 * so it comes from SettingsFieldRenderer, and its label, type and help live in
 * settings.json.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

class RecoveryKeySetupPanel {

	/** POST action values the default (core Backups) save path answers to. */
	const DEFAULT_ACTIONS = array(
		'save'   => 'save_recovery_key',
		'verify' => 'verify_recovery_key',
		'clear'  => 'clear_recovery_key',
	);

	/**
	 * Render the outstanding step for the current state.
	 *
	 * @param object $page    Anything with getFormWriter() — AdminPage or PublicPage.
	 * @param array  $options {
	 *   @type array  $state        Pre-read setup_state(), when the page already has it.
	 *   @type array  $actions      Override the save/verify/clear POST action values.
	 *   @type string $form_prefix  Prefix for form names, when a page hosts two panels.
	 *   @type string $extra_fields HTML emitted inside every form (a plugin's own CSRF field).
	 * }
	 */
	public static function render($page, array $options = array()): void {
		$state   = $options['state'] ?? BackupRecoveryKey::setup_state();
		$actions = ($options['actions'] ?? array()) + self::DEFAULT_ACTIONS;
		$prefix  = $options['form_prefix'] ?? 'recovery';
		$extra   = $options['extra_fields'] ?? '';

		switch ($state['state']) {
			case 'unconfigured':
			case 'invalid':
				self::renderSetup($page, $state, $actions, $prefix, $extra);
				return;
			case 'unproven':
				self::renderProve($page, $state, $actions, $prefix, $extra);
				return;
			default:
				self::renderReady($state);
		}
	}

	/**
	 * Nothing usable is configured: make a keypair and save the public half.
	 */
	private static function renderSetup($page, array $state, array $actions, string $prefix, string $extra): void {
		if ($state['state'] === 'invalid') {
			echo '<div class="alert alert-danger">' . htmlspecialchars($state['error']) . '</div>';
		}

		echo '<p>Backups are encrypted, and one recovery key opens them. Generate it here, keep it in your '
		   . 'password manager, and every backup this site makes from now on can be opened with it.</p>';

		// Generation happens in the page. The button starts hidden and the
		// script reveals it once it has confirmed this browser can actually do
		// X25519 — an enabled button that fails on click is worse than one that
		// was never offered, on the page whose whole job is recovery.
		echo '<div id="rk-gen-box" hidden>';
		echo '<button type="button" id="rk-generate" class="btn btn-primary">Generate a recovery key</button>';
		echo '<p class="text-muted small mt-2 mb-0">Made in this browser. The private half is never sent '
		   . 'anywhere — not to this site, not to Joinery.</p>';
		echo '</div>';

		echo '<div id="rk-gen-result" hidden>';
		echo '<div class="alert alert-warning mt-2"><strong>Save this now — it is shown once.</strong> '
		   . 'This is the only copy. Nobody can reissue it, and without it the backups this site makes '
		   . 'cannot be opened.</div>';
		echo '<label for="rk-generated-private" class="form-label"><strong>Your recovery key</strong></label>';
		echo '<textarea id="rk-generated-private" class="form-control" rows="2" readonly '
		   . 'spellcheck="false" autocomplete="off"></textarea>';
		echo '<div class="mt-2">';
		echo '<button type="button" id="rk-copy" class="btn btn-sm btn-primary">Copy</button> ';
		echo '<a id="rk-download" class="btn btn-sm btn-outline-secondary" download="recovery.key" href="#">'
		   . 'Download recovery.key</a>';
		echo ' <span id="rk-gen-status" class="small"></span>';
		echo '</div>';
		echo '<p class="text-muted small mt-2 mb-0">Paste it into your password manager. The download is for '
		   . 'an offline copy — delete it from this machine once it is somewhere safe.</p>';
		echo '</div>';

		echo '<hr class="mt-3">';

		$fw = $page->getFormWriter($prefix . '_key_form');
		$fw->begin_form();
		echo $extra;
		$fw->hiddeninput('action', '', array('value' => $actions['save']));

		// Declared setting: the renderer owns the field. The page contributes
		// only the fact that this box cannot be submitted empty.
		SettingsFieldRenderer::renderGroup($fw, 'backups', array(
			'source' => 'core',
			'only'   => array(BackupRecoveryKey::PUBLIC_KEY_SETTING),
			'field_options' => array(
				BackupRecoveryKey::PUBLIC_KEY_SETTING => array('required' => true),
			),
		));

		// The confirmation gate, revealed only after a key is generated here. A
		// pasted key came from somewhere the operator already keeps it, so there
		// is nothing to confirm; a generated one exists only on this screen.
		echo '<div id="rk-confirm-wrap" hidden>';
		$fw->checkboxinput('rk_saved_confirm', 'I have saved the recovery key somewhere I can get it back', array(
			'checked'  => false,
			'helptext' => 'Checked from memory does not count — put it where it will still be when this server is gone.',
		));
		echo '</div>';

		$fw->submitbutton('btn_save_recovery', 'Save public key', array('id' => 'rk-save'));
		$fw->end_form();

		echo '<details class="mt-3"><summary class="small">Or generate it at the command line</summary>';
		echo '<pre class="border rounded p-2 small">php ' . htmlspecialchars(PathHelper::getSiteRoot())
		   . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php generate --private-out ~/recovery.key</pre>';
		echo '<p class="small text-muted mb-0">Prints the public key to paste above and writes the private half '
		   . 'to that path. Move it into your password manager and delete the file.</p>';
		echo '</details>';

		self::emitScript(array('generator' => array(
			'buttonId'      => 'rk-generate',
			'boxId'         => 'rk-gen-box',
			'resultId'      => 'rk-gen-result',
			'privateOutId'  => 'rk-generated-private',
			'publicFieldId' => BackupRecoveryKey::PUBLIC_KEY_SETTING,
			'copyButtonId'  => 'rk-copy',
			'downloadId'    => 'rk-download',
			'statusId'      => 'rk-gen-status',
			'confirmWrapId' => 'rk-confirm-wrap',
			'confirmBoxId'  => 'rk_saved_confirm',
			'submitId'      => 'rk-save',
		)));
	}

	/**
	 * A key is saved but unproven: open the challenge with the copy that was
	 * saved. Nothing is sealed to the key until this succeeds.
	 */
	private static function renderProve($page, array $state, array $actions, string $prefix, string $extra): void {
		echo '<p>Key ' . htmlspecialchars($state['fingerprint']) . '… is saved but unverified. '
		   . 'Nothing is sealed to it yet: a key that was mistyped — or generated and never saved — would seal '
		   . 'happily and produce backups nobody could ever open. Paste the copy you saved to prove it works.</p>';

		echo '<label for="rk-privkey" class="form-label"><strong>Paste your recovery key</strong></label>';
		echo '<p class="text-muted small mb-1">It is used in your browser and never sent anywhere.</p>';
		echo '<input type="password" id="rk-privkey" class="form-control" autocomplete="off" spellcheck="false">';
		echo '<button type="button" id="rk-open" class="btn btn-primary btn-sm mt-2">Open the challenge</button>';
		echo '<div id="rk-status" class="small mt-2"></div>';

		$fw = $page->getFormWriter($prefix . '_proof_form');
		$fw->begin_form();
		echo $extra;
		$fw->hiddeninput('action', '', array('value' => $actions['verify']));
		$fw->textinput('recovery_proof', 'Result', array('autocomplete' => 'off', 'id' => 'rk-proof'));
		$fw->submitbutton('btn_verify_recovery', 'Verify');
		$fw->end_form();

		echo '<details class="mt-2"><summary class="small">Or open it at the command line</summary>';
		echo '<pre class="border rounded p-2 small">echo \''
		   . htmlspecialchars(BackupRecoveryKey::possession_challenge())
		   . '\' | php ' . htmlspecialchars(PathHelper::getSiteRoot())
		   . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php unseal --private ~/recovery.key</pre></details>';

		self::emitScript(array('ceremony' => array(
			'keyInputId' => 'rk-privkey',
			'buttonId'   => 'rk-open',
			'statusId'   => 'rk-status',
			'proofId'    => 'rk-proof',
			'challenge'  => BackupRecoveryKey::browser_challenge(),
			'publicKey'  => base64_encode(BackupRecoveryKey::parse_public_key()),
			'infoPrefix' => BackupRecoveryKey::BROWSER_INFO,
		)));

		$fw2 = $page->getFormWriter($prefix . '_clear_form');
		$fw2->begin_form();
		echo $extra;
		$fw2->hiddeninput('action', '', array('value' => $actions['clear']));
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
