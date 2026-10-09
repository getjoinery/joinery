<?php
/**
 * CopyKeyHandoff - a copy made from backups asks its owner, on its own page,
 * to open its backup's key (specs/site_copy.md WP10).
 *
 * When a site's server is dead, its copy is made from the site's backups
 * alone, and the one holder of the backups' key left is the owner's backup
 * recovery key. This machine's agent (copy_take_key) stages what the owner
 * should check: which backup, its newest run, when its storage provider says
 * it was stored, its manifest's hash, and the fingerprint of the recovery key
 * it is sealed to. The agent read all of it from the backup at its provider
 * (specs/storage_targets.md F7); the stored date is empty when the storage is
 * not at a provider it knows, and the page then says it could not be checked. The /copy-key page shows
 * it; the owner pastes their recovery key there, their browser works out the
 * one value that opens that backup's sealed key (X25519 of the recovery key
 * with the sealed box's ephemeral key), and only that value comes back here,
 * for the agent to finish opening the box. The recovery key never leaves the
 * browser, and the management node is not in the path at all: the page is
 * this machine's, reached through its look link.
 *
 * The handoff is two settings rows, as every approval's is (ApprovalChallenge).
 * Nothing here can tell a right answer from a wrong one; the agent checks the
 * key's fingerprint and the box, and writes a wrong answer's reason back into
 * the request for the page to show.
 *
 * The page does nothing unless a request is pending, and only the agent of a
 * dormant copy stages one, so on any other site it is an empty page.
 *
 * @version 1.1 - pending() carries stored_time and stored_at
 * @version 1.0
 */

class CopyKeyHandoffException extends Exception {}

class CopyKeyHandoff {

	const REQUEST_SETTING = 'copy_key_request';
	const ANSWER_SETTING  = 'copy_key_answer';

	/**
	 * The pending request, or null: the agent's statement with how long is
	 * left and whether an answer is already waiting for the agent.
	 */
	public static function pending(): ?array {
		$req = json_decode(self::read_setting(self::REQUEST_SETTING), true);
		if (!is_array($req) || empty($req['job_id']) || empty($req['ephemeral_public'])) {
			return null;
		}
		$expires = strtotime((string)($req['expires_time'] ?? '') . ' UTC');
		$left = $expires ? $expires - time() : 0;
		if ($left <= 0) {
			return null;
		}
		$ans = json_decode(self::read_setting(self::ANSWER_SETTING), true);
		$answered = is_array($ans) && (int)($ans['job_id'] ?? 0) === (int)$req['job_id'];
		return array(
			'job_id'               => (int)$req['job_id'],
			'site'                 => (string)($req['site'] ?? ''),
			'chain_id'             => (string)($req['chain_id'] ?? ''),
			'run_time'             => (string)($req['run_time'] ?? ''),
			'stored_time'          => (string)($req['stored_time'] ?? ''),
			'stored_at'            => (string)($req['stored_at'] ?? ''),
			'manifest_sha256'      => (string)($req['manifest_sha256'] ?? ''),
			'recovery_fingerprint' => (string)($req['recovery_fingerprint'] ?? ''),
			'ephemeral_public'     => (string)$req['ephemeral_public'],
			'last_error'           => (string)($req['last_error'] ?? ''),
			'answered'             => $answered,
			'seconds_left'         => (int)$left,
		);
	}

	/** Hand the agent what the owner's browser worked out. Shape only: the agent judges it. */
	public static function answer($job_id, $shared, $public_key): void {
		$pending = self::pending_for($job_id);
		foreach (array('shared' => $shared, 'public_key' => $public_key) as $what => $value) {
			$raw = base64_decode(trim((string)$value), true);
			if ($raw === false || strlen($raw) !== 32) {
				throw new CopyKeyHandoffException('Open the backup with your recovery key first: the box above does that in your browser.');
			}
		}
		self::write_setting(self::ANSWER_SETTING, (string)json_encode(array(
			'job_id'     => (int)$pending['job_id'],
			'shared'     => trim((string)$shared),
			'public_key' => trim((string)$public_key),
		)));
	}

	/** Say no: the copy takes no key, and its run stops. */
	public static function decline($job_id): void {
		$pending = self::pending_for($job_id);
		self::write_setting(self::ANSWER_SETTING, (string)json_encode(array('job_id' => (int)$pending['job_id'], 'declined' => true)));
	}

	private static function pending_for($job_id): array {
		$pending = self::pending();
		if ($pending === null || (int)$job_id !== $pending['job_id']) {
			throw new CopyKeyHandoffException('This copy is not waiting for that backup\'s key any more. Reload the page.');
		}
		if ($pending['answered']) {
			throw new CopyKeyHandoffException('An answer is already with this machine\'s agent. Reload the page in a few seconds.');
		}
		return $pending;
	}

	// Read direct, as ApprovalChallenge does: the settings singleton memoizes,
	// and these rows change under a running process.
	private static function read_setting(string $name): string {
		try {
			$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array($name));
			$v = $q->fetchColumn();
			return $v === false ? '' : (string)$v;
		} catch (\Throwable $e) {
			error_log('CopyKeyHandoff: could not read ' . $name . ': ' . $e->getMessage());
			return '';
		}
	}

	private static function write_setting(string $name, string $value): void {
		Setting::put($name, $value);
	}
}
