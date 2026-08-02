<?php
/**
 * Scrub inlined cloud-storage credential values out of historical job rows.
 *
 * Management-job rows persist forever. Upload/download steps reach the agent
 * with only a __SM_CREDS_<id>__ placeholder that is resolved in memory — but
 * rows built before that carried the decrypted credential array inline in
 * mjb_commands. This replaces every stored secret value (and, defensively,
 * node API secret values) with __SM_SCRUBBED__ in mjb_commands and
 * mjb_output. A scrubbed historical job can no longer be re-run as-is —
 * rebuild the job from the dashboard instead.
 *
 * Matching is by literal value, which covers the realistic case: provider
 * secrets are alphanumeric(+/), so they appear unescaped inside the stored
 * JSON text. Idempotent — once scrubbed, nothing matches.
 */
function scrub_job_row_inline_credentials() {
	$db = DbConnector::get_instance()->get_db_link();

	// The plugin's tables may not exist on a site that never activated it.
	$has_jobs    = $db->query("SELECT to_regclass('mjb_management_jobs')")->fetchColumn();
	$has_targets = $db->query("SELECT to_regclass('bkt_backup_targets')")->fetchColumn();
	if (!$has_jobs) {
		echo "server_manager job table absent — nothing to scrub.\n";
		return;
	}

	$secrets = [];

	if ($has_targets) {
		require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
		foreach ($db->query("SELECT bkt_id FROM bkt_backup_targets")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
			try {
				$target = new BackupTarget(intval($tid), TRUE);
				$creds  = $target->get_credentials();
			} catch (\Throwable $e) {
				echo "Target {$tid}: credentials unreadable — skipped ({$e->getMessage()}).\n";
				continue;
			}
			foreach (['secret_key', 'application_key', 'app_key'] as $k) {
				if (!empty($creds[$k]) && strlen((string)$creds[$k]) > 8) {
					$secrets[] = (string)$creds[$k];
				}
			}
		}
	}

	// Node API secrets too: no known builder persisted them, but a value match
	// anywhere in a job row deserves scrubbing regardless of how it got there.
	foreach ($db->query("SELECT mgn_api_secret_key FROM mgn_managed_nodes WHERE mgn_api_secret_key IS NOT NULL AND mgn_api_secret_key <> ''")->fetchAll(PDO::FETCH_COLUMN) as $s) {
		if (strlen((string)$s) > 8) {
			$secrets[] = (string)$s;
		}
	}

	$secrets = array_values(array_unique($secrets));
	if (empty($secrets)) {
		echo "No credential values available to scan for.\n";
		return;
	}

	$rows_touched = 0;
	foreach ($secrets as $secret) {
		$u = $db->prepare(
			"UPDATE mjb_management_jobs SET
				mjb_commands = replace(mjb_commands::text, :s1, '__SM_SCRUBBED__')::jsonb,
				mjb_output   = replace(coalesce(mjb_output, ''), :s2, '__SM_SCRUBBED__')
			 WHERE position(:s3 in mjb_commands::text) > 0
			    OR position(:s4 in coalesce(mjb_output, '')) > 0");
		$u->execute([':s1' => $secret, ':s2' => $secret, ':s3' => $secret, ':s4' => $secret]);
		$rows_touched += $u->rowCount();
	}

	echo "Scanned " . count($secrets) . " secret value(s); scrubbed {$rows_touched} job row(s).\n";
}
