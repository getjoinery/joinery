<?php
/**
 * The agent's on/off switch arrives defaulting to off (specs/agent_on_node_architecture.md).
 *
 * That default is right for the fleet — a machine should not start running a
 * root service because it took an upgrade — but wrong for the handful of
 * machines already running one. The installer stops the agent wherever the
 * setting is off, so shipping the default unaltered would switch off every
 * agent that works today, at the first root moment after the upgrade.
 *
 * So the setting is seeded from what is actually on the machine: an installed
 * binary means the agent was wanted here, and stays on. Nothing else is
 * inferred — this does not connect anything to a management node, and a
 * machine with no agent is left off.
 *
 * Idempotent: only writes when the setting has no value yet, so an operator who
 * later turns the agent off is not overruled by a re-run.
 */
function enable_agent_where_already_installed() {
	$db = DbConnector::get_instance()->get_db_link();

	$current = $db->query(
		"SELECT stg_value FROM stg_settings WHERE stg_name = 'agent_enabled'")->fetchColumn();

	if ($current === false) {
		echo "  agent_enabled: not seeded yet (settings sync pending) — nothing to do\n";
		return;
	}
	if (trim((string)$current) !== '') {
		echo "  agent_enabled: already set to '" . trim((string)$current) . "' — left alone\n";
		return;
	}

	// The binary, not the setting, is the evidence: this runs on a machine
	// whose agent has been installed and running since before a switch existed.
	if (!file_exists('/usr/local/bin/joinery-agent')) {
		echo "  agent_enabled: no agent installed on this machine — left off\n";
		return;
	}

	$q = $db->prepare("UPDATE stg_settings SET stg_value = '1' WHERE stg_name = 'agent_enabled'");
	$q->execute();
	echo "  agent_enabled: an agent is already installed here — switched on so the upgrade does not stop it\n";
}
?>
