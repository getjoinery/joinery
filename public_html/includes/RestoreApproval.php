<?php
/**
 * RestoreApproval — this machine asking its own administrator whether to
 * destroy its own data.
 *
 * A restore erases a live database or replaces a live site tree. When a
 * management node runs this machine's backups, it is also the party that would
 * dispatch a restore — and it is exactly the party that must not be able to
 * authorize one, because a management node that had been compromised would find
 * restore the sharpest tool in the box: the one operation designed to destroy,
 * rather than one that destroys only through a bug.
 *
 * So the decision is made here, on this machine, by whoever holds this machine's
 * backup recovery key. The management node is not in the path at all — not as a
 * gate, and not as a relay.
 *
 * HOW IT WORKS, in the order it happens:
 *
 *   1. The root agent on this machine claims a restore job and runs NOTHING. It
 *      composes its own statement of what the job would do — which database,
 *      which archive, that archive's real age and size — from its own records,
 *      not from anything the management node sent.
 *   2. It seals a one-time secret to the backup recovery public key this site
 *      already holds, binding it to that job and that statement, and writes the
 *      ciphertext into the settings table. The settings table is the handoff
 *      surface the join and leave watchers already use: the one storage the web
 *      tier and the root agent can both reach on every install.
 *   3. This class reads that row and the Backups page renders it.
 *   4. The administrator opens the challenge in their browser with their
 *      recovery key — the same in-browser flow that proved the key in the first
 *      place, and the key never leaves the page — and the recovered sentence is
 *      written back through answer().
 *   5. The agent compares it against what it sealed, and only then restores.
 *
 * WHAT THIS CLASS CANNOT DO, which is the reassuring part: it cannot approve
 * anything. It moves a ciphertext one way and a recovered plaintext the other.
 * The secret is inside the box, so the whole of the web tier — this file
 * included — could be rewritten and still not produce an answer without the
 * private key, which is in the administrator's password manager and has never
 * been on a server.
 *
 * THE ONE THING IT DOES REST ON: this page is served by this machine's own web
 * tier, so a compromised web tier HERE could show one thing while the challenge
 * binds another, and could capture the recovery key as it is typed. That is the
 * same trust the recovery-key setup ceremony already asks for. Three things
 * narrow it and none needs anyone to read carefully: the machine keeps no local
 * archives and holds a write-only bucket credential, so a captured key has no
 * ciphertext on the machine to open; the agent refuses any archive absent from
 * its own upload ledger whatever was approved; and the archive's true age is a
 * first-class line on the screen rather than a detail. Beyond that, a machine
 * whose web tier you suspect is not a machine to restore in place — it is a
 * machine to rebuild.
 *
 * @version 1.0
 */

class RestoreApprovalException extends Exception {}

class RestoreApproval {

	/** Written by the agent, read here. Never written from the web tier. */
	const REQUEST_SETTING = 'restore_approval_request';

	/** Written here, read and cleared by the agent. */
	const ANSWER_SETTING = 'restore_approval_answer';

	/**
	 * HKDF context for the approval challenge. Different from the possession
	 * ceremony's, so an approval challenge and a possession challenge can never
	 * be answers to one another. Must match approvalInfoPrefix in the agent.
	 */
	const BROWSER_INFO = 'joinery-restore-approval:';

