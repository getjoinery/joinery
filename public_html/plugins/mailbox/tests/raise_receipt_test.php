<?php
/** @joinery-test
 * name: raise_receipt
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Raise receipt (specs/mailbox_raise_receipt.md): the ceremony card resolving
 * into the completed facts after a level raise.
 *
 *  - Checklist heading names the destination level.
 *  - Receipt render: working state (live sealing row, hidden button, noscript
 *    batch form), completed state (sealed-count fact, visible button),
 *    zero-backlog wording, unlock fact naming (self vs other holder).
 *  - Fortress handoff variant: honest title, continue-to-protect button.
 *  - Sealed/backlog counters over real rows.
 *  - Stuck-batch contract: a backlog with no sealable holder returns
 *    sealed=0 with rows remaining — the shape the editor's JS loop stops on.
 *  - mailbox/seal_batch action: permission gate and descriptor.
 *
 * Run: php tests/run.php db --filter=raise_receipt
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/seal_batch_logic.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

const RR_ACTING = 77;

function rr_facts_one_holder(int $uid, string $name): array {
	return array('passkeys_enabled' => true, 'relay_fronted' => false, 'aliases' => array(
		array('alias_id' => 1, 'address' => 'box@x.example', 'holders' => array(
			array('user_id' => $uid, 'name' => $name, 'has_vault' => true, 'has_prf_passkey' => true),
		)),
	));
}

try {

	// -----------------------------------------------------------------------
	section('checklist heading names the destination');

	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'rr-priv-' . bin2hex(random_bytes(3)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));

	$urls = array('editor_url' => '/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id='
		. intval($dom->key), 'alias_url' => '/plugins/mailbox/admin/admin_mailbox_alias');
	$rows = mailbox_protection_rows(rr_facts_one_holder(RR_ACTING, 'Robin'), InboundEmailDomain::LEVEL_PRIVATE, RR_ACTING);

	$html = mailbox_protection_render($rows, $dom, $urls, InboundEmailDomain::LEVEL_PRIVATE);
	check(strpos($html, 'Before this domain can be Private') !== false,
		'the Private checklist heading names Private');
	$html = mailbox_protection_render($rows, $dom, $urls, InboundEmailDomain::LEVEL_FORTRESS);
	check(strpos($html, 'Before this domain can be Fortress') !== false,
		'the Fortress checklist heading names Fortress');
	$html = mailbox_protection_render($rows, $dom, $urls);
	check(strpos($html, 'Before this domain can be protected') !== false,
		'no target falls back to the generic heading');

	// -----------------------------------------------------------------------
	section('receipt render: working state');

	$state = array('backlog' => 340, 'sealed_total' => 12, 'acting_user_id' => RR_ACTING,
		'editor_url' => $urls['editor_url']);
	$html = mailbox_protection_receipt_render($dom, rr_facts_one_holder(RR_ACTING, 'Robin'), $state);
	check(strpos($html, 'This domain is now Private') !== false, 'working state already states the event');
	check(strpos($html, 'Sealing earlier messages — 340 remaining') !== false,
		'the sealing row is live with the backlog count');
	// The batch loop is the shared one (assets/js/ceremony-batch.js), configured
	// from a data attribute: the action to call, the payload it needs, where the
	// countdown starts, and which response key counts as progress.
	check(strpos($html, 'data-ceremony-batch=') !== false, 'the card carries the shared batch driver config');
	check(strpos($html, '&quot;remaining&quot;:340') !== false && strpos($html, '&quot;doneTotal&quot;:12') !== false,
		'the config states the backlog and the running total');
	check(strpos($html, '&quot;action&quot;:&quot;mailbox\/seal_batch&quot;') !== false
		&& strpos($html, '&quot;domain_id&quot;:' . intval($dom->key)) !== false,
		'the config names the batch action and its domain');
	check(strpos($html, 'data-ceremony-dot') !== false && strpos($html, 'data-ceremony-text') !== false,
		'the progress row marks the pieces the driver updates');
	check(strpos($html, 'd-none') !== false, 'the action button hides until the sealing resolves');
	check(strpos($html, 'ceremony_seal_batch') !== false && strpos($html, '<noscript>') !== false,
		'the noscript batch form is present while a backlog remains');
	check(strpos($html, 'New mail seals on arrival') !== false, 'the ingest fact renders');
	check(strpos($html, 'Reading takes your unlock') !== false,
		'the sole-holder acting user reads as YOUR unlock');

	// -----------------------------------------------------------------------
	section('receipt render: completed state');

	$state = array('backlog' => 0, 'sealed_total' => 3, 'acting_user_id' => RR_ACTING,
		'editor_url' => $urls['editor_url']);
	$html = mailbox_protection_receipt_render($dom, rr_facts_one_holder(9001, 'Sam Holder'), $state);
	check(strpos($html, '3 earlier messages sealed') !== false, 'the sealed count is the completed fact');
	check(strpos($html, 'd-none') === false, 'the action button is visible on completion');
	check(strpos($html, 'Open mailbox') !== false && strpos($html, 'admin_mailbox_reader') !== false,
		'the button opens the mailbox reader');
	check(strpos($html, '<noscript>') === false, 'no batch form remains with an empty backlog');
	check(strpos($html, 'Reading takes an unlock by Sam Holder') !== false,
		'another sole holder is named in the unlock fact');

	$state['sealed_total'] = 0;
	$html = mailbox_protection_receipt_render($dom, rr_facts_one_holder(RR_ACTING, 'Robin'), $state);
	check(strpos($html, 'No earlier messages needed sealing') !== false,
		'a zero-backlog raise says nothing needed sealing');

	$state['sealed_total'] = 1;
	$html = mailbox_protection_receipt_render($dom, rr_facts_one_holder(RR_ACTING, 'Robin'), $state);
	check(strpos($html, '1 earlier message sealed') !== false, 'the singular count reads singular');

	// -----------------------------------------------------------------------
	section('receipt render: fortress handoff');

	$fort = new InboundEmailDomain(NULL);
	$fort->set('ied_domain', 'rr-fort-' . bin2hex(random_bytes(3)) . '.example');
	$fort->set('ied_is_enabled', true);
	$fort->set('ied_security_level', InboundEmailDomain::LEVEL_FORTRESS);
	$fort->save();
	$fort->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($fort->key));

	$state = array('backlog' => 0, 'sealed_total' => 5, 'acting_user_id' => RR_ACTING,
		'editor_url' => $urls['editor_url']);
	$html = mailbox_protection_receipt_render($fort, rr_facts_one_holder(RR_ACTING, 'Robin'), $state);
	check(strpos($html, 'Earlier messages sealed — one step left') !== false,
		'the pre-protect Fortress receipt never claims Fortress');
	check(strpos($html, 'activate outbound protection') !== false
		&& strpos($html, 'admin_mailbox_setup') !== false,
		'the handoff button continues into the protect ceremony');
	check(strpos($html, 'This domain is now Fortress') === false,
		'no premature Fortress claim anywhere in the card');

	$state['backlog'] = 7;
	$html = mailbox_protection_receipt_render($fort, rr_facts_one_holder(RR_ACTING, 'Robin'), $state);
	check(strpos($html, 'one step left after this') !== false,
		'the working handoff title says a step will remain');
	check(strpos($html, 'data-title-done="Earlier messages sealed — one step left"') !== false,
		'the resolved handoff title rides for the JS swap');

	// -----------------------------------------------------------------------
	section('counters over real rows');

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($dom->key));
	$alias->set('iea_alias', 'receipt');
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));

	$msg_ids = array();
	foreach (array(false, false, true) as $i => $sealed) {
		$msg = new InboundEmailMessage(NULL);
		$msg->set('iem_ied_inbound_email_domain_id', intval($dom->key));
		$msg->set('iem_iea_inbound_email_alias_id', intval($alias->key));
		$msg->set('iem_sender', 'sender@elsewhere.example');
		$msg->set('iem_recipient', 'receipt@' . $dom->get('ied_domain'));
		$msg->set('iem_subject', 'receipt fixture ' . $i);
		$msg->set('iem_body_plain', 'body ' . $i);
		$msg->set('iem_content_sealed', $sealed);
		$msg->save();
		$msg->load();
		$msg_ids[] = intval($msg->key);
		harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', intval($msg->key));
	}

	check(mailbox_protection_backlog_count(intval($dom->key)) === 2, 'two unsealed rows count as backlog');
	check(mailbox_protection_sealed_count(intval($dom->key)) === 1, 'one sealed row counts as sealed');

	// -----------------------------------------------------------------------
	section('stuck-batch contract: unsealable backlog seals nothing');

	// The alias has NO holder, so no vault to seal to: the batch must return
	// sealed=0 with the rows still remaining — the exact shape the editor's
	// JS loop stops on (instead of spinning forever, which is what the old
	// page-reload loop would have done here).
	$result = mailbox_protection_seal_batch($dom, 200);
	check($result['sealed'] === 0 && $result['remaining'] === 2,
		'a holderless backlog returns sealed=0 with rows remaining', json_encode($result));

	// -----------------------------------------------------------------------
	section('mailbox/seal_batch action: gate and descriptor');

	$descriptor = seal_batch_logic_descriptor();
	check(!empty($descriptor['requires_session'])
		&& !empty($descriptor['auth']['requires_browser_session']),
		'the action requires a browser session');

	// The CLI harness session carries no staff permission, so the gate refuses.
	$res = seal_batch_logic(array('domain_id' => intval($dom->key)));
	check($res->error !== null, 'a below-staff session is refused by the action');

} catch (Throwable $harness_e) {
	// Names the throw and where it happened, which beats the fatal-handler
	// detail the crash net has to fall back on.
	check(false, 'the suite ran to completion without throwing',
		get_class($harness_e) . ': ' . $harness_e->getMessage()
		. ' @ ' . $harness_e->getFile() . ':' . $harness_e->getLine());
}

// Outside the try, and NEVER in a finally: harness_finish() exit()s, so calling
// it while an exception is unwinding swallows the throw and reports PASS on
// however many checks completed. Fixture teardown runs from here and from the
// crash net, so nothing leaks on either path.
// tests/estate/harness_contract_test.php enforces this shape estate-wide.
harness_finish();
?>
