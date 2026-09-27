<?php
/**
 * ApprovalChallenge — a site asking its own administrator to consent to a
 * destructive act, answered with the site's backup recovery key.
 *
 * A root agent that is about to destroy something — this machine's own agent
 * before a restore, or a Docker host's agent before it removes this site —
 * refuses to act on the management node's word alone, because the management
 * node is exactly the party that must not be able to authorize it. A
 * management node that had been compromised would find these operations the
 * sharpest tools in the box: the ones designed to destroy, rather than ones
 * that destroy only through a bug.
 *
 * So the decision is made here, on the site whose data is at stake, by whoever
 * holds this site's backup recovery key. The management node is not in the
 * path at all — not as a gate, and not as a relay.
 *
 * HOW IT WORKS, in the order it happens:
 *
 *   1. The agent claims the job and runs NOTHING. It composes its own
 *      statement of what the job would do from its own records, not from
 *      anything the management node sent.
 *   2. It seals a one-time secret to the backup recovery public key this site
 *      already holds, binding it to that job and that statement, and writes
 *      the ciphertext into this site's settings table — the one storage the
 *      web tier and a root agent can both reach on every install.
 *   3. This class reads that row and ApprovalChallengePanel renders it on the
 *      Backups page.
 *   4. The administrator opens the challenge in the browser with the recovery
 *      key — the same in-browser flow that proved the key — and the recovered
 *      sentence is written back through answer().
 *   5. The agent compares it against what it sealed, and only then acts.
 *
 * ONE MECHANISM, SEVERAL SCOPES. What varies between the acts is the scope:
 * which settings rows carry the handoff, which HKDF context seals the
 * challenge, and every word the administrator reads. The agent's approvalScope
 * (approval.go) is the other half of each entry and pins the same strings. The
 * separation is total: an answer recovered for one scope can never satisfy
 * another, and each screen names its own act — staging one act into another's
 * rows would show consent copy for the wrong act, and informed consent is the
 * point of the ceremony. A new scope is one entry here and one in the agent.
 *
 * WHAT THIS CLASS CANNOT DO, which is the reassuring part: it cannot approve
 * anything. It moves a ciphertext one way and a recovered plaintext the other.
 * The secret is inside the box, so the whole of the web tier — this file
 * included — could be rewritten and still not produce an answer without the
 * private key, which is in the administrator's password manager and has never
 * been on a server.
 *
 * THE ONE THING IT DOES REST ON: this page is served by this site's own web
 * tier, so a compromised web tier HERE could show one thing while the
 * challenge binds another, and could capture the recovery key as it is typed.
 * That is the same trust the recovery-key setup ceremony already asks for.
 * Three things narrow it for a restore and none needs anyone to read
 * carefully: the machine keeps no local archives and holds a write-only bucket
 * credential, so a captured key has no ciphertext on the machine to open; the
 * agent refuses any archive absent from its own upload ledger whatever was
 * approved; and the archive's true age is a first-class line on the screen.
 * Beyond that, a machine whose web tier you suspect is not a machine to
 * restore in place — it is a machine to rebuild.
 *
 * @version 1.0 - one class for every scope; restore and decommission were two copies
 */

class ApprovalChallengeException extends Exception {}

class ApprovalChallenge {

	const RESTORE      = 'restore';
	const DECOMMISSION = 'decommission';

