<?php
/**
 * DecommissionApproval — this site asked for its own consent to be destroyed.
 *
 * A decommission permanently removes a container site from its shared Docker
 * host: the container, its database, its uploads, its configuration. The party
 * that runs the teardown is the HOST's root agent — this site's agent lives
 * inside the container being destroyed and cannot outlive the work — and the
 * party that dispatches it is the management node, which is exactly the party
 * that must not be able to authorize it. So the decision is made here, on the
 * site whose data dies, by whoever holds THIS site's backup recovery key. The
 * management node is not in the path at all — not as a gate, and not as a
 * relay.
 *
 * The mechanism is the restore approval's, under its own names: the host agent
 * composes a statement (including this site's own record of its last completed
 * offsite upload), seals a one-time secret to this site's proven recovery
 * public key, and stages the ciphertext into THIS site's settings table over
 * the container's published database port. This class reads that row, the
 * Backups page renders it, the administrator opens the challenge in the
 * browser with the recovery key, and the recovered sentence goes back the same
 * way. The host agent compares it against what it sealed, and only then tears
 * down.
 *
 * THE NAMES ARE DECOMMISSION'S OWN — settings rows, HKDF context, plaintext
 * tag. Staging a decommission into the restore rows would render consent copy
 * for the wrong act, and the binding would hold cryptographically while the
 * person approved something they were not told about. Informed consent is the
 * point of the ceremony, so the domain separation is total: an answer
 * recovered for a restore can never satisfy a decommission, and vice versa.
 * The Go side pins the same three strings (decommissionScope, victim_test.go).
 *
 * @version 1.0
 */

class DecommissionApprovalException extends Exception {}

class DecommissionApproval {

	/** Written by the HOST's agent over this site's DB port, read here. */
	const REQUEST_SETTING = 'decommission_approval_request';

	/** Written here, read and cleared by the host's agent. */
	const ANSWER_SETTING = 'decommission_approval_answer';

	/**
	 * HKDF context for the decommission challenge. Its own, so a decommission
	 * challenge, a restore challenge and a possession challenge can never be
	 * answers to one another. Must match decommissionScope.infoPrefix in the
	 * agent.
	 */
	const BROWSER_INFO = 'joinery-decommission-approval:';

	/**
	 * The decommission waiting on an answer, or null. Same contract as
	 * RestoreApproval::pending(): an expired challenge is null, not a stale
	 * screen.
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
	 * Hand back what the administrator recovered. Shape-only validation, for
	 * the reason RestoreApproval::answer() gives: this side cannot tell a
	 * right answer from a wrong one, and a check written here would be a check
	 * a compromised web tier could pass. The host agent compares in constant
	 * time against what it sealed.
	 */
	public static function answer($job_id, $answer) {
		$pending = self::pending();
		if ($pending === null) {
			throw new DecommissionApprovalException(
				'There is no removal waiting for approval on this site — it may have expired, or been '
				. 'withdrawn. If this site is still meant to be removed, ask for the removal again.');
		}
		if ((int)$job_id !== $pending['job_id']) {
			throw new DecommissionApprovalException(
				'That approval is for a different removal than the one waiting. Reload this page.');
		}
		$answer = trim((string)$answer);
		if ($answer === '') {
			throw new DecommissionApprovalException(
				'Open the challenge with your recovery key first — the box above does that in your browser.');
		}
		if (strlen($answer) > 4096) {
			throw new DecommissionApprovalException('That is not what the challenge opens to.');
		}

		self::write_setting(self::ANSWER_SETTING, (string)json_encode(array(
			'job_id' => (int)$job_id,
			'answer' => $answer,
		)));
	}

	/**
	 * Say no. The host's agent stops waiting and reports the job refused —
	 * a decision, recorded as one.
	 */
	public static function decline($job_id) {
		$pending = self::pending();
		if ($pending === null || (int)$job_id !== $pending['job_id']) {
			throw new DecommissionApprovalException('There is no removal waiting for approval on this site.');
		}
		self::write_setting(self::ANSWER_SETTING, (string)json_encode(array(
			'job_id'   => (int)$job_id,
			'declined' => true,
		)));
	}

	// ── settings plumbing ──
	//
	// Read direct rather than through the settings singleton: the HOST's agent
	// mutates this row underneath a running process when it stages or clears a
	// challenge, and the singleton memoizes.

	private static function read_setting($name) {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array($name));
			$v = $q->fetchColumn();
			return ($v === false) ? '' : (string)$v;
		} catch (\Throwable $e) {
			error_log('DecommissionApproval: could not read ' . $name . ': ' . $e->getMessage());
			return '';
		}
	}

	private static function write_setting($name, $value) {
		require_once(PathHelper::getIncludePath('data/settings_class.php'));
		Setting::put($name, $value);
	}
}
