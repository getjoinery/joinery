<?php
/**
 * v1_census.php - what on this site is still on an old X25519 (v1) key, as ONE
 * JSON object on stdout, before the release that removes every v1 opener
 * (strategy/v1_key_retirement.md, § Before the deletion release).
 *
 * TEMPORARY. The v1_census observe word (agent 1.103.0) runs it, and the
 * deletion release removes the word, this file and the plane's handling of it
 * together with the v1 code.
 *
 * The agent runs this file, verified against the release manifest, with no
 * argv and no stdin; anyone can run it by hand the same way:
 *   sudo -u www-data php maintenance_scripts/sysadmin_tools/v1_census.php
 *
 * What it prints (no member data: user ids and permission levels, never an
 * address, a name or a key):
 *   v1_vaults     vaults on a 32-byte key, by scope, and their owners by id
 *   v2_blobs      v1.seal. / v1.edgeseal. values in any text column, by table
 *                 and column; 'leftover' counts rows whose owner's account
 *                 vault is already X-Wing, which a completed rotation should
 *                 have re-sealed
 *                 and 'generations' the key generation those rows record
 *   v3_chains     vaults with retired keys in their chain, and the entries
 *                 (their version only shows under the vault's key; the
 *                 deletion drops the v1 ones)
 *   v4_relays     each live relay's transport key kind, and whether a retired
 *                 secret is still kept (the spool not yet drained)
 *   v5            this node's break-glass copy (whose key, and that key's kind)
 *                 and, on a management node, live escrow rows with a v1 box
 *   v6            backup keys (informational: old backups are the accepted loss)
 *   unscanned     tables the V2 scan could not finish in time (each counts as a
 *                 finding: 20 s a table, four minutes in all)
 *   findings      what is left to handle; 0 means clear
 *
 * It reads and never writes: every query runs in a READ ONLY transaction. It
 * lives in sysadmin_tools/, outside the web root; the CLI check below is the
 * second line.
 *
 * Exit 0 with the object; exit 1 with an ERROR: line when it could not read.
 *
 * Validate with `php -l` only - never the file validator, which executes the
 * file it is checking.
 *
 * @version 1.1 - each V2 column names the key generations its rows record, and V3
 *               counts chain entries not yet swept (VaultChainSweep)
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

if (count($argv) > 1) {
	fwrite(STDERR, "Usage: php v1_census.php (it takes no arguments)\n");
	exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');

try {
	$db = DbConnector::get_instance()->get_db_link();
	$db->exec('BEGIN TRANSACTION READ ONLY');
	$out = array('findings' => 0);

	$q = function ($ident) { return '"' . str_replace('"', '""', $ident) . '"'; };
	$all_cols = array();
	foreach ($db->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public'") as $c) {
		$all_cols[$c['table_name']][] = $c['column_name'];
	}
	$old_key_sql = "length(trim(coalesce(uev_public_key, ''))) BETWEEN 1 AND 99";

	// V1: vaults on a v1 key.
	$by_scope = $db->query("SELECT uev_scope, count(*) FROM uev_user_encryption_vaults WHERE $old_key_sql
		GROUP BY 1 ORDER BY 2 DESC, 1")->fetchAll(PDO::FETCH_KEY_PAIR);
	$owners = array();
	foreach ($db->query("SELECT v.uev_usr_user_id AS id, u.usr_permission AS perm, (u.usr_delete_time IS NOT NULL) AS deleted,
			string_agg(v.uev_scope, ',' ORDER BY v.uev_scope) AS scopes
			FROM uev_user_encryption_vaults v LEFT JOIN usr_users u ON u.usr_user_id = v.uev_usr_user_id
			WHERE $old_key_sql GROUP BY 1, 2, 3 ORDER BY 1 LIMIT 200") as $r) {
		$owners[] = array('user_id' => (int)$r['id'], 'permission' => (int)$r['perm'], 'deleted' => (bool)$r['deleted'], 'scopes' => $r['scopes']);
	}
	$out['v1_vaults'] = array(
		'total'    => (int)$db->query('SELECT count(*) FROM uev_user_encryption_vaults')->fetchColumn(),
		'old'      => array_sum(array_map('intval', $by_scope)),
		'by_scope' => array_map('intval', $by_scope),
		'owners'   => $owners,
	);
	$out['findings'] += $out['v1_vaults']['old'];

	// V2: framed v1 blobs in any text column. Owners whose account vault is on
	// X-Wing rotated; a blob of theirs is a leftover.
	$rotated = array();
	foreach ($db->query("SELECT uev_usr_user_id FROM uev_user_encryption_vaults
			WHERE uev_scope = 'user' AND length(trim(coalesce(uev_public_key, ''))) >= 100") as $r) {
		$rotated[(int)$r['uev_usr_user_id']] = true;
	}
	$text_cols = array();
	foreach ($db->query("SELECT c.table_name, c.column_name FROM information_schema.columns c
			JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
			WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE'
			AND c.data_type IN ('text', 'character varying', 'character')
			ORDER BY c.table_name, c.ordinal_position") as $c) {
		$text_cols[$c['table_name']][] = $c['column_name'];
	}
	$prefixes = array('v1.seal.', 'v1.edgeseal.');
	$blobs = array();
	$unscanned = array();
	$v2_total = 0;
	$v2_leftover = 0;
	// The agent gives an observe word five minutes: four for the scan, each
	// table at most 20 s. A table not reached or not finished is unscanned.
	$scan_until = microtime(true) + 240;
	foreach ($text_cols as $table => $columns) {
		if (microtime(true) > $scan_until) {
			$unscanned[] = $table;
			continue;
		}
		// Whose key a row is sealed to first, then the row's owner, then any user column.
		$owner = null;
		foreach (array('/_sealed_owner_user_id$/', '/_owner_usr_user_id$/', '/_owner_user_id$/', '/_usr_user_id$/') as $pattern) {
			foreach ($all_cols[$table] ?? array() as $c) {
				if ($owner === null && preg_match($pattern, $c)) {
					$owner = $c;
				}
			}
		}
		$filters = array();
		foreach ($columns as $col) {
			foreach ($prefixes as $p) {
				$filters[] = $q($col) . " LIKE '" . $p . "%'";
			}
		}
		$db->exec('SAVEPOINT v1_census_table');
		try {
			$db->exec("SET LOCAL statement_timeout = '20s'");
			$hits = (int)$db->query('SELECT count(*) FROM ' . $q($table) . ' WHERE ' . implode(' OR ', $filters))->fetchColumn();
			if ($hits > 0) {
				foreach ($columns as $col) {
					foreach ($prefixes as $p) {
						$groups = $db->query('SELECT ' . ($owner ? $q($owner) : 'NULL') . ' AS owner, count(*) AS n FROM ' . $q($table)
							. ' WHERE ' . $q($col) . " LIKE '" . $p . "%' GROUP BY 1")->fetchAll(PDO::FETCH_ASSOC);
						if (!$groups) {
							continue;
						}
						$n = 0; $left = 0; $no_owner = 0; $owner_ids = array();
						foreach ($groups as $g) {
							$n += (int)$g['n'];
							if ($g['owner'] === null) {
								$no_owner += (int)$g['n'];
								continue;
							}
							$owner_ids[(int)$g['owner']] = (int)$g['n'];
							if (isset($rotated[(int)$g['owner']])) {
								$left += (int)$g['n'];
							}
						}
						arsort($owner_ids);
						// The generation each row records: the chain sweep moves a row
						// only when its generation names a retired key in the chain.
						$generations = array();
						$gen_col = substr($col, 0, 4) . 'key_generation';
						if (in_array($gen_col, $all_cols[$table] ?? array(), true)) {
							foreach ($db->query('SELECT ' . $q($gen_col) . ' AS g, count(*) AS n FROM ' . $q($table)
									. ' WHERE ' . $q($col) . " LIKE '" . $p . "%' GROUP BY 1 ORDER BY 1") as $g) {
								$generations[$g['g'] === null ? 'null' : (string)(int)$g['g']] = (int)$g['n'];
							}
						}
						$blobs[] = array('table' => $table, 'column' => $col, 'prefix' => $p, 'count' => $n,
							'leftover' => $left, 'no_owner' => $no_owner, 'owners' => array_slice($owner_ids, 0, 20, true),
							'generations' => $generations);
						$v2_total += $n;
						$v2_leftover += $left;
					}
				}
			}
			$db->exec('RELEASE SAVEPOINT v1_census_table');
		} catch (PDOException $e) {
			$db->exec('ROLLBACK TO SAVEPOINT v1_census_table');
			$unscanned[] = $table;
		}
	}
	$out['v2_blobs'] = array('total' => $v2_total, 'leftover' => $v2_leftover, 'columns' => $blobs);
	$out['unscanned'] = $unscanned;
	$out['findings'] += $v2_total + count($unscanned);

	// V3: retired keys kept in a vault's chain. Informational.
	$chains = 0; $entries = 0; $unswept = 0;
	foreach ($db->query("SELECT uev_previous_keys FROM uev_user_encryption_vaults
			WHERE coalesce(uev_previous_keys, '') NOT IN ('', '[]')") as $r) {
		$list = json_decode((string)$r['uev_previous_keys'], true);
		$chains++;
		foreach (is_array($list) ? $list : array() as $e) {
			$entries++;
			$unswept += empty($e['swept']) ? 1 : 0;
		}
	}
	$out['v3_chains'] = array('vaults' => $chains, 'entries' => $entries, 'unswept' => $unswept);

	// V4: relay transport keys.
	$relays = array();
	if (isset($all_cols['mrl_mailbox_relays'])) {
		foreach ($db->query('SELECT mrl_mailbox_relay_id, mrl_transport_public_key, mrl_transport_secret_retired_sealed
				FROM mrl_mailbox_relays WHERE mrl_delete_time IS NULL ORDER BY 1') as $r) {
			$raw = SealedBox::b64url_decode((string)$r['mrl_transport_public_key']);
			$kind = ($raw === false || $raw === '') ? 'none' : (strlen($raw) === 32 ? 'x25519' : 'xwing');
			$kept = (string)$r['mrl_transport_secret_retired_sealed'] !== '';
			$relays[] = array('relay_id' => (int)$r['mrl_mailbox_relay_id'], 'transport_kind' => $kind, 'retired_kept' => $kept);
			$out['findings'] += ($kind === 'x25519' ? 1 : 0) + ($kept ? 1 : 0);
		}
	}
	$out['v4_relays'] = $relays;

	// V5: break-glass copies.
	$report = BackupRecoveryKey::key_report();
	$st = DataRootStatus::read();
	$v5 = array('encrypted' => DataRootStatus::encrypted($st), 'sealed_to' => '', 'recovery_kind' => '', 'escrows_v1' => null);
	if ($v5['encrypted']) {
		$v5['sealed_to'] = DataRootStatus::sealed_to($st, $report['fingerprint']);
		$v5['recovery_kind'] = BackupRecoveryKey::key_kind(BackupRecoveryKey::public_key());
		if ($v5['sealed_to'] !== 'this_site' || $v5['recovery_kind'] !== 'xwing') {
			$out['findings']++;
		}
	}
	if (isset($all_cols['dre_data_root_escrows']) && class_exists('MultiDataRootEscrow')) {
		$ids = array();
		foreach (new MultiDataRootEscrow(array()) as $escrow) {
			$parts = $escrow->box_parts();
			if ($parts !== null && $parts['version'] === SealedBox::V1 && (string)$escrow->get('dre_status') !== 'retired') {
				$ids[] = (int)$escrow->key;
			}
		}
		$v5['escrows_v1'] = $ids;
		$out['findings'] += count($ids);
	}
	$out['v5'] = $v5;

	// V6: backup keys. Informational.
	$out['v6'] = BackupRecoveryKey::keys_report();

	$db->exec('ROLLBACK');
	echo json_encode($out) . "\n";
} catch (\Throwable $e) {
	echo 'ERROR: the v1 census could not be taken: ' . get_class($e) . "\n";
	exit(1);
}
exit(0);
