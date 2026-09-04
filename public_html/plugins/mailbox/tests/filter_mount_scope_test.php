<?php
/** @joinery-test
 * name: filter_mount_scope
 * tier: db
 * env: any
 * needs: []
 */
/**
 * What each Filters mount may see and touch.
 *
 * Filters have two mounts of one code path: the admin tab, which manages every
 * mailbox on the deployment, and the member page, which manages the mailboxes
 * that member holds. The whole difference is the scope list — and since a
 * filter may only be acted on when its own scope is in that list, the list is
 * also the authorization boundary.
 *
 * What this pins down:
 *   - the operator list carries every filterable mailbox plus the domain-wide
 *     bucket;
 *   - a member's list carries only their granted mailboxes, and NEVER a
 *     domain-wide bucket — a rule over everybody's mail is not theirs to write;
 *   - a mailbox that cannot run filters at all (pure forward) is in neither;
 *   - a stored filter reports the scope value the lists are keyed by, which is
 *     what makes the reach check a lookup.
 *
 * Run: php tests/run.php db --filter=filter_mount_scope
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_filters_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

function fms_domain(): InboundEmailDomain {
	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'fms-' . bin2hex(random_bytes(4)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_catch_all_mode', 'store');
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	return $dom;
}

function fms_alias(int $domain_id, string $local, string $mode, array $holder_ids): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', $mode);
	$alias->set('iea_destinations', $mode === 'store' ? '' : 'somewhere@elsewhere.example');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));

	foreach ($holder_ids as $uid) {
		$grant = new InboundEmailMailboxGrant(NULL);
		$grant->set('ieg_iea_inbound_email_alias_id', intval($alias->key));
		$grant->set('ieg_usr_user_id', intval($uid));
		$grant->save();
		$grant->load();
		harness_register_row('ieg_inbound_email_mailbox_grants',
			'ieg_inbound_email_mailbox_grant_id', intval($grant->key));
	}
	return $alias;
}

try {

	section('the two scope lists');

	$member = make_user('FmsMember');
	$member_id = intval($member->key);
	$stranger = make_user('FmsStranger');
	$stranger_id = intval($stranger->key);

	$domain = fms_domain();
	$domain_id = intval($domain->key);
	$mine = fms_alias($domain_id, 'mine', 'store', array($member_id));
	$theirs = fms_alias($domain_id, 'theirs', 'store', array($stranger_id));
	$forwarder = fms_alias($domain_id, 'forwarder', 'forward', array($member_id));

	$mine_scope = 'alias:' . intval($mine->key);
	$theirs_scope = 'alias:' . intval($theirs->key);
	$forward_scope = 'alias:' . intval($forwarder->key);
	$domain_scope = 'domain:' . $domain_id;

	$operator = _filter_scope_options(null)['options'];
	check(isset($operator[$mine_scope]) && isset($operator[$theirs_scope]),
		'the operator list carries every filterable mailbox on the domain');
	check(isset($operator[$domain_scope]),
		'and the domain-wide bucket');
	check(!isset($operator[$forward_scope]),
		'a pure-forward mailbox is in no list — filters never fire for it');

	$viewer = MailboxViewer::forUser($member_id, 0);
	$member_options = _filter_scope_options($viewer)['options'];
	check(isset($member_options[$mine_scope]),
		'the member list carries the mailbox they hold');
	check(!isset($member_options[$theirs_scope]),
		'and not one they do not');
	check(!isset($member_options[$domain_scope]),
		'and never a domain-wide bucket');
	check(!isset($member_options[$forward_scope]),
		'and not their own pure-forward mailbox');

	section('a stored filter names its own scope');

	$filter = new InboundEmailFilter(NULL);
	$filter->set('fil_iea_inbound_email_alias_id', intval($mine->key));
	$filter->set('fil_ied_inbound_email_domain_id', $domain_id);
	$filter->set('fil_name', 'fms mailbox rule');
	$filter->set('fil_match_from', 'someone@elsewhere.example');
	$filter->set('fil_action_archive', true);
	$filter->prepare();
	$filter->save();
	harness_register_model('InboundEmailFilter', intval($filter->key));

	$wide = new InboundEmailFilter(NULL);
	$wide->set('fil_ied_inbound_email_domain_id', $domain_id);
	$wide->set('fil_name', 'fms domain rule');
	$wide->set('fil_match_from', 'someone@elsewhere.example');
	$wide->set('fil_action_archive', true);
	$wide->prepare();
	$wide->save();
	harness_register_model('InboundEmailFilter', intval($wide->key));

	check(_filter_model_scope($filter) === $mine_scope,
		'a mailbox filter reports its alias scope', _filter_model_scope($filter));
	check(_filter_model_scope($wide) === $domain_scope,
		'a domain-wide filter reports its domain scope', _filter_model_scope($wide));

	// The reach check the mounts make: the filter's own scope, looked up in the
	// list this mount offers. The member reaches their own rule and neither the
	// stranger's mailbox rule nor the domain-wide one.
	check(isset($member_options[_filter_model_scope($filter)]),
		'the member reaches a filter on their own mailbox');
	check(!isset($member_options[_filter_model_scope($wide)]),
		'and not the domain-wide filter');
	check(isset($operator[_filter_model_scope($wide)]),
		'the operator reaches the domain-wide filter');

} catch (Throwable $harness_e) {
	check(false, 'the suite ran to completion without throwing',
		get_class($harness_e) . ': ' . $harness_e->getMessage()
		. ' @ ' . $harness_e->getFile() . ':' . $harness_e->getLine());
}

harness_finish();
?>
