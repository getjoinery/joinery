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
 * Open while the newest queued email to the site's buyer that names the site
 * is a permanent failure, within LOOK_BACK_DAYS. A later one that was sent
 * closes it, so sending it again by hand is the fix as well as the remedy.
 *
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
		if ($row === null || (int)$row['equ_status'] !== QueuedEmail::PERMANENT_FAILURE) {
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

	/** The newest queued email to the node's buyer that names its site, or null. */
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
			$q = DbConnector::get_instance()->get_db_link()->prepare(
				'SELECT equ_status, equ_subject, equ_to, equ_from, equ_timestamp FROM equ_queued_emails'
				// To the buyer, or, where the queue is in email test mode, redirected
				// with the buyer's address moved into the subject.
				. ' WHERE (lower(equ_to) = lower(?) OR position(lower(?) in lower(equ_subject)) > 0) AND position(? in equ_subject) > 0'
				. ' AND equ_timestamp > ?'
				. ' ORDER BY equ_queued_email_id DESC LIMIT 1');
			$q->execute(array($to, $to, $domain, gmdate('Y-m-d H:i:s', time() - self::LOOK_BACK_DAYS * 86400)));
			$row = $q->fetch(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			error_log('IncidentSourceBuyerMailFailed: could not read the mail queue: ' . $e->getMessage());
			return null;
		}
		return $row ?: null;
	}
}
?>
