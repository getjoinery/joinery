<?php
/**
 * Mailing List Synchronize
 *
 * Reconciles local mailing list registrants against the configured remote
 * provider via opaque-cursor pagination. The same compare-timestamps logic from
 * the legacy mailchimp_synchronize.php is preserved; only the API calls change.
 *
 * Usage: hit by URL by an authenticated admin (permission >= 5).
 * Optional ?test=1 to dry-run without writing.
 */

error_reporting(E_ERROR | E_PARSE);
set_time_limit(0);

require_once(__DIR__ . '/../includes/Globalvars.php');
require_once(__DIR__ . '/../includes/EmailTemplate.php');
require_once(__DIR__ . '/../data/users_class.php');
require_once(__DIR__ . '/../data/contact_types_class.php');
require_once(__DIR__ . '/../data/event_logs_class.php');
require_once(PathHelper::getIncludePath('includes/MailingListService.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$test = (bool)LibraryFunctions::fetch_variable('test', 0, 0, '');

$event_log = new EventLog(NULL);
$event_log->set('evl_event', 'mailing_list_synchronize');
$event_log->set('evl_usr_user_id', User::USER_SYSTEM);
$event_log->save();
$event_log->load();

$provider = MailingListService::getProvider();
if (!$provider) {
    echo "No mailing list provider configured. Aborting.<br>\n";
    $event_log->set('evl_was_success', 0);
    $event_log->set('evl_note', 'No provider configured');
    $event_log->save();
    exit();
}

$mailing_lists = new MultiMailingList(['deleted' => false]);
$mailing_lists->load();

$total_processed = 0;

foreach ($mailing_lists as $mailing_list) {
    $remote_list_id = $mailing_list->get('mlt_provider_list_id');
    if (!$remote_list_id) {
        continue;
    }

    echo '<h3>List: ' . htmlspecialchars($mailing_list->get('mlt_name')) . "</h3>\n";

    $cursor = null;
    do {
        try {
            $batch = $provider->getSubscribers($remote_list_id, $cursor, 1000);
        } catch (MailingListProviderException $e) {
            if ($e->isRetryable()) {
                echo 'Retryable error: ' . htmlspecialchars($e->getMessage()) . " — backing off 30s<br>\n";
                @ob_flush();
                @flush();
                sleep(30);
                continue;
            }
            echo 'Permanent error, aborting list: ' . htmlspecialchars($e->getMessage()) . "<br>\n";
            break;
        }

        foreach ($batch['subscribers'] as $subscriber) {
            $total_processed++;
            $email = $subscriber['email'];
            $remote_status = $subscriber['status'];
            $remote_changed = $subscriber['last_changed'];

            $user = User::GetByEmail($email);
            if (!$user) {
                continue;
            }

            $registrant_in_list = $mailing_list->is_user_in_list($user->key);
            $local_change_time = null;
            $local_change_wording = 'Not in local list';
            if ($registrant_in_list) {
                $local_change_time = $registrant_in_list->get('mlr_change_time');
                $local_change_wording = $registrant_in_list->get('mlr_change_time');
            }

            echo $user->key . ': Remote:' . htmlspecialchars((string)$remote_changed) .
                ' -- Local:' . htmlspecialchars((string)$local_change_wording) . ' Result: ';

            if ($remote_status === 'subscribed' && $registrant_in_list) {
                echo ' NO CHANGE (sub)';
            } else if ($remote_status === 'unsubscribed' && !$registrant_in_list) {
                echo ' NO CHANGE (unsub)';
            } else if ($remote_status === 'subscribed' && !$registrant_in_list) {
                if (!$local_change_time || $local_change_time < $remote_changed) {
                    // Remote is most recent — update locally
                    echo ' subscribe locally ';
                    if (!$test) {
                        $mailing_list->add_registrant($user->key);
                    }
                } else if ($local_change_time >= $remote_changed) {
                    // Local is most recent — push unsubscribe to remote
                    if (!$test) {
                        try {
                            $provider->unsubscribe($remote_list_id, $user->get('usr_email'));
                        } catch (MailingListProviderException $e) {
                            error_log('Sync unsubscribe failed: ' . $e->getMessage());
                        } catch (\InvalidArgumentException $e) {
                            error_log('Sync unsubscribe rejected bad input: ' . $e->getMessage());
                        }
                    }
                    echo ' set provider to unsubscribed';
                }
            } else if ($remote_status === 'unsubscribed' && $registrant_in_list) {
                if (!$local_change_time || $local_change_time < $remote_changed) {
                    if (!$test) {
                        $mailing_list->remove_registrant($user->key);
                    }
                    echo ' unsubscribe locally ';
                } else if ($local_change_time >= $remote_changed) {
                    if (!$test) {
                        try {
                            $provider->subscribe(
                                $remote_list_id,
                                $user->get('usr_email'),
                                $user->get('usr_first_name'),
                                $user->get('usr_last_name')
                            );
                        } catch (MailingListProviderException $e) {
                            error_log('Sync subscribe failed: ' . $e->getMessage());
                        } catch (\InvalidArgumentException $e) {
                            error_log('Sync subscribe rejected bad input: ' . $e->getMessage());
                        }
                    }
                    echo ' set provider to subscribed';
                }
            } else if ($remote_status === 'bounced') {
                if (!$test) {
                    $user->email_unverify_bouncing_user();
                }
                echo ' marked bouncing';
            } else if ($remote_status === 'pending') {
                echo ' pending (no action)';
            } else {
                echo ' unknown status: ' . htmlspecialchars($remote_status);
            }

            echo "<br>\n";
        }

        $cursor = $batch['next_cursor'];
        @ob_flush();
        @flush();
    } while ($cursor !== null);
}

$event_log->set('evl_was_success', 1);
$event_log->set('evl_note', 'Subscribers processed: ' . $total_processed);
$event_log->save();

echo "SYNC COMPLETED (processed $total_processed subscribers)<br>\n";
exit();
