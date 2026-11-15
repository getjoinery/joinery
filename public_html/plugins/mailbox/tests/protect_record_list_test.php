<?php
/** @joinery-test
 * name: protect_record_list
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [public_html/plugins/mailbox/assets/protect_records.js]
 */
/**
 * Without a DNS key, the send-protection records are one ordered list that
 * rechecks itself (specs/send_protection_one_press.md WP4).
 *
 *  - mailbox_protect_record_list(): group 1 is the signing stage with what is
 *    live; group 2 is the strict stage, not looked up and not active until
 *    signing is on; signing_ready is the activate gate's own answer.
 *  - The table: group 2 greyed with nothing to copy, the page polls only while
 *    something is outstanding, the turn-on button is drawn disabled until
 *    group 1 passes.
 *  - The recheck action reads and writes nothing, and the script it feeds
 *    enables the button on signing_ready.
 *
 * @version 1.3 - automated mail leads the ceremony (WP6)
 * @version 1.2 - rows keyed by place, not value; two SPF records at the forwarding name block signing
 * @version 1.1 - reviewer2's review: both strict records always listed, a second SPF or DMARC record is
 *   not live, two apex SPF records fail the SPF row, a Standard domain is not ready
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_record_table.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/protect_record_list_logic.php'));

$name = 'records-' . bin2hex(random_bytes(3)) . '.example';
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $name);
$domain->set('ied_is_enabled', true);
$domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
$domain->set('ied_send_lock_requested', true);
$domain->set('ied_dkim_selector', 'mailk1');
$domain->set('ied_dkim_public_dns', 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest');
$domain->set('ied_dkim_sealed_key', 'sealed-fixture');
$domain->set('ied_forwarding_subdomain', 'fwd.' . $name);
$domain->save();
$domain->load();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));

// The zone, as a map the test publishes into.
$zone = new class {
	public $data = array();
	public function getRecords($n, $t) { return $this->data[strtolower($n) . '|' . $t] ?? array(); }
	public function publish(array $row): void {
		if ($row['type'] === 'TXT') {
			$this->data[$row['name'] . '|' . DNS_TXT][] = array('txt' => $row['value'], 'entries' => array($row['value']));
		} elseif ($row['type'] === 'MX') {
			list($pri, $host) = explode(' ', $row['value'], 2);
			$this->data[$row['name'] . '|' . DNS_MX][] = array('target' => $host, 'pri' => (int)$pri);
			// The check follows the MX to this server's own address.
			$ip = (new ReflectionProperty(InboundEmailSetupCheck::class, 'publicIp'))->getValue(new InboundEmailSetupCheck());
			$this->data[strtolower($host) . '|' . DNS_A] = array(array('ip' => (string)$ip));
		}
	}
};
DnsResolver::setBackend($zone);
$fresh = function () use ($domain) { return new InboundEmailDomain(intval($domain->key), TRUE); };

try {
	section('before anything is published');

	$list = mailbox_protect_record_list($fresh());
	list($now, $after) = $list['groups'];
	check($now['key'] === 'now' && $after['key'] === 'after', 'the groups come in publish order');
	check(count($now['rows']) >= 1 && $now['rows'][0]['name'] === 'mailk1._domainkey.' . $name,
		'group 1 leads with the sealed key\'s record', json_encode(array_column($now['rows'], 'name')));
	check(count(array_filter($now['rows'], function ($r) { return $r['status'] === 'missing'; })) === count($now['rows']),
		'and nothing in it is live yet');
	$after_names = array_column($after['rows'], 'name');
	check(in_array($name, $after_names, true) && in_array('_dmarc.' . $name, $after_names, true),
		'group 2 is the apex SPF and _dmarc', json_encode($after_names));
	check(!$after['active'] && count(array_filter($after['rows'], function ($r) { return $r['status'] !== 'waiting'; })) === 0,
		'and waits for signing: inactive, and not read as live or missing');
	$spf = array_values(array_filter($after['rows'], function ($r) use ($name) { return $r['name'] === $name; }))[0] ?? array();
	check(($spf['value'] ?? '') === 'v=spf1 -all' && strpos($spf['hint'] ?? '', 'starts v=spf1') !== false,
		'the SPF row names the one TXT record to replace', json_encode($spf));
	check(!$list['signing_ready'] && !empty($list['blockers']), 'the button would not pass yet');

	$html = mailbox_protect_record_table($list, intval($domain->key));
	check(strpos($html, 'data-poll="1"') !== false, 'the page rechecks while group 1 is outstanding');
	$greyed = substr($html, strpos($html, 'data-group="after"'));
	check(strpos($greyed, 'aria-disabled="true"') !== false, 'group 2 renders greyed out');
	check(strpos($greyed, PublicPageBase::copy_field('v=spf1 -all')) === false,
		'and offers nothing to copy from it');
	check(strpos($html, 'protect_records.js') !== false, 'the recheck script is on the page');

	section('group 1 published');

	foreach ($now['rows'] as $r) { $zone->publish($r); }
	$list = mailbox_protect_record_list($fresh());
	check(count(array_filter($list['groups'][0]['rows'], function ($r) { return $r['status'] === 'live'; }))
			=== count($list['groups'][0]['rows']),
		'every group 1 row reads as live', json_encode(array_column($list['groups'][0]['rows'], 'status')));
	check($list['signing_ready'] === empty(mailbox_protect_signing_blockers($fresh(), new InboundEmailSetupCheck())),
		'signing_ready is the activate gate\'s own answer', json_encode($list['blockers']));
	check($list['signing_ready'], 'and with group 1 live, the button would pass', json_encode($list['blockers']));
	check(strpos(mailbox_protect_record_table($list, intval($domain->key)), 'data-poll="0"') !== false,
		'so the page stops rechecking');

	// Two SPF records at the forwarding name: the row says so, and so does the gate.
	$fwd_spf = array_values(array_filter($list['groups'][0]['rows'], function ($r) use ($name) {
		return $r['type'] === 'TXT' && $r['name'] === 'fwd.' . $name; }))[0];
	$zone->publish(array('type' => 'TXT', 'name' => 'fwd.' . $name, 'value' => 'v=spf1 include:old.example ~all'));
	$list = mailbox_protect_record_list($fresh());
	$row = array_values(array_filter($list['groups'][0]['rows'], function ($r) use ($fwd_spf) { return $r['key'] === $fwd_spf['key']; }))[0];
	check($row['status'] === 'differs' && !$list['signing_ready']
			&& strpos(implode(' ', $list['blockers']), '2 SPF records') !== false,
		'two SPF records at the forwarding name: the row and the gate both refuse', json_encode($list['blockers']));
	$zone->data['fwd.' . $name . '|' . DNS_TXT] = array();
	$zone->publish($fwd_spf);
	check(mailbox_protect_record_list($fresh())['signing_ready'], 'one removed, ready again');

	$std = $fresh();
	$std->set('ied_security_level', InboundEmailDomain::LEVEL_STANDARD);
	$std->save();
	$list = mailbox_protect_record_list($fresh());
	check(!$list['signing_ready'] && strpos(implode(' ', $list['blockers']), 'Standard') !== false,
		'a Standard domain is not ready: the gate refuses it too', json_encode($list['blockers']));
	$std->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$std->save();

	section('signing on, group 2 outstanding');

	mailbox_protect_start_signing($fresh());
	$list = mailbox_protect_record_list($fresh());
	check($list['protected'] && $list['groups'][1]['active'], 'group 2 is active once signing is on');
	check(count(array_filter($list['groups'][1]['rows'], function ($r) { return $r['status'] === 'missing'; })) === 2
			&& !$list['strict_live'],
		'and is read from DNS: both missing', json_encode(array_column($list['groups'][1]['rows'], 'status')));
	$html = mailbox_protect_record_table($list, intval($domain->key));
	check(strpos($html, 'data-poll="1"') !== false && strpos($html, 'aria-disabled') === false,
		'the page rechecks it, with nothing greyed');

	$strict_rows = $list['groups'][1]['rows'];

	// The right values added beside the old ones: the likeliest slip by hand.
	$zone->publish(array('type' => 'TXT', 'name' => $name, 'value' => 'v=spf1 include:old.example ~all'));
	$zone->publish(array('type' => 'TXT', 'name' => '_dmarc.' . $name, 'value' => 'v=DMARC1; p=none'));
	foreach ($strict_rows as $r) { $zone->publish($r); }
	$list = mailbox_protect_record_list($fresh());
	$rows = $list['groups'][1]['rows'];
	check(count(array_filter($rows, function ($r) { return $r['status'] === 'differs' && count($r['live']) === 2
				&& strpos($r['hint'], 'Remove every one but this') !== false; })) === 2 && !$list['strict_live'],
		'a second SPF or DMARC record beside the right one is not live, and both are shown',
		json_encode(array_map(function ($r) { return array($r['status'], $r['live']); }, $rows)));
	$checks = (new InboundEmailSetupCheck())->protectedDomainChecks($fresh());
	$spf_row = array_values(array_filter($checks, function ($r) { return $r['id'] === 'domain.spf'; }))[0] ?? array();
	check(($spf_row['status'] ?? '') === InboundEmailSetupCheck::FAIL && strpos($spf_row['summary'] ?? '', '2 SPF records') !== false,
		'and the Setup SPF row fails on two SPF records, whatever the first one says', json_encode($spf_row));
	$setup_src = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	check(strpos($setup_src, "} elseif (\$spf_count > 1) {\n\t\t\t\t\$out[] = \$this->spfDuplicateResult(") !== false,
		'so does the ordinary SPF row, for a domain without send protection');

	// Only the strict ones left.
	$zone->data[$name . '|' . DNS_TXT] = array();
	$zone->data['_dmarc.' . $name . '|' . DNS_TXT] = array();
	foreach ($strict_rows as $r) { $zone->publish($r); }
	$list = mailbox_protect_record_list($fresh());
	check($list['strict_live'], 'with the old ones removed, both read as live and the list is finished',
		json_encode(array_column($list['groups'][1]['rows'], 'status')));

	// A _dmarc that cannot be read leaves it out of the plan; the list keeps it.
	$zone->data['_dmarc.' . $name . '|' . DNS_TXT] = false;
	$list = mailbox_protect_record_list($fresh());
	$dmarc = array_values(array_filter($list['groups'][1]['rows'], function ($r) use ($name) {
		return $r['name'] === '_dmarc.' . $name; }))[0] ?? array();
	$zone->data['_dmarc.' . $name . '|' . DNS_TXT] = array();
	$readable = mailbox_protect_record_list($fresh());
	$dmarc_read = array_values(array_filter($readable['groups'][1]['rows'], function ($r) use ($name) {
		return $r['name'] === '_dmarc.' . $name; }))[0] ?? array();
	check(($dmarc['key'] ?? 'a') === ($dmarc_read['key'] ?? 'b') && ($dmarc_read['value'] ?? '') !== '',
		'a row keeps its key when its value changes, so the page can find it again');
	check(strpos(mailbox_protect_record_table($readable, intval($domain->key)), 'data-record-value="') !== false,
		'and each row carries its value, for the page to notice the change');
	check(($dmarc['status'] ?? '') === 'unknown' && !$list['strict_live'],
		'a _dmarc lookup that fails is a row that could not be checked, not a finished list', json_encode($list['groups'][1]['rows']));
	check(strpos(mailbox_protect_record_table($list, intval($domain->key)), PublicPageBase::copy_field('')) === false,
		'and it offers no empty value to copy');

	section('the recheck action and the page');

	$d = protect_record_list_logic_descriptor();
	check($d['mutates'] === false && empty($d['session_write']), 'the recheck changes nothing and holds no session lock');
	$logic = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/logic/protect_record_list_logic.php'));
	check(($d['auth']['min_user_permission'] ?? null) === 'restricted:read',
		'it declares the Setup tab\'s own class, where ApiAuth enforces it');
	$js = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/assets/protect_records.js'));
	check(strpos($js, "'mailbox/protect_record_list'") !== false && strpos($js, '30000') !== false,
		'the script asks the action every 30 seconds');
	check(strpos($js, "row.value !== tr.getAttribute('data-record-value')") !== false
			&& strpos($js, 'if (!paint(data)) { redraw(); return; }') !== false,
		'the script redraws the page when a value to publish changed');
	check(strpos($js, "[data-protect-turn-on] button") !== false && strpos($js, 'b.disabled = false') !== false
			&& strpos($js, 'data.signing_ready') !== false,
		'and enables the turn-on button when signing_ready');
	$view = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/admin/admin_mailbox_setup.php'));
	check(strpos($view, "'disabled' => !\$ready") !== false && strpos($view, 'data-protect-turn-on') !== false,
		'the page draws the button disabled until group 1 passes');
	check(strpos($view, "'action' => 'protect_activate', 'protect_setup' => '1'") !== false,
		'turning it on lands back in the ceremony, where group 2 is shown');
	$setup_logic = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_setup_logic.php'));
	check(strpos($setup_logic, 'if ($protect_setup && $protect !== null && $focus_domain_model !== null && $focus_is_protected)') !== false,
		'and a finished domain\'s ordinary view pays no lookups for it');
	$button = PublicPageBase::action_button('Go', '/x', array('disabled' => true));
	check(strpos($button, ' disabled>') !== false, 'action_button draws a disabled button when asked');

	section('automated mail leads the ceremony (WP6)');

	harness_set_setting_mem('defaultemail', 'robot@' . $name);
	$rows = (new InboundEmailSetupCheck())->signingReadinessChecks($fresh());
	$sys = array_values(array_filter($rows, function ($r) { return $r['id'] === 'domain.system_mail_readiness'; }));
	check(count($sys) === 1 && $sys[0]['severity'] === InboundEmailSetupCheck::RECOMMENDED
			&& ($sys[0]['fix']['link']['label'] ?? '') === 'Set up the machine sender first',
		'system mail on the domain gives one warning row with the machine sender link');
	check(count(array_filter(mailbox_protect_blockers_in($rows), function ($b) {
			return strpos($b, 'System mail has somewhere to go') === 0; })) === 0,
		'and it is not a blocker');
	harness_set_setting_mem('defaultemail', 'robot@elsewhere.example');
	$rows = (new InboundEmailSetupCheck())->signingReadinessChecks($fresh());
	check(!in_array('domain.system_mail_readiness', array_column($rows, 'id'), true),
		'system mail elsewhere gives no such row');

	$open = strpos($view, 'Leave this for now</a></p>');
	$lead = strpos($view, "echo '<h5 class=\"mt-3 mb-2\">First, automated mail</h5>';");
	$press = strpos($view, "dns_publish_box_render(\$page, \$protect_press_box, 'Turn on send protection'");
	$table = strpos($view, "Publish the records in group 1 where your domain");
	check($open !== false && $lead !== false && $press !== false && $table !== false
			&& $open < $lead && $lead < $press && $lead < $table,
		'the page draws that row first in the ceremony, above the press and above group 1');
	check(substr_count($view, "=== 'domain.system_mail_readiness'") === 1
			&& substr_count($view, "!== 'domain.system_mail_readiness'") === 1,
		'and takes it out of the readiness lists below, so it is said once');
	$unset = strpos($view, "unset(\$r['fix']['link']);");
	check($unset !== false && $unset > $lead && $unset < strpos($view, '$render_check($r);', $lead)
			&& strpos($view, "htmlspecialchars(\$link['url'] . '&protect_setup=1')") !== false,
		'its link is one button, not also in the details, and it comes back to the ceremony');
	check(strpos($view, "\$si_at_top   = !\$machine_open") !== false
			&& strpos($view, "if (!empty(\$lead_rows) && !\$machine_open)") !== false,
		'while the machine sender steps are open they take the top, and the ceremony waits below without its lead');
	check(strpos($view, "\$machine_leave_url = \$setup_open ? \$setup_url : \$focus_base;") !== false
			&& strpos($view, "(\$setup_open ? '&protect_setup=1' : '')") !== false
			&& substr_count($view, 'htmlspecialchars($machine_post_url)') === 1
			&& substr_count($view, "'action' => \$machine_post_url") === 1,
		'leaving or finishing the machine sender returns to the ceremony it was opened from');
	$logic = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_setup_logic.php'));
	check(strpos($logic, "return LogicResult::redirect(\$state_qs(array('machine_setup' => false)));") !== false,
		'the Switch redirect keeps every other flag, protect_setup among them');
} finally {
	DnsResolver::clearBackend();
}

harness_finish();
