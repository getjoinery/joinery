<?php
/**
 * The level each Server Manager page action needs (spec declared_permissions
 * WP6). A page opens at its header's level (the dashboard, node detail and
 * the job pages at 7: Q1, Q10, Q11); what a POST on it does needs its own:
 *
 *   7   a look: a job that reads a node and changes nothing on it (Q1)
 *   8   a change: run, restart, update, publish, cancel, save
 *   10  destructive or key-changing (Q8): restore, delete, purge, move or
 *       copy a site, reboot, root login, a join's trust, a credential, the
 *       data root's encryption, a release's archive
 *
 * A page asks refuse_post() before its handlers run. An action not named for
 * its page needs 10, so a new one is refused until it is placed.
 *
 * @version 1.0
 */
final class SmActionLevels {

	const PAGES = array(
		'node_detail' => array(
			// Looks (7)
			'check_status' => 7, 'install_report' => 7, 'host_report' => 7, 'site_log' => 7, 'site_log_count' => 7,
			'log_table_tail' => 7, 'unit_journal' => 7, 'disk_usage' => 7, 'listening_sockets' => 7,
			'site_state_report' => 7, 'container_inventory' => 7, 'spam_learning_status' => 7, 'file_head' => 7,
			'schema_probe' => 7, 'page_probe' => 7, 'net_health' => 7, 'web_workers' => 7, 'edge_vs_origin' => 7,
			// Changes (8)
			'host_converge' => 8, 'install_security_updates' => 8, 'refresh_site_containers' => 8,
			'reset_failed_unit' => 8, 'restart_unit' => 8, 'restart_container' => 8, 'hold_container' => 8,
			'site_limits' => 8, 'outbound_limits' => 8, 'run_installer' => 8, 'reclaim_managed_file' => 8,
			'restore_release_file' => 8, 'repair_release_files' => 8, 'backup_run' => 8, 'save_backup_policy' => 8,
			'apply_update' => 8, 'apply_update_all_on_host' => 8, 'publish_upgrade' => 8, 'retry_install' => 8,
			'provision_ssl' => 8, 'run_plugin_installers' => 8, 'restart_agent' => 8, 'set_reverse_dns' => 8,
			'save_node' => 8,
			// Destructive or key-changing (10)
			'reboot_machine' => 10, 'disable_root_login' => 10, 'move_to_plane' => 10, 'recreate_site' => 10,
			'data_root_migrate' => 10, 'data_root_enrol' => 10, 'data_root_unlock' => 10, 'data_root_escrow_retire' => 10,
			'data_root_encrypt' => 10, 'data_root_decrypt' => 10, 'data_root_rekey' => 10, 'data_root_escrow_excuse' => 10,
			'restore_database' => 10, 'restore_project' => 10, 'restore_chain' => 10, 'move_backup_storage' => 10,
			'adopt_cloud_server' => 10, 'save_api_credential' => 10, 'approve_join' => 10, 'reject_join' => 10,
			'unpair_agent' => 10, 'delete_node' => 10, 'decommission_node' => 10, 'remove_site_certificate' => 10,
			'purge_node' => 10, 'restore_node' => 10,
			'copy_new_server' => 10, 'copy_own_server' => 10, 'copy_on_host' => 10, 'copy_approve_join' => 10,
			'copy_again' => 10, 'copy_discard' => 10, 'copy_switch' => 10, 'copy_move' => 10,
			'copy_retry_start' => 10, 'copy_retry_settle' => 10, 'copy_go_back' => 10, 'copy_finish' => 10,
		),
		'index' => array(
			'adopt_join' => 10, 'adopt_provision_join' => 10, 'reject_join' => 10, 'reopen_join' => 10,
			'break_glass_reveal' => 10, 'break_glass_retire' => 10, 'break_glass_retire_all' => 10,
			'escrow_approve' => 10, 'escrow_refuse' => 10,
			'upgrade_host_sites' => 8, 'dismiss_provision' => 8,
		),
		'job_detail' => array(
			'cancel_job' => 8, 'rerun_job' => 8, 'mark_job_lost' => 8,
		),
		// The publish page's own form; node detail's publish_upgrade above is the node's job.
		'publish_upgrade' => array(
			'publish_upgrade' => 8, 'toggle_keep' => 8, 'delete_upgrade' => 10, 'prune_archives' => 10,
		),
		'incident' => array('triage' => 8, 'analyze' => 8, 'note' => 8),
		'incidents' => array('triage' => 8),
		'rollouts' => array('start_rollout' => 8, 'stop_rollout' => 8),
		'fleet_security' => array('start_run' => 8, 'stop_run' => 8),
	);

	/** The level a page's action needs; one not named for the page needs 10. */
	public static function level(string $page, string $action): int {
		return self::PAGES[$page][$action] ?? 10;
	}

	/**
	 * Why the session may not run the action, or null when it may. Asked
	 * through Access::decide(), so a refusal is recorded.
	 */
	public static function refusal(string $page, string $action, $session): ?string {
		$level = self::level($page, $action);
		if (Access::decide($level, $session)) {
			return null;
		}
		return 'That needs level ' . $level . ' (' . Access::levelName($level) . ').';
	}

	/**
	 * Before a page's handlers: a POST naming an action the session is below
	 * is refused with a message, back to $return_url, and nothing runs.
	 */
	public static function refuse_post(string $page, $session, string $return_url): void {
		if (!($_POST && isset($_POST['action']))) {
			return;
		}
		$refused = self::refusal($page, (string)$_POST['action'], $session);
		if ($refused === null) {
			return;
		}
		$session->save_message(new DisplayMessage($refused, 'Error', '/\/admin\/server_manager/',
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		header('Location: ' . $return_url);
		exit;
	}
}
