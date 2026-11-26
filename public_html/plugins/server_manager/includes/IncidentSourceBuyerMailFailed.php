<?php
/**
 * IncidentSourceBuyerMailFailed - plane:buyer_mail_failed: an email this
 * plane owed a site's buyer could not be sent.
 *
 * "Your site is ready" is how a buyer learns their site exists. The mail
 * queue tries a message three times and then drops it as a permanent
 * failure, and nothing told anyone: every such email on one deployment failed
 * for days (specs/hosting_purchase_walkthrough_test.md, finding 98).
 *
 * Open while the newest email about the site (an outbox row about its
 * provision; for a row with no record, one to its buyer that names the site)
 * is a permanent failure, within LOOK_BACK_DAYS. A send-once notice about the
 * site (HostingBuyerNotices) that failed for good keeps it open until that
 * notice is sent, though its next pass revives the row and newer emails go:
 * the row keeps equ_given_up_time until it is Sent. A later one that was sent
 * closes it, so sending it again by hand is the fix as well as the remedy.
 *
 * @version 1.2 - a send-once notice that failed for good stays open across its next pass's revive, until it is Sent
 *   (equ_given_up_time; reviewer2 F2)
 * @version 1.1 - an outbox row about the site's provision is read by its record (specs/email_outbox.md §5)
 * @version 1.0
 */
class IncidentSourceBuyerMailFailed implements IncidentSource {

	const NAME = 'plane:buyer_mail_failed';

	const LOOK_BACK_DAYS = 14;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$row = self::newest_mail($node);
		$status = $row === null ? 0 : (int)$row['equ_status'];
		if ($row === null || ($status !== QueuedEmail::PERMANENT_FAILURE
				&& (empty($row['equ_given_up_time']) || in_array($status, array(QueuedEmail::SENT, QueuedEmail::DELETED), true)))) {
			return null;
		}
		return array(
			'title'    => 'An email to this site\'s buyer could not be sent',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Email'         => (string)$row['equ_subject'],
				'To'            => (string)$row['equ_to'],
				'From'          => (string)$row['equ_from'],
				'Queued'        => (string)$row['equ_timestamp'] . ' UTC',
				'What it means' => 'The mail queue tried three times and gave up. The buyer has not been told.',
				'What to do'    => 'Check that this site\'s mail provider may send from that From address (Provisioning Setup names the '
					. 'welcome address; empty uses the site\'s own default sender), then tell the buyer by hand or queue it again.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The newest email to this site\'s buyer about it is no longer a failed one.';
	}

	/** The newest email about the node's site, or null. */
	private static function newest_mail(ManagedNode $node): ?array {
		$provision = CustomerCloudProvision::latest_for_node((int)$node->key);
		if (!$provision) {
			return null;
		}
		$to = trim((string)$provision->get('cvp_buyer_email'));
		$domain = trim((string)$provision->get('cvp_domain'));
		if ($to === '' || $domain === '') {
			return null;
		}
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$since = gmdate('Y-m-d H:i:s', time() - self::LOOK_BACK_DAYS * 86400);
			$cols = 'SELECT equ_status, equ_subject, equ_to, equ_from, equ_timestamp, equ_given_up_time FROM equ_queued_emails';
			// A send-once notice about the site that failed for good and is not
			// sent yet, whatever was sent after it.
			$q = $db->prepare($cols . " WHERE equ_entity_type = 'cvp_customer_cloud_provisions' AND equ_entity_id = ?"
				. ' AND equ_once_key IS NOT NULL AND equ_status NOT IN (' . QueuedEmail::SENT . ', ' . QueuedEmail::DELETED . ')'
				. ' AND (equ_status = ' . QueuedEmail::PERMANENT_FAILURE . ' OR equ_given_up_time IS NOT NULL)'
				. ' AND equ_timestamp > ? ORDER BY equ_timestamp DESC LIMIT 1');
			$q->execute(array((int)$provision->key, $since));
			$row = $q->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				$q = $db->prepare($cols
					// About the provision (an ordinary email); or, for a row with no
					// record (from before the outbox, or posted by another store's
					// plane), by address and subject, where the queue is in email
					// test mode redirected with the buyer's address in the subject.
					. " WHERE ((equ_entity_type = 'cvp_customer_cloud_provisions' AND equ_entity_id = ? AND equ_once_key IS NULL)"
					. ' OR (equ_entity_type IS NULL AND (lower(equ_to) = lower(?) OR position(lower(?) in lower(equ_subject)) > 0)'
					. ' AND position(? in equ_subject) > 0))'
					. ' AND equ_timestamp > ?'
					. ' ORDER BY equ_timestamp DESC, equ_queued_email_id DESC LIMIT 1');
				$q->execute(array((int)$provision->key, $to, $to, $domain, $since));
				$row = $q->fetch(PDO::FETCH_ASSOC);
			}
		} catch (Throwable $e) {
			error_log('IncidentSourceBuyerMailFailed: could not read the mail queue: ' . $e->getMessage());
			return null;
		}
		return $row ?: null;
	}
}
?>
