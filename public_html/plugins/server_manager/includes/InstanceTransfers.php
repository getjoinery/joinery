<?php
/**
 * InstanceTransfers — handing a Managed site's server to its customer's own
 * Linode account (specs/managed_to_self_hosted_transfer.md).
 *
 * A Managed site runs on its own instance in the operator's cloud account.
 * The provider's Service Transfer moves that instance — running, same disks,
 * same addresses — into another account: we create a code, the customer
 * redeems it in their own Cloud Manager, the provider moves it. Nothing is
 * copied or rebuilt and DNS does not change. This class is everything on our
 * side before the move finishes: who may start one, the check that says
 * whether one can start, the code, the status the provider reports, and what
 * each person is told. The finish is InstanceTransferFinish's.
 *
 * WHY THE CUSTOMER ACCEPTS BY HAND. Accepting through the API would need a
 * grant on the customer's account wide enough to accept transfers. We ask for
 * no such thing anywhere, and a customer becoming self-hosted should end up
 * with less of our reach, not more. Cloud Manager's Accept is one screen.
 *
 * THE CODE IS A BEARER SECRET. Whoever redeems it gets the machine and every
 * byte on it. It is sealed on the row, shown only on the customer's own
 * signed-in page, and never written into an email, a
 * job, a log line or an error message.
 *
 * Seams for tests: $driver (the operator-side provider driver), $mailer and
 * $signal stand in for the real ones when set.
 *
 * TWO WRITERS, ONE ROW. A poll tick and a person's cancel can act on the same
 * row at once. Both take the row's lock (SELECT … FOR UPDATE) and re-read it
 * before they decide, so neither saves over the other's state.
 *
 * @version 1.1 - poll and cancel act on the row under its lock; a code that cannot be read is said for
 *                accepted as well as code_issued; an expired code Linode no longer knows is treated as stale
 * @version 1.0
 */
class InstanceTransferFlowException extends Exception {}

class InstanceTransfers {

	/** @var object|null A CloudComputeProvider & CloudInstanceTransfers standing in for the operator's (tests). */
	public static $driver = null;

	/** @var callable|null fn(string $template, string $to, array $vars) (tests). */
	public static $mailer = null;

	/** @var callable|null fn(string $signal, array $payload) (tests). */
	public static $signal = null;

	/** Where the customer manages it all. */
	const SITES_PATH = '/profile/server_manager';

	/** The operator's queue. */
	const QUEUE_PATH = '/admin/server_manager/transfers';

	/** The email template behind each customer email kind. */
	const EMAIL_TEMPLATES = array(
		'invite'       => 'instance_transfer_invite',
		'code_ready'   => 'instance_transfer_code_ready',
		'code_expired' => 'instance_transfer_code_expired',
		'failed'       => 'instance_transfer_failed',
		'done'         => 'instance_transfer_done',
	);

	// ── The operator's driver ─────────────────────────────────────────────────

