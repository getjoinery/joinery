<?php
/** @joinery-test
 * name: relay_fix_pack
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Tests for the relay fix pack's Round 2 regressions
 * (specs/mailbox_relay_fix_pack.md § Round 2):
 *
 *   R2-1  InboundEmailMessage::updateColumns() binds booleans/nulls/ints with
 *         explicit PDO types — a live-driver test with boolean false (the
 *         untyped bind pdo_pgsql rejects with 22P02).
 *   R2-3  SRS bounce addresses survive only with their case intact: rewrite →
 *         detect → validate round-trips on the raw address and fails on a
 *         lowercased copy (why the pipes use flags=DRh and processEmail runs
 *         the SRS check before lowercasing).
 *   R2-8  RelayMapSync::contentHash() is the single map-hash formula: it is
 *         deterministic and covers every artifact (including srs_access, the
 *         one the freshness check used to drop).
 *
 * Run: php plugins/mailbox/tests/relay_fix_pack_test.php
 *
 * The R2-1 test creates one scratch message row and deletes it; it is skipped
 * when no inbound domain exists to attach it to.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/SRSRewriter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

class RelayFixPackTest {

	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function ok($cond, $label) { return check((bool)$cond, $label); }

	function run() {
		try {
			$this->testContentHashCoversEveryArtifact();
			$this->testSrsCaseSensitivity();
			$this->testUpdateColumnsTypedBinding();
		} catch (\Throwable $e) {
			check(false, 'uncaught ' . get_class($e), $e->getMessage());
		}
	}

	// R2-8 — one formula, every artifact.
	private function testContentHashCoversEveryArtifact() {
		section('contentHash');
		$base = array(
			'relay_domains' => 'a', 'recipients' => 'b', 'transport' => 'c',
			'srs_access' => 'd', 'routing_json' => 'e',
		);
		$this->ok(RelayMapSync::contentHash($base) === RelayMapSync::contentHash($base),
			'deterministic for identical artifacts');
		foreach (array_keys($base) as $key) {
			$mutated = $base;
			$mutated[$key] .= 'X';
			$this->ok(RelayMapSync::contentHash($mutated) !== RelayMapSync::contentHash($base),
				"a change to {$key} changes the hash");
		}
	}

	// R2-3 — the SRS address is case-sensitive end to end.
	private function testSrsCaseSensitivity() {
		section('SRS case');
		$srs = new SRSRewriter('test-secret-for-case-check');
		$addr = $srs->rewrite('Alice.Smith@Gmail.com', 'fwd.example.com');

		$this->ok(SRSRewriter::isSRSAddress($addr), 'rewritten address is detected');
		$this->ok($srs->validate($addr), 'rewritten address validates');

		$lower = strtolower($addr);
		$this->ok(!SRSRewriter::isSRSAddress($lower), 'lowercased copy is NOT detected (SRS0= prefix)');
		$this->ok(!$srs->validate($lower), 'lowercased copy does NOT validate (hash is case-sensitive)');
	}

	// R2-1 — updateColumns with boolean false must not throw 22P02 and must land.
	private function testUpdateColumnsTypedBinding() {
		section('updateColumns typed binding');
		$db = DbConnector::get_instance()->get_db_link();

		$domain_id = $db->query(
			"SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
			  WHERE ied_delete_time IS NULL LIMIT 1"
		)->fetchColumn();
		if (!$domain_id) {
			harness_skip('no inbound domain to attach a scratch row to');
			return;
		}

		$stmt = $db->prepare(
			"INSERT INTO iem_inbound_email_messages
				(iem_ied_inbound_email_domain_id, iem_sender, iem_recipient, iem_subject,
				 iem_body_plain, iem_body_html, iem_raw_message, iem_is_read)
			 VALUES (?, 'srs-test@example.com', 'scratch@example.com', 'relay_fix_pack_test scratch',
				 '', '', '', true)
			 RETURNING iem_inbound_email_message_id"
		);
		$stmt->execute(array($domain_id));
		$id = intval($stmt->fetchColumn());
		$this->ok($id > 0, 'scratch row created');

		try {
			// boolean false + null + string + int in one call — the exact mix the
			// deferred-parse clear uses.
			InboundEmailMessage::updateColumns($id, array(
				'iem_is_read'    => false,
				'iem_read_time'  => null,
				'iem_subject'    => 'updated-by-typed-binding',
				'iem_size_bytes' => 42,
				'not_a_column'   => 'must be ignored',
			));
			$row = $db->query(
				"SELECT iem_is_read, iem_read_time, iem_subject, iem_size_bytes
				   FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = " . intval($id)
			)->fetch(PDO::FETCH_ASSOC);
			$this->ok($row !== false && in_array($row['iem_is_read'], array(false, 'f', 0, '0'), true),
				'boolean false landed (no 22P02)');
			$this->ok($row['iem_read_time'] === null, 'null landed');
			$this->ok($row['iem_subject'] === 'updated-by-typed-binding', 'string landed');
			$this->ok(intval($row['iem_size_bytes']) === 42, 'int landed');
		} finally {
			$db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = " . intval($id));
		}
	}
}

$test = new RelayFixPackTest();
$test->run();
harness_finish();
