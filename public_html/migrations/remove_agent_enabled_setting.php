<?php
/**
 * Delete the agent_enabled row once nothing on this machine reads it
 * (AgentEnabledRow). update_database asks again on every run, so a machine
 * whose agent is still older keeps the row only until its agent updates.
 */
function remove_agent_enabled_setting() {
	$said = AgentEnabledRow::remove_when_safe();
	if ($said !== '') {
		echo '  ' . $said . "\n";
	}
	return true;
}
?>
