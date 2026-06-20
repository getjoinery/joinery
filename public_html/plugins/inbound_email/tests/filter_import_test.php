<?php
/**
 * Tests for Gmail filter import (specs/inbound_email_filter_import.md).
 *
 * The bulk exercises InboundEmailFilter::parseGmailExport() directly — it is pure
 * (no DB writes), so the parser, the size trap, the property mapping, name
 * synthesis, and the importable test are all unit-testable against the real
 * Gmail export fixture and small synthetic XML snippets.
 *
 * The DB-backed cases (label find-or-create on confirm, the re-import dedup
 * signature) only run when invoked with `--db`, since they create and then clean
 * up rows. The signature helper is exercised purely via reflection of the logic
 * file's _filter_signature().
 *
 * Run (pure):   php plugins/inbound_email/tests/filter_import_test.php
 * Run (+DB):    php plugins/inbound_email/tests/filter_import_test.php --db
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_filter_class.php'));

class FilterImportTest {
	private $pass = 0;
	private $fail = 0;
	private $fixture;

	function __construct() {
		$this->fixture = __DIR__ . '/fixtures/mailFilters.xml';
	}

	private function out($msg) {
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . $msg . "\n";
	}
	private function eq($expected, $actual, $label) {
		if ($expected === $actual) {
			$this->pass++;
			$this->out('  PASS: ' . $label);
		} else {
			$this->fail++;
			$this->out('  FAIL: ' . $label . ' (expected ' . var_export($expected, true)
				. ', got ' . var_export($actual, true) . ')');
		}
	}
	private function ok($cond, $label) { $this->eq(true, (bool)$cond, $label); }

	/** Find the first candidate whose mapped fields contain $col == $val. */
	private function candWith(array $cands, string $col, $val) {
		foreach ($cands as $c) {
			if (isset($c['fields'][$col]) && $c['fields'][$col] === $val) { return $c; }
		}
		return null;
	}

	// --------------------------------------------------------- fixture parse

	function testFixtureParses() {
		$this->out("\n# Real Gmail export fixture");
		$xml = file_get_contents($this->fixture);
		$cands = InboundEmailFilter::parseGmailExport($xml);

		$this->eq(44, count($cands), '44 entries parse');

		// The size trap: NOT ONE candidate gains a size criterion, even though every
		// entry carries default sizeOperator/sizeUnit.
		$withSize = 0;
		foreach ($cands as $c) { if (!empty($c['fields']['fil_match_size_op'])) { $withSize++; } }
		$this->eq(0, $withSize, 'size trap — no candidate gets a bogus size criterion');

		// from + shouldArchive map; the first entry is from=dealnews, label=deals, archive.
		$deal = $this->candWith($cands, 'fil_match_from', 'dealnews');
		$this->ok($deal !== null, 'from=dealnews candidate present');
		$this->eq(true, $deal['fields']['fil_action_archive'] ?? null, 'shouldArchive -> fil_action_archive');
		$this->eq('deals', $deal['label'], 'label carried by NAME on the candidate');
		$this->ok(!in_array('deals', $deal['skipped'], true), 'label is NOT in skipped');
		$this->ok(empty($deal['skipped']), 'no spurious skipped entries (sizeOperator/Unit ignored silently)');
		$this->eq('From: dealnews', $deal['name'], 'name synthesized from the From criterion');
		$this->ok($deal['importable'], 'from + archive + label -> importable');

		// The label property never resolves to an id at parse time (no DB key leaks in).
		$leak = false;
		foreach ($cands as $c) { if (array_key_exists('fil_action_ilb_inbound_email_label_id', $c['fields'])) { $leak = true; } }
		$this->ok(!$leak, 'parse never sets a label id (resolution deferred to confirm)');

		// The lone subject / shouldMarkAsRead / hasTheWord / doesNotHaveTheWord /
		// shouldNeverSpam entries are present and mapped.
		$haveSubject = $haveRead = $haveWords = $haveExcludes = $haveNeverSpam = 0;
		foreach ($cands as $c) {
			if (!empty($c['fields']['fil_match_subject']))    { $haveSubject++; }
			if (!empty($c['fields']['fil_action_mark_read'])) { $haveRead++; }
			if (!empty($c['fields']['fil_match_has_words']))  { $haveWords++; }
			if (!empty($c['fields']['fil_match_excludes']))   { $haveExcludes++; }
			if (!empty($c['fields']['fil_action_never_spam'])){ $haveNeverSpam++; }
		}
		$this->eq(1, $haveSubject, 'the single subject entry maps');
		$this->eq(1, $haveRead, 'the single shouldMarkAsRead entry maps');
		$this->eq(2, $haveWords, 'two hasTheWord entries map');
		$this->eq(4, $haveExcludes, 'four doesNotHaveTheWord entries map');
		$this->eq(2, $haveNeverSpam, 'two shouldNeverSpam entries map');
	}

	// --------------------------------------------------------- size mapping

	function testSizeMapping() {
		$this->out("\n# Size mapping (with a real value)");
		$units = array('s_sb' => 1, 's_skb' => 1024, 's_smb' => 1048576);
		foreach ($units as $unit => $mult) {
			foreach (array('s_sl' => 'lt', 's_sg' => 'gt') as $op => $expectOp) {
				$xml = $this->feed(
					"<apps:property name='from' value='x'/>" .
					"<apps:property name='size' value='5'/>" .
					"<apps:property name='sizeOperator' value='$op'/>" .
					"<apps:property name='sizeUnit' value='$unit'/>" .
					"<apps:property name='shouldArchive' value='true'/>");
				$c = InboundEmailFilter::parseGmailExport($xml)[0];
				$this->eq($expectOp, $c['fields']['fil_match_size_op'] ?? null, "sizeOperator $op -> $expectOp");
				$this->eq(5 * $mult, $c['fields']['fil_match_size_bytes'] ?? null, "5 $unit -> " . (5 * $mult) . ' bytes');
			}
		}

		// size=0 or non-numeric is ignored (still the size trap).
		$c0 = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='from' value='x'/>" .
			"<apps:property name='size' value='0'/>" .
			"<apps:property name='sizeOperator' value='s_sl'/>" .
			"<apps:property name='shouldArchive' value='true'/>"))[0];
		$this->ok(empty($c0['fields']['fil_match_size_op']), 'size=0 -> no size criterion');
	}

	// --------------------------------------------------------- importable test

	function testImportableTest() {
		$this->out("\n# Importable floor (>=1 criterion AND >=1 action)");

		// hasAttachment criterion + label-only action -> importable.
		$attach = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='hasAttachment' value='true'/>" .
			"<apps:property name='label' value='Files'/>"))[0];
		$this->eq(true, $attach['fields']['fil_match_has_attachment'] ?? null, 'hasAttachment maps');
		$this->ok($attach['importable'], 'attachment criterion + label-only action -> importable');

		// from + label, no other action -> importable (label counts as an action).
		$labelOnly = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='from' value='news'/>" .
			"<apps:property name='label' value='News'/>"))[0];
		$this->ok($labelOnly['importable'], 'label-only action is importable');

		// from + importance-only action, NO label -> non-importable (importance skipped).
		$imp = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='from' value='boss'/>" .
			"<apps:property name='shouldAlwaysMarkAsImportant' value='true'/>"))[0];
		$this->ok(!$imp['importable'], 'importance-only action (no label) -> non-importable');
		$this->ok(in_array('mark important', $imp['skipped'], true), 'importance recorded in skipped');

		// No criteria at all -> non-importable even with an action.
		$noCrit = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='shouldArchive' value='true'/>" .
			"<apps:property name='label' value='X'/>"))[0];
		$this->ok(!$noCrit['importable'], 'no criterion -> non-importable');
	}

	// --------------------------------------------------------- skip + multi-label

	function testSkipAndMultiLabel() {
		$this->out("\n# Skipped properties and multi-label");

		$c = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='from' value='x'/>" .
			"<apps:property name='label' value='First'/>" .
			"<apps:property name='label' value='Second'/>" .
			"<apps:property name='smartLabelToApply' value='^smartlabel_promo'/>" .
			"<apps:property name='excludeChats' value='true'/>" .
			"<apps:property name='someUnknownProp' value='zzz'/>"))[0];
		$this->eq('First', $c['label'], 'first label wins');
		$this->ok(in_array('extra label: Second', $c['skipped'], true), 'second label reported skipped');
		$this->ok(in_array('categorize: ^smartlabel_promo', $c['skipped'], true), 'smartLabelToApply skipped');
		$this->ok(in_array('chats excluded', $c['skipped'], true), 'excludeChats skipped');
		$this->ok(in_array('someUnknownProp: zzz', $c['skipped'], true), 'unknown property surfaced in skipped');

		// Nested Gmail label kept verbatim (the slash is literal).
		$nested = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='from' value='x'/>" .
			"<apps:property name='label' value='Parent/Child'/>"))[0];
		$this->eq('Parent/Child', $nested['label'], 'nested label kept verbatim');
	}

	// --------------------------------------------------------- malformed input

	function testMalformed() {
		$this->out("\n# Malformed input");
		$this->threw('not xml at all', 'non-XML throws');
		$this->threw('<other><foo/></other>', 'wrong-root XML throws');
		$this->threw('', 'empty string throws');

		// A feed with an entry of only unknown/unmappable props parses but is non-importable.
		$c = InboundEmailFilter::parseGmailExport($this->feed(
			"<apps:property name='totallyUnknown' value='1'/>"))[0];
		$this->ok(!$c['importable'], 'entry with only unknown props -> non-importable (not fatal)');
	}

	private function threw(string $xml, string $label) {
		try {
			InboundEmailFilter::parseGmailExport($xml);
			$this->eq('threw', 'did not throw', $label);
		} catch (\Throwable $e) {
			$this->ok(true, $label);
		}
	}

	// --------------------------------------------------------- DB-backed (opt-in)

	function testLabelResolutionAndDedup() {
		$this->out("\n# Label resolution + dedup signature (DB)");
		require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
		require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_labels_class.php'));
		require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_filters_logic.php'));

		// Reuse an existing label by name; create a new one; both find-or-create.
		$name = 'ImportTest_' . substr(md5(uniqid('', true)), 0, 8);
		$first = InboundEmailLabel::findOrCreate($name);
		$this->ok($first && $first->key, 'findOrCreate mints a new label');
		$again = InboundEmailLabel::findOrCreate($name);
		$this->eq(intval($first->key), intval($again->key), 're-resolving the same name reuses the row');

		// Signature stability: same fields + same label id -> identical signature.
		$fields = array('fil_match_from' => 'dealnews', 'fil_action_archive' => true);
		$sigA = _filter_signature($fields, intval($first->key));
		$sigB = _filter_signature($fields, intval($first->key));
		$this->eq($sigA, $sigB, 'signature is stable for identical input');
		$sigNoLabel = _filter_signature($fields, null);
		$this->ok($sigA !== $sigNoLabel, 'label id participates in the signature');

		// Clean up the label we created.
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('DELETE FROM ilb_inbound_email_labels WHERE ilb_inbound_email_label_id = ?')
			->execute(array(intval($first->key)));
		$this->out('  (cleaned up test label #' . intval($first->key) . ')');
	}

	// --------------------------------------------------------- helpers

	/** Wrap property XML in a minimal Gmail feed with one entry. */
	private function feed(string $propsXml): string {
		return "<?xml version='1.0' encoding='UTF-8'?>" .
			"<feed xmlns='http://www.w3.org/2005/Atom' xmlns:apps='http://schemas.google.com/apps/2006'>" .
			"<entry><category term='filter'></category><title>Mail Filter</title>" . $propsXml . "</entry>" .
			"</feed>";
	}

	function run(bool $withDb) {
		$this->out('=== Gmail filter import tests ===');
		$this->testFixtureParses();
		$this->testSizeMapping();
		$this->testImportableTest();
		$this->testSkipAndMultiLabel();
		$this->testMalformed();
		if ($withDb) {
			$this->testLabelResolutionAndDedup();
		} else {
			$this->out("\n# (skipping DB-backed label/dedup tests; pass --db to run them)");
		}
		$this->out("\n=== " . $this->pass . ' passed, ' . $this->fail . ' failed ===');
		return $this->fail === 0;
	}
}

$withDb = in_array('--db', $argv ?? array(), true);
$ok = (new FilterImportTest())->run($withDb);
exit($ok ? 0 : 1);
