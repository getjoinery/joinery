<?php
/**
 * set_recovery_key.php — give this site the backup recovery key its control
 * plane already holds, or report which key it is holding.
 *
 * A site that backs itself up on a schedule needs its own recovery public key,
 * set and proven, or BackupRunner refuses to run — a scheduled backup reads the
 * site's own setting, because nothing is sending it one. Doing that by hand on
 * every managed site is the same ceremony repeated to establish a fact the
 * control plane has already established, and the site that gets skipped is the
 * one that ends up with no backups.
 *
 * THE RULE: this fills an empty slot and never overwrites one.
 *
 * A site with no key is a site making no encrypted backups, so writing a key
 * there cannot destroy anything. Replacing a key that is already in use is a
 * rotation — archives already on the shelf open only with the old private key —
 * which is a different operation with different consequences, and it is not
 * this. A site found holding a key the control plane did not put there is left
 * exactly as it is and reported.
 *
 * It lives in sysadmin_tools/ rather than public_html/utils/ for the same
 * reason reset_admin_password.php does: /utils/<name> is web-routable and the
 * router applies no permission check of its own, and this writes the setting
 * that decides who can open every backup this site makes. This directory is
 * outside the web root; the CLI SAPI check below is the second line.
 *
 * Usage:
 *   php set_recovery_key.php --public BASE64 [--proven-fpr SHA256]
 *   php set_recovery_key.php --report
 *
 *   --public BASE64      The recovery PUBLIC key, as escrow_keypair.php or the
 *                        setup panel prints it. Never a private key.
 *   --proven-fpr SHA256  The fingerprint of that key, already proven at the
 *                        control plane. Without it the key is written but stays
 *                        unproven, and encrypted backups still refuse to run.
 *   --report             Print what this site holds and change nothing.
 *
 * Output is one machine-readable line on stdout, so a job step can record it:
 *
 *   RECOVERY_KEY=written     fpr=<sha256> proven=0|1  a slot was filled
 *   RECOVERY_KEY=proof_write fpr=<sha256> proven=1    same key, proof completed
 *   RECOVERY_KEY=already     fpr=<sha256> proven=1    nothing to do
 *   RECOVERY_KEY=different   fpr=<sha256> proven=0|1  someone else's key, left alone
 *   RECOVERY_KEY=none                                 nothing configured
 *   RECOVERY_KEY=invalid                              a value that is not a key
 *
 * Every one of those is exit 0: none of them is a fault of the run. Only bad
 * usage or a malformed key exits non-zero.
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

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

/** Print usage and exit. */
function srk_usage($code = 2) {
    $stream = ($code === 0) ? STDOUT : STDERR;
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php set_recovery_key.php --public BASE64 [--proven-fpr SHA256]\n");
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
// Report only
// ---------------------------------------------------------------------------
if (isset($opts['report'])) {
    $here = BackupRecoveryKey::key_report();
    switch ($here['state']) {
        case 'unconfigured': srk_report('none');
        case 'invalid':      srk_report('invalid');
        default:             srk_report('already', $here['fingerprint'], $here['state'] === 'proven');
    }
}

// ---------------------------------------------------------------------------
// Validate what we were handed BEFORE reading what is here. A malformed push is
// refused without the database being touched at all, so a mangled command can
// never be the thing that goes wrong halfway.
// ---------------------------------------------------------------------------
$public_b64 = isset($opts['public']) && is_string($opts['public']) ? trim($opts['public']) : '';
if ($public_b64 === '') {
    fwrite(STDERR, "ERROR: --public is required (or use --report).\n");
    srk_usage();
}

$raw = base64_decode($public_b64, true);
if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
    fwrite(STDERR, "ERROR: --public is not a base64 box public key. Nothing was changed.\n");
    exit(2);
}
$incoming_fpr = hash('sha256', $raw);

$proven_fpr = isset($opts['proven-fpr']) && is_string($opts['proven-fpr'])
    ? strtolower(trim($opts['proven-fpr'])) : '';
if ($proven_fpr !== '' && !hash_equals($incoming_fpr, $proven_fpr)) {
    fwrite(STDERR, "ERROR: --proven-fpr is not the fingerprint of --public, so one of them is wrong. "
                 . "Nothing was changed.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Decide
// ---------------------------------------------------------------------------
// The rule itself lives in BackupRecoveryKey and is tested there, without a
// database — this file carries it out rather than restating it.
$here = BackupRecoveryKey::key_report();
$decision = BackupRecoveryKey::push_decision(
    $here['state'], $here['fingerprint'], $incoming_fpr, $proven_fpr !== '');

try {
    switch ($decision) {
        case 'different':
            srk_report('different', $here['fingerprint'], $here['state'] === 'proven');

        case 'already':
            srk_report('already', $here['fingerprint'], $here['state'] === 'proven');

        case 'proof_write':
            BackupRecoveryKey::accept_proven_fingerprint($proven_fpr);
            srk_report('proof_write', $incoming_fpr, true);

        default:
            BackupRecoveryKey::set_public_key($public_b64);
            if ($proven_fpr !== '') {
                BackupRecoveryKey::accept_proven_fingerprint($proven_fpr);
            }
            srk_report('written', $incoming_fpr, $proven_fpr !== '');
    }

} catch (Exception $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
