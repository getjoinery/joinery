<?php
/**
 * VaultHostHealth - the host facts that keep an open vault window's key off
 * the disk, summarized for the places a person looks.
 *
 * VaultHealth holds the checks. Five of them are about the key reaching a
 * disk: APCu backed by a file, the RAM store off tmpfs or readable by others,
 * a PHP worker core dump, an exception trace carrying the key as an argument,
 * and swap in the clear. Each can stop holding without anyone touching the
 * site (an OS upgrade brings apport back, swap is added unencrypted), and
 * none of them blocks anything. This makes an unmet one visible: an admin
 * notice on the site (VaultHostNotice) and a count with the check names in
 * the stats the management node reads, which ambers the node's badge.
 *
 * The other VaultHealth rows each have their own notice already (parser jail,
 * unseal daemon, host converger, certificates) and are not repeated here.
 *
 * Only a site that holds a vault has a key to leak this way, so a site with
 * none reports nothing (relevant()).
 *
 * The facts are the web process's: it holds the windows, and three of the
 * checks (core-dump limit, exception arguments, APCu) read the calling
 * process's own settings. So only a web request's answer is cached and sent
 * to the management node. A CLI caller (a test, a shell) gets a live run that
 * describes the CLI, not the site's workers. An all-met answer is cached for
 * an hour; an unmet one for five minutes, so an operator who fixes the host
 * sees the notice go soon after.
 *
 * @version 1.1 - unmet answers cached for five minutes, apcu_enabled(), one run per request, relevant(),
 *                fromWebProcess(), unknown check names in forStats() (reviewer2 B12-B16)
 * @version 1.0 (specs/security_weak_spots_2026_11.md WP3)
 */
class VaultHostHealth {

	const CACHE_KEY = 'vault_host_health';
	const CACHE_TTL = 3600;
	const CACHE_TTL_UNMET = 300;

	/** @var array|null this request's summary */
	private static $memo = null;

	/** The five checks, run now. */
	public static function rows(): array {
		return array(
			VaultHealth::checkApcuAnonymous(),
			VaultHealth::checkRamStore(),
			VaultHealth::checkCoredumpsDisabled(),
			VaultHealth::checkExceptionArgs(),
			VaultHealth::checkSwapSafe(),
		);
	}

	/** Is this the web process, whose facts these are? */
	public static function fromWebProcess(): bool {
		return PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
	}

	/** Does this site hold any vault, so that a key could be open here at all? */
	public static function relevant(): bool {
		$q = DbConnector::get_instance()->get_db_link()->query(
			'SELECT 1 FROM uev_user_encryption_vaults LIMIT 1');
		return $q !== false && $q->fetchColumn() !== false;
	}

	/**
	 * What the rows say, as a summary: how many were checked, the unmet ones
	 * with their words, and the keys of any that could not be read. Pure, so a
	 * test can hand it fixture rows.
	 *
	 * @return array{checked:int, unmet:array<array{key:string,label:string,reason:string}>, unknown:string[]}
	 */
	public static function summarize(array $rows): array {
		$unmet = array();
		$unknown = array();
		foreach ($rows as $row) {
			$state = (string)($row['state'] ?? '');
			if ($state === 'unmet') {
				$unmet[] = array(
					'key'    => (string)($row['key'] ?? ''),
					'label'  => (string)($row['label'] ?? ''),
					'reason' => (string)($row['reason'] ?? ''),
				);
			} elseif ($state !== 'verified') {
				$unknown[] = (string)($row['key'] ?? '');
			}
		}
		return array('checked' => count($rows), 'unmet' => $unmet, 'unknown' => $unknown);
	}

	/** The summary for this process: once per request, and from the cache in a web request. */
	public static function summary(): array {
		if (self::$memo !== null) {
			return self::$memo;
		}
		$cacheable = self::fromWebProcess() && function_exists('apcu_enabled') && apcu_enabled();
		$key = self::CACHE_KEY . ':' . md5(PathHelper::getSiteRoot());
		if ($cacheable) {
			$hit = false;
			$cached = ApcuStore::fetch($key, $hit);
			if ($hit && is_array($cached) && isset($cached['unmet'])) {
				return self::$memo = $cached;
			}
		}
		$summary = self::summarize(self::rows());
		if ($cacheable) {
			apcu_store($key, $summary, $summary['unmet'] ? self::CACHE_TTL_UNMET : self::CACHE_TTL);
		}
		return self::$memo = $summary;
	}

	/**
	 * What goes to the management node: counts and check names only. A
	 * reason can name a path or a device, which is the node's business, so it
	 * stays on the node; the badge links there.
	 *
	 * @return array{checked:int, unmet:int, unmet_checks:string[], unknown:int, unknown_checks:string[]}
	 */
	public static function forStats(array $summary): array {
		return array(
			'checked'        => (int)($summary['checked'] ?? 0),
			'unmet'          => count($summary['unmet'] ?? array()),
			'unmet_checks'   => array_values(array_map(function ($u) { return (string)$u['key']; }, $summary['unmet'] ?? array())),
			'unknown'        => count($summary['unknown'] ?? array()),
			'unknown_checks' => array_values(array_map('strval', $summary['unknown'] ?? array())),
		);
	}
}
?>
