<?php
/** @joinery-test
 * name: pg_dump_row_order
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * PgDumpRowOrder: the install SQL's seed rows come out in key order whatever
 * order pg_dump found them on disk, so a row merely updated on the publishing
 * site does not show as a change in the committed file.
 *
 * Run: php tests/unit/pg_dump_row_order_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$copy = function (string $header, array $rows): string {
	return "SET x = 1;\n\n" . $header . "\n" . implode("\n", $rows) . "\n\\.\n\n\nSELECT 1;\n";
};

// ---------------------------------------------------------------------------
section('Rows in key order');

$header = 'COPY public.com_components (com_component_id, com_title, com_body) FROM stdin;';
$disk = array("24\tSpacer\tb", "3\tHero\ta\\tb", "20\tImage Gallery\tc", "100\tLast\td");
$sorted = PgDumpRowOrder::sort($copy($header, $disk), array('com_component_id'));
check($sorted === $copy($header, array("3\tHero\ta\\tb", "20\tImage Gallery\tc", "24\tSpacer\tb", "100\tLast\td")),
	'rows are ordered by the integer key (3, 20, 24, 100), not as text');
check(PgDumpRowOrder::sort($copy($header, array_reverse($disk)), array('com_component_id')) === $sorted,
	'the same rows in another on-disk order give the same bytes');

$header2 = 'COPY public.t (a, "b", c) FROM stdin;';
$two = PgDumpRowOrder::sort($copy($header2, array("x\t2\tq", "x\t1\tr", "a\t9\ts")), array('a', 'b'));
check($two === $copy($header2, array("a\t9\ts", "x\t1\tr", "x\t2\tq")), 'a two-column key orders by the first, then the second (a quoted name is found)');

$zone = 'COPY public.zone (zone_id, country_code, zone_name) FROM stdin;';
check(PgDumpRowOrder::sort($copy($zone, array("10\tAQ\tb", "2\tAE\ta", "1\tAD\tz")), array())
	=== $copy($zone, array("1\tAD\tz", "2\tAE\ta", "10\tAQ\tb")), 'a table with no key is ordered by its first column, as numbers');

// ---------------------------------------------------------------------------
section('Nothing else moves');

$plain = "SET a = 1;\nSELECT pg_catalog.setval('s', 3, true);\n";
check(PgDumpRowOrder::sort($plain, array('id')) === $plain, 'output with no COPY block is unchanged');
$empty = $copy($header, array());
check(PgDumpRowOrder::sort($empty, array('com_component_id')) === $empty, 'an empty COPY block is unchanged');

harness_finish();
