<?php
/**
 * Bug Reports plugin migrations.
 *
 * @version 1.0 - br_001 moves the statuses to an incident's choices: seen becomes new, closed resolved,
 *                muted ignored through the newest version its fault had reported
 */
return [
	[
		// A report is new, snoozed, resolved or ignored, as an incident is.
		// Seen read the same as new and is gone; closed is resolved; a muted
		// fault is ignored through the newest version it was reported from,
		// so the next release that still has it is news again.
		'id' => 'br_001_statuses_to_triage',
		'version' => '1.4.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$ready = $dblink->query("SELECT to_regclass('rbr_received_bug_reports') IS NOT NULL")->fetchColumn();
			if (!$ready) {
				return;   // no table yet: nothing to move
			}
			$has_column = $dblink->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns
				WHERE table_name = 'rbr_received_bug_reports' AND column_name = 'rbr_ignored_through')")->fetchColumn();
			if (!$has_column) {
				return 'defer';   // the column arrives with this pass's table update
			}
			$dblink->exec("UPDATE rbr_received_bug_reports SET rbr_status = 'new' WHERE rbr_status = 'seen'");
			$dblink->exec("UPDATE rbr_received_bug_reports SET rbr_status = 'resolved' WHERE rbr_status = 'closed'");
			$groups = $dblink->query("SELECT DISTINCT " . ReceivedBugReport::GROUP_KEY . " FROM rbr_received_bug_reports
				WHERE rbr_status = 'muted'")->fetchAll(PDO::FETCH_COLUMN);
			$versions = $dblink->prepare("SELECT DISTINCT rbr_claimed_version FROM rbr_received_bug_reports
				WHERE " . ReceivedBugReport::GROUP_KEY . " = ?");
			$ignore = $dblink->prepare("UPDATE rbr_received_bug_reports SET rbr_status = 'ignored', rbr_ignored_through = ?
				WHERE " . ReceivedBugReport::GROUP_KEY . " = ? AND rbr_status = 'muted'");
			foreach ($groups as $group) {
				$versions->execute(array($group));
				$through = ReceivedBugReport::newest_version($versions->fetchAll(PDO::FETCH_COLUMN));
				$ignore->execute(array(mb_substr($through, 0, 64), $group));
			}
		},
	],
];
