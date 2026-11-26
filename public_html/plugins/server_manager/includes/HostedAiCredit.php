<?php
/**
 * HostedAiCredit — every change to a site's AI credit, in one place
 * (specs/hosted_ai_credit.md §3.1, §3.5, §3.6).
 *
 * The setup leg (ProvisionHostedAi), the store's fulfilment
 * (HostedAiCreditFulfillment), the lifecycle hooks (suspension, removal,
 * transfer) and the operator's Add credit button all come here, so the money
 * model is written once. HostedAiCap holds the arithmetic; this class writes
 * the ledger row and the account's running figure in one transaction, and
 * moves the key's cap at TrustedRouter to match.
 *
 * THE CAP IS ALWAYS U0 + A + B. Anything that changes A or B calls sync() so
 * the cap follows at once where TrustedRouter can be reached; where it cannot,
 * the setup leg's next tick finds wanted_cap() ≠ hai_limit_set and PATCHes then.
 * Customer money is in the ledger before the cap rises, so a cap never stands
 * above what we were paid for.
 *
 * EVERY CHANGE TO THE FIGURES HOLDS THE ACCOUNT'S ROW LOCKED (locked()): the row
 * is read again FOR UPDATE inside the transaction before anything is computed,
 * so a purchase landing during the setup leg's roll is never overwritten by the
 * leg's older copy.
 *
 * @version 1.2 - claim_collection(): a bundle site's key collection is claimed under the row lock (B12); a site
 *   back in the month it left gets no second free usage (B13)
 * @version 1.1 - row locks on every figure change (B7); a replaced key is switched off, settled, and deleted
 *   only on a later pass from its final usage (retire_key, finish_retiring: B1, B2); a removal that cannot
 *   reach TrustedRouter is kept in `removing` and retried (B5); a lapsed bundle site that pays again is
 *   reopened (B4); a purchase repeated at the same moment is the one already applied (B9) (reviewer2)
 * @version 1.0
 */

class HostedAiCredit {

	/** How long a switched-off key is left before its final usage is read and it is deleted. */
	const RETIRE_WAIT_SECONDS = 120;

	/** @var TrustedRouterClient|false|null A test's stand-in client (false: none configured). */
	public static $client_stand_in = null;

	/** The configured client, or null. */
	public static function client(): ?TrustedRouterClient {
		if (self::$client_stand_in !== null) {
			return self::$client_stand_in === false ? null : self::$client_stand_in;
		}
		return TrustedRouterClient::fromSettings();
	}

	/** The current period, as stored in hai_period_start: '2026-12'. */
	public static function period(?int $now = null): string {
		return gmdate('Y-m', $now ?? time());
	}

