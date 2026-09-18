<?php
/**
 * AgentLogAccessNotice — the one-time admin-header notice on a node that was
 * already connected to a management node when log access arrived
 * (specs/agent_log_access.md §4.1).
 *
 * The log-access switch (agent_log_access) is on by default, and a node
 * connected after the switch existed saw it on the page it used to connect.
 * A node connected BEFORE that had the setting seeded on with no owner
 * action, which is the accepted default but must not be silent. So while
 * three stored facts hold — the node is connected, log access is on, and
 * nobody has acknowledged it — every admin page says so once, in the owner's
 * words, with the switch one click away.
 *
 * Reads STORED facts only (three settings rows). Acknowledging is a POST to
 * the Management Node page, from the button here or from the switch there:
 * never a write made because somebody viewed a page.
 *
 * @version 1.0
 */
class AgentLogAccessNotice {

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$settings = Globalvars::get_instance();
		if (!self::due(
				(string)$settings->get_setting('agent_join_state'),
				(string)$settings->get_setting('agent_log_access'),
				(string)$settings->get_setting('agent_log_access_notice_seen'))) {
			return '';
		}
		return '<div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="alert">'
			. '<div style="flex:1 1 30rem;"><strong>Your management node can read this site\'s logs.</strong> '
			. 'It can ask for the last lines of the error and task logs and the newest rows of the login, request, event, '
			. 'form-error and webhook logs. Passwords, keys, tokens, addresses and the personal half of email addresses are '
			. 'masked on this machine before anything is sent. The switch is on the '
			. '<a href="/admin/admin_management_node">Management Node</a> page.</div>'
			. '<form method="POST" action="/admin/admin_management_node" class="m-0">'
			. '<input type="hidden" name="action" value="log_access_notice_seen">'
			. '<button type="submit" class="btn btn-sm btn-outline-secondary">Got it</button>'
			. '</form>'
			. '</div>';
	}

	/**
	 * Is the notice due, given the three stored values? Pure, so the test asks
	 * exactly the question the renderer asks.
	 *
	 * Connected means the join state the agent reports says so; a request on
	 * the table, a rejection or an empty state is not a connection, and a node
	 * nobody is connected to has nobody to be told about.
	 */
	public static function due(string $join_state, string $log_access, string $seen): bool {
		if (in_array(strtolower(trim($seen)), array('1', 'true', 'yes', 'on'), true)) {
			return false;
		}
		if (!in_array(strtolower(trim($log_access)), array('1', 'true', 'yes', 'on'), true)) {
			return false;
		}
		$state = json_decode($join_state, true);
		return is_array($state) && (string)($state['status'] ?? '') === 'connected';
	}
}
