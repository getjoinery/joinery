<?php
/**
 * site_census.php — count what this site holds, for checking a copy against its source.
 *
 * Prints one line on stdout: CENSUS= and a JSON object with the rows in every
 * table, the files, directories and bytes under each top-level directory of the
 * site, and the state of its sealed secrets (SiteCensus::take(), which says
 * what is counted and what each machine keeps as its own).
 *
 * Run on two machines, the two lines compare with SiteCensus::compare(). The
 * site_census agent word runs it (specs/site_copy.md, step 5), and so can
 * anyone checking a restore by hand:
 *
 *   sudo php maintenance_scripts/sysadmin_tools/site_census.php
 *
 * It reads and never writes. Run it as root, or as an account that can read
 * every file of the site: a directory it cannot read fails the census rather
 * than undercounting it.
 *
 * It lives in sysadmin_tools/, outside the web root, as set_recovery_key.php
 * does; the CLI check below is the second line.
 *
 * Exit 0 with the CENSUS= line; exit 1 with an ERROR: line when it could not
 * count (the database unreachable, a directory unreadable).
 *
 * Validate with `php -l` only — never the file validator, which executes the
 * file it is checking.
 *
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (count($argv) > 1) {
    fwrite(STDERR, "Usage: php site_census.php (it takes no arguments)\n");
    exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');

try {
    echo SiteCensus::format_output(SiteCensus::take());
} catch (\Throwable $e) {
    echo 'ERROR: the census could not be taken: ' . $e->getMessage() . "\n";
    exit(1);
}
exit(0);
