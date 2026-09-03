<?php
/**
 * API action: mailbox/check_mail — run the inbound pull legs of the delivery
 * chain right now, instead of waiting for the next scheduled pass.
 *
 * POST /api/v1/action/mailbox/check_mail (session key). The reader's Refresh
 * button calls this before re-reading the list, so "Refresh" means "go get my
 * mail", not just "re-ask the database". Two pull lanes, both covered:
 *
 *   - Relay spool (the Fortress topology): pull the relay's sealed spool
 *     (RelaySpoolConsumer). Without this, arriving mail sits on the relay
 *     until the next cron pass — up to the whole cron interval. The parse of
 *     any Fortress pending rows is NOT done here: the list read that follows
 *     drains it (MailboxService::listThreads -> drainRelayBacklog) while the
 *     viewer's vault is unlocked. On direct MX this lane is a fast no-op —
 *     mail is pushed at SMTP time.
 *   - IMAP feeds: fetch the accounts bound to the viewer's accessible
 *     mailboxes (ImapFetch::run — the same full cycle the scheduled poller
 *     runs), bypassing the per-account poll interval. Oldest-fetched first,
 *     a few accounts per click, so one press cannot hold the refresh for a
 *     long roll-call of feeds.
 *
 * Guards: each lane carries a short cooldown (the relay row's last-pull time;
 * an atomic 15-second claim on each account's last-poll time), so rapid
 * clicking is absorbed. Overlap is impossible by construction — the relay
 * pull holds a per-relay advisory lock, the IMAP ingest a per-account one —
 * so a click can never race the scheduled passes.
 *
 * Time: a click is answered inside ImapFetch::INTERACTIVE_BUDGET_SECONDS. The
 * IMAP lane hands every account the same absolute deadline; a fetch the
 * deadline stops (or an account it never reached) is left due, so the
 * scheduled poller finishes it at its next tick instead of one interval
 * later. The response says what was deferred and how long each lane took
 * (specs/mailbox_refresh_budget.md).
 *
 * @version 1.2.0 - the click has a time budget; deferred work is handed to the poller
 * @version 1.1.0 - the IMAP lane: Refresh fetches the viewer's feeds too
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function check_mail_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->isAllAccess() && !count($viewer->accessibleAliasIds())) {
		return LogicResult::error('No mailbox access.');
	}

	$deadline = microtime(true) + ImapFetch::INTERACTIVE_BUDGET_SECONDS;

	$started = microtime(true);
	$relay = _check_mail_relay();
	$relay['took_ms'] = (int)round((microtime(true) - $started) * 1000);

	$started = microtime(true);
	$imap = _check_mail_imap($viewer, $deadline);
	$imap['took_ms'] = (int)round((microtime(true) - $started) * 1000);

	return LogicResult::render(array('relay' => $relay, 'imap' => $imap));
}

/** The relay-spool lane: pull sealed blobs off the fronting relay now. */
function _check_mail_relay(): array {
	if (mailbox_receive_mode() !== 'relay') {
		// Direct MX: mail is pushed at SMTP time; there is no pull leg to run.
		return array('pulled' => false, 'reason' => 'direct');
	}

	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
	$relay = MailboxRelay::active();
	if ($relay === null) {
		return array('pulled' => false, 'reason' => 'no_relay');
	}

	// Cooldown: a pull that just ran (this button, another reader, or the
	// scheduled reconcile) has already collected everything the relay had.
	$last_pull = (string)$relay->get('mrl_last_pull_time');
	$floor = LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-10 seconds', 'Y-m-d H:i:s');
	if ($last_pull !== '' && $last_pull > $floor) {
		return array('pulled' => false, 'reason' => 'recent');
	}

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));
	$consumer = new RelaySpoolConsumer($relay);
	$result = $consumer->pull(100);

	if (($result['status'] ?? '') === 'error') {
		// The reader still re-reads the list after this; a failed pull must not
		// read as a broken refresh. Report it as "nothing new" and log the why.
		error_log('check_mail: relay pull failed: ' . ($result['message'] ?? ''));
		return array('pulled' => false, 'reason' => 'pull_failed');
	}

	return array(
		'pulled'  => true,
		'stored'  => intval($result['stored'] ?? 0),
		'pending' => intval($result['pending'] ?? 0),
	);
}

