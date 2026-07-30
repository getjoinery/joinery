<?php
/**
 * reset_admin_password.php — set a Joinery account's password from the server.
 *
 * The recovery path of last resort. Every other route back into an account goes
 * through email or a passkey, and a fresh install has neither: no mail provider
 * is configured yet, and on most VPS providers outbound port 25 is blocked at
 * the account level, so a local MTA cannot deliver either. Someone who sets a
 * password at the forced first-login change and then forgets it has, without
 * this tool, only hand-editing Postgres.
 *
 * It lives in sysadmin_tools/ rather than public_html/utils/ deliberately.
 * /utils/<name> is web-routable and the router applies no permission check of
 * its own — every script in there guards itself, and one forgotten guard on a
 * password reset is an internet-facing account takeover. This directory is
 * outside the web root entirely; the CLI SAPI check below is the second line.
 *
 * Usage:
 *   sudo php reset_admin_password.php [--email=ADDRESS] [--set-email=ADDRESS]
 *                                     [--password-file=PATH]
 *                                     [--clear-second-factor] [--yes]
 *
 *   --email=ADDRESS         Account to reset. Defaults to the only permission-10
 *                           account when there is exactly one.
 *   --set-email=ADDRESS     Also change the account's address, in the same save
 *                           as the password. An installer supplies the owner's
 *                           real address here so the account is recoverable by
 *                           email from the moment it has a working password —
 *                           there is no window in which a fresh credential sits
 *                           on an address nobody can receive mail at.
 *   --password-file=PATH    Read the new password from the first line of PATH.
 *                           Without it, the password is prompted for with echo
 *                           off. There is no positional password argument —
 *                           it would land in shell history and in `ps`.
 *   --clear-second-factor   Also turn off TOTP and rotate the trusted-device
 *                           key. Opt-in: losing a password often means losing
 *                           the authenticator with it (same phone, same
 *                           laptop), but wiping a second factor should be a
 *                           decision rather than a side effect of a routine
 *                           password change.
 *   --yes                   Skip the confirmation prompt.
 *
 * The account is left with usr_force_password_change set, so the password typed
 * here is a way in, not a permanent credential. Changing the password also
 * revokes every active API session key for that user (User::save handles it).
 *
 * Validate with `php -l` only — never the file validator, which executes the
 * file it is checking.
 *
 * @version 1.1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

/** Print usage and exit. */
function rap_usage($code = 2) {
    $stream = ($code === 0) ? STDOUT : STDERR;
    fwrite($stream, "Usage: php reset_admin_password.php [options]\n\n");
    fwrite($stream, "  --email=ADDRESS         account to reset (default: the sole permission-10 account)\n");
    fwrite($stream, "  --set-email=ADDRESS     also change the account's address, in the same save\n");
    fwrite($stream, "  --password-file=PATH    read the new password from the first line of PATH\n");
    fwrite($stream, "  --clear-second-factor   also disable TOTP and rotate the trusted-device key\n");
    fwrite($stream, "  --yes                   skip the confirmation prompt\n");
    fwrite($stream, "  --help                  show this message\n");
    exit($code);
}

/** Minimal --flag / --flag=value parser. */
function rap_opts(array $argv) {
    $opts = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') !== 0) {
            fwrite(STDERR, "ERROR: unexpected argument '{$arg}'. The password is never passed on the command line.\n");
            rap_usage();
        }
        $body = substr($arg, 2);
        if (strpos($body, '=') !== false) {
            list($key, $value) = explode('=', $body, 2);
            $opts[$key] = $value;
        } else {
            $opts[$body] = true;
        }
    }
    return $opts;
}

