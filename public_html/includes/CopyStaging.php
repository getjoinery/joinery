<?php
/**
 * CopyStaging — download the backup chain a dormant copy will apply
 * (specs/site_copy.md WP4, G7).
 *
 * A copy cannot stage its source's chain with BackupStaging: that accepts
 * only artifacts in this machine's own upload ledger, which has never heard
 * of the source's runs, and opens the chain with this machine's own site key,
 * which the source's chain is not sealed to. This stages the other way, and
 * BackupStaging never learns that copies exist:
 *
 *   - The manifest is fetched first and kept only when its hash is one the
 *     source vouched for. The agent reads the vouch (the copy's state
 *     directory, written by copy_import from the source's signed export) and
 *     passes the hashes in; nothing here reads the record itself. A
 *     substituted manifest costs one small download, not a chain.
 *   - Every artifact the run needs is fetched under its manifest size as a
 *     ceiling, then checked against the manifest's size and hash. An artifact
 *     already in the workspace that matches is kept.
 *   - No key is written. copy_import wrote chain.key from the export.
 *
 * Failures are BackupStagingException, with the exit status as the code, as
 * BackupStaging's are. utils/copy_stage.php is the script around it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));

class CopyStaging {

	/** The largest manifest a copy will fetch: a 181-run chain's is a few hundred KB. */
	const MANIFEST_MAX_BYTES = 16777216;

	/** The most artifact links one request may carry: the chain words' bound. */
	const MAX_LINKS = 1024;

	/** Where a manifest lands before its hash is checked. */
	const INCOMING_SUFFIX = '.incoming';

	/**
	 * Understand a request: the agent's arguments and the management node's
	 * configuration.
	 *
	 * @param array $args   argv after the script name: --workspace PATH, --vouched SHA (repeated)
	 * @param mixed $config the decoded JSON from stdin
	 * @return array ['work','vouched','chain_id','manifest_url','artifact_urls','seq']
	 * @throws BackupStagingException code MALFORMED
	 */
	public static function parse_request(array $args, $config) {
		$work = '';
		$vouched = array();
		$args = array_values($args);
		for ($i = 0; $i < count($args); $i++) {
			$a = (string)$args[$i];
			if (($a === '--workspace' || $a === '--vouched') && isset($args[$i + 1])) {
				$v = (string)$args[++$i];
				if ($a === '--workspace') { $work = $v; } else { $vouched[] = $v; }
				continue;
			}
			throw new BackupStagingException('unrecognised argument: ' . $a, BackupStagingException::MALFORMED);
		}
		foreach ($vouched as $v) {
			if (!preg_match('/^[0-9a-f]{64}$/', $v)) {
				throw new BackupStagingException('a vouched manifest hash is not a hash', BackupStagingException::MALFORMED);
			}
		}
		if (!$vouched) {
			throw new BackupStagingException('the source vouched for no run, so nothing is downloaded',
				BackupStagingException::MALFORMED);
		}

		if (!is_array($config)) {
			throw new BackupStagingException('this run needs its configuration as JSON on stdin', BackupStagingException::MALFORMED);
		}
		$unknown = array_diff(array_keys($config), array('chain_id', 'manifest_url', 'artifact_urls', 'seq'));
		if ($unknown) {
			sort($unknown);
			throw new BackupStagingException('configuration carries unrecognised key(s): ' . implode(', ', $unknown),
				BackupStagingException::MALFORMED);
		}
		$chain_id = (string)($config['chain_id'] ?? '');
		// The agent derives the workspace from the chain id; the two must name
		// the same chain, or the links are for another one.
		if (!preg_match(BackupStaging::CHAIN_ID_PATTERN, $chain_id) || $work === '' || $work[0] !== '/'
			|| basename($work) !== 'restore_' . $chain_id) {
			throw new BackupStagingException('the chain id does not name this workspace', BackupStagingException::MALFORMED);
		}
		if (!BackupFetch::is_signed_url((string)($config['manifest_url'] ?? ''))) {
			throw new BackupStagingException("'manifest_url' must be an https URL", BackupStagingException::MALFORMED);
		}
		$seq = (isset($config['seq']) && $config['seq'] !== '' && $config['seq'] !== null) ? (int)$config['seq'] : null;
		if ($seq !== null && ($seq < 0 || $seq > BackupStaging::MAX_SEQ)) {
			throw new BackupStagingException('a chain run number must be between 0 and ' . BackupStaging::MAX_SEQ,
				BackupStagingException::MALFORMED);
		}
		return array(
			'work'          => rtrim($work, '/'),
			'vouched'       => $vouched,
			'chain_id'      => $chain_id,
			'manifest_url'  => (string)$config['manifest_url'],
			'artifact_urls' => BackupStaging::link_map($config['artifact_urls'] ?? null, 'artifact_urls',
				BackupStaging::LINK_NAME_PATTERN, self::MAX_LINKS),
			'seq'           => $seq,
		);
	}

	/**
	 * Stage the chain: the vouched manifest, then each artifact the run needs.
	 *
	 * @param array $req what parse_request() returned
	 * @param callable|null $progress fn('already staged'|'fetching', $name)
	 * @return array ['seq' => int, 'fetched' => int, 'bytes' => int]
	 * @throws BackupStagingException
	 */
	public static function stage(array $req, ?callable $progress = null) {
		$work = $req['work'];
		BackupStaging::prepare_workspace($work);

		$incoming = $work . '/' . BackupChain::MANIFEST_NAME . self::INCOMING_SUFFIX;
		$got = self::fetch($req['manifest_url'], $incoming, self::MANIFEST_MAX_BYTES);
		if (!$got['ok']) {
			@unlink($incoming);
			throw new BackupStagingException('could not bring back the chain manifest: ' . $got['error']);
		}
		$sum = (string)hash_file('sha256', $incoming);
		if (!in_array($sum, $req['vouched'], true)) {
			@unlink($incoming);
			throw new BackupStagingException('the manifest backup storage holds for ' . $req['chain_id'] . ' ('
				. substr($sum, 0, 16) . '…) is not one the source vouched for. Export again from the source: '
				. 'a backup may have run there since the last export');
		}
		if (!@rename($incoming, $work . '/' . BackupChain::MANIFEST_NAME)) {
			@unlink($incoming);
			throw new BackupStagingException('could not keep the chain manifest in ' . $work);
		}

		$manifest = BackupStaging::read_manifest($work, $req['chain_id']);
		$plan     = BackupStaging::plan($manifest, $req['seq']);

		$fetched = 0;
		$bytes   = 0;
		foreach (BackupChain::plan_artifacts($plan) as $item) {
			$entry = $item['entry'];
			$name  = (string)($entry['name'] ?? '');
			if ($name === '') { continue; }
			if ($name !== basename($name) || empty($entry['sha256']) || empty($entry['bytes'])) {
				throw new BackupStagingException('the manifest names ' . $name
					. ' without the size and hash a copy checks it by');
			}
			$expected = array('bytes' => (int)$entry['bytes'], 'sha256' => (string)$entry['sha256']);
			$local = $work . '/' . $name;

			if (is_file($local)) {
				try {
					BackupChain::verify_artifact($local, $expected);
					if ($progress) { $progress('already staged', $name); }
					$fetched++;
					$bytes += (int)filesize($local);
					continue;
				} catch (BackupChainException $e) {
					@unlink($local);
				}
			}

			if (!isset($req['artifact_urls'][$name])) {
				throw new BackupStagingException('gone: ' . $name . ' is not in backup storage (no download link was '
					. 'signed for it), and run ' . (int)$plan['seq'] . ' needs it');
			}
			if ($progress) { $progress('fetching', $name); }
			$got = self::fetch($req['artifact_urls'][$name], $local, BackupFetch::size_ceiling($expected['bytes']));
			if (!$got['ok']) {
				@unlink($local);
				throw new BackupStagingException('could not bring back ' . $name . ': ' . $got['error']);
			}
			try {
				BackupChain::verify_artifact($local, $expected);
			} catch (BackupChainException $e) {
				@unlink($local);
				throw new BackupStagingException($e->getMessage());
			}
			$fetched++;
			$bytes += (int)filesize($local);
		}
		return array('seq' => (int)$plan['seq'], 'fetched' => $fetched, 'bytes' => $bytes);
	}

	/** One fetch, through BackupFetch or BackupStaging's test hook. */
	private static function fetch($url, $sink, $max_bytes) {
		if (BackupStaging::$fetch_for_tests !== null) {
			return call_user_func(BackupStaging::$fetch_for_tests, (string)$url, (string)$sink, (int)$max_bytes);
		}
		return BackupFetch::fetch((string)$url, (string)$sink, (int)$max_bytes);
	}
}