/**
 * The IMAP lane: fetch the enabled feeds bound to the viewer's accessible
 * mailboxes, bypassing the scheduled poll interval. Most-starved first, capped
 * per click so a fleet of feeds cannot hold one refresh hostage, and every
 * account shares one absolute $deadline. Per-account failures never fail the
 * action — the click's job is the mail it CAN get; what it could not get in
 * time is left due for the poller and counted as deferred.
 */
function _check_mail_imap(MailboxViewer $viewer, ?float $deadline = null): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

	$accounts = new MultiInboundImapAccount(
		array('enabled' => true, 'deleted' => false),
		array('iia_last_poll_time' => 'ASC')
	);
	$accounts->load();
	if (!count($accounts)) {
		return array('fetched' => 0, 'stored' => 0, 'skipped' => 0, 'errors' => 0);
	}

	$accessible = $viewer->isAllAccess() ? null : array_flip($viewer->accessibleAliasIds());

	$fetched = 0; $stored = 0; $skipped = 0; $errors = 0; $deferred = 0;
	$max_accounts = 5;

	foreach ($accounts as $account) {
		if ($fetched >= $max_accounts) {
			break;
		}
		if ($accessible !== null
			&& !isset($accessible[intval($account->get('iia_iea_inbound_email_alias_id'))])) {
			continue;
		}
		if (!$account->isConnectable()) {
			continue;
		}
		if ($deadline !== null && microtime(true) >= $deadline) {
			// The click's time is spent. This account was not touched, so it
			// is as due as it was — the poller's next tick takes it.
			$deferred++;
			continue;
		}
		if (!_check_mail_imap_claim(intval($account->key))) {
			$skipped++; // fetched within the last few seconds, or claimed by another click
			continue;
		}

		$fetched++;
		try {
			$result = ImapFetch::run($account, 50, $deadline);
			$stored += intval($result['stored'] ?? 0);
			if (!empty($result['budget_exhausted'])) {
				// The claim above stamped the account as just polled; undo that
				// so the poller finishes the cycle at its next tick.
				$deferred++;
				ImapFetch::leaveDue($account);
			}
		} catch (ImapFetchBusyException $e) {
			// The scheduled poller (or another fetch) already has this account —
			// the fetch this click wanted is the one running.
			$skipped++;
		} catch (\Throwable $e) {
			$errors++;
			// recordStatus is credential-free by construction.
			$account->recordStatus('Fetch error: ' . substr($e->getMessage(), 0, 400));
			error_log('check_mail: IMAP account ' . $account->key . ' failed: ' . $e->getMessage());
		}
	}

	return array('fetched' => $fetched, 'stored' => $stored, 'skipped' => $skipped, 'errors' => $errors,
		'deferred' => $deferred);
}

/**
 * Atomically claim an account for this click: stamp iia_last_poll_time unless
 * it was fetched within the last 15 seconds. The floor is deliberately NOT the
 * account's own poll interval — bypassing that cadence is the point of
 * Refresh — but two clicks in quick succession collapse to one fetch, and the
 * stamp also resets the scheduled poller's timer (the mail was just fetched).
 */
function _check_mail_imap_claim(int $account_id): bool {
	$db = DbConnector::get_instance()->get_db_link();
	$sql = "UPDATE iia_inbound_imap_accounts
			SET iia_last_poll_time = now()
			WHERE iia_inbound_imap_account_id = :id
			  AND iia_is_enabled = true
			  AND iia_delete_time IS NULL
			  AND (iia_last_poll_time IS NULL
			       OR iia_last_poll_time <= now() - INTERVAL '15 seconds')";
	$stmt = $db->prepare($sql);
	$stmt->execute(array(':id' => $account_id));
	return $stmt->rowCount() > 0;
}

function check_mail_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'Pull newly arrived mail into this deployment now (relay spool pull + IMAP feed fetch), ahead of the scheduled passes',
	];
}

?>
