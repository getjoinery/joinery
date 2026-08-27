<?php
/**
 * Turn the agent on where one is already installed — the correction 175 owed.
 *
 * Migration 175 tried to do this and could not, for a reason worth recording
 * because it will catch the next person: it refused to act unless the
 * agent_enabled row was EMPTY, reasoning that a value meant an operator had
 * chosen one. But settings seeding writes the declared factory default, and it
 * does so before this runs. So 175 saw a value it had not written, read it as a
 * decision nobody had made, and left the switch off — on machines whose agents
 * were already running. The installer then did exactly what it is supposed to do
 * with an off switch and stopped them. Both plane agents went down mid-upgrade
 * on 2026-08-27, and every node that had just been given an agent needed the
 * installer run by hand.
 *
 * The guard was not merely wrong, it was VACUOUS at the only moment it ran. The
 * switch shipped in the same release as this migration; at the instant it
 * executes, no operator has ever had the opportunity to set it, so a falsy value
 * cannot mean anything except "nobody has chosen yet".
 *
 * So: enable where a binary is installed, whatever the current falsy value says.
 * An installed agent is evidence that this machine was meant to run one — it
 * only got there by someone installing it, back when running it was the only
 * thing installing it meant.
 *
 * This is a NEW migration rather than an edit to 175. Migrations are gated on
 * md5_file() of the migration file, so editing 175 would re-run it everywhere
 * under its old version number and its old record would stop describing what
 * actually happened. 175 stays as the history of what ran; this is what fixes it.
 *
 * Idempotent: a switch already on is left alone, so the only machine this
 * changes is one with an agent and a switch that was never really chosen.
 */
function enable_agent_where_it_was_already_installed() {
	require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));

	$db = DbConnector::get_instance()->get_db_link();

	$current = $db->query(
		"SELECT stg_value FROM stg_settings WHERE stg_name = 'agent_enabled'")->fetchColumn();

	if ($current === false) {
		// The row does not exist yet. On a fresh install that is correct — the
		// agent ships off — and there is nothing here to correct.
		echo "  agent_enabled: not seeded on this site — nothing to correct\n";
		return;
	}

	// Only a TRUTHY value is a decision worth respecting. Read with the same
	// helper the admin page and the CLI use, so three readers cannot disagree
	// about what 'on' looks like.
	if (admin_management_node_agent_switch_on((string)$current)) {
		echo "  agent_enabled: already on — left alone\n";
		return;
	}

	if (!file_exists(ADMIN_MANAGEMENT_NODE_AGENT_BINARY)) {
		echo "  agent_enabled: no agent installed on this machine — correctly left off\n";
		return;
	}

	$q = $db->prepare("UPDATE stg_settings SET stg_value = '1', stg_update_time = NOW() WHERE stg_name = 'agent_enabled'");
	$q->execute();
	echo "  agent_enabled: an agent is installed here — switched on so the installer does not stop it\n";
}
?>
