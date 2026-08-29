<?php
/**
 * set_recovery_key.php — report which backup recovery key this site is holding.
 *
 * Despite the name it sets nothing, and the name is kept because a control
 * plane's status check invokes it by path: renaming it would make an
 * un-upgraded plane stop learning a node's recovery state, and a fact that
 * quietly stops arriving is worse than one that arrives from an oddly named
 * tool.
 *
 * THE RULE: only this site sets this site's key.
 *
 * The key decides who can open every backup this site makes, and sealing to a
 * public key always appears to succeed — so a key handed over from elsewhere
 * produces archives that report themselves encrypted while only the sender can
 * read them, with nothing looking wrong on either machine until a restore is
 * needed. A key nobody here proved possession of is a key nobody here can
 * trust, however trustworthy the sender. Possession is proven on this site, by
 * whoever administers it, against a challenge this site issued: Admin -> System
 * -> Backups, which generates a keypair in the browser and runs the challenge in
 * one pass. That path needs no management node and no shell.
 *
 * So this tool reads. A --public argument is refused rather than ignored, so a
 * management node still trying to push a key finds out.
 *
 * It lives in sysadmin_tools/ rather than public_html/utils/ for the same
 * reason reset_admin_password.php does: /utils/<name> is web-routable and the
 * router applies no permission check of its own. This directory is outside the
 * web root; the CLI SAPI check below is the second line.
 *
 * Usage:
 *   php set_recovery_key.php --report
 *
 * Output is one machine-readable line on stdout, so a job step can record it:
 *
 *   RECOVERY_KEY=already fpr=<sha256> proven=0|1  the key this site holds
 *   RECOVERY_KEY=none                             nothing configured
 *   RECOVERY_KEY=invalid                          a value that is not a key
 *
 * Every one of those is exit 0: none of them is a fault of the run. Only bad
 * usage exits non-zero.
 *
 * Validate with `php -l` only — never the file validator, which executes the
 * file it is checking.
 *
 * @version 2.0 - reports only. Writing this site's recovery key from outside the site is gone:
 *                no key arrives from a management node, and no proof established elsewhere is
 *                accepted here
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

/** Print usage and exit. */
function srk_usage($code = 2) {
    $stream = ($code === 0) ? STDOUT : STDERR;
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php set_recovery_key.php --report\n");
    exit($code);
}

/** Minimal --flag / --flag=value / --flag value parser. */
function srk_opts(array $argv) {
    $opts = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') !== 0) {
            fwrite(STDERR, "ERROR: unexpected argument '{$arg}'.\n");
            srk_usage();
        }
        $body = substr($arg, 2);
        if (strpos($body, '=') !== false) {
            list($key, $value) = explode('=', $body, 2);
            $opts[$key] = $value;
        } elseif ($i + 1 < count($argv) && strpos($argv[$i + 1], '--') !== 0) {
            $opts[$body] = $argv[++$i];
        } else {
            $opts[$body] = true;
        }
    }
    return $opts;
}

/** Emit the one status line and stop. */
function srk_report($outcome, $fingerprint = '', $proven = null) {
    $line = 'RECOVERY_KEY=' . $outcome;
    if ($fingerprint !== '') {
        $line .= ' fpr=' . $fingerprint;
    }
    if ($proven !== null) {
        $line .= ' proven=' . ($proven ? '1' : '0');
    }
    fwrite(STDOUT, $line . "\n");
    exit(0);
}

$opts = srk_opts($argv);
if (isset($opts['help'])) {
    srk_usage(0);
}

// ---------------------------------------------------------------------------
// Refused, not ignored. A caller passing a key believes it is deciding who can
// open this site's backups, and it is not — saying so is the whole point.
// ---------------------------------------------------------------------------
foreach (array('public', 'proven-fpr') as $srk_write_flag) {
    if (isset($opts[$srk_write_flag])) {
        fwrite(STDERR, "ERROR: --{$srk_write_flag} is refused. Only this site sets this site's "
                     . "recovery key, and only by proving possession against a challenge it issued "
                     . "(Admin -> System -> Backups). A management node still passing this flag is out "
                     . "of date. Nothing was changed.\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$here = BackupRecoveryKey::key_report();
switch ($here['state']) {
    case 'unconfigured': srk_report('none');
    case 'invalid':      srk_report('invalid');
    default:             srk_report('already', $here['fingerprint'], $here['state'] === 'proven');
}
