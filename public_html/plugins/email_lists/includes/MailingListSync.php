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
 * @version 1.0 - from the utils/mailing_list_synchronize.php page
 */
class MailingListSync {

	/** Retryable provider errors tolerated per list before giving up on it. */
	const MAX_RETRIES = 3;

	/**
	 * @param bool $test report what would change, write nothing
	 * @return array{ok:bool, processed:int, changes:int, lines:string[], message:string}
	 */
	public static function run(bool $test = false): array {
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
		foreach (new MultiMailingList(array('deleted' => false)) as $mailing_list) {
			$remote_list_id = $mailing_list->get('mlt_provider_list_id');
			if (!$remote_list_id) {
				continue;
			}
			$lines[] = 'List: ' . $mailing_list->get('mlt_name');

			$cursor = null;
			$retries = 0;
			do {
				try {
					$batch = $provider->getSubscribers($remote_list_id, $cursor, 1000);
				} catch (MailingListProviderException $e) {
					if ($e->isRetryable() && ++$retries <= self::MAX_RETRIES) {
						$lines[] = 'Retryable error, backing off 30s: ' . $e->getMessage();
						sleep(30);
						continue;
					}
					$lines[] = 'Error, skipping the rest of this list: ' . $e->getMessage();
					break;
				}

				foreach ($batch['subscribers'] as $subscriber) {
					$processed++;
					$result = self::reconcile($provider, $mailing_list, $remote_list_id, $subscriber, $test);
					if ($result !== null) {
						$changes++;
						$lines[] = $result;
					}
				}
				$cursor = $batch['next_cursor'];
			} while ($cursor !== null);
		}

		$event_log->set('evl_was_success', 1);
		$event_log->set('evl_note', 'Subscribers processed: ' . $processed);
		$event_log->save();

		return array('ok' => true, 'processed' => $processed, 'changes' => $changes, 'lines' => $lines,
			'message' => ($test ? 'Dry run: ' : '') . $processed . ' remote subscribers checked, ' . $changes . ' changed.');
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