	/**
	 * Every scope, keyed by name. The handoff fields must match the agent's
	 * approvalScope of the same act; the rest is what the administrator reads.
	 *
	 *   request_setting  written by the agent, read here, never written from the web tier
	 *   answer_setting   written here, read and cleared by the agent
	 *   info             HKDF context; matches the agent's infoPrefix
	 *   approve_action / decline_action   the Backups page's POST actions
	 *   approve_form / decline_form       FormWriter ids, and so the CSRF token names
	 *   id_prefix        DOM id prefix for the key box
	 *   box_title … declined_notice       the screen, in reading order
	 */
	const SCOPES = array(
		self::RESTORE => array(
			'request_setting' => 'restore_approval_request',
			'answer_setting'  => 'restore_approval_answer',
			'info'            => 'joinery-restore-approval:',
			'approve_action'  => 'approve_restore',
			'decline_action'  => 'decline_restore',
			'approve_form'    => 'restore_approval_form',
			'decline_form'    => 'restore_decline_form',
			'id_prefix'       => 'ra',

			'none_waiting'    => 'There is no restore waiting for approval on this machine — it may have expired, or the '
			                   . 'management node may have given up on it. Ask for the restore again.',
			'wrong_job'       => 'That approval is for a different restore than the one waiting. Reload this page.',
			'none_to_decline' => 'There is no restore waiting for approval on this machine.',

			'box_title'       => 'Approve a restore',
			'headline'        => 'A restore is waiting for your approval.',
			// The age line is the point of this screen. Every other check is a
			// machine checking a machine, and all of them pass against the
			// attack that matters most: a compromised management node serving
			// this machine its OWN genuine month-old archive under a
			// fresh-looking name. Only the date is wrong, and only a person
			// can notice — so it is said here, above the key box.
			'intro'           => 'Everything below was written by this machine, from its own records — not by whoever '
			                   . 'asked for the restore. Check the date of the archive before you approve: an archive '
			                   . 'that is older than you expect is the one thing no automatic check can catch for you.',
			// This machine's agent runs one job at a time, so it runs nothing
			// else while it waits — which makes declining strictly better than
			// walking away, and nothing else would say so.
			'expiry_tail'     => '. Until you answer it, <strong>this machine runs nothing else</strong> — no backup, no '
			                   . 'status check, no upgrade. If you are not going to approve it, press <em>Decline</em> '
			                   . 'rather than closing this page: declining frees the machine now, and letting it expire '
			                   . 'leaves it waiting. Either way nothing is restored, and the restore can be asked for '
			                   . 'again.',
			'key_label'       => 'Paste your recovery key to approve',
			'key_help'        => 'The same key that opens this machine\'s backups. It is used in your browser and never '
			                   . 'sent anywhere — approving proves you hold it, which is the whole of the check.',
			'approve_button'  => 'Approve this restore',
			'decline_button'  => 'No — do not restore',
			'approved_notice' => 'Approved. This machine is checking your answer and will start the restore.',
			'declined_notice' => 'Declined. Nothing was restored, and the job is reported refused.',
		),

		// The site being destroyed renders this on ITS admin and answers with
		// ITS recovery key. The host's agent stages it over the container's
		// published database port; this site's own agent lives inside the
		// container being removed and cannot outlive the work.
		self::DECOMMISSION => array(
			'request_setting' => 'decommission_approval_request',
			'answer_setting'  => 'decommission_approval_answer',
			'info'            => 'joinery-decommission-approval:',
			'approve_action'  => 'approve_decommission',
			'decline_action'  => 'decline_decommission',
			'approve_form'    => 'decommission_approval_form',
			'decline_form'    => 'decommission_decline_form',
			'id_prefix'       => 'da',

			'none_waiting'    => 'There is no removal waiting for approval on this site — it may have expired, or been '
			                   . 'withdrawn. If this site is still meant to be removed, ask for the removal again.',
			'wrong_job'       => 'That approval is for a different removal than the one waiting. Reload this page.',
			'none_to_decline' => 'There is no removal waiting for approval on this site.',

			'box_title'       => 'Approve the permanent removal of this site',
			'headline'        => 'This site is about to be destroyed, permanently — and it will not happen without your '
			                   . 'approval.',
			// The load-bearing fact is this site's OWN record of its last
			// completed offsite upload: when something of it last left the
			// machine is what a person must weigh before agreeing to the rest.
			'intro'           => 'This is not a restore and not a move. Approving deletes this site\'s database, files and '
			                   . 'container from its server; only its offsite backups survive. Check the last-upload line '
			                   . 'below before you decide — it is this site\'s own record of when something of it last '
			                   . 'reached offsite storage.',
			'expiry_tail'     => '. If you are not going to approve it, press <em>Decline</em> rather than closing this '
			                   . 'page — declining answers now, and letting it expire leaves the host waiting. Either way '
			                   . 'nothing is deleted, and the removal can be asked for again.',
			'key_label'       => 'Paste this site\'s recovery key to approve its destruction',
			'key_help'        => 'The same key that opens this site\'s backups. It is used in your browser and never '
			                   . 'sent anywhere — approving proves you hold it, which is the whole of the check.',
			'approve_button'  => 'Approve — destroy this site permanently',
			'decline_button'  => 'No — keep this site',
			'approved_notice' => 'Approved. The host is checking your answer and will remove this site permanently.',
			'declined_notice' => 'Declined. Nothing was deleted, and the removal is reported refused.',
		),
	);

	/**
	 * Which scope shows first when more than one is pending. A removal
	 * outranks a restore: the page renders one ceremony at a time, because
	 * recovery-readiness.js binds one window.rrApproval and two key boxes on
	 * one screen is how a person answers the wrong one.
	 */
	const PRECEDENCE = array(self::DECOMMISSION, self::RESTORE);

	/** One scope's entry. An unknown name is a programming error. */
	public static function scope(string $name): array {
		if (!isset(self::SCOPES[$name])) {
			throw new InvalidArgumentException('Unknown approval scope: ' . $name);
		}
		return self::SCOPES[$name] + array('name' => $name);
	}

