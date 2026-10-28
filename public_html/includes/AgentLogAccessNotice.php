<?php
/**
 * AgentLogAccessNotice — the one-time admin-header notice on a node that was
 * already connected to a management node when log access arrived
 * (specs/agent_log_access.md §4.1).
 *
 * The log-access switch (agent_log_access) starts off on a new install, and a
 * node connected after the switch existed saw it on the page it used to
 * connect. A node connected BEFORE that had the setting seeded on with no
 * owner action, which must not be silent. So while
 * three stored facts hold — the node is connected, log access is on, and
 * nobody has acknowledged it — every admin page says so once, in the owner's
 * words, with the switch one click away.
 *
 * Reads STORED facts only (three settings rows). Acknowledging is a POST to
 * the Management Node page, from the button here or from the switch there:
 * never a write made because somebody viewed a page.
 *
 * @version 1.2 - the header names the switch's default as off on a new install
 * @version 1.1 - Calmer wording: what the node looks at and why, what is hidden, and where the switch is
 * @version 1.0
 */
class AgentLogAccessNotice {

	public static function render(): string {
		if (!Access::permits(10)) {
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
			. '<div style="flex:1 1 30rem;">Your management node can look at this site\'s error and activity logs when it needs to '
			. 'troubleshoot. Passwords, keys, tokens and email addresses are hidden on this machine before anything is sent. '
			. 'You can turn this off any time on the <a href="/admin/admin_management_node">Management Node</a> page.</div>'
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
