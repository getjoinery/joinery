<?php
/**
 * https_check.php - why this site has no secure connection yet, from the
 * server's console. Installed as `sudo joinery-https-check [SITENAME]`
 * (arm_ssl_retry.sh writes the wrapper).
 *
 * The same findings as the site's /https-status page and the setup wizard's
 * Secure connection step (setup_https_findings), plus what only the console
 * can give: the exact command to move the site to another name, and the
 * certificate retry's last journal lines.
 *
 * Usage: sudo php maintenance_scripts/sysadmin_tools/https_check.php [SITENAME]
 *
 * Exit status: 0 the connection is secure, 1 it is not (yet), 2 usage.
 *
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('logic/setup_https_check_logic.php'));

$site_root = dirname(PathHelper::getBasePath());
$site = basename($site_root);
$tools = $site_root . '/maintenance_scripts/sysadmin_tools';

$d = setup_https_diagnose();
$domain = (string)($d['domain'] ?? '');
echo "Site: {$site}" . ($domain !== '' ? ", web address {$domain}" : '') . "\n";
if (!empty($d['applicable'])) {
	$here = array_filter(array((string)$d['server_ip4'], (string)$d['server_ip6']));
	echo 'This server: ' . ($here ? implode(', ', $here) : 'could not be determined') . "\n";
	$answers = array_merge((array)$d['dns_a'], (array)$d['dns_aaaa']);
	echo $domain . ' answers with: ' . ($answers ? implode(', ', $answers) : 'nothing') . "\n";
}
echo "\n";

$marks = array('ok' => '[ok]', 'problem' => '[!!]', 'waiting' => '[..]');
foreach (setup_https_findings($d) as $item) {
	echo $marks[$item['kind']] . ' ' . wordwrap($item['text'], 100, "\n     ") . "\n";
}

if (!empty($d['applicable']) && empty($d['https_ready'])) {
	if (empty($d['dns_match']) && !empty($d['points_elsewhere'])) {
		echo "\nTo move the site to another name that points here (home.{$domain} shown as the example):\n";
		echo "  sudo {$tools}/manage_domain.sh set {$site} home.{$domain}\n";
	}
	if (empty($d['retry_armed'])) {
		echo "\nTo ask for a certificate now:\n  sudo {$tools}/setup_ssl.sh {$domain}\n";
	} else {
		echo "\nTo try now instead of waiting for the next five-minute check:\n";
		echo "  sudo systemctl start joinery-ssl-retry@{$domain}.service\n";
	}
	if (function_exists('posix_geteuid') && posix_geteuid() === 0 && preg_match('/^[a-z0-9.-]+$/', $domain)) {
		$journal = (string)shell_exec('journalctl -u ' . escapeshellarg('joinery-ssl-retry@' . $domain . '.service') . ' -n 8 --no-pager -o cat 2>/dev/null');
		if (trim($journal) !== '') {
			echo "\nThe certificate retry's last lines:\n" . preg_replace('/^/m', '  ', rtrim($journal)) . "\n";
		}
	}
}

exit(!empty($d['https_ready']) ? 0 : 1);