	/**
	 * The scope and verb a Backups page POST action names, or null when the
	 * action is not an approval's.
	 *
	 * @return array{scope:string, approve:bool}|null
	 */
	public static function for_action(string $action): ?array {
		foreach (self::SCOPES as $name => $s) {
			if ($action === $s['approve_action']) { return array('scope' => $name, 'approve' => true); }
			if ($action === $s['decline_action']) { return array('scope' => $name, 'approve' => false); }
		}
		return null;
	}

	/**
	 * Every scope with a live challenge, in PRECEDENCE order, as
	 * scope name => pending(). Empty when nothing is waiting.
	 */
	public static function all_pending(): array {
		$out = array();
		foreach (self::PRECEDENCE as $name) {
			$p = self::pending($name);
			if ($p !== null) { $out[$name] = $p; }
		}
		return $out;
	}

	/**
	 * The act waiting on an answer in this scope, or null.
	 *
	 * An expired challenge is null, not a stale screen. The agent gives up on
	 * its own schedule and stops watching for an answer, so an approval offered
	 * after that would be an administrator authorizing something that does not
	 * happen — and then, hours later, wondering whether it did.
	 *
	 * @return array|null {
	 *   @type int    job_id           The management job this is bound to
	 *   @type string primitive        The operation, as the agent names it
	 *   @type string summary          One plain sentence, composed by the agent
	 *   @type array  facts            [['label'=>..,'value'=>..], ...] in reading order
	 *   @type string challenge        base64 blob the browser opens
	 *   @type string public_key       base64 recovery public key it is sealed to
	 *   @type string info             HKDF context the browser must use
	 *   @type string expires_time     UTC 'Y-m-d H:i:s'
	 *   @type int    seconds_left     How long the administrator has
	 * }
	 */
	public static function pending(string $scope) {
		$s = self::scope($scope);
		$raw = self::read_setting($s['request_setting']);
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
			'info'         => (string)($req['info'] ?? $s['info']),
			'issued_time'  => (string)($req['issued_time'] ?? ''),
			'expires_time' => (string)($req['expires_time'] ?? ''),
			'seconds_left' => (int)$left,
		);
	}

	/**
	 * Hand the agent what the administrator recovered from the challenge.
	 *
	 * Nothing is validated here beyond shape, and that is deliberate rather
	 * than lax: this side cannot tell a right answer from a wrong one — the
	 * secret is inside a box only the recovery key opens — and a check that
	 * could be written here would be a check a compromised web tier could
	 * pass. The agent compares in constant time against what it sealed, and
	 * that comparison is the only one that means anything.
	 *
	 * The job id is carried so an answer can never satisfy a different job. It
	 * is bound INSIDE the sealed plaintext as well, so this field is a
	 * convenience for the agent's own matching rather than something an
	 * attacker gains by editing.
	 */
	public static function answer(string $scope, $job_id, $answer) {
		$s = self::scope($scope);
		$pending = self::pending($scope);
		if ($pending === null) {
			throw new ApprovalChallengeException($s['none_waiting']);
		}
		if ((int)$job_id !== $pending['job_id']) {
			throw new ApprovalChallengeException($s['wrong_job']);
		}
		$answer = trim((string)$answer);
		if ($answer === '') {
			throw new ApprovalChallengeException(
				'Open the challenge with your recovery key first — the box above does that in your browser.');
		}
		if (strlen($answer) > 4096) {
			throw new ApprovalChallengeException('That is not what the challenge opens to.');
		}

		self::write_setting($s['answer_setting'], (string)json_encode(array(
			'job_id' => (int)$job_id,
			'answer' => $answer,
		)));
	}

	/**
	 * Say no. The agent stops waiting and reports the job refused, which reads
	 * on the management node as a decision rather than a fault — because it is
	 * one.
	 */
	public static function decline(string $scope, $job_id) {
		$s = self::scope($scope);
		$pending = self::pending($scope);
		if ($pending === null || (int)$job_id !== $pending['job_id']) {
			throw new ApprovalChallengeException($s['none_to_decline']);
		}
		self::write_setting($s['answer_setting'], (string)json_encode(array(
			'job_id'   => (int)$job_id,
			'declined' => true,
		)));
	}

	// ── settings plumbing ──
	//
	// Read direct rather than through the settings singleton, for the reason
	// BackupRecoveryKey gives: the singleton memoizes, and these rows change
	// underneath a running process every time an agent stages or clears a
	// challenge.

	private static function read_setting($name) {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array($name));
			$v = $q->fetchColumn();
			return ($v === false) ? '' : (string)$v;
		} catch (\Throwable $e) {
			error_log('ApprovalChallenge: could not read ' . $name . ': ' . $e->getMessage());
			return '';
		}
	}

	private static function write_setting($name, $value) {
		Setting::put($name, $value);
	}
}