/** Read a line from the terminal with echo off where the terminal supports it. */
function rap_prompt_hidden($label) {
    fwrite(STDOUT, $label);
    $stty = trim((string)shell_exec('command -v stty 2>/dev/null'));
    $restore = null;
    if ($stty !== '') {
        $restore = trim((string)shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -echo 2>/dev/null');
    }
    $value = fgets(STDIN);
    if ($restore !== null && $restore !== '') {
        shell_exec('stty ' . escapeshellarg($restore) . ' 2>/dev/null');
    }
    fwrite(STDOUT, "\n");
    return ($value === false) ? '' : rtrim($value, "\r\n");
}

$opts = rap_opts($argv);
if (isset($opts['help'])) {
    rap_usage(0);
}

// ---------------------------------------------------------------------------
// Pick the account
// ---------------------------------------------------------------------------

$email = isset($opts['email']) && is_string($opts['email']) ? trim($opts['email']) : '';

if ($email === '') {
    $superadmins = new MultiUser(
        ['permission_range' => [10, 10], 'deleted' => false],
        ['usr_user_id' => 'ASC']
    );
    $superadmins->load();

    $candidates = [];
    foreach ($superadmins as $candidate) {
        $candidates[] = $candidate;
    }

    if (count($candidates) === 1) {
        $user = $candidates[0];
        $email = $user->get('usr_email');
        fwrite(STDOUT, "No --email given; using the only permission-10 account: {$email}\n");
    } elseif (count($candidates) === 0) {
        fwrite(STDERR, "ERROR: no permission-10 account exists. Pass --email=ADDRESS.\n");
        exit(1);
    } else {
        fwrite(STDERR, "ERROR: this site has " . count($candidates) . " permission-10 accounts. Pass --email=ADDRESS to choose one:\n");
        foreach ($candidates as $candidate) {
            fwrite(STDERR, '  ' . $candidate->get('usr_email') . "\n");
        }
        exit(1);
    }
} else {
    $user = User::GetByEmail($email);
    if ($user === NULL) {
        fwrite(STDERR, "ERROR: no account found for '{$email}'.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Validate the replacement address, if one was given
// ---------------------------------------------------------------------------

$new_email = isset($opts['set-email']) && is_string($opts['set-email']) ? trim($opts['set-email']) : '';

if ($new_email !== '') {
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "ERROR: --set-email is not a valid address: '{$new_email}'\n");
        exit(1);
    }
    // Checked before anything is written. An installer that mistypes should
    // leave the account exactly as it found it, not half-changed.
    if (strcasecmp($new_email, (string)$user->get('usr_email')) === 0) {
        $new_email = '';
    } else {
        $existing = User::GetByEmail($new_email);
        if ($existing !== NULL) {
            fwrite(STDERR, "ERROR: another account already uses '{$new_email}' (user "
                . $existing->get('usr_user_id') . "). Nothing has been changed.\n");
            exit(1);
        }
    }
}

// ---------------------------------------------------------------------------
// Collect the new password
// ---------------------------------------------------------------------------

if (isset($opts['password-file'])) {
    $path = $opts['password-file'];
    if (!is_string($path) || $path === '') {
        fwrite(STDERR, "ERROR: --password-file needs a path.\n");
        exit(2);
    }
    if (!is_readable($path)) {
        fwrite(STDERR, "ERROR: cannot read password file: {$path}\n");
        exit(1);
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "ERROR: failed to read password file: {$path}\n");
        exit(1);
    }
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    $password = rtrim((string)$lines[0]);
} else {
    $password = rap_prompt_hidden('New password: ');
    $confirm  = rap_prompt_hidden('Confirm password: ');
    if ($password !== $confirm) {
        fwrite(STDERR, "ERROR: passwords do not match.\n");
        exit(1);
    }
}

if (trim($password) === '') {
    fwrite(STDERR, "ERROR: empty password.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Confirm and apply
// ---------------------------------------------------------------------------

$clear_second_factor = isset($opts['clear-second-factor']);
$totp_on = (bool)$user->get('usr_totp_enabled_time');

if (!isset($opts['yes'])) {
    fwrite(STDOUT, "\nAbout to reset the password for {$email} (user " . $user->get('usr_user_id') . ").\n");
    if ($new_email !== '') {
        fwrite(STDOUT, "The account's address will change to {$new_email} at the same time.\n");
    }
    fwrite(STDOUT, "They will be required to choose a new password at their next sign-in.\n");
    if ($clear_second_factor) {
        fwrite(STDOUT, "Their authenticator app will also be turned off and every trusted device signed out.\n");
    } elseif ($totp_on) {
        fwrite(STDOUT, "Their authenticator app stays on — they will still need a code to finish signing in.\n");
        fwrite(STDOUT, "Re-run with --clear-second-factor if that device is gone too.\n");
    }
    fwrite(STDOUT, 'Proceed? [y/N] ');
    $answer = strtolower(trim((string)fgets(STDIN)));
    if ($answer !== 'y' && $answer !== 'yes') {
        fwrite(STDOUT, "Aborted.\n");
        exit(0);
    }
}

try {
    $user->set('usr_password', User::GeneratePassword($password));
    $user->set('usr_force_password_change', true);
    if ($new_email !== '') {
        $user->set('usr_email', $new_email);
    }
    $user->save();
} catch (Exception $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($clear_second_factor) {
    try {
        // disable_totp() also rotates the trusted-device HMAC key, which signs
        // every outstanding "remember this device" grant out at once.
        $user->disable_totp();
    } catch (Exception $e) {
        fwrite(STDERR, 'ERROR: password was reset but clearing the second factor failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$note = $clear_second_factor ? ' (second factor cleared)' : '';
if ($new_email !== '') {
    $note .= ' (address changed to ' . $new_email . ')';
}
error_log('reset_admin_password.php: password reset for ' . $email
    . ' (user ' . $user->get('usr_user_id') . ') by uid ' . (function_exists('posix_geteuid') ? posix_geteuid() : 'unknown')
    . $note);

fwrite(STDOUT, "\nPassword reset for {$email}.\n");
if ($new_email !== '') {
    fwrite(STDOUT, "Account address changed to {$new_email} — sign in with that from now on.\n");
}
fwrite(STDOUT, "They will be asked to choose a new password at their next sign-in.\n");
if ($clear_second_factor) {
    fwrite(STDOUT, "Authenticator app turned off; all trusted devices signed out.\n");
} elseif ($totp_on) {
    fwrite(STDOUT, "Authenticator app left on — a code is still required to finish signing in.\n");
}
exit(0);
