<?php
/**
 * MailingListSync — reconcile local list subscribers with the configured
 * remote provider (MailChimp), list by list, through the provider's
 * cursor pagination.
 *
 * For each remote subscriber with a local account, whichever side changed
 * last wins: a remote subscribe newer than the local state subscribes
 * locally, an older one is pushed back as an unsubscribe, and the same for
 * unsubscribes. A bounced address marks the account bouncing. A remote
 * "pending" is not imported: the provider has no consent for it yet.
 *
 * Run by the MailingListSyncTask task and by the "Sync now" button on the
 * mailing lists admin page. Never by a page view.
 *
 * @version 1.1 - a retryable provider error retries the same page (it ended the list on page one, B11); a
 *   list that still fails makes the run unsuccessful; a web request never sleeps between tries
 *   (specs/email_provider_sdk_removal.md review)
 * @version 1.0 - from the utils/mailing_list_synchronize.php page
 */
class MailingListSync {

	/** Retryable provider errors tolerated per list before giving up on it. */
	const MAX_RETRIES = 3;

	/** Seconds waited before retrying a retryable error, where waiting is allowed. */
	const BACKOFF_SECONDS = 30;

	/** @var callable|null tests: stand-in for sleep() */
	private static $sleeper = null;

	/** Record waits instead of sleeping (test processes only). */
	public static function useTestSleep(?callable $sleeper): void {
		if (php_sapi_name() !== 'cli') {
			throw new LogicException('MailingListSync::useTestSleep is for test processes only.');
		}
		self::$sleeper = $sleeper;
	}

	/**
	 * @param bool $test report what would change, write nothing
	 * @param bool|null $wait back off between retries; default only on the command line (the
	 *        task), never in a web request (the "Sync now" button), which gives up on the list
	 *        at its first retryable error instead of holding the page
	 * @return array{ok:bool, processed:int, changes:int, lines:string[], message:string}
	 */
	public static function run(bool $test = false, ?bool $wait = null): array {
		$wait = $wait ?? (PHP_SAPI === 'cli');
		$lines = array();
		$event_log = new EventLog(NULL);
		$event_log->set('evl_event', 'mailing_list_synchronize');
		$event_log->set('evl_usr_user_id', User::USER_SYSTEM);
		$event_log->save();
		$event_log->load();

		$provider = MailingListService::getProvider();
		if (!$provider) {
			$event_log->set('evl_was_success', 0);
			$event_log->set('evl_note', 'No provider configured');
			$event_log->save();
			return array('ok' => false, 'processed' => 0, 'changes' => 0, 'lines' => array(), 'message' => 'No mailing list provider is configured.');
		}

		$processed = 0;
		$changes = 0;
		$failed_lists = array();
		foreach (new MultiMailingList(array('deleted' => false)) as $mailing_list) {
			$remote_list_id = $mailing_list->get('mlt_provider_list_id');
			if (!$remote_list_id) {
				continue;
			}
			$lines[] = 'List: ' . $mailing_list->get('mlt_name');

			$ok = self::eachSubscriber($provider, $remote_list_id, $wait, $lines,
				function (array $subscriber) use ($provider, $mailing_list, $remote_list_id, $test, &$processed, &$changes, &$lines) {
					$processed++;
					$result = self::reconcile($provider, $mailing_list, $remote_list_id, $subscriber, $test);
					if ($result !== null) {
						$changes++;
						$lines[] = $result;
					}
				});
			if (!$ok) {
				$failed_lists[] = $mailing_list->get('mlt_name');
			}
		}

		$all_ok = empty($failed_lists);
		$event_log->set('evl_was_success', $all_ok ? 1 : 0);
		$event_log->set('evl_note', 'Subscribers processed: ' . $processed
			. ($all_ok ? '' : '; lists not finished: ' . implode(', ', $failed_lists)));
		$event_log->save();

		return array('ok' => $all_ok, 'processed' => $processed, 'changes' => $changes, 'lines' => $lines,
			'message' => ($test ? 'Dry run: ' : '') . $processed . ' remote subscribers checked, ' . $changes . ' changed.'
				. ($all_ok ? '' : ' Not finished, the provider kept failing: ' . implode(', ', $failed_lists) . '.'));
	}