	/** The AI credit account of the site on this node: its Managed site's, or its bundle rows'. */
	public static function account_for_node($node): ?HostedAiAccount {
		$provision = CustomerCloudProvision::latest_for_node($node->key);
		$account = $provision ? HostedAiAccount::for_provision($provision->key) : null;
		if ($account !== null) {
			return $account;
		}
		$tenant = ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_MAIL)
			?? ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_SHELF);
		if ($tenant === null) {
			return null;
		}
		$ids = array_map(function ($row) { return (int)$row->key; }, $tenant->siteRows());
		return $ids ? HostedAiAccount::for_tenant(min($ids)) : null;
	}

	// ── Opening an account ────────────────────────────────────────────────────

	/**
	 * A new account for a site, with this month's free usage granted. A
	 * site's first month gets the full free figure whatever day it starts.
	 */
	public static function open(?int $provision_id, ?int $tenant_id): HostedAiAccount {
		$free = HostedAiCap::free_from_settings();
		return self::in_transaction(function () use ($provision_id, $tenant_id, $free) {
			$account = new HostedAiAccount(NULL);
			$account->set('hai_cvp_customer_cloud_provision_id', $provision_id ?: null);
			$account->set('hai_svt_service_tenant_id', $tenant_id ?: null);
			$account->set('hai_state', HostedAiAccount::STATE_PENDING);
			$account->set('hai_period_start', self::period());
			$account->set('hai_allowance', $free);
			$account->save();
			self::ledger($account, HostedAiLedger::KIND_GRANT, $free, array('hal_note' => 'Free usage for the first month.'));
			return $account;
		});
	}

	/**
	 * A removed bundle site whose bundle is paid again: its account opens
	 * again with no key, its new month's free usage less anything it still
	 * owes, and the leg mints it a key. Bought credit was expired when it
	 * left, so B starts at zero.
	 */
	public static function reopen(HostedAiAccount $account): void {
		$free = HostedAiCap::free_from_settings();
		self::locked($account, function () use ($account, $free) {
			if ((string)$account->get('hai_state') !== HostedAiAccount::STATE_REMOVED) {
				return;
			}
			$owed = (int)$account->get('hai_owed');
			// Back in the month it left: its free usage for this month was
			// already had, so none is granted until the next roll (B13).
			$same_month = (string)$account->get('hai_period_start') === self::period();
			$account->set('hai_state', HostedAiAccount::STATE_PENDING);
			$account->set('hai_period_start', self::period());
			$account->set('hai_period_start_usage', 0);
			$account->set('hai_usage', 0);
			$account->set('hai_usage_time', null);
			$account->set('hai_key_time', null);
			$account->set('hai_allowance', $same_month ? 0 : max(0, $free - $owed));
			$account->set('hai_owed', $same_month ? $owed : max(0, $owed - $free));
			$account->set('hai_purchased', 0);
			$account->set('hai_attempts', 0);
			$account->set('hai_note', 'Reopened: the site\'s bundle is paid again.');
			$account->save();
			self::ledger($account, HostedAiLedger::KIND_GRANT, (int)$account->get('hai_allowance'),
				array('hal_note' => $same_month
					? 'Back in the month it left: this month\'s free usage was already had; the next month tops it up.'
					: 'Free usage for ' . self::period() . ', on the site\'s return.'));
		});
	}

	/** How long a claim on a collection holds off another while its key is minted. */
	const CLAIM_SECONDS = 300;

	/**
	 * Claim a bundle site's key collection, under the row lock, before
	 * anything is minted (reviewer2 B12): the 24-hour rule and the state are
	 * checked again here, and hai_key_time is stamped as the claim, so of two
	 * collections at the same moment the second is refused and only one key
	 * is ever minted. A claim whose mint failed (no key on record) may be
	 * taken again after CLAIM_SECONDS. Throws HostedAiAccountException with
	 * the reason when refused.
	 */
	public static function claim_collection(HostedAiAccount $account, int $every_seconds): void {
		self::locked($account, function () use ($account, $every_seconds) {
			if (in_array((string)$account->get('hai_state'), array(HostedAiAccount::STATE_REMOVED,
					HostedAiAccount::STATE_REMOVING, HostedAiAccount::STATE_FAILED, HostedAiAccount::STATE_DISABLED), true)) {
				throw new HostedAiAccountException('This site has no AI credit to collect a key for.');
			}
			$stamped = strtotime((string)$account->get('hai_key_time') . ' UTC');
			if ($stamped !== false) {
				$age = time() - $stamped;
				$has_key = trim((string)$account->get('hai_key_hash')) !== '';
				if (($has_key && $age < $every_seconds) || (!$has_key && $age < self::CLAIM_SECONDS)) {
					throw new HostedAiAccountException('This site collected its AI credit key less than a day ago, or is '
						. 'collecting one now. It can collect a fresh one after '
						. gmdate('Y-m-d H:i', $stamped + ($has_key ? $every_seconds : self::CLAIM_SECONDS)) . ' UTC.');
				}
			}
			$account->set('hai_key_time', gmdate('Y-m-d H:i:s'));
			$account->save();
		});
	}

	// ── Purchases and refunds ─────────────────────────────────────────────────

	/**
	 * Credit bought: $money dollars paid on order line $order_item_id. Applies
	 * once per line however many times fulfilment runs. Returns the purchase
	 * row (the existing one on a repeat).
	 */
	public static function purchase(HostedAiAccount $account, int $order_item_id, float $money,
			?float $markup_percent = null): HostedAiLedger {
		if ($order_item_id <= 0) {
			throw new HostedAiLedgerException('A purchase names the order line that paid for it.');
		}
		$markup = $markup_percent ?? HostedAiCap::markup_from_settings();
		try {
			$row = self::locked($account, function () use ($account, $order_item_id, $money, $markup) {
			$existing = HostedAiLedger::purchase_for_line($order_item_id);
			if ($existing !== null) {
				return $existing;
			}
			$credit = HostedAiCap::credit_for_money($money, $markup);
			$account->set('hai_purchased', (int)$account->get('hai_purchased') + $credit);
			$account->save();
			return self::ledger($account, HostedAiLedger::KIND_PURCHASE, $credit, array(
				'hal_purchase_odi_order_item_id' => $order_item_id,
				'hal_money'                      => number_format($money, 2, '.', ''),
				'hal_markup_percent'             => $markup,
				'hal_note'                       => 'Bought $' . number_format($money, 2) . ' at a ' . rtrim(rtrim(number_format($markup, 2, '.', ''), '0'), '.') . '% markup.',
			));
			});
		} catch (Throwable $e) {
			// Two fulfilments of one line at the same moment: the other one
			// wrote it, and the unique purchase line refused this one.
			$existing = HostedAiLedger::purchase_for_line($order_item_id);
			if ($existing === null) {
				throw $e;
			}
			$account->load();
			return $existing;
		}
		self::sync_quietly($account);
		return $row;
	}

	/**
	 * Bring a credit purchase line's refunds into the ledger: where the store
	 * has refunded more of the line ($refunded_total, odi_refund_amount) than
	 * the refund rows already say, write one row for the difference and take
	 * that much credit off, at the markup recorded on the purchase. Returns the
	 * row written, or null when there was nothing new.
	 *
	 * The credit taken is never more than what is unspent: spent credit is
	 * gone, and taking it off again would leave the site owing it. A refund on
	 * a removed site writes the row and changes no cap.
	 */
	public static function refund_line(int $order_item_id, float $refunded_total): ?HostedAiLedger {
		$purchase = HostedAiLedger::purchase_for_line($order_item_id);
		if ($purchase === null) {
			return null;
		}
		$account = new HostedAiAccount((int)$purchase->get('hal_hai_hosted_ai_account_id'), TRUE);
		if (!$account->key) {
			return null;
		}
		$row = self::locked($account, function () use ($account, $purchase, $order_item_id, $refunded_total) {
			$already = HostedAiLedger::refunded_money_for_line($order_item_id);
			$new_money = round($refunded_total - $already, 2);
			if ($new_money <= 0) {
				return null;
			}
			$markup = (float)$purchase->get('hal_markup_percent');
			$removed = (string)$account->get('hai_state') === HostedAiAccount::STATE_REMOVED;
			$credit = $removed ? 0 : HostedAiCap::credit_for_refund($new_money, $markup, $account->unspent_purchased());
			if ($credit > 0) {
				$account->set('hai_purchased', max(0, (int)$account->get('hai_purchased') - $credit));
				$account->save();
			}
			return self::ledger($account, HostedAiLedger::KIND_REFUND, -$credit, array(
				'hal_odi_order_item_id' => $order_item_id,
				'hal_money'             => number_format($new_money, 2, '.', ''),
				'hal_markup_percent'    => $markup,
				'hal_note'              => 'Refunded $' . number_format($new_money, 2)
					. ($removed ? ' after the site was removed; no credit to take off.' : '.'),
			));
		});
		if ($row !== null) {
			self::sync_quietly($account);
		}
		return $row;
	}

	/**
	 * The watch's refund pass: every credit purchase line whose store refund
	 * total moved since its refund rows were written. Returns rows written.
	 */
	public static function apply_refunds(): int {
		$written = 0;
		foreach (new MultiHostedAiLedger(array('kind' => HostedAiLedger::KIND_PURCHASE, 'deleted' => false)) as $purchase) {
			$line_id = (int)$purchase->get('hal_purchase_odi_order_item_id');
			if ($line_id <= 0 || !class_exists('OrderItem')) {
				continue;
			}
			try {
				$line = new OrderItem($line_id, TRUE);
			} catch (Throwable $e) {
				continue;
			}
			if (!$line->key) {
				continue;
			}
			$refunded = (float)$line->get('odi_refund_amount');
			if ($refunded <= 0) {
				continue;
			}
			if (self::refund_line($line_id, $refunded) !== null) {
				$written++;
			}
		}
		return $written;
	}

	/** An operator's correction to bought credit, with a reason. */
	public static function adjust(HostedAiAccount $account, int $amount_micro, string $reason, int $by_user_id): HostedAiLedger {
		$reason = trim($reason);
		if ($reason === '') {
			throw new HostedAiLedgerException('Say why the credit is adjusted.');
		}
		$row = self::locked($account, function () use ($account, $amount_micro, $reason, $by_user_id) {
			$after = (int)$account->get('hai_purchased') + $amount_micro;
			if ($after < 0) {
				throw new HostedAiLedgerException('That would take the site\'s bought credit below zero.');
			}
			$account->set('hai_purchased', $after);
			$account->save();
			return self::ledger($account, HostedAiLedger::KIND_ADJUST, $amount_micro, array(
				'hal_note' => mb_substr($reason, 0, 500), 'hal_usr_user_id' => $by_user_id ?: null,
			));
		});
		self::sync_quietly($account);
		return $row;
	}

	// ── The month ─────────────────────────────────────────────────────────────

	/**
	 * Roll an account into the current month, given its lifetime usage now
	 * and whether its plan still gives it free usage. Writes the grant row.
	 * Returns whether it rolled (false when already in this month).
	 */
	public static function roll(HostedAiAccount $account, int $usage, bool $entitled): bool {
		$period = self::period();
		if ((string)$account->get('hai_period_start') === $period) {
			return false;
		}
		$free = HostedAiCap::free_from_settings();
		return self::locked($account, function () use ($account, $usage, $entitled, $period, $free) {
			if ((string)$account->get('hai_period_start') === $period) {
				return false;
			}
			$new = HostedAiCap::roll($usage, (int)$account->get('hai_period_start_usage'),
				(int)$account->get('hai_allowance'), (int)$account->get('hai_purchased'),
				(int)$account->get('hai_owed'), $free, $entitled);
			$account->set('hai_usage', max($usage, (int)$account->get('hai_usage')));
			$account->set('hai_usage_time', gmdate('Y-m-d H:i:s'));
			$account->set('hai_period_start_usage', $new['u0']);
			$account->set('hai_allowance', $new['allowance']);
			$account->set('hai_purchased', $new['purchased']);
			$account->set('hai_owed', $new['owed']);
			$account->set('hai_period_start', $period);
			$account->save();
			$note = $entitled ? 'Free usage topped up for ' . $period . '.' : 'No free usage for ' . $period . ': the plan has lapsed.';
			if ($new['over'] > 0) {
				$note .= ' ' . HostedAiCap::dollars($new['over']) . ' spent past the cap last month comes off free usage.';
			}
			self::ledger($account, HostedAiLedger::KIND_GRANT, $new['allowance'], array('hal_note' => $note));
			return true;
		});
	}

	/**
	 * Take a key's spend this month out of A and B (past both, it is owed),
	 * and start the next key's count from zero. Call inside locked().
	 */
	private static function settle(HostedAiAccount $account, int $old_usage): void {
		$spent = HostedAiCap::spent(max($old_usage, (int)$account->get('hai_usage')),
			(int)$account->get('hai_period_start_usage'));
		self::charge($account, $spent);
		$account->set('hai_period_start_usage', 0);
		$account->set('hai_usage', 0);
		$account->set('hai_usage_time', null);
		$account->set('hai_limit_set', null);
	}

	/** Spend taken from A first, then B, the rest owed. Call inside locked(). */
	private static function charge(HostedAiAccount $account, int $spent): void {
		$spent = max(0, $spent);
		$a = (int)$account->get('hai_allowance');
		$b = (int)$account->get('hai_purchased');
		$account->set('hai_owed', (int)$account->get('hai_owed') + max(0, $spent - $a - $b));
		$account->set('hai_allowance', $a - HostedAiCap::allowance_used($spent, $a));
		$account->set('hai_purchased', HostedAiCap::unspent_purchased($spent, $a, $b));
	}

	/**
	 * Retire the account's current key, step one: switch it off at
	 * TrustedRouter, settle what it has spent ($usage, as just read), and park
	 * it for finish_retiring(). The account then has no key, so the leg (or a
	 * bundle site's collection) mints the next one at what is left.
	 *
	 * A failure to switch it off throws and changes nothing.
	 */
	public static function retire_key(HostedAiAccount $account, TrustedRouterClient $client, int $usage): void {
		$hash = trim((string)$account->get('hai_key_hash'));
		if ($hash === '') {
			return;
		}
		$client->setDisabled($hash, true);
		self::locked($account, function () use ($account, $hash, $usage) {
			if (trim((string)$account->get('hai_key_hash')) !== $hash) {
				return;
			}
			$settled = max($usage, (int)$account->get('hai_usage'));
			self::settle($account, $settled);
			$parked = $account->retiring_keys();
			$parked[] = array('hash' => $hash, 'usage' => $settled, 'time' => time());
			$account->set_retiring_keys($parked);
			$account->set('hai_key_hash', null);
			$account->set('hai_key_time', null);
			$account->set('hai_disabled_set', false);
			$account->save();
		});
	}

	/**
	 * Step two, on a later pass: each parked key's final usage (what was in
	 * flight when it was switched off has landed by now) is charged, and the
	 * key deleted. A key's spend is charged only once its delete has
	 * succeeded, so a failure leaves it parked for the next pass, charged
	 * once. $usage is the key listing (hash => ['usage' => …]); a key not
	 * listed is already gone. Returns how many finished; the first delete
	 * that fails is thrown after the others are tried.
	 */
	public static function finish_retiring(HostedAiAccount $account, TrustedRouterClient $client, array $usage): int {
		$finished = 0;
		$failure = null;
		foreach ($account->retiring_keys() as $entry) {
			$hash = (string)($entry['hash'] ?? '');
			if ($hash === '' || time() - (int)($entry['time'] ?? 0) < self::RETIRE_WAIT_SECONDS) {
				continue;
			}
			$final = isset($usage[$hash]) ? (int)$usage[$hash]['usage'] : (int)($entry['usage'] ?? 0);
			try {
				$client->deleteKey($hash);
			} catch (Throwable $e) {
				$failure = $failure ?? $e;
				continue;
			}
			self::locked($account, function () use ($account, $hash, $final) {
				$left = array();
				$late = 0;
				foreach ($account->retiring_keys() as $entry) {
					if ((string)($entry['hash'] ?? '') === $hash) {
						$late = max(0, $final - (int)($entry['usage'] ?? 0));
					} else {
						$left[] = $entry;
					}
				}
				if ((string)$account->get('hai_state') !== HostedAiAccount::STATE_REMOVED) {
					self::charge($account, $late);
				}
				$account->set_retiring_keys($left);
				$account->save();
			});
			$finished++;
		}
		if ($failure !== null) {
			throw $failure;
		}
		return $finished;
	}

	// ── The key at TrustedRouter ──────────────────────────────────────────────

	/**
	 * Move the key's cap to what the figures call for, if it is not there.
	 * Returns true when it PATCHed. Throws TrustedRouterException.
	 */
	public static function sync(HostedAiAccount $account, ?TrustedRouterClient $client = null): bool {
		if (!$account->has_key()) {
			return false;
		}
		$wanted = $account->wanted_cap();
		if ($account->get('hai_limit_set') !== null && (int)$account->get('hai_limit_set') === $wanted) {
			return false;
		}
		$client = $client ?? self::client();
		if ($client === null) {
			return false;
		}
		$client->setLimit((string)$account->get('hai_key_hash'), $wanted);
		$account->set('hai_limit_set', $wanted);
		$account->save();
		return true;
	}

	/** sync(), with a failure written on the row for the leg's next tick to retry. */
	public static function sync_quietly(HostedAiAccount $account): void {
		try {
			self::sync($account);
		} catch (Throwable $e) {
			$account->set('hai_note', 'The cap could not be moved yet: ' . mb_substr($e->getMessage(), 0, 500));
			$account->save();
		}
	}

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	/** Suspension: refuse every request on the key. Returns the step's line. */
	public static function suspend_provision(int $provision_id): string {
		$account = HostedAiAccount::for_provision($provision_id);
		if ($account === null || !$account->has_key()) {
			return 'No AI credit key on record; nothing to switch off.';
		}
		$client = self::client();
		if ($client === null) {
			return 'The AI credit key was not switched off: this management node holds no TrustedRouter key.';
		}
		try {
			$client->setDisabled((string)$account->get('hai_key_hash'), true);
		} catch (Throwable $e) {
			return 'The AI credit key was not switched off: ' . $e->getMessage();
		}
		$account->set('hai_disabled_set', true);
		if ((string)$account->get('hai_state') === HostedAiAccount::STATE_DONE) {
			$account->set('hai_state', HostedAiAccount::STATE_DISABLED);
		}
		$account->save();
		return 'Switched off the site\'s AI credit key at TrustedRouter.';
	}

	/** Reopen: allow requests on the key again. Returns the step's line. */
	public static function reopen_provision(int $provision_id): string {
		$account = HostedAiAccount::for_provision($provision_id);
		if ($account === null || !$account->has_key()) {
			return 'No AI credit key on record; nothing to switch on.';
		}
		$client = self::client();
		if ($client === null) {
			return 'The AI credit key was not switched on: this management node holds no TrustedRouter key.';
		}
		try {
			$client->setDisabled((string)$account->get('hai_key_hash'), false);
		} catch (Throwable $e) {
			return 'The AI credit key was not switched on: ' . $e->getMessage();
		}
		$account->set('hai_disabled_set', false);
		if ((string)$account->get('hai_state') === HostedAiAccount::STATE_DISABLED) {
			$account->set('hai_state', HostedAiAccount::STATE_DONE);
		}
		$account->save();
		return 'Switched the site\'s AI credit key back on at TrustedRouter.';
	}

	/**
	 * The site has left us: delete its key and end its unspent bought credit
	 * with an expire row (Q6: a refund, if the buyer asks, is by hand on the
	 * store's refund page). Returns ['ok' => bool, 'line' => the step in words];
	 * not ok leaves the account as it was, to be asked again.
	 */
	public static function remove(HostedAiAccount $account, string $why): array {
		if ((string)$account->get('hai_state') === HostedAiAccount::STATE_REMOVED) {
			return array('ok' => true, 'line' => 'The AI credit account was already closed.');
		}
		$hashes = array_filter(array_merge(array(trim((string)$account->get('hai_key_hash'))),
			array_map(function ($e) { return (string)($e['hash'] ?? ''); }, $account->retiring_keys())));
		$line = 'No AI credit key to delete.';
		if ($hashes) {
			$client = self::client();
			$failure = $client === null ? 'this management node holds no TrustedRouter key' : '';
			if ($client !== null) {
				try {
					foreach ($hashes as $hash) {
						$client->deleteKey($hash);
					}
				} catch (Throwable $e) {
					$failure = $e->getMessage();
				}
			}
			if ($failure !== '') {
				// Kept and asked again by the leg each pass: a live key with
				// unexpired credit must not outlive its site for want of one call.
				$account->set('hai_state', HostedAiAccount::STATE_REMOVING);
				$account->set('hai_note', mb_substr('Removing (' . $why . '); the key was not deleted yet: ' . $failure, 0, 4000));
				$account->save();
				return array('ok' => false, 'line' => 'The AI credit key was not deleted yet (' . $failure
					. '); it is asked again on every provisioning pass. Its cap still limits it.');
			}
			$line = 'Deleted the site\'s AI credit key at TrustedRouter.';
		}
		$unspent = 0;
		self::locked($account, function () use ($account, $why, &$unspent) {
			$unspent = $account->unspent_purchased();
			$account->set('hai_purchased', 0);
			$account->set('hai_allowance', 0);
			$account->set('hai_state', HostedAiAccount::STATE_REMOVED);
			$account->set('hai_key_hash', null);
			$account->set('hai_key_time', null);
			$account->set_retiring_keys(array());
			$account->set('hai_limit_set', null);
			$account->set('hai_note', mb_substr($why, 0, 500));
			$account->save();
			if ($unspent > 0) {
				self::ledger($account, HostedAiLedger::KIND_EXPIRE, -$unspent, array(
					'hal_note' => 'Unspent bought credit ended with the site: ' . mb_substr($why, 0, 400),
				));
			}
		});
		if ($unspent > 0) {
			$line .= ' ' . HostedAiCap::dollars($unspent) . ' of unspent bought credit ended with it (refundable by hand on request).';
		}
		return array('ok' => true, 'line' => $line);
	}

	/** remove() for a Managed site, by its provision; ok with nothing to do when it has no account. */
	public static function remove_provision(int $provision_id, string $why): array {
		$account = HostedAiAccount::for_provision($provision_id);
		return $account === null ? array('ok' => true, 'line' => '') : self::remove($account, $why);
	}

	/**
	 * A Managed site moved to its buyer's own account. Where it carries on
	 * with our email and backups (its node-linked Services rows), it is a
	 * bundle site now and keeps its key and credit: the account gains the
	 * tenant id. Otherwise the site is switched off (an empty key pushed,
	 * which clears only the hosted AI setting) and the account removed.
	 * Returns the step in words.
	 */
	public static function carry_or_end(CustomerCloudProvision $provision, ?ManagedNode $node): string {
		$account = HostedAiAccount::for_provision($provision->key);
		if ($account === null || (string)$account->get('hai_state') === HostedAiAccount::STATE_REMOVED) {
			return '';
		}
		// Named: classes under includes/provisioning/ are not on the name-resolution path.
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionHostedAi.php'));
		$tenant = null;
		if ($node !== null) {
			$tenant = ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_MAIL)
				?? ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_SHELF);
		}
		if ($tenant !== null) {
			$anchor = ProvisionHostedAi::anchor_of($tenant) ?? $tenant;
			$account->set('hai_svt_service_tenant_id', (int)$anchor->key);
			$account->save();
			return 'Its AI credit carries on with its email and backups.';
		}
		$lines = array();
		if ($node !== null && JobCommandBuilder::has_primitive($node, ProvisionHostedAi::JOB_TYPE)) {
			try {
				$built = JobCommandBuilder::build_hosted_ai_settings($node, array('api_key' => ''));
				ManagementJob::createFromBuild($node->key, ProvisionHostedAi::JOB_TYPE, $built,
					array('hai_account_id' => (int)$account->key, 'clear' => '1'), null);
				$lines[] = 'Asked the site to forget its AI credit key.';
			} catch (Throwable $e) {
				$lines[] = 'Could not ask the site to forget its AI credit key: ' . $e->getMessage();
			}
		}
		$lines[] = self::remove($account, 'The site moved to its owner\'s own account.')['line'];
		return implode(' ', $lines);
	}

	// ── Shared ────────────────────────────────────────────────────────────────

	private static function ledger(HostedAiAccount $account, string $kind, int $amount, array $fields): HostedAiLedger {
		$row = new HostedAiLedger(NULL);
		$row->set('hal_hai_hosted_ai_account_id', (int)$account->key);
		$row->set('hal_kind', $kind);
		$row->set('hal_amount', $amount);
		$row->set('hal_period', (string)$account->get('hai_period_start'));
		foreach ($fields as $name => $value) {
			$row->set($name, $value);
		}
		$row->save();
		return $row;
	}

	/**
	 * Run $fn with the account's row locked: inside a transaction, the row is
	 * read again FOR UPDATE and the object refreshed from it, so $fn computes
	 * from the figures as they stand, not from a copy loaded earlier.
	 */
	private static function locked(HostedAiAccount $account, callable $fn) {
		return self::in_transaction(function () use ($account, $fn) {
			$q = DbConnector::get_instance()->get_db_link()->prepare(
				'SELECT 1 FROM hai_hosted_ai_accounts WHERE hai_hosted_ai_account_id = ? FOR UPDATE');
			$q->execute(array((int)$account->key));
			$account->load();
			return $fn();
		});
	}

	/** Run $fn in a transaction, joining one already open (a test's, or a caller's). */
	private static function in_transaction(callable $fn) {
		$db = DbConnector::get_instance()->get_db_link();
		if ($db->inTransaction()) {
			return $fn();
		}
		$db->beginTransaction();
		try {
			$out = $fn();
			$db->commit();
			return $out;
		} catch (Throwable $e) {
			$db->rollBack();
			throw $e;
		}
	}
}
