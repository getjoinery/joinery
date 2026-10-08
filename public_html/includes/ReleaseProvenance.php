<?php
/**
 * ReleaseProvenance — what release this site is running, in the release's own
 * words (spec release_transparency, D7).
 *
 * A logged release ships its RELEASE_STATEMENT at the top of the code tree.
 * The statement names the version, the public commits the release was built
 * from, and the public log entry it was written to. This class reads that file
 * for the Updates page and for the upgrade's install record.
 *
 * It reads; it does not verify. The upgrade verified the statement before it
 * deployed the release, and a page served by the machine it describes cannot
 * prove anything about that machine anyway. The proof is utils/verify_release.php,
 * run somewhere else.
 *
 * @version 1.0
 */
class ReleaseProvenance {

	/** The public repositories every logged release is built from (D1). */
	const CORE_REPO  = 'https://github.com/getjoinery/joinery';
	const AGENT_REPO = 'https://github.com/getjoinery/joinery-agent';

	/**
	 * The running release as its statement records it, or null when the tree
	 * carries no statement (a release from before logging, or the origin's own
	 * working tree) or the file is not one a publish writes.
	 *
	 * @return array{version:string, core_commit:string, agent_commit:string,
	 *               published_at:string, log_origin:string, log_index:?int}|null
	 */
	public static function running(?string $live_directory = null): ?array {
		$dir = rtrim($live_directory ?? PathHelper::getRootDir(), '/');
		$path = $dir . '/' . PackageSignature::STATEMENT_NAME;
		if (!is_file($path)) {
			return null;
		}
		return self::read((string)@file_get_contents($path));
	}

	/** The fields running() returns, from a statement file's bytes; null when they are not a statement. */
	public static function read(string $body): ?array {
		$file = json_decode($body, true);
		if (!is_array($file) || !is_array($file['envelope'] ?? null)) {
			return null;
		}
		$payload = json_decode((string)base64_decode((string)($file['envelope']['payload'] ?? ''), true), true);
		if (!is_array($payload) || trim((string)($payload['version'] ?? '')) === '') {
			return null;
		}
		$entry = is_array($file['entry'] ?? null) ? $file['entry'] : array();
		return array(
			'version'      => (string)$payload['version'],
			'core_commit'  => self::commit($payload['core_commit'] ?? ''),
			'agent_commit' => self::commit($payload['agent_commit'] ?? ''),
			'published_at' => (string)($payload['published_at'] ?? ''),
			'log_origin'   => (string)($entry['log_origin'] ?? ''),
			'log_index'    => isset($entry['log_index']) && is_numeric($entry['log_index']) ? (int)$entry['log_index'] : null,
		);
	}

	/** A commit's page in its public repository; '' for anything that is not a full commit hash. */
	public static function commit_url(string $repo, string $commit): string {
		return self::commit($commit) === '' ? '' : $repo . '/commit/' . $commit;
	}

	private static function commit($value): string {
		$value = strtolower(trim((string)$value));
		return preg_match('/^[0-9a-f]{40}$/', $value) ? $value : '';
	}
}