	/**
	 * Every subscriber of one remote list, page by page, handed to $each.
	 * A retryable error asks for the same page again, up to MAX_RETRIES
	 * times, waiting BACKOFF_SECONDS first when $wait. Returns false when the
	 * list could not be read to the end.
	 */
	public static function eachSubscriber($provider, string $remote_list_id, bool $wait, array &$lines, callable $each): bool {
		$cursor = null;
		$retries = 0;
		while (true) {
			try {
				$batch = $provider->getSubscribers($remote_list_id, $cursor, 1000);
			} catch (MailingListProviderException $e) {
				if ($wait && $e->isRetryable() && ++$retries <= self::MAX_RETRIES) {
					$lines[] = 'Retryable error, backing off ' . self::BACKOFF_SECONDS . 's: ' . $e->getMessage();
					if (self::$sleeper !== null) {
						(self::$sleeper)(self::BACKOFF_SECONDS);
					} else {
						sleep(self::BACKOFF_SECONDS);
					}
					continue;   // the same page again
				}
				$lines[] = 'Error, skipping the rest of this list: ' . $e->getMessage();
				return false;
			}
			$retries = 0;
			foreach ($batch['subscribers'] as $subscriber) {
				$each($subscriber);
			}
			$cursor = $batch['next_cursor'];
			if ($cursor === null) {
				return true;
			}
		}
	}

	/** One remote subscriber. Returns what changed, or null when nothing did. */
	private static function reconcile($provider, MailingList $mailing_list, string $remote_list_id, array $subscriber, bool $test): ?string {
		$remote_status = $subscriber['status'];
		$remote_changed = $subscriber['last_changed'];

		$user = User::GetByEmail($subscriber['email']);
		if (!$user) {
			return null;
		}

		$local = $mailing_list->is_user_in_list($user->key);
		$local_change_time = $local ? $local->get('mlr_change_time') : null;
		$remote_is_newer = !$local_change_time || $local_change_time < $remote_changed;
		$who = 'User ' . $user->key . ': ';

		if ($remote_status === 'subscribed' && !$local) {
			if ($remote_is_newer) {
				if (!$test) {
					// The provider holds the consent for a remote "subscribed".
					$mailing_list->add_registrant($user->key, array('source' => MailingListRegistrant::SOURCE_PROVIDER, 'email' => $subscriber['email']));
				}
				return $who . 'subscribed locally';
			}
			if (!$test) {
				self::push($provider, 'unsubscribe', $remote_list_id, $user);
			}
			return $who . 'provider set to unsubscribed';
		}
		if ($remote_status === 'unsubscribed' && $local) {
			if ($remote_is_newer) {
				if (!$test) {
					$mailing_list->remove_registrant($user->key);
				}
				return $who . 'unsubscribed locally';
			}
			if (!$test) {
				self::push($provider, 'subscribe', $remote_list_id, $user);
			}
			return $who . 'provider set to subscribed';
		}
		if ($remote_status === 'bounced') {
			if (!$test) {
				$user->email_unverify_bouncing_user();
			}
			return $who . 'marked bouncing';
		}
		return null;
	}

	private static function push($provider, string $what, string $remote_list_id, User $user): void {
		try {
			if ($what === 'subscribe') {
				$provider->subscribe($remote_list_id, $user->get('usr_email'), $user->get('usr_first_name'), $user->get('usr_last_name'));
			} else {
				$provider->unsubscribe($remote_list_id, $user->get('usr_email'));
			}
		} catch (MailingListProviderException $e) {
			error_log('MailingListSync ' . $what . ' failed: ' . $e->getMessage());
		} catch (\InvalidArgumentException $e) {
			error_log('MailingListSync ' . $what . ' rejected bad input: ' . $e->getMessage());
		}
	}
}