	/**
	 * The restore waiting on an answer, or null.
	 *
	 * An expired challenge is null, not a stale screen. The agent gives up on
	 * its own schedule and stops watching for an answer, so an approval offered
	 * after that would be an administrator authorizing something that does not
	 * happen — and then, hours later, wondering whether it did.
	 *
	 * @return array|null {
	 *   @type int    job_id           The management job this is bound to
	 *   @type string primitive        restore_database | restore_project | restore_chain
	 *   @type string summary          One plain sentence, composed by the agent
	 *   @type array  facts            [['label'=>..,'value'=>..], ...] in reading order
	 *   @type string challenge        base64 blob the browser opens
	 *   @type string public_key       base64 recovery public key it is sealed to
	 *   @type string info             HKDF context the browser must use
	 *   @type string expires_time     UTC 'Y-m-d H:i:s'
	 *   @type int    seconds_left     How long the administrator has
	 * }
	 */
	public static function pending() {
		$raw = self::read_setting(self::REQUEST_SETTING);
		if (trim((string)$raw) === '') {
			return null;
		}
		$req = json_decode((string)$raw, true);
		if (!is_array($req) || empty($req['job_id']) || empty($req['challenge'])) {
			return null;
		}

		$expires = strtotime((string)($req['expires_time'] ?? '') . ' UTC');
		$left = $expires ? ($expires - time()) : 0;
		if ($left <= 0) {
			return null;
		}

		return array(
			'job_id'       => (int)$req['job_id'],
			'primitive'    => (string)($req['primitive'] ?? ''),
			'summary'      => (string)($req['summary'] ?? ''),
			'facts'        => is_array($req['facts'] ?? null) ? $req['facts'] : array(),
			'challenge'    => (string)$req['challenge'],
			'public_key'   => (string)($req['public_key'] ?? ''),
			'info'         => (string)($req['info'] ?? self::BROWSER_INFO),
			'issued_time'  => (string)($req['issued_time'] ?? ''),
			'expires_time' => (string)($req['expires_time'] ?? ''),
			'seconds_left' => (int)$left,
		);
	}

	/**
	 * Hand the agent what the administrator recovered from the challenge.
	 *
	 * Nothing is validated here beyond shape, and that is deliberate rather than
	 * lax: this side cannot tell a right answer from a wrong one — the secret is
	 * inside a box only the recovery key opens — and a check that could be
	 * written here would be a check a compromised web tier could pass. The agent
	 * compares in constant time against what it sealed, and that comparison is
	 * the only one that means anything.
	 *
	 * The job id is carried so an answer can never satisfy a different restore.
	 * It is bound INSIDE the sealed plaintext as well, so this field is a
	 * convenience for the agent's own matching rather than something an attacker
	 * gains by editing.
	 */
	public static function answer($job_id, $answer) {
		$pending = self::pending();
		if ($pending === null) {
			throw new RestoreApprovalException(
				'There is no restore waiting for approval on this machine — it may have expired, or the '
				. 'management node may have given up on it. Ask for the restore again.');
		}
		if ((int)$job_id !== $pending['job_id']) {
			throw new RestoreApprovalException(
				'That approval is for a different restore than the one waiting. Reload this page.');
		}
		$answer = trim((string)$answer);
		if ($answer === '') {
			throw new RestoreApprovalException(
				'Open the challenge with your recovery key first — the box above does that in your browser.');
		}
		if (strlen($answer) > 4096) {
			throw new RestoreApprovalException('That is not what the challenge opens to.');
		}

		self::write_setting(self::ANSWER_SETTING, (string)json_encode(array(
			'job_id' => (int)$job_id,
			'answer' => $answer,
		)));
	}

	/**
	 * Say no. The agent stops waiting and reports the job refused, which reads
	 * on the management node as a decision rather than a fault — because it is
	 * one.
	 */
	public static function decline($job_id) {
		$pending = self::pending();
		if ($pending === null || (int)$job_id !== $pending['job_id']) {
			throw new RestoreApprovalException('There is no restore waiting for approval on this machine.');
		}
		self::write_setting(self::ANSWER_SETTING, (string)json_encode(array(
			'job_id'   => (int)$job_id,
			'declined' => true,
		)));
	}

	/**
	 * What the administrator must recover, described for the command-line
	 * fallback. The blob is opened the same way the possession challenge is,
	 * with the approval context in place of the possession one.
	 */
	public static function cli_hint(array $pending) {
		return 'The challenge is sealed to your recovery key with the context '
			. $pending['info'] . ' — the box on this page opens it in your browser, '
			. 'which is where your key should stay.';
	}

	// ── settings plumbing ──
	//
	// Read direct rather than through the settings singleton, for the reason
	// BackupRecoveryKey gives: the singleton memoizes, and this row changes
	// underneath a running process every time the agent stages or clears a
	// challenge.

	private static function read_setting($name) {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array($name));
			$v = $q->fetchColumn();
			return ($v === false) ? '' : (string)$v;
		} catch (\Throwable $e) {
			error_log('RestoreApproval: could not read ' . $name . ': ' . $e->getMessage());
			return '';
		}
	}

	private static function write_setting($name, $value) {
		require_once(PathHelper::getIncludePath('data/settings_class.php'));
		Setting::put($name, $value);
	}
}
