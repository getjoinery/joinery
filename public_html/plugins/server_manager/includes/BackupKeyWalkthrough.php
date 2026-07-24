<?php
/**
 * BackupKeyWalkthrough — the guided setup panel for backup key recovery.
 *
 * Renders whichever step is outstanding, from BackupKeyCustody::setup_state():
 * create the recovery key, then prove possession of it. A completed step
 * collapses to one line, and once both are done the panel rests on a standing
 * record of what was set up.
 *
 * Setting up the recovery key is a fleet-level act and belongs here. Sealing an
 * individual node's backup key to it is not — that is done from the node's own
 * Backups tab, and happens by itself with the node's next encrypting backup.
 *
 * The panel explains what the operator is doing in their terms — one or two
 * sentences per step. The long-form background (threat model, recovery drill)
 * lives in plugins/server_manager/docs/overview.md.
 *
 * Rendering only: the POST handlers live on the page that includes this
 * (views/admin/targets.php), which owns the permission gate and CSRF check.
 *
 * @version 1.4 - two steps only (create the key, prove it); sealing a node key belongs to that
 *                node Backups tab, so the fleet-wide step is gone and the panel rests on a
 *                standing record once verified
 * @version 1.3 - step 2 verifies in the page: paste the recovery key from your password manager and
 *                it opens the challenge with WebCrypto (key never leaves the browser); the command
 *                line is the fallback
 * @version 1.2 - step 2 is a single copy-paste command with the challenge piped in on stdin (no
 *                temporary file to create)
 * @version 1.1 - the commands shown are absolute paths, so they run from any directory
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupKeyCustody.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAssets.php'));

class BackupKeyWalkthrough {

	/** Anchor the rest of the plugin links to when it wants to send someone here. */
	const URL = '/admin/server_manager/targets#backup-key-setup';

	/**
	 * Absolute path to the keypair tool. Absolute because the commands shown are
	 * meant to be pasted and run: the script sits beside public_html, so a
	 * repo-relative path fails from the directory an operator is usually in.
	 */
	private static function script_path() {
		return PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php';
	}

	/**
	 * One-line summary used by other surfaces (node Backups tab, dashboard) so
	 * they describe the outstanding step in the same words the panel does.
	 */
	public static function outstanding_summary(array $state): string {
		switch ($state['state']) {
			case 'unconfigured':
				return 'Backup key recovery has not been set up yet.';
			case 'invalid':
				return 'The recovery key that is configured cannot be read.';
			case 'unproven':
				return 'The recovery key has not been verified yet.';
			default:
				return 'Backup key recovery is set up.';
		}
	}

	/**
	 * @param AdminPage $page       for FormWriter + box chrome
	 * @param array     $state      BackupKeyCustody::setup_state()
	 * @param string    $action_url page URL the step forms post back to
	 */
	public static function render($page, array $state, $action_url) {
		$step = ['unconfigured' => 1, 'invalid' => 1, 'unproven' => 2, 'ready' => 3];
		$current = $step[$state['state']] ?? 1;

		echo '<a id="backup-key-setup"></a>';
		$page->begin_box(['title' => 'Backup key recovery']);

		if ($current < 3) {
			echo '<p class="mb-3">Each node encrypts its own backups with a secret that exists only on that node. '
			   . 'If the node is lost, its offsite backups cannot be opened by anyone. Set up a recovery key you '
			   . 'keep yourself and the platform stores a sealed copy of every node&rsquo;s backup key &mdash; sealed '
			   . 'so that only you can open it.</p>';
		}

		self::step_1($page, $state, $action_url, $current);
		self::step_2($page, $state, $action_url, $current);
		self::resting($state, $current);

		$page->end_box();
	}

	// ── Step chrome ────────────────────────────────────────────────────────

	private static function head($n, $title, $current, $done_note = '') {
		if ($n < $current) {
			echo '<div class="border rounded p-2 mb-2 bg-light"><span class="badge bg-success me-2">&check;</span>'
			   . '<strong>' . htmlspecialchars($title) . '</strong>'
			   . ($done_note ? ' <span class="text-muted small">&mdash; ' . htmlspecialchars($done_note) . '</span>' : '')
			   . '</div>';
			return false;
		}
		if ($n > $current) {
			echo '<div class="border rounded p-2 mb-2 text-muted"><span class="badge bg-secondary me-2">' . (int)$n . '</span>'
			   . htmlspecialchars($title) . '</div>';
			return false;
		}
		echo '<div class="border rounded p-3 mb-2">';
		echo '<h5><span class="badge bg-primary me-2">' . (int)$n . '</span>' . htmlspecialchars($title) . '</h5>';
		return true; // caller renders the body, then calls foot()
	}

	private static function foot() { echo '</div>'; }

	private static function command($cmd) {
		echo '<pre class="border rounded p-2 mb-2" style="white-space:pre-wrap;word-break:break-all;">'
		   . htmlspecialchars($cmd) . '</pre>';
	}

	// ── Step 1 — create the recovery key ───────────────────────────────────

	private static function step_1($page, array $state, $action_url, $current) {
		$fpr = $state['fingerprint'] ? 'key ' . substr($state['fingerprint'], 0, 8) : '';
		if (!self::head(1, 'Create your recovery key', $current, $fpr)) { return; }

		if ($state['state'] === 'invalid') {
			echo '<div class="alert alert-danger">' . htmlspecialchars($state['error'])
			   . ' Paste the public key again below.</div>';
		}

		echo '<p>This creates a pair of keys. The platform gets the public half, which can only lock things. '
		   . 'You keep the private half, which is the only thing that can unlock them &mdash; so it belongs in your '
		   . 'password manager, never on a server.</p>';
		echo '<p class="text-muted">Run this on your own machine (copy the script over), or run it here and move '
		   . 'the private key into your password manager afterwards, deleting the file it wrote:</p>';
		self::command('php ' . self::script_path() . ' generate --private-out ~/recovery.key');
		echo '<p class="text-muted">It prints one line: the public key. Paste that here. The platform will never ask '
		   . 'for the private key and has nowhere to store it.</p>';

		$fw = $page->getFormWriter('escrow_pubkey_form');
		$fw->begin_form();
		echo SmAdminCsrf::field();
		echo '<input type="hidden" name="action" value="save_escrow_public_key">';
		$fw->textinput('escrow_public_key', 'Recovery public key', ['required' => true]);
		$fw->submitbutton('btn_save_escrow_key', 'Save public key');
		$fw->end_form();
		self::foot();
	}

	// ── Step 2 — prove possession ──────────────────────────────────────────

	private static function step_2($page, array $state, $action_url, $current) {
		if (!self::head(2, 'Prove you hold the private key', $current, 'verified')) { return; }

		echo '<p>Sealing something to a public key always appears to work, even if the key was pasted wrong &mdash; '
		   . 'you would only discover it when a recovery failed. So open one sealed message first, using the copy '
		   . 'of the key you are actually keeping. Once it opens, every backup key sealed here is provably one you '
		   . 'can get back.</p>';

		try {
			$cli_challenge     = BackupKeyCustody::possession_challenge();
			$browser_challenge = BackupKeyCustody::browser_challenge();
			$public_key        = base64_encode(BackupKeyCustody::parse_public_key());
		} catch (BackupKeyCustodyException $e) {
			echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
			self::foot();
			return;
		}

		// The paste box: the key is read here and used here. It sits outside the
		// form on purpose — there is no field for it to be submitted in.
		echo '<div class="border rounded p-2 mb-3 bg-light">';
		echo '<label for="sm-escrow-privkey" class="form-label mb-1"><strong>Paste your recovery key</strong></label>';
		echo '<p class="text-muted small mb-2">Copy it out of your password manager &mdash; that tests the copy you '
		   . 'will still have if this machine is gone. It is opened here in the page: nothing is sent to the server, '
		   . 'and the box is cleared as soon as it is used.</p>';
		echo '<input type="password" id="sm-escrow-privkey" class="form-control" autocomplete="off" '
		   . 'autocapitalize="off" spellcheck="false" placeholder="Recovery private key (base64)">';
		echo '<button type="button" id="sm-escrow-unseal" class="btn btn-primary btn-sm mt-2">Open the challenge</button>';
		echo '<div id="sm-escrow-unseal-status" class="small mt-2"></div>';
		echo '</div>';

		$fw = $page->getFormWriter('escrow_proof_form');
		$fw->begin_form();
		echo SmAdminCsrf::field();
		echo '<input type="hidden" name="action" value="verify_escrow_key">';
		$fw->textinput('escrow_proof', 'Result', [
			'required'    => true,
			'placeholder' => 'Your recovery key opened this message. Backup key recovery is proven for key fingerprint …',
			'help'        => 'What comes out is a sentence, not a code — if it reads like this, the right key opened it.',
		]);
		$fw->submitbutton('btn_verify_escrow', 'Verify');
		$fw->end_form();

		// Same check, at the command line, for a key that lives on a machine
		// rather than in a password manager (or a browser without X25519).
		echo '<details class="mt-3"><summary class="text-muted small">Do it from the command line instead</summary>';
		echo '<p class="text-muted small mb-1 mt-2">The sealed message is already in the command, so there is '
		   . 'nothing to save first. Point <code>--private</code> at your key file:</p>';
		self::command("echo '" . $cli_challenge . "' | php "
			. self::script_path() . ' unseal --private ~/recovery.key');
		echo '<p class="text-muted small">Paste what it prints into the box above.</p>';
		echo '</details>';

		echo SmAssets::script_tag('backup_key_verify.js');
		echo '<script>smBackupKeyVerify.attach(' . json_encode([
			'keyInputId' => 'sm-escrow-privkey',
			'buttonId'   => 'sm-escrow-unseal',
			'statusId'   => 'sm-escrow-unseal-status',
			'challenge'  => $browser_challenge,
			'publicKey'  => $public_key,
		], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';

		// Nothing is sealed to the key until it is proven, so swapping it here
		// costs nothing and is the fix for a key whose private half was lost.
		echo '<form method="post" action="' . htmlspecialchars($action_url) . '" class="mt-2">';
		echo SmAdminCsrf::field();
		echo '<input type="hidden" name="action" value="clear_escrow_public_key">';
		echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Use a different public key</button>';
		echo '</form>';
		self::foot();
	}

	// ── Set up: the standing state once the key is verified ────────────────

	/**
	 * What the panel says once setup is done: that it is done, and what happens
	 * next without anyone doing anything. Nothing else — the key was just proven
	 * against the copy the operator keeps, so restating its fingerprint asks them
	 * to re-check what they have already checked; the agent signing key seals
	 * itself at the next publish; and recovery steps are for the moment recovery
	 * is needed, not for a page nobody will be reading then.
	 */
	private static function resting(array $state, $current) {
		if ($current < 3) {
			echo '<div class="border rounded p-2 mb-2 text-muted">Once verified, each node seals its backup key '
			   . 'to it &mdash; on the node&rsquo;s own Backups tab, or automatically with its next encrypted backup.</div>';
			return;
		}

		echo '<div class="alert alert-success mb-0"><strong>Backup key recovery is set up.</strong> '
		   . 'Nodes seal their backup keys to it from their own Backups tab, and any node&rsquo;s next encrypted '
		   . 'backup seals its key as part of running.</div>';
	}
}
