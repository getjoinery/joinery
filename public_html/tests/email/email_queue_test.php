<?php
/** @joinery-test
 * name: email_queue
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Email::queue() — the one place an audience becomes erc_email_recipients rows
 * (specs/group_sends_one_row.md §3.1, §3.2, §3.4).
 *
 *  - add groups union, remove groups subtract, ids de-duplicate;
 *  - a user already on the email is not written twice, a user with no row is
 *    skipped, the count is what the email has after expansion;
 *  - status and scheduled time move only when there is someone to send to;
 *  - a mailing-list email expands its subscribers;
 *  - the core `user` provider is an audience of one, absent from the picker;
 *  - MultiEmail's recipient_group filter matches the add side only.
 *
 * Run: php tests/run.php db --filter=email_queue
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/emails_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_list_registrants_class.php'));
require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));

/** A provider whose audiences are whatever the test says they are. */
class HarnessFakeRecipientProvider implements RecipientGroupProvider {
	public static $audiences = array();
	public function key(): string { return 'harness_fake'; }
	public function label(): string { return 'Harness fake'; }
	public function options(): array { return array(); }
	public function resolve(int $reference_id): array { return self::$audiences[$reference_id] ?? array(); }
	public function reference_label(int $reference_id): string { return 'Fake #' . $reference_id; }
}
RecipientGroupProviderRegistry::register(new HarnessFakeRecipientProvider());

function eq_make_email($label) {
	$email = new Email(NULL);
	$email->set('eml_subject', 'HarnessTest ' . $label . ' ' . bin2hex(random_bytes(3)));
	$email->set('eml_message_html', '<p>body</p>');
	$email->set('eml_message_plain', 'body');
	$email->set('eml_status', Email::EMAIL_CREATED);
	$email->save();
	$email->load();
	harness_register_model('Email', $email->key);
	return $email;
}

function eq_recipient_ids($email) {
	$ids = array();
	foreach (new MultiEmailRecipient(array('email_id' => $email->key)) as $r) {
		$ids[] = (int)$r->get('erc_usr_user_id');
	}
	sort($ids);
	return $ids;
}

