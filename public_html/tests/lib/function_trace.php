<?php
/**
 * The function trace: prepended to every PHP suite the runner starts when
 * xhprof is loaded (`-d auto_prepend_file`, tests/run.php run_one_start()), so
 * it runs before the suite's first line. harness_finish() stops it and puts
 * the functions the process ran in the result contract (`functions`), which
 * is what lets a change inside a function body select only the suites that
 * ran that function (tests/lib/coverage.php coverage_function_change()).
 *
 * Built-in functions are left out: only repository code can change.
 *
 * @version 1.0
 */
if (function_exists('xhprof_enable') && !defined('JOINERY_FUNCTION_TRACE')) {
	xhprof_enable(XHPROF_FLAGS_NO_BUILTINS);
	define('JOINERY_FUNCTION_TRACE', 1);
}
