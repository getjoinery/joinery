<?php
/**
 * CheckDomainSetup - Scheduled Task (per-domain DNS verdict).
 *
 * The expensive tier of the Accounts listing's setup hints
 * (specs/mailbox_setup_verdicts.md). Walks the enabled domains once a day,
 * runs the DNS-only check entry point, and stamps a verdict onto each domain
 * row so the listing can badge a broken domain for free.
 *
 * It uses runDomainChecks(), not run(): that entry point is deliberately
 * per-domain DNS with no host or relay layer, so a daily pass costs a handful
 * of lookups per domain and never probes the mail stack.
 *
 * Two rules keep the badge worth reading, both about not crying wolf:
 *
 *  - **Only REQUIRED failures count.** A missing DMARC record is graded
 *    `recommended` — real advice, but a domain receiving mail perfectly well
 *    should not wear a badge saying otherwise.
 *  - **A check that could not run is not a failure.** UNKNOWN means the
 *    resolver did not answer. Counting that as breakage would make every badge
 *    flap with the first DNS hiccup, and flapping badges get ignored — which
 *    costs more than the feature is worth. When nothing could be evaluated at
 *    all, the previous verdict is left alone rather than overwritten with an
 *    absence of information.
 *
 * What it writes is a NAVIGATION HINT, never an answer about the world. The
 * Setup tab re-runs everything live and is the only thing that claims a domain
 * is correct or broken.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));

class CheckDomainSetup implements ScheduledTaskInterface {

	public function run(array $config) {
		$domains = new MultiInboundEmailDomain(array('deleted' => false, 'enabled' => true),
			array('ied_domain' => 'ASC'));
		$domains->load();

		$checker = new InboundEmailSetupCheck();
		$counts = array('ok' => 0, 'attention' => 0, 'unknown' => 0, 'skipped' => 0);
		$flagged = array();

		foreach ($domains as $domain) {
			// An IMAP-source domain pulls its mail from somebody else's server;
			// it has no MX or SPF of its own to be wrong.
			if ($domain->get('ied_is_imap_source')) {
				$counts['skipped']++;
				continue;
			}

			$name = strtolower(trim((string)$domain->get('ied_domain')));
			if ($name === '') {
				$counts['skipped']++;
				continue;
			}

			try {
				$status = self::verdictFor($checker->runDomainChecks($name));
			} catch (\Throwable $e) {
				error_log('CheckDomainSetup: ' . $name . ' failed: ' . $e->getMessage());
				$counts['unknown']++;
				continue;
			}

			$counts[$status]++;
			if ($status === 'attention') {
				$flagged[] = $name;
			}

			// Nothing evaluable means no new information — keep what was there.
			if ($status === 'unknown' && (string)$domain->get('ied_setup_status') !== '') {
				continue;
			}

			$domain->set('ied_setup_status', $status);
			$domain->set('ied_setup_checked_time', gmdate('Y-m-d H:i:s'));
			$domain->save();
		}

		$message = 'Domain setup: ' . $counts['ok'] . ' ok, ' . $counts['attention'] . ' need attention'
			. ($counts['unknown'] ? ', ' . $counts['unknown'] . ' undetermined' : '')
			. ($counts['skipped'] ? ', ' . $counts['skipped'] . ' skipped' : '') . '.';
		if (!empty($flagged)) {
			$message .= ' Flagged: ' . implode(', ', $flagged) . '.';
		}

		return array('status' => 'success', 'message' => $message);
	}

	/**
	 * Grade one domain's rows. Public and static because it IS the two rules
	 * this task exists to apply, and they are worth testing without a database
	 * or a live domain.
	 *
	 * @param array[] $rows From runDomainChecks().
	 * @return string ok | attention | unknown
	 */
	public static function verdictFor(array $rows): string {
		$evaluated = 0;
		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			// Not evidence either way — skip without counting as evaluated.
			if ($status === InboundEmailSetupCheck::UNKNOWN || $status === InboundEmailSetupCheck::INFO) {
				continue;
			}
			$evaluated++;
			if ($status === InboundEmailSetupCheck::FAIL
					&& (string)($row['severity'] ?? '') === InboundEmailSetupCheck::REQUIRED) {
				return 'attention';
			}
		}
		return $evaluated > 0 ? 'ok' : 'unknown';
	}
}
