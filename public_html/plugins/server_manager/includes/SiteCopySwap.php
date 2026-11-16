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
 *                    the key the way back needs. Provision rows and escrow
 *                    rows follow their machines, except a SOLD provision
 *                    (an order or a buyer's checkout made it): that is the
 *                    site's purchase, its billing row, relay slot and mail
 *                    account hang on it, and removing the old machine would
 *                    remove them all. It stays on the site's row and only
 *                    its machine columns follow the machine.
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
 * @version 1.11 - a sold provision stays with the site: only its machine columns move (PROVISION_MACHINE_COLUMNS),
 *                 so its order, billing row, relay slot and mail account are not removed with the old machine
 *                 (hosting_paid_launch B5, R17)
 * @version 1.10 - mgn_agent_unseal_daemon moves with the machine, beside the agent's other reports
 * @version 1.9 - the agent's X-Wing key and its signature move with the machine, beside its agent key
 * @version 1.8 - mgn_agent_root_access moves with the machine, beside mgn_agent_log_access
 * @version 1.7 - at a take-over the old machine's row keeps the release both machines run, so Permanently Delete
 *                Site can remove a switch-over's old container (C7's B52)
 * @version 1.6 - the container's published port moves with the machine: a copy onto a host is a container
 *                too, on a port of its own there (specs/site_container_engine.md D9)
 * @version 1.5 - an escrow row follows its machine: it stayed on the node, whose page then showed, and whose Stop
 *                holding its key gave up, the other machine's key (B56)
 * @version 1.4 - the agent's support-bundle verdict moves with the machine
 * @version 1.3 - the agent's self-update state moves with the machine
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
		// A container's machine is its container on its host, reached on its
		// own published port: each side's moves with it, whether the copy is
		// bare metal or a container on another host.
		'mgn_container_name', 'mgn_mgh_managed_host_id', 'mgn_port',
		'mgn_last_host_report', 'mgn_last_host_report_time',
		'mgn_agent_public_key', 'mgn_agent_kem_public_key', 'mgn_agent_kem_signature', 'mgn_agent_paired_time', 'mgn_agent_quiet_time', 'mgn_agent_last_poll',
		'mgn_agent_version', 'mgn_agent_primitives', 'mgn_agent_recipes', 'mgn_agent_bundle_version',
		'mgn_agent_log_access', 'mgn_agent_root_access', 'mgn_agent_server_manager', 'mgn_agent_update_state', 'mgn_agent_update_offered', 'mgn_agent_bundle_state', 'mgn_agent_unseal_daemon',
		'mgn_script_trust', 'mgn_script_trust_since', 'mgn_script_trust_reason', 'mgn_script_trust_job_type',
	);

	/**
	 * The columns of a provision that describe its machine rather than the
	 * purchase: whose account it is in, the instance or the host place, and
	 * the machine's own keys. At a switch-over these follow the machine while
	 * a sold provision stays with the site. The docker mode is settled on its
	 * own (settle_sold_provisions): a copy's provision must stay bare metal.
	 */
	const PROVISION_MACHINE_COLUMNS = array(
		'cvp_hosting_mode', 'cvp_oca_operator_cloud_account_id', 'cvp_cca_customer_cloud_account_id', 'cvp_provider',
		'cvp_instance_id', 'cvp_instance_ip', 'cvp_instance_ipv6', 'cvp_region', 'cvp_instance_type',
		'cvp_landing', 'cvp_mgh_managed_host_id', 'cvp_port',
		'cvp_root_pass_sealed', 'cvp_root_ssh_keys', 'cvp_root_pass_vault_sealed', 'cvp_root_access_offered',
		'cvp_data_root_encrypted', 'cvp_provider_disk_encryption',
		'cvp_expected_site_key', 'cvp_expected_host_key', 'cvp_keys_captured_time',
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
	 * Keep each row's sold provision on its row, and make it describe the
	 * machine the row now holds. Called after the node rows' machine columns
	 * have moved and before the provision links move.
	 *
	 * With a provision on the other row too (a copy onto a new server has
	 * one), the two trade machine columns, so each still describes the
	 * machine on its own row: neither changes row. A copy onto a host has
	 * none: a sold container provision then takes the host and port its row
	 * now holds. A sold instance provision with nothing to trade with is
	 * left to follow its machine, and the error log says so: its row would
	 * otherwise name an instance it no longer runs on.
	 *
	 * @return int[] the provision ids that stay where they are
	 */
	private static function settle_sold_provisions(ManagedNode $node, ManagedNode $other): array {
		$on = function (ManagedNode $row) {
			$out = array();
			foreach (new MultiCustomerCloudProvision(array('node_id' => (int)$row->key, 'deleted' => false)) as $p) {
				$out[] = $p;
			}
			return $out;
		};
		$sides = array(array($node, $on($node), $other, $on($other)), array($other, $on($other), $node, $on($node)));
		$stay = array();
		$traded = array();
		foreach ($sides as list($row, $mine, $far, $theirs)) {
			foreach ($mine as $p) {
				if (!$p->is_sold() || isset($traded[(int)$p->key])) {
					continue;
				}
				$partner = null;
				foreach ($theirs as $q) {
					if (!$q->is_sold() && !isset($traded[(int)$q->key])) {
						$partner = $q;
						break;
					}
				}
				if ($partner !== null) {
					foreach (self::PROVISION_MACHINE_COLUMNS as $col) {
						$a = $p->get($col);
						$p->set($col, $partner->get($col));
						$partner->set($col, $a);
					}
					// A container site is a docker one; a site on a server of
					// its own runs as that server was installed. A copy's own
					// provision is bare metal whatever it now describes: that
					// is what its copy install was.
					$pd = (string)($p->get('cvp_docker_mode') ?: 'docker');
					$qd = (string)($partner->get('cvp_docker_mode') ?: 'docker');
					$p->set('cvp_docker_mode', $p->is_container() ? 'docker' : $qd);
					if ((string)$partner->get('cvp_install_mode') !== 'copy') {
						$partner->set('cvp_docker_mode', $partner->is_container() ? 'docker' : $pd);
					}
					// Each validates against the machine it now describes;
					// saved through the model only once both have moved.
					$p->save();
					$partner->save();
					$traded[(int)$p->key] = true;
					$traded[(int)$partner->key] = true;
					$stay[] = (int)$p->key;
					$stay[] = (int)$partner->key;
				} elseif ($p->is_container() && trim((string)$row->get('mgn_container_name')) !== '') {
					$p->set('cvp_mgh_managed_host_id', (int)$row->get('mgn_mgh_managed_host_id') ?: null);
					$p->set('cvp_port', (int)$row->get('mgn_port') ?: 8080);
					$p->save();
					$stay[] = (int)$p->key;
				} else {
					error_log('SiteCopySwap: sold provision #' . $p->key . ' (' . $p->get('cvp_domain') . ') has no provision on '
						. $far->get('mgn_slug') . ' to trade machines with, so it follows its machine to that row; '
						. 'removing that row removes the purchase with it.');
				}
			}
		}
		return $stay;
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
			// At a take-over both machines run the one release: a copy installs
			// its site's exact release and is refused a switch once they differ
			// (SiteCopyRunner::stale_release). The old machine's row keeps
			// it, as no check can ask a container held stopped, and Permanently
			// Delete Site needs it (build_decommission_node).
			$release = trim((string)$node->get('mgn_joinery_version'));
			if ($other_state === 'retired' && $release !== '') {
				$other->set('mgn_joinery_version', $release);
			}
			$node->set('mgn_install_state', $node_state);
			$other->set('mgn_install_state', $other_state);
			$other->set('mgn_name', self::other_name($node, $other, $other_state));
			$node->save();
			$other->save();

			// A sold provision stays with the site; its machine columns follow
			// the machine. Read before the links below move.
			$sold = self::settle_sold_provisions($node, $other);

			// A provision row names the machine it created; it follows that
			// machine. The sold ones settled above are left where they are.
			$keep = $sold ? ' AND cvp_customer_cloud_provision_id NOT IN (' . implode(',', array_map('intval', $sold)) . ')' : '';
			$q = $db->prepare(
				'UPDATE cvp_customer_cloud_provisions SET cvp_mgn_managed_node_id = CASE cvp_mgn_managed_node_id
				   WHEN ?::bigint THEN ?::bigint WHEN ?::bigint THEN ?::bigint END
				 WHERE cvp_mgn_managed_node_id IN (?::bigint, ?::bigint)' . $keep);
			$q->execute(array((int)$node->key, (int)$other->key, (int)$other->key, (int)$node->key,
				(int)$node->key, (int)$other->key));
			// So does an escrow row: its unlock identity is on that machine's disk.
			$q = $db->prepare(
				'UPDATE dre_data_root_escrows SET dre_mgn_managed_node_id = CASE dre_mgn_managed_node_id
				   WHEN ?::bigint THEN ?::bigint WHEN ?::bigint THEN ?::bigint END
				 WHERE dre_mgn_managed_node_id IN (?::bigint, ?::bigint)');
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
