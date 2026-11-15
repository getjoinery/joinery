<?php
/** @joinery-test
 * name: managed_dns_records_kind
 * tier: db
 * env: any
 * needs: [db]
 */
/**
 * DNS ownership rows are kept per TXT kind (dnr_kind), against the real table.
 *
 * A row written before kinds were kept has dnr_kind NULL; its kind is read from
 * its value, which is why no migration fills the column. Such a row must be
 * found as the kind its value is, not as '', and the next Remember() of that
 * record upgrades the same row rather than adding a second.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('data/managed_dns_records_class.php'));

$domain = 'harnesstest-dnr-' . bin2hex(random_bytes(4)) . '.example';

/** A row as one written before kinds were kept: dnr_kind NULL. */
function dnr_old_row(string $domain, string $type, string $name, string $value): int {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('INSERT INTO dnr_managed_dns_records (dnr_domain, dnr_type, dnr_name, dnr_value, dnr_owner, dnr_kind)
		VALUES (?, ?, ?, ?, ?, NULL) RETURNING dnr_managed_dns_record_id');
	$q->execute(array($domain, $type, $name, $value, 'harness'));
	$id = intval($q->fetchColumn());
	harness_register_row('dnr_managed_dns_records', 'dnr_managed_dns_record_id', $id);
	return $id;
}

section('A row with no stored kind is read by its value');

$spf_id = dnr_old_row($domain, 'TXT', $domain, 'v=spf1 -all');
$spf = ManagedDnsRecord::Find($domain, 'TXT', $domain, 'spf');
check($spf !== null && intval($spf->key) === $spf_id, 'an old SPF row is found as spf');
check(ManagedDnsRecord::Find($domain, 'TXT', $domain, '') === null, 'and not as an other-kind TXT');
check(ManagedDnsRecord::Find($domain, 'TXT', $domain, 'dmarc') === null, 'nor as DMARC');

$mx_id = dnr_old_row($domain, 'MX', $domain, 'mail.example.com');
$mx = ManagedDnsRecord::Find($domain, 'MX', $domain);
check($mx !== null && intval($mx->key) === $mx_id, 'an old MX row is found with no kind');

section('Remember() upgrades the same row and keeps kinds apart');

$again = ManagedDnsRecord::Remember($domain, 'TXT', $domain, 'v=spf1 include:new.example -all', 'harness');
check(intval($again->key) === $spf_id && $again->get('dnr_kind') === 'spf',
	'remembering the SPF again updates the old row and records its kind');

$proof = ManagedDnsRecord::Remember($domain, 'TXT', $domain, 'joinery-verify=xyz', 'harness');
harness_register_row('dnr_managed_dns_records', 'dnr_managed_dns_record_id', intval($proof->key));
check(intval($proof->key) !== $spf_id && $proof->get('dnr_kind') === '',
	'a verification at the same name is its own row');
check(count(ManagedDnsRecord::OwnedFor($domain)) === 3, 'the domain owns three slots: SPF, verification, MX');

ManagedDnsRecord::Forget($domain, 'TXT', $domain, 'spf');
check(ManagedDnsRecord::Find($domain, 'TXT', $domain, 'spf') === null
	&& ManagedDnsRecord::Find($domain, 'TXT', $domain, '') !== null,
	'forgetting the SPF leaves the verification');

harness_finish();