	/**
	 * The driver that acts on the operator's account, with the transfer
	 * capability, or a reason there is none.
	 *
	 * @return array{driver: ?object, reason: string}
	 */
	public static function driver(): array {
		if (self::$driver !== null) {
			return array('driver' => self::$driver, 'reason' => '');
		}
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));
		require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
		$token = ProvisionCustomerCloud::operator_compute_token();
		if ($token === '') {
			return array('driver' => null, 'reason' => 'No operator cloud token is configured. Set it on the Provisioning Setup page.');
		}
		$driver = new LinodeComputeDriver($token);
		if (!($driver instanceof CloudInstanceTransfers)) {
			return array('driver' => null, 'reason' => 'This provider cannot hand an instance to another account. '
				. 'Move the site with a site copy to a new server instead.');
		}
		return array('driver' => $driver, 'reason' => '');
	}

	// ── The eligibility check (§3) ────────────────────────────────────────────

	/**
	 * Every reason this site cannot be handed over now, and everything worth
	 * saying first. Never fixes anything itself.
	 *
	 * @return array{checked_time: string, blockers: int, warnings: int, shutdown: bool, items: array}
	 */
	public static function check(CustomerCloudProvision $provision): array {
		$node = self::node_of($provision);
		$items = self::platform_items($provision, $node);

		$instance_id = trim((string)$provision->get('cvp_instance_id'));
		if ($instance_id !== '' && $provision->is_operator_hosted()) {
			$resolved = self::driver();
			if ($resolved['driver'] === null) {
				$items[] = self::item('provider', 'The operator\'s cloud account can be asked', 'blocker',
					$resolved['reason'], '');
			} else {
				try {
					foreach ($resolved['driver']->transferEligibility($instance_id) as $item) {
						$items[] = $item;
					}
				} catch (Throwable $e) {
					$items[] = self::item('provider', 'The operator\'s cloud account can be asked', 'blocker',
						'Linode could not be asked: ' . $e->getMessage(), 'Check the operator token on the Provisioning Setup page, then re-check.');
				}
			}
		}

		$blockers = 0;
		$warnings = 0;
		foreach ($items as $item) {
			if ($item['result'] === 'blocker') { $blockers++; }
			if ($item['result'] === 'warning') { $warnings++; }
		}
		$trial = HostedTrial::for_provision((int)$provision->key);
		return array(
			'checked_time' => gmdate('Y-m-d H:i:s'),
			'blockers'     => $blockers,
			'warnings'     => $warnings,
			'shutdown'     => $trial !== null && (string)$trial->get('htr_state') === HostedTrial::STATE_SHUTDOWN,
			'items'        => $items,
		);
	}

	/** The platform half of the check: is this a sold Managed site we can let go of now? */
	public static function platform_items(CustomerCloudProvision $provision, ?ManagedNode $node): array {
		$items = array();

		// A sold Managed site on its own instance, and nothing else: a relay
		// shard and an operator-account site copy are operator-mode rows too.
		$not = array();
		if (!$provision->is_operator_hosted()) {
			$not[] = $provision->is_transferred() ? 'it has already moved to its customer\'s account'
				: 'it is not hosted on our account';
		}
		if (!in_array((string)$provision->get('cvp_origin'), array('order', 'buyer'), true)
				|| !(int)$provision->get('cvp_external_order_item_id')) {
			$not[] = 'nobody bought it (an operator-created machine)';
		}
		if ((string)$provision->get('cvp_install_mode') === 'bare') {
			$not[] = 'it is a bare machine with no site';
		}
		if (trim((string)$provision->get('cvp_instance_id')) === '') {
			$not[] = 'it has no instance';
		}
		if ((string)$provision->get('cvp_status') !== 'done') {
			$not[] = 'its setup has not finished (status ' . $provision->get('cvp_status') . ')';
		}
		if ($node !== null && ($node->is_management_node() || $node->get('mgn_is_relay'))) {
			$not[] = 'its node is infrastructure (a management node or a relay)';
		}
		$items[] = $not
			? self::item('managed_site', 'A sold Managed site on its own instance', 'blocker', ucfirst(implode('; ', $not)) . '.',
				'Only a Managed site a customer bought can be handed over. A site in a container moves with a site copy.')
			: self::item('managed_site', 'A sold Managed site on its own instance', 'pass');

		if ($node === null) {
			$items[] = self::item('node', 'The site\'s node is working', 'blocker', 'The provision has no node.',
				'Nothing here manages this site.');
			return $items;
		}
		$items[] = $node->is_operational()
			? self::item('node', 'The site\'s node is working', 'pass')
			: self::item('node', 'The site\'s node is working', 'blocker',
				'The node is ' . $node->install_state_label() . '.', 'Wait until it is a working site again.');

		$copy_open = null;
		$copy = SiteCopy::live_for_source((int)$node->key);
		if ($copy !== null && $copy->status() !== SiteCopy::STATUS_DORMANT) {
			$copy_open = 'A copy of this site is ' . strtolower($copy->status_label()) . '.';
		}
		if (SiteCopy::live_for_copy_node((int)$node->key) !== null) {
			$copy_open = 'This node is itself a site copy\'s target.';
		}
		$items[] = $copy_open !== null
			? self::item('site_copy', 'No site copy in progress', 'blocker', $copy_open, 'Wait for the copy to finish or discard it.')
			: self::item('site_copy', 'No site copy in progress', 'pass');

		$copies_here = array();
		foreach (new MultiManagedNode(array('mgn_copy_of_node_id' => (int)$node->key, 'deleted' => false)) as $other) {
			$copies_here[] = '#' . (int)$other->key . ' ' . $other->get('mgn_name');
		}
		if ($copies_here) {
			$items[] = self::item('dormant_copies', 'No dormant copy names this site', 'warning',
				'Dormant copies of this site: ' . implode(', ', $copies_here) . '.',
				'After the move they name a site we no longer host. Discard them, or keep them knowingly.');
		}

		$busy = self::busy_job_count((int)$node->key);
		$items[] = $busy > 0
			? self::item('jobs', 'No job running or queued on the node', 'blocker',
				$busy . ' job(s) running or queued.', 'Wait for them to finish, then re-check.')
			: self::item('jobs', 'No job running or queued on the node', 'pass');

		$password = (string)$provision->get('cvp_install_password');
		if (in_array($password, CustomerCloudProvision::PASSWORD_HELD_STATES, true)) {
			$items[] = self::item('install_password', 'The install password is retired', 'blocker',
				'The machine still accepts the install password (' . $password . ').',
				'Retire it first (re-run retire_install_password from its job page): a working credential of ours must not ride along.');
		} elseif ($password === '') {
			$items[] = self::item('install_password', 'The install password is retired', 'warning',
				'This site predates keyless provisioning, so there is no record of how it was reached.',
				'Check by hand that no password or key of ours opens the machine.');
		} else {
			$items[] = self::item('install_password', 'The install password is retired', 'pass');
		}

		$key_path = trim((string)$node->get('mgn_ssh_key_path'));
		$ssh_user = trim((string)$node->get('mgn_ssh_user'));
		$items[] = ($key_path !== '' || ($ssh_user !== '' && $ssh_user !== 'root'))
			? self::item('ssh_key', 'No operator SSH key on the node', 'blocker',
				'The node records an SSH ' . ($key_path !== '' ? 'key (' . $key_path . ')' : 'login as ' . $ssh_user) . '.',
				'Remove our key from the machine and clear it from the node\'s connection settings: a working credential of ours must not ride along.')
			: self::item('ssh_key', 'No operator SSH key on the node', 'pass');

		$mail_state = (string)$provision->get('cvp_mail_state');
		$items[] = !in_array($mail_state, array('', 'done', 'failed'), true)
			? self::item('mail_leg', 'Outbound mail setup has finished', 'blocker',
				'The mail setup is at ' . $mail_state . '.', 'Wait for it to finish: it only advances while we host the site.')
			: self::item('mail_leg', 'Outbound mail setup has finished', 'pass');

		$trial = HostedTrial::for_provision((int)$provision->key);
		$plan = $trial ? (string)$trial->get('htr_state') : '';
		if ($plan === HostedTrial::STATE_GRACE) {
			$items[] = self::item('plan', 'Hosting is paid up', 'warning', 'A hosting payment failed; the site is in its grace period.',
				'Your call: the move ends the subscription either way.');
		} elseif ($plan === HostedTrial::STATE_SHUTDOWN) {
			$items[] = self::item('plan', 'Hosting is paid up', 'warning',
				'The site was shut down for non-payment and its deletion was asked for.',
				'Start withdraws the deletion request. The site moves powered off and its customer boots it.');
		}

		$open_incidents = array();
		foreach (new MultiIncidentRecord(array('node_id' => (int)$node->key, 'status' => IncidentRecord::STATUS_OPEN,
				'deleted' => false)) as $inc) {
			if (in_array($inc->triage(), array(IncidentRecord::TRIAGE_NEW, IncidentRecord::TRIAGE_LOOKING,
					IncidentRecord::TRIAGE_SNOOZED), true)) {
				$open_incidents[] = $inc->title();
			}
		}
		if ($open_incidents) {
			$items[] = self::item('incidents', 'No open incidents', 'warning', implode('; ', $open_incidents) . '.',
				'Worth settling before the customer owns the machine.');
		}

		return $items;
	}

	private static function item(string $key, string $label, string $result, string $detail = '', string $fix = ''): array {
		return array('key' => $key, 'label' => $label, 'result' => $result, 'detail' => $detail, 'fix' => $fix);
	}

	private static function busy_job_count(int $node_id): int {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT COUNT(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_delete_time IS NULL
			   AND mjb_status IN ('queued', 'pending', 'running')");
		$q->execute(array($node_id));
		return (int)$q->fetchColumn();
	}

	// ── The flow (§4) ─────────────────────────────────────────────────────────

	/**
	 * The customer asks (D3). The row waits as `requested` until the operator
	 * presses Start. Asking twice finds the same row.
	 */
	public static function request(CustomerCloudProvision $provision): InstanceTransfer {
		$open = InstanceTransfer::open_for_provision((int)$provision->key);
		if ($open !== null) {
			return $open;
		}
		self::assert_startable($provision);
		$row = self::new_row($provision, 'customer');
		$row->save();
		self::alert($provision, 'A customer asked to move their site to their own Linode account',
			'Open the transfer queue, run the check and press Start.', $row);
		return $row;
	}

	/**
	 * The operator starts it: the check runs, a clean result invites the
	 * customer, and a shut-down site's deletion is withdrawn first. A row the
	 * customer requested is the row started.
	 */
	public static function start(CustomerCloudProvision $provision): InstanceTransfer {
		self::assert_startable($provision);
		$row = InstanceTransfer::open_for_provision((int)$provision->key);
		if ($row !== null && $row->state() !== InstanceTransfer::STATE_REQUESTED) {
			throw new InstanceTransferFlowException('This site already has a transfer under way (' . $row->state_label() . ').');
		}
		$check = self::check($provision);
		if ($row === null) {
			$row = self::new_row($provision, 'operator');
		}
		$row->set('itx_check_result', $check);
		if ($check['blockers'] > 0) {
			if ($row->key) {
				$row->save();
			}
			throw new InstanceTransferFlowException('The check found ' . $check['blockers']
				. ' thing(s) to fix first: ' . self::blocker_summary($check) . '.');
		}
		$row->set('itx_state', InstanceTransfer::STATE_INVITED);
		$row->set('itx_error', null);
		$row->save();
		self::withdraw_deletion_if_shut_down($provision, $row);
		self::email($row, $provision, 'invite');
		return $row;
	}

	/**
	 * Issue the transfer code. The customer's "Get my transfer code", or the
	 * operator's "Issue code now" for a customer walked through it on a call
	 * (which may skip the invitation). The check runs again first: a blocker
	 * keeps the row where it is, tells the operator, and the customer is told
	 * we are on it. Linode's own refusal at create is shown verbatim, because
	 * something attached between the check and the create cannot be checked.
	 */
	public static function issue_code(InstanceTransfer $row, string $by): InstanceTransfer {
		$from = array(InstanceTransfer::STATE_INVITED, InstanceTransfer::STATE_FAILED);
		if ($by === 'operator') {
			$from[] = InstanceTransfer::STATE_REQUESTED;
		}
		if (!in_array($row->state(), $from, true)) {
			throw new InstanceTransferFlowException('A code cannot be issued while the transfer is: ' . $row->state_label() . '.');
		}
		$provision = self::provision_of($row);
		$check = self::check($provision);
		$row->set('itx_check_result', $check);
		if ($check['blockers'] > 0) {
			$row->set('itx_error', 'Blocked at code issue: ' . self::blocker_summary($check) . '.');
			$row->save();
			self::alert($provision, 'A transfer code could not be issued',
				'The check found something to fix first: ' . self::blocker_summary($check) . '.', $row);
			throw new InstanceTransferFlowException($by === 'customer'
				? 'Something on our side needs fixing before your code can be issued. We have been told and will '
					. 'email you as soon as it is ready.'
				: 'The check found ' . $check['blockers'] . ' thing(s) to fix first: ' . self::blocker_summary($check) . '.');
		}
		self::withdraw_deletion_if_shut_down($provision, $row);

		$resolved = self::driver();
		if ($resolved['driver'] === null) {
			throw new InstanceTransferFlowException($resolved['reason']);
		}
		try {
			$created = $resolved['driver']->createTransfer((string)$row->get('itx_instance_id'));
		} catch (Throwable $e) {
			$row->set('itx_error', 'Linode refused the transfer: ' . $e->getMessage());
			$row->save();
			self::alert($provision, 'Linode refused a transfer code', $e->getMessage(), $row);
			throw new InstanceTransferFlowException($by === 'customer'
				? 'Linode would not issue a code just now. We have been told and will email you as soon as it is ready.'
				: 'Linode refused the transfer: ' . $e->getMessage());
		}
		$row->seal_token((string)$created['token']);
		$row->set('itx_token_expiry', $created['expiry'] !== '' ? $created['expiry']
			: gmdate('Y-m-d H:i:s', time() + 86400));
		$row->set('itx_code_issued_time', gmdate('Y-m-d H:i:s'));
		$row->set('itx_linode_status', (string)$created['status']);
		$row->set('itx_linode_checked_time', gmdate('Y-m-d H:i:s'));
		$row->set('itx_state', InstanceTransfer::STATE_CODE_ISSUED);
		$row->set('itx_error', null);
		$row->save();
		self::email($row, $provision, 'code_ready');
		return $row;
	}

	/**
	 * Withdraw it. Only before the customer redeems the code: a pending code is
	 * deleted at the provider first, and a code the provider says was already
	 * accepted is not canceled here — the row follows the provider instead.
	 */
	public static function cancel(InstanceTransfer $row, string $by): InstanceTransfer {
		// The refusal is thrown after the lock's transaction commits, so the
		// accepted state cancel just learned is kept, not rolled back with it.
		$result = self::locked($row, function () use ($row) {
			if (!$row->cancelable()) {
				throw new InstanceTransferFlowException('It can no longer be canceled: ' . $row->state_label() . '.');
			}
			$provision = self::provision_of($row);
			if ($row->state() === InstanceTransfer::STATE_CODE_ISSUED) {
				$token = $row->open_token();
				$resolved = self::driver();
				if ($token !== '' && $resolved['driver'] !== null) {
					$status = $resolved['driver']->getTransferStatus($token);
					if (in_array($status['status'], array('accepted', 'completed'), true)) {
						self::apply_status($row, $status['status'], $status['expiry']);
						return 'already_accepted';
					}
					if ($status['status'] === 'pending') {
						$resolved['driver']->cancelTransfer($token);
					}
				} elseif ($token !== '') {
					throw new InstanceTransferFlowException('The code cannot be withdrawn: ' . $resolved['reason']);
				}
			}
			$row->set('itx_state', InstanceTransfer::STATE_CANCELED);
			$row->set('itx_error', null);
			$row->set('itx_operator_todo', null);
			$row->save();
			self::restore_deletion_if_withdrawn($provision, $row);
			return $row;
		});
		if ($result === 'already_accepted') {
			throw new InstanceTransferFlowException('The code was already accepted in Cloud Manager; the move cannot be undone.');
		}
		return $result;
	}

	/**
	 * Run $fn with this row locked and freshly read, inside a transaction of
	 * its own unless one is already open. A poll tick and a person's cancel
	 * both come through here, so whichever is second sees what the first saved.
	 */
	private static function locked(InstanceTransfer $row, callable $fn) {
		$db = DbConnector::get_instance()->get_db_link();
		$own = !$db->inTransaction();
		if ($own) {
			$db->beginTransaction();
		}
		try {
			$q = $db->prepare('SELECT itx_instance_transfer_id FROM itx_instance_transfers WHERE itx_instance_transfer_id = ? FOR UPDATE');
			$q->execute(array((int)$row->key));
			$row->load();
			$result = $fn();
			if ($own) {
				$db->commit();
			}
			return $result;
		} catch (Throwable $e) {
			if ($own && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}

	/** Send again whatever the customer was last owed for this state. */
	public static function resend(InstanceTransfer $row): string {
		$kind = array(
			InstanceTransfer::STATE_INVITED     => 'invite',
			InstanceTransfer::STATE_CODE_ISSUED => 'code_ready',
			InstanceTransfer::STATE_FAILED      => 'failed',
			InstanceTransfer::STATE_DONE        => 'done',
		)[$row->state()] ?? '';
		if ($kind === '') {
			throw new InstanceTransferFlowException('There is no email to resend while the transfer is: ' . $row->state_label() . '.');
		}
		self::email($row, self::provision_of($row), $kind);
		return $kind;
	}

	/** The operator ticks off the to-do (the remote store's subscription, a confirmed date). */
	public static function todo_done(InstanceTransfer $row): void {
		$row->set('itx_operator_todo', null);
		$row->save();
	}

	// ── What the provider says ────────────────────────────────────────────────

	/** Ask the provider about one row and act on the answer. Returns true when the row moved. */
	public static function poll(InstanceTransfer $row): bool {
		$token = $row->open_token();
		if ($token === '') {
			if (in_array($row->state(), InstanceTransfer::TOKEN_STATES, true)) {
				// A code we can no longer read cannot be followed: Linode is
				// asked by it. Said on the row, because an accepted move that
				// is never followed never finishes and its hosting keeps billing.
				$row->set('itx_error', 'The stored code cannot be read, so Linode cannot be asked where this transfer stands. '
					. ($row->state() === InstanceTransfer::STATE_ACCEPTED
						? 'Watch it in Cloud Manager (Account → Service Transfers); once the instance has left our account, finish it by hand.'
						: 'Cancel it in Cloud Manager (Account → Service Transfers), cancel it here, and issue a new code.'));
				$row->save();
			}
			return false;
		}
		$resolved = self::driver();
		if ($resolved['driver'] === null) {
			throw new InstanceTransferFlowException($resolved['reason']);
		}
		try {
			$status = $resolved['driver']->getTransferStatus($token);
		} catch (CloudComputeException $e) {
			// An expired code Linode no longer answers for is an expired code.
			// Without this the row would sit at code_issued forever, the poll
			// would fail every tick, and the customer would never hear.
			$expiry = strtotime((string)$row->get('itx_token_expiry') . ' UTC');
			if ((int)$e->getCode() === 404 && $row->state() === InstanceTransfer::STATE_CODE_ISSUED
					&& $expiry !== false && time() - $expiry > 3600) {
				$status = array('status' => 'stale', 'expiry' => '');
			} else {
				throw $e;
			}
		}
		// Applied to the row as it is NOW: a cancel may have landed while
		// Linode was being asked.
		return (bool)self::locked($row, function () use ($row, $token, $status) {
			if (!in_array($row->state(), InstanceTransfer::TOKEN_STATES, true) || $row->open_token() !== $token) {
				return false;
			}
			return self::apply_status($row, (string)$status['status'], (string)$status['expiry']);
		});
	}

	/**
	 * One provider status, applied. pending changes nothing; accepted means
	 * nothing can stop it now; completed hands the row to the finish; failed
	 * and stale and canceled each say what happened. Returns true when the
	 * row's state moved.
	 */
	public static function apply_status(InstanceTransfer $row, string $status, string $expiry = ''): bool {
		$before = $row->state();
		$row->set('itx_linode_status', $status);
		$row->set('itx_linode_checked_time', gmdate('Y-m-d H:i:s'));
		$provision = self::provision_of($row);

		if (in_array($status, array('accepted', 'completed'), true)
				&& !in_array($before, array(InstanceTransfer::STATE_CODE_ISSUED, InstanceTransfer::STATE_ACCEPTED,
					InstanceTransfer::STATE_FINISHING), true)) {
			// Should never happen: a code we no longer think is out was redeemed.
			self::alert($provision, 'A transfer code was accepted while its row said ' . $row->state_label(),
				'This means a code leaked or was reused. Look at the operator account\'s Service Transfers now.', $row);
		}

		switch ($status) {
			case 'accepted':
				if (in_array($before, array(InstanceTransfer::STATE_CODE_ISSUED, InstanceTransfer::STATE_INVITED,
						InstanceTransfer::STATE_CANCELED, InstanceTransfer::STATE_FAILED), true)) {
					$row->set('itx_state', InstanceTransfer::STATE_ACCEPTED);
					$row->set('itx_accepted_time', gmdate('Y-m-d H:i:s'));
				}
				break;
			case 'completed':
				if ($before !== InstanceTransfer::STATE_FINISHING && $before !== InstanceTransfer::STATE_DONE) {
					$row->set('itx_state', InstanceTransfer::STATE_FINISHING);
					if (!$row->get('itx_accepted_time')) {
						$row->set('itx_accepted_time', gmdate('Y-m-d H:i:s'));
					}
				}
				break;
			case 'failed':
				if ($before !== InstanceTransfer::STATE_FAILED) {
					$row->set('itx_state', InstanceTransfer::STATE_FAILED);
					$row->set('itx_error', 'Linode reported the transfer failed. The likeliest cause is a Linode on the '
						. 'customer\'s account with the same label (' . self::label_hint($provision) . '): relabel ours, then issue a new code.');
					$row->save();
					self::alert($provision, 'A transfer failed at Linode', (string)$row->get('itx_error'), $row);
					self::email($row, $provision, 'failed');
				}
				break;
			case 'stale':
				if ($before === InstanceTransfer::STATE_CODE_ISSUED) {
					$row->set('itx_state', InstanceTransfer::STATE_INVITED);
					$row->set('itx_token_expiry', null);
					$row->save();
					self::email($row, $provision, 'code_expired');
				}
				break;
			case 'canceled':
				if ($before === InstanceTransfer::STATE_CODE_ISSUED) {
					$row->set('itx_state', InstanceTransfer::STATE_CANCELED);
					$row->set('itx_error', 'The code was canceled at Linode.');
					$row->save();
					self::restore_deletion_if_withdrawn($provision, $row);
				}
				break;
			default:
				if ($expiry !== '') {
					$row->set('itx_token_expiry', $expiry);
				}
		}
		$row->save();
		return $row->state() !== $before;
	}

	// ── A shut-down site ──────────────────────────────────────────────────────

	/**
	 * A site in `shutdown` had a deletion asked of a person. A person acting on
	 * it while a code is out deletes the instance and the transfer fails, so
	 * the deletion is withdrawn — on the node, and to the same recipients.
	 */
	private static function withdraw_deletion_if_shut_down(CustomerCloudProvision $provision, InstanceTransfer $row): void {
		if ($row->get('itx_deletion_withdrawn')) {
			return;
		}
		$trial = HostedTrial::for_provision((int)$provision->key);
		if ($trial === null || (string)$trial->get('htr_state') !== HostedTrial::STATE_SHUTDOWN) {
			return;
		}
		$row->set('itx_deletion_withdrawn', true);
		$row->save();
		$node = self::node_of($provision);
		if ($node !== null) {
			$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
				. 'Deletion withdrawn on ' . gmdate('Y-m-d') . ': this server is being handed to its customer. Do not delete it.'));
			$node->save();
		}
		self::signal('hosted.deletion_withdrawn', array(
			'provision_id' => (int)$provision->key,
			'domain'       => (string)$provision->get('cvp_domain'),
			'instance_id'  => (string)$provision->get('cvp_instance_id'),
		));
	}

	/** The move did not happen: the shut-down site's deletion is owed again. */
	private static function restore_deletion_if_withdrawn(CustomerCloudProvision $provision, InstanceTransfer $row): void {
		if (!$row->get('itx_deletion_withdrawn')) {
			return;
		}
		$row->set('itx_deletion_withdrawn', false);
		$row->save();
		$trial = HostedTrial::for_provision((int)$provision->key);
		$node = self::node_of($provision);
		if ($node !== null) {
			$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
				. 'Transfer canceled on ' . gmdate('Y-m-d') . ': the instance is powered off and awaiting deletion at the provider again.'));
			$node->save();
		}
		self::signal('hosted.deletion_required', array(
			'provision_id'    => (int)$provision->key,
			'domain'          => (string)$provision->get('cvp_domain'),
			'instance_id'     => (string)$provision->get('cvp_instance_id'),
			'buyer_email'     => (string)$provision->get('cvp_buyer_email'),
			'shelf_ends_time' => $trial ? (string)$trial->get('htr_shelf_ends_time') : '',
		));
	}

	// ── Telling people ────────────────────────────────────────────────────────

	/**
	 * One customer email. The code is never in it — an email is a copy in
	 * somebody else's system; it links to the signed-in page that shows it.
	 */
	public static function email(InstanceTransfer $row, CustomerCloudProvision $provision, string $kind, array $extra = array()): bool {
		$template = self::EMAIL_TEMPLATES[$kind] ?? '';
		$to = trim((string)$provision->get('cvp_buyer_email'));
		if ($template === '' || $to === '') {
			return false;
		}
		$vars = array_merge(self::email_vars($row, $provision), $extra);
		try {
			if (self::$mailer !== null) {
				call_user_func(self::$mailer, $template, $to, $vars);
			} else {
				EmailSender::sendTemplate($template, $to, $vars);
			}
		} catch (Throwable $e) {
			error_log('InstanceTransfers: the ' . $kind . ' email for ' . $provision->get('cvp_domain')
				. ' could not be sent: ' . $e->getMessage());
			return false;
		}
		$row->mark_emailed($kind);
		$row->save();
		return true;
	}

	/** What every transfer email may say. Never the code. */
	public static function email_vars(InstanceTransfer $row, CustomerCloudProvision $provision): array {
		$settings = Globalvars::get_instance();
		$referral = trim((string)$settings->get_setting('server_manager_linode_referral_url', false, true));
		$expiry = trim((string)$row->get('itx_token_expiry'));
		$paid_until = trim((string)$row->get('itx_paid_until'));
		return array(
			'recipient'      => self::recipient_for($provision),
			'buyer_name'     => (string)($provision->get('cvp_buyer_name') ?: 'there'),
			'domain'         => (string)$provision->get('cvp_domain'),
			'sites_url'      => LibraryFunctions::get_absolute_url(self::SITES_PATH),
			'linode_signup_url' => strpos($referral, 'https://') === 0 ? $referral : 'https://www.linode.com/',
			'monthly_cost'   => self::monthly_cost($provision),
			'instance_label' => self::label_hint($provision),
			'code_expiry'    => $expiry !== '' ? gmdate('F j, Y \a\t H:i', strtotime($expiry . ' UTC')) . ' UTC' : '',
			'paid_until'     => $paid_until !== '' ? gmdate('F j, Y', strtotime($paid_until . ' UTC')) : '',
			'was_shut_down'  => $row->get('itx_deletion_withdrawn') ? 1 : 0,
			'dns_records'    => '',
			'linode_backups' => self::linode_backups_moved($row) ? 1 : 0,
		);
	}

	/**
	 * The recipient block the shared email wrapper reads: the buyer's own user
	 * row where it is on this site, else what the provision knows of them.
	 */
	public static function recipient_for(CustomerCloudProvision $provision): array {
		$user_id = (int)$provision->get('cvp_usr_user_id');
		if ($user_id > 0) {
			try {
				$user = new User($user_id, TRUE);
				if ($user->key && strcasecmp((string)$user->get('usr_email'), (string)$provision->get('cvp_buyer_email')) === 0) {
					return $user->export_as_array();
				}
			} catch (Throwable $e) {
				// A buyer whose account lives on the store's site, not this one.
			}
		}
		$name = trim((string)$provision->get('cvp_buyer_name'));
		return array(
			'usr_email'      => (string)$provision->get('cvp_buyer_email'),
			'usr_first_name' => $name !== '' ? explode(' ', $name)[0] : '',
		);
	}

	/** Did the instance carry Linode Backups with it (the check warned of it)? */
	private static function linode_backups_moved(InstanceTransfer $row): bool {
		$check = $row->check_result();
		foreach ((array)($check['items'] ?? array()) as $item) {
			if (($item['key'] ?? '') === 'instance_backups') {
				return true;
			}
		}
		return false;
	}

	/** What the instance costs a month at list price, said plainly, or ''. */
	public static function monthly_cost(CustomerCloudProvision $provision): string {
		$type = trim((string)$provision->get('cvp_instance_type'));
		if ($type === '') {
			return '';
		}
		$resolved = self::driver();
		if ($resolved['driver'] === null || !method_exists($resolved['driver'], 'typeMonthlyPrice')) {
			return '';
		}
		try {
			$price = $resolved['driver']->typeMonthlyPrice($type);
		} catch (Throwable $e) {
			return '';
		}
		return $price === null ? '' : '$' . number_format($price, 2) . ' a month';
	}

	/** The label the instance carries at the provider ({slug}-{provision id}). */
	public static function label_hint(CustomerCloudProvision $provision): string {
		return (string)$provision->get('cvp_slug') . '-' . (int)$provision->key;
	}

	/**
	 * An operator alert: the bell and email to the operator, by the signal's
	 * own notify settings. Never carries the code.
	 */
	public static function alert(CustomerCloudProvision $provision, string $title, string $detail, ?InstanceTransfer $row = null): void {
		self::signal('hosted.transfer_attention', array(
			'provision_id' => (int)$provision->key,
			'domain'       => (string)$provision->get('cvp_domain'),
			'title'        => $title,
			'detail'       => $detail,
			'link'         => self::QUEUE_PATH . ($row !== null && $row->key ? '?itx_instance_transfer_id=' . (int)$row->key : ''),
		));
	}

	private static function signal(string $name, array $payload): void {
		if (self::$signal !== null) {
			call_user_func(self::$signal, $name, $payload);
			return;
		}
		try {
			SignalBus::dispatch($name, $payload);
		} catch (Throwable $e) {
			error_log('InstanceTransfers: signal ' . $name . ' failed: ' . $e->getMessage());
		}
	}

	// ── Shared ────────────────────────────────────────────────────────────────

	/** Refuse a start on something that is not a site we can hand over at all. */
	private static function assert_startable(CustomerCloudProvision $provision): void {
		if (!$provision->is_operator_hosted()) {
			throw new InstanceTransferFlowException($provision->is_transferred()
				? 'This site has already moved to its own Linode account.'
				: 'Only a site we host on our own Linode account can be handed over this way.');
		}
		if ((string)$provision->get('cvp_status') !== 'done' || trim((string)$provision->get('cvp_instance_id')) === '') {
			throw new InstanceTransferFlowException('This site is not finished being set up yet.');
		}
	}

	private static function new_row(CustomerCloudProvision $provision, string $by): InstanceTransfer {
		$row = new InstanceTransfer(NULL);
		$row->set('itx_cvp_customer_cloud_provision_id', (int)$provision->key);
		$row->set('itx_mgn_managed_node_id', (int)$provision->get('cvp_mgn_managed_node_id') ?: null);
		$row->set('itx_usr_user_id', (int)$provision->get('cvp_usr_user_id') ?: null);
		$row->set('itx_instance_id', (string)$provision->get('cvp_instance_id'));
		$row->set('itx_requested_by', $by);
		$row->set('itx_state', InstanceTransfer::STATE_REQUESTED);
		return $row;
	}

	/** The blockers of a check, in one line. */
	public static function blocker_summary(array $check): string {
		$out = array();
		foreach ((array)($check['items'] ?? array()) as $item) {
			if (($item['result'] ?? '') === 'blocker') {
				$out[] = $item['label'] . ($item['detail'] !== '' ? ' — ' . rtrim($item['detail'], '.') : '');
			}
		}
		return implode('; ', $out);
	}

	public static function provision_of(InstanceTransfer $row): CustomerCloudProvision {
		$provision = new CustomerCloudProvision((int)$row->get('itx_cvp_customer_cloud_provision_id'), TRUE);
		if (!$provision->key) {
			throw new InstanceTransferFlowException('The site this transfer belongs to no longer exists.');
		}
		return $provision;
	}

	public static function node_of(CustomerCloudProvision $provision): ?ManagedNode {
		$id = (int)$provision->get('cvp_mgn_managed_node_id');
		if (!$id) {
			return null;
		}
		$node = new ManagedNode($id, TRUE);
		return ($node->key && !$node->get('mgn_delete_time')) ? $node : null;
	}
}
