#!/usr/bin/php
<?php
/**
 * managed_domain_prepare.php - make this box mail-ready for one domain, and
 * say what DNS that requires.
 *
 * Runs ON the node, called over SSH by the management node's managed-domain
 * provisioning phase (specs/managed_domain_registration.md). The management node
 * owns the registrar and the zone; the box owns everything that decides what
 * belongs in that zone — its receive topology, its SPF shape, its DKIM key,
 * whether it speaks Joinery Direct. A management node that computed those
 * records itself would publish a plausible set the box does not match, and the
 * mismatch would show up as mail silently failing authentication.
 *
 * So the split is: this prints desired state, the management node publishes it.
 *
 * Three steps, all idempotent — re-running for a prepared domain changes
 * nothing and just reprints the plan:
 *
 *   1. register the domain for receiving (mailbox_provision_domain)
 *   2. make sure a DKIM signing key exists for it
 *   3. print the record set InboundEmailSetupCheck::dnsPlan() prescribes
 *
 * Output is ONE JSON line on stdout:
 *   {"ok":true,"dkim_ready":bool,"records":[{"type","name","value","priority","note"}...]}
 *   {"ok":false,"error":"..."}
 *
 * dkim_ready false is not a failure: MX and SPF are what make mail arrive, so
 * the caller publishes what it got and comes back for the signing key. It just
 * means the domain is not finished.
 *
 * Usage: php plugins/mailbox/utils/managed_domain_prepare.php <domain>
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

$domain = strtolower(trim((string)($argv[1] ?? '')));
if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
	fwrite(STDERR, "Usage: managed_domain_prepare.php <domain>\n");
	exit(2);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

/** One JSON line, then out. */
function mdp_emit(array $payload, int $code = 0) {
	echo json_encode($payload) . "\n";
	exit($code);
}

try {
	// ---- 1. The domain exists here and accepts mail for itself.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/provisioning.php'));
	$provisioned = mailbox_provision_domain($domain);
	if (!empty($provisioned['error'])) {
		mdp_emit(array('ok' => false, 'error' => (string)$provisioned['error']), 1);
	}

	// ---- 2. A signing key, so outbound mail from this domain can be trusted.
	// provision_dkim.sh is idempotent and never regenerates an existing key —
	// regenerating would invalidate a DNS record already published.
	$key_file = '/etc/opendkim/keys/' . $domain . '/mail.txt';
	if (!is_readable($key_file)) {
		$script = PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_dkim.sh');
		if (is_file($script)) {
			$cmd = (posix_geteuid() === 0 ? '' : 'sudo -n ')
				. 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($domain) . ' 2>&1';
			exec($cmd, $script_output, $script_code);
			if ($script_code !== 0) {
				// Not fatal: the record set below is still correct and useful
				// without DKIM, and dkim_ready reports the shortfall honestly.
				error_log('managed_domain_prepare: provision_dkim.sh for ' . $domain
					. ' exited ' . $script_code . ': ' . implode(' ', array_slice($script_output, -5)));
			}
		}
	}

	// ---- 3. What this box needs published for the domain.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	$plan = (new InboundEmailSetupCheck())->dnsPlan($domain);

	$records = array();
	$dkim_ready = false;
	foreach ($plan->toArray()['records'] as $record) {
		// A removal is an instruction about somebody else's record and is not
		// this pipeline's business — a fresh domain has nothing to take away.
		if (!empty($record['absent'])) {
			continue;
		}
		if (strtoupper((string)($record['type'] ?? '')) === 'TXT'
				&& strpos((string)($record['name'] ?? ''), '._domainkey.') !== false) {
			$dkim_ready = true;
		}
		$records[] = array(
			'type'     => (string)($record['type'] ?? ''),
			'name'     => (string)($record['name'] ?? ''),
			'value'    => (string)($record['value'] ?? ''),
			'priority' => isset($record['priority']) ? $record['priority'] : null,
			'note'     => (string)($record['note'] ?? ''),
		);
	}

	mdp_emit(array('ok' => true, 'dkim_ready' => $dkim_ready, 'records' => $records));
} catch (\Throwable $e) {
	mdp_emit(array('ok' => false, 'error' => $e->getMessage()), 1);
}
