<?php
/**
 * SiteCopySwap - the two node rows of a switch-over, swapped
 * (specs/site_copy.md D4, step 10 and the way back).
 *
 * A switch-over makes the copy's machine the node without a new record type
 * and without a re-pair:
 *
 *   take_over(S, T)  S's row keeps its id, slug, name and history, takes the
 *                    copy's machine (its agent key, host and what its agent
 *                    reports) and leaves `switching`: it is the node, now on the
 *                    new machine. The copy's row takes S's old machine and
 *                    becomes `retired`, kept out of every automation, holding
 *                    the key the way back needs. Provision rows follow their
 *                    machines.
 *   go_back(N, R)    the inverse: the node takes back the machine on the
 *                    retired row and returns to `switching` (its owner unfreezes
 *                    it); the other row becomes `copy` again.
 *
 * Only machine columns move, and the other row's name follows what it holds
 * (the site's name with (copy), (old container) or (old server)). Everything
 * that describes the site — name, slug,
 * site URL, web root, backup policy, what the site's status check reported —
 * stays on the node, because the site is the same site on either machine.
 *
 * take_over() is called in exactly one place: the management node's answer to
 * the copy's take_node_id result (AgentChannelEndpoint::handle_result), because
 * the copy changes its own identity only when that answer confirms the swap.
 * A swap made anywhere else would leave the copy signing as itself against a
 * row that no longer holds its key.
 *
 * @version 1.2 - the other row is renamed with what it holds: (old container) or (old server) once retired,
 *                (copy) again on the way back; it kept (copy) on the old machine (B6)
 * @version 1.1 - the container name and Docker host move with the machine: a container source's copy is bare metal
 * @version 1.0
 */
class SiteCopySwap {

	/**
	 * The columns that describe a machine and its agent rather than the site:
	 * they move with the agent key.
	 */
	const MACHINE_COLUMNS = array(
		'mgn_host', 'mgn_ssh_user', 'mgn_ssh_key_path', 'mgn_ssh_port',
		// A container source's machine is its container on a Docker host;
		// the copy is bare metal, so the site's row leaves both behind.
		'mgn_container_name', 'mgn_mgh_managed_host_id',
		'mgn_last_host_report', 'mgn_last_host_report_time',
		'mgn_agent_public_key', 'mgn_agent_paired_time', 'mgn_agent_quiet_time', 'mgn_agent_last_poll',
		'mgn_agent_version', 'mgn_agent_primitives', 'mgn_agent_recipes', 'mgn_agent_bundle_version',
		'mgn_agent_log_access', 'mgn_agent_server_manager',
		'mgn_script_trust', 'mgn_script_trust_since', 'mgn_script_trust_reason', 'mgn_script_trust_job_type',
	);

	/**
	 * Step 10: the copy's machine becomes the node. $source is in `switching`,
	 * $copy in `copy` and recorded as $source's copy.
	 *
	 * @throws Exception naming what is not as a switch-over leaves it
	 */
	public static function take_over(ManagedNode $source, ManagedNode $copy): void {
		self::refuse_unless($source, $copy, 'switching', 'copy', 'take over');
		if (trim((string)$copy->get('mgn_agent_public_key')) === '') {
			throw new Exception("The copy '{$copy->get('mgn_slug')}' has no agent key, so there is no machine for the node to move to.");
		}
		self::exchange($source, $copy, null, 'retired');
	}

	/**
	 * The way back after step 10: the node takes back the machine on the
	 * retired row. Anything written on the other machine since step 10 stays
	 * there; the page says so before the button works.
	 *
	 * @throws Exception naming what is not as a switch-over leaves it
	 */
	public static function go_back(ManagedNode $node, ManagedNode $retired): void {
		self::refuse_unless($node, $retired, '', 'retired', 'go back');
		self::exchange($node, $retired, 'switching', 'copy');
	}

	private static function refuse_unless(ManagedNode $node, ManagedNode $other, string $node_state, string $other_state, string $what): void {
		if (!$node->key || !$other->key || (int)$node->key === (int)$other->key) {
			throw new Exception("Cannot {$what}: two different node records are needed.");
		}
		if ($node->get('mgn_delete_time') || $other->get('mgn_delete_time')) {
			throw new Exception("Cannot {$what}: one of the two node records has been removed.");
		}
		$have = trim((string)$node->get('mgn_install_state'));
		if ($have !== $node_state) {
			throw new Exception("Cannot {$what}: '{$node->get('mgn_slug')}' is "
				. ($have === '' ? 'a working site' : "in state '{$have}'") . ', not '
				. ($node_state === '' ? 'a working site' : "'{$node_state}'") . '.');
		}
		$have = trim((string)$other->get('mgn_install_state'));
		if ($have !== $other_state || (int)$other->get('mgn_copy_of_node_id') !== (int)$node->key) {
			throw new Exception("Cannot {$what}: '{$other->get('mgn_slug')}' is not the {$other_state} row of '{$node->get('mgn_slug')}'.");
		}
	}

	/**
	 * The other row's name says what it now holds: the copy, or the site's
	 * old machine once the copy has taken over. Read after the machine
	 * columns moved, so a container is named by the row that has it now.
	 */
	private static function other_name(ManagedNode $node, ManagedNode $other, string $other_state): string {
		if ($other_state !== 'retired') {
			return $node->get('mgn_name') . ' (copy)';
		}
		return $node->get('mgn_name') . (trim((string)$other->get('mgn_container_name')) !== '' ? ' (old container)' : ' (old server)');
	}

	/**
	 * Swap the machine columns and the provision links of two rows and set
	 * their states, in one transaction.
	 */
	private static function exchange(ManagedNode $node, ManagedNode $other, ?string $node_state, string $other_state): void {
		$db = DbConnector::get_instance()->get_db_link();
		$own = $db->inTransaction() === false;
		if ($own) {
			$db->beginTransaction();
		}
		try {
			$a = array();
			$b = array();
			foreach (self::MACHINE_COLUMNS as $col) {
				$a[$col] = $node->get($col);
				$b[$col] = $other->get($col);
			}
			foreach (self::MACHINE_COLUMNS as $col) {
				$node->set($col, $b[$col]);
				$other->set($col, $a[$col]);
			}
			$node->set('mgn_install_state', $node_state);
			$other->set('mgn_install_state', $other_state);
			$other->set('mgn_name', self::other_name($node, $other, $other_state));
			$node->save();
			$other->save();

			// A provision row names the machine it created; it follows that machine.
			$q = $db->prepare(
				'UPDATE cvp_customer_cloud_provisions SET cvp_mgn_managed_node_id = CASE cvp_mgn_managed_node_id
				   WHEN ?::bigint THEN ?::bigint WHEN ?::bigint THEN ?::bigint END
				 WHERE cvp_mgn_managed_node_id IN (?::bigint, ?::bigint)');
			$q->execute(array((int)$node->key, (int)$other->key, (int)$other->key, (int)$node->key,
				(int)$node->key, (int)$other->key));

			if ($own) {
				$db->commit();
			}
		} catch (Throwable $e) {
			if ($own && $db->inTransaction()) {
				$db->rollBack();
			}
			$node->load();
			$other->load();
			throw $e;
		}
	}
}