try {

	$a = make_user('EqA');
	$b = make_user('EqB');
	$c = make_user('EqC');
	$ida = (int)$a->key; $idb = (int)$b->key; $idc = (int)$c->key;
	HarnessFakeRecipientProvider::$audiences = array(
		1 => array($ida, $idb, $idc, $idb),   // a duplicate inside one group
		2 => array($idc),
		3 => array(),
	);

	section('Expansion: add, remove, de-duplicate');
	$email = eq_make_email('Expand');
	$email->add_recipient_group('harness_fake', 1);
	$email->add_recipient_group('harness_fake', 2, 'remove');
	$email->add_recipient_group('user', $b->key);          // B twice across groups
	$email->add_recipient_group('user', 2147483000);       // no such user: skipped

	$resolved = $email->resolve_recipient_user_ids();
	sort($resolved);
	$expected = array($ida, $idb, 2147483000); sort($expected);
	check($resolved === $expected, 'resolve: A and B stay, C is removed, the unknown id is still an id', json_encode($resolved));
	check(!in_array($idc, $resolved, true), 'the remove group subtracts C');

	$n = $email->queue();
	check($n === 2, 'queue() returns the recipients on the email', "got $n");
	$expected = array($ida, $idb); sort($expected);
	check(eq_recipient_ids($email) === $expected, 'one erc row each for A and B, none for C or the unknown id', json_encode(eq_recipient_ids($email)));
	$email->load();
	check((int)$email->get('eml_status') === Email::EMAIL_QUEUED, 'status is QUEUED');
	check($email->get('eml_scheduled_time') !== null && $email->get('eml_scheduled_time') !== '', 'scheduled time is stamped');
	check((int)$email->get('eml_status') !== Email::EMAIL_SENT, 'queue() never marks the email sent');
	$sent = count(new MultiEmailRecipient(array('email_id' => $email->key, 'sent' => true)));
	check($sent === 0, 'no recipient is marked sent by queueing', "sent=$sent");

	section('Queueing twice');
	$n2 = $email->queue();
	check($n2 === 2, 'a second queue() reports the same audience', "got $n2");
	check(count(eq_recipient_ids($email)) === 2, 'and writes no second row for anyone');

	section('Nobody to send to');
	$empty = eq_make_email('Empty');
	$empty->add_recipient_group('harness_fake', 3);
	$n0 = $empty->queue();
	$empty->load();
	check($n0 === 0, 'queue() returns 0 for an empty audience', "got $n0");
	check((int)$empty->get('eml_status') === Email::EMAIL_CREATED, 'status is left as it was');
	check($empty->get('eml_scheduled_time') === null || $empty->get('eml_scheduled_time') === '', 'no scheduled time is stamped');
	check(count(eq_recipient_ids($empty)) === 0, 'no recipient rows');

	section('Mailing list email');
	$list = new MailingList(NULL);
	$list->set('mlt_name', 'HarnessTest queue list ' . bin2hex(random_bytes(3)));
	$list->set('mlt_link', 'harnesstest-queue-' . bin2hex(random_bytes(3)));
	$list->set('mlt_is_active', true);
	$list->save();
	$list->load();
	harness_register_model('MailingList', $list->key);
	foreach (array($a, $c) as $u) {
		$reg = new MailingListRegistrant(NULL);
		$reg->set('mlr_mlt_mailing_list_id', $list->key);
		$reg->set('mlr_usr_user_id', $u->key);
		$reg->save();
		$reg->load();
		harness_register_model('MailingListRegistrant', $reg->key);
	}
	$list_email = eq_make_email('List');
	$list_email->set('eml_mlt_mailing_list_id', $list->key);
	$list_email->save();
	$nl = $list_email->queue();
	$expected = array($ida, $idc); sort($expected);
	check($nl === 2, 'a mailing-list email queues to its subscribers', "got $nl");
	check(eq_recipient_ids($list_email) === $expected, 'the subscribers are the recipients', json_encode(eq_recipient_ids($list_email)));

	section('The user provider');
	$provider = RecipientGroupProviderRegistry::get('user');
	check($provider instanceof UserRecipientProvider, 'core registers the user provider');
	check($provider->resolve($ida) === array($ida), 'resolve() is the one id');
	check($provider->resolve(0) === array(), 'resolve(0) is nobody');
	check($provider->options() === array(), 'options() is empty: not in the campaign picker');
	check($provider->reference_label($ida) === $a->display_name(), 'reference_label() is the display name');

	section('MultiEmail recipient_group');
	$removed_only = eq_make_email('RemovedOnly');
	$removed_only->add_recipient_group('harness_fake', 1, 'remove');
	$matches = array();
	foreach (new MultiEmail(array('recipient_group' => array('provider' => 'harness_fake', 'reference_id' => 1))) as $e) {
		$matches[] = (int)$e->key;
	}
	check(in_array((int)$email->key, $matches, true), 'an email with the audience on its add side matches');
	check(!in_array((int)$removed_only->key, $matches, true), 'an email with the audience only on its remove side does not');
	check(!in_array((int)$empty->key, $matches, true), 'an email to a different audience does not');
	$count = count(new MultiEmail(array('recipient_group' => array('provider' => 'harness_fake', 'reference_id' => 1))));
	check($count === count($matches), 'count() agrees with iteration', "$count vs " . count($matches));
	$threw = false;
	try {
		count(new MultiEmail(array('recipient_group' => array('provider' => "x'; DROP", 'reference_id' => 1))));
	} catch (InvalidArgumentException $e) {
		$threw = true;
	}
	check($threw, 'a provider key that is not a key is refused');

} catch (\Throwable $e) {
	check(false, 'no exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
