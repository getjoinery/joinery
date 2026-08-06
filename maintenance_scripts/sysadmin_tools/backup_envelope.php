<?php
/**
 * backup_envelope.php — mint and open the per-backup data keys that encrypt an
 * archive.
 *
 * Every backup run gets its own random data key. The archive is encrypted with
 * it, and the key itself is sealed to two recipients and stored beside the
 * archive as a JSON sidecar:
 *
 *   recovery - the operator's recovery public key. The private half lives in a
 *              password manager and opens every backup from every site given
 *              the same public key. Always sealed to; without it this tool
 *              refuses to mint.
 *   site     - a keypair the site holds (config/backup_site_key) so it can open
 *              its own backups unattended. Disposable: lose it and recovery
 *              still opens everything.
 *
 * The plaintext data key exists only as a 0600 file for the length of the run.
 * It is never passed in argv (visible in ps) and never crosses a management job
 * row. Nothing on the node stays precious: losing the node loses no ability to
 * read any backup it made.
 *
 * Runs on any machine with PHP + libsodium and NO platform bootstrap, so it
 * works during disaster recovery when the site is gone. Validate with `php -l`
 * only — never the file validator (this is a CLI with a run-on-include body).
 *
 * Usage:
 *   mint:      php backup_envelope.php mint --recovery-pub B64 --artifact NAME \
 *                  --key-out PATH --sidecar-out PATH [--site-key PATH]
 *              Mints a data key, seals it, writes the key file (0600) and the
 *              sidecar (0640, so the account that restores can read it back).
 *              Prints nothing secret.
 *
 *   open:      php backup_envelope.php open --sidecar PATH [--private PATH] [--key-out PATH]
 *              Recovers the data key. --sidecar takes either a standalone
 *              envelope or a chain's manifest.json, which nests one -- a chain
 *              writes no separate sidecar, so the manifest is the only envelope
 *              it has. --private takes the recovery private key or the site key
 *              file; with neither, the key is read from stdin.
 *              Writes to --key-out (0600) or prints to stdout.
 *
 *   relabel:   php backup_envelope.php relabel --sidecar PATH --artifact NAME [--out PATH]
 *              Points an envelope at the archive it belongs to, once the engine
 *              has settled on a filename. Moves only the label.
 *
 *   site-key:  php backup_envelope.php site-key --site-key PATH
 *              Prints the site's PUBLIC key, minting the keypair if absent.
 *
 * The envelope format written here is the same one includes/BackupEnvelope.php
 * reads; backup_envelope_cli_test.php holds both to that contract.
 *
 * @version 1.1 - open accepts a chain manifest, not only a standalone sidecar.
 *                A chain produces no sidecar, so recovering its data key -- the
 *                documented restore path -- was impossible with this tool.
 * @version 1.0
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "ERROR: the sodium extension is required.\n");
    exit(1);
}

const BE_VERSION = 1;
const BE_CIPHER  = 'aes-256-cbc-pbkdf2';

function be_usage($stream = STDERR) {
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php backup_envelope.php mint --recovery-pub B64 --artifact NAME --key-out PATH --sidecar-out PATH [--site-key PATH]\n");
    fwrite($stream, "  php backup_envelope.php open --sidecar PATH|MANIFEST [--private PATH] [--key-out PATH]\n");
    fwrite($stream, "  php backup_envelope.php relabel --sidecar PATH --artifact NAME [--out PATH]\n");
    fwrite($stream, "  php backup_envelope.php site-key --site-key PATH\n");
}

function be_fail($message) {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

/** Minimal --flag value parser. */
function be_opts(array $argv) {
    $opts = [];
    for ($i = 2; $i < count($argv); $i++) {
        $a = $argv[$i];
        if (strpos($a, '--') === 0) {
            $key = substr($a, 2);
            $val = ($i + 1 < count($argv) && strpos($argv[$i + 1], '--') !== 0) ? $argv[++$i] : true;
            $opts[$key] = $val;
        }
    }
    return $opts;
}

function be_require_opt(array $opts, $name) {
    $v = $opts[$name] ?? null;
    if (!is_string($v) || $v === '') {
        be_fail("--{$name} is required.");
    }
    return $v;
}

/**
 * Write restricted, creating nothing world-readable along the way.
 *
 * Defaults to owner-only, which is right for a plaintext data key: one process
 * mints it, uses it, and destroys it. Callers pass 0640 for the two things that
 * outlive the run and get read back by a different account — the site key and
 * the envelope sidecar — since the web user writes those on the scheduled run
 * and the deploy account reads them to restore from a shell.
 */
function be_write_private($path, $contents, $mode = 0600) {
    $old = umask(0077);
    $ok = @file_put_contents($path, $contents);
    umask($old);
    if ($ok === false) {
        be_fail("could not write {$path}.");
    }
    @chmod($path, $mode);
}

/** Decode a base64 public key, or fail with a message that names the mistake. */
function be_public_key($b64, $label) {
    $raw = base64_decode(trim((string)$b64), true);
    if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
        be_fail("the {$label} public key is not a valid base64 box public key.");
    }
    return $raw;
}

/**
 * The site's keypair, minting one on first use.
 *
 * Minting is no-clobber: two runs racing to create the key must not end with
 * one holding a keypair the file no longer names, because the backups it sealed
 * would then open only with the recovery key. link() fails when the destination
 * exists, which is the atomic "only if absent" that rename() does not give us;
 * the loser re-reads the winner's file.
 */
function be_site_keypair($path) {
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        // Existing-but-unreadable is not the same as absent: falling through to
        // the mint below would write a second key over a live one and orphan
        // the site recipient for every backup sealed to the first.
        if ($raw === false) {
            be_fail("the site key at {$path} exists but is not readable by "
                . (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user')
                    : 'this user')
                . ". Run fix_permissions.sh, or omit --site-key to seal to recovery alone.");
        }
        if (trim($raw) !== '') {
            $kp = base64_decode(trim($raw), true);
            if ($kp === false || strlen($kp) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
                be_fail("the site key at {$path} is not a valid keypair. Move it aside to have a new one minted; "
                    . "backups already made stay openable with the recovery key.");
            }
            return $kp;
        }
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        be_fail("the site key directory {$dir} does not exist.");
    }

    $kp  = sodium_crypto_box_keypair();
    $tmp = $path . '.' . getmypid() . '.tmp';
    be_write_private($tmp, base64_encode($kp), 0640);

    if (@link($tmp, $path)) {
        @unlink($tmp);
        return $kp;
    }
    @unlink($tmp);

    $raw = @file_get_contents($path);
    $won = ($raw === false) ? false : base64_decode(trim($raw), true);
    if ($won === false || strlen($won) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
        be_fail("could not create the site key at {$path}.");
    }
    return $won;
}

/** Coerce a supplied secret into a sodium keypair (raw or base64, keypair or secret key). */
function be_identity($secret) {
    $raw = trim((string)$secret);
    if ($raw === '') {
        be_fail('no key supplied.');
    }
    if (!in_array(strlen($secret), [SODIUM_CRYPTO_BOX_KEYPAIRBYTES, SODIUM_CRYPTO_BOX_SECRETKEYBYTES], true)) {
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && $decoded !== '') {
            $raw = $decoded;
        }
    } else {
        $raw = $secret;
    }
    if (strlen($raw) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
        return $raw;
    }
    if (strlen($raw) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
        return sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $raw, sodium_crypto_box_publickey_from_secretkey($raw));
    }
    be_fail('that is not a recovery key. Expected the base64 secret key or keypair, not a file path.');
}

$mode = $argv[1] ?? '';

// ---------------------------------------------------------------------- mint

if ($mode === 'mint') {
    $opts        = be_opts($argv);
    $artifact    = be_require_opt($opts, 'artifact');
    $key_out     = be_require_opt($opts, 'key-out');
    $sidecar_out = be_require_opt($opts, 'sidecar-out');
    $recovery    = be_public_key(be_require_opt($opts, 'recovery-pub'), 'recovery');

    $recipients = [['kind' => 'recovery', 'pub' => $recovery]];

    // The site recipient is what lets the site restore itself unattended. It is
    // optional only because a bare archive job may run somewhere with no site
    // config directory; recovery alone still opens the result.
    $site_key_path = $opts['site-key'] ?? null;
    if (is_string($site_key_path) && $site_key_path !== '') {
        $site_kp = be_site_keypair($site_key_path);
        $recipients[] = ['kind' => 'site', 'pub' => sodium_crypto_box_publickey($site_kp)];
    }

    $data_key = base64_encode(random_bytes(32));

    $sealed = [];
    foreach ($recipients as $r) {
        $sealed[] = [
            'kind'        => $r['kind'],
            'fingerprint' => hash('sha256', $r['pub']),
            'sealed'      => base64_encode(sodium_crypto_box_seal($data_key, $r['pub'])),
        ];
    }

    $envelope = [
        'version'    => BE_VERSION,
        'artifact'   => $artifact,
        'cipher'     => BE_CIPHER,
        'created'    => gmdate('Y-m-d\TH:i:s\Z'),
        'recipients' => $sealed,
    ];

    $json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        be_fail('could not encode the backup envelope.');
    }

    be_write_private($key_out, $data_key);
    be_write_private($sidecar_out, $json . "\n", 0640);

    // Names only — the caller needs to know where things landed, and neither of
    // these lines may ever carry key material into a job output row.
    fwrite(STDOUT, "ENVELOPE_KEY_FILE={$key_out}\n");
    fwrite(STDOUT, "ENVELOPE_SIDECAR={$sidecar_out}\n");
    fwrite(STDOUT, 'ENVELOPE_RECIPIENTS=' . implode(',', array_column($sealed, 'kind')) . "\n");
    exit(0);
}

// ---------------------------------------------------------------------- open

if ($mode === 'open') {
    $opts    = be_opts($argv);
    $sidecar = be_require_opt($opts, 'sidecar');

    $raw = @file_get_contents($sidecar);
    if ($raw === false) {
        be_fail("could not read the backup envelope at {$sidecar}.");
    }
    $envelope = json_decode($raw, true);
    if (!is_array($envelope)) {
        be_fail('this backup envelope is not readable JSON.');
    }

    // A chain writes one manifest.json and no separate sidecar, and carries its
    // envelope nested under "envelope". Accept either shape: this is the tool the
    // restore instructions send people to for a chain's data key, and at disaster
    // time nobody should have to know which of the two files they are holding.
    // Unwrap BEFORE the version check, so the version compared is the envelope's
    // own rather than the manifest's — they are separate formats that are free to
    // diverge, and comparing the wrong one would reject a readable envelope.
    if (isset($envelope['envelope']) && is_array($envelope['envelope'])) {
        $envelope = $envelope['envelope'];
    }

    if ((int)($envelope['version'] ?? 0) !== BE_VERSION) {
        be_fail('unsupported backup envelope version ' . (int)($envelope['version'] ?? 0)
            . '; this build reads version ' . BE_VERSION . '.');
    }
    $recipients = $envelope['recipients'] ?? null;
    if (!is_array($recipients) || !$recipients) {
        be_fail('this backup envelope lists no recipients.');
    }

    $private_path = $opts['private'] ?? null;
    if (is_string($private_path) && $private_path !== '') {
        $secret = @file_get_contents($private_path);
        if ($secret === false) {
            be_fail("could not read the key file at {$private_path}.");
        }
    } else {
        $secret = stream_get_contents(STDIN);
    }
    $secret = trim((string)$secret);
    if ($secret === '') {
        be_fail('no key supplied (use --private PATH or pipe the key on stdin).');
    }

    // A public key is the same 32 bytes as a secret key, so only the envelope
    // can tell them apart — and the two mistakes need opposite fixes.
    $probe = base64_decode($secret, true);
    if ($probe !== false && strlen($probe) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
        foreach ($recipients as $r) {
            if (hash_equals((string)($r['fingerprint'] ?? ''), hash('sha256', $probe))) {
                be_fail('that is the PUBLIC half of the ' . (string)($r['kind'] ?? 'recovery') . ' key — it seals '
                    . 'backups but cannot open them. Supply the private key you saved when the keypair was generated.');
            }
        }
    }

    $keypair = be_identity($secret);

    $data_key = false;
    foreach ($recipients as $r) {
        $blob = base64_decode((string)($r['sealed'] ?? ''), true);
        if ($blob === false || $blob === '') {
            continue;
        }
        $opened = @sodium_crypto_box_seal_open($blob, $keypair);
        if ($opened !== false) {
            $data_key = $opened;
            break;
        }
    }

    if ($data_key === false) {
        be_fail('that key does not open this backup. The envelope is sealed to: '
            . implode(', ', array_column($recipients, 'kind')) . '.');
    }

    $key_out = $opts['key-out'] ?? null;
    if (is_string($key_out) && $key_out !== '') {
        be_write_private($key_out, $data_key);
        fwrite(STDOUT, "ENVELOPE_KEY_FILE={$key_out}\n");
    } else {
        fwrite(STDOUT, $data_key . "\n");
    }
    exit(0);
}

// ------------------------------------------------------------------- relabel

// The data key has to exist before the backup runs, but the archive's name is
// only settled once the engine has written it (the engine stamps its own
// timestamp). So the envelope is minted under a working name and renamed to sit
// beside the finished archive. Only the label moves — the sealed keys are
// untouched, so this can never invalidate an envelope.
if ($mode === 'relabel') {
    $opts     = be_opts($argv);
    $sidecar  = be_require_opt($opts, 'sidecar');
    $artifact = be_require_opt($opts, 'artifact');
    $out      = $opts['out'] ?? ($artifact . '.keys.json');

    $raw = @file_get_contents($sidecar);
    if ($raw === false) {
        be_fail("could not read the backup envelope at {$sidecar}.");
    }
    $envelope = json_decode($raw, true);
    if (!is_array($envelope) || !is_array($envelope['recipients'] ?? null)) {
        be_fail('this backup envelope is not readable JSON.');
    }

    $envelope['artifact'] = basename($artifact);
    $json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        be_fail('could not encode the backup envelope.');
    }

    be_write_private($out, $json . "\n", 0640);
    if (realpath($sidecar) !== realpath($out)) {
        @unlink($sidecar);
    }
    fwrite(STDOUT, "ENVELOPE_SIDECAR={$out}\n");
    exit(0);
}

// ------------------------------------------------------------------ site-key

if ($mode === 'site-key') {
    $opts = be_opts($argv);
    $path = be_require_opt($opts, 'site-key');
    $kp   = be_site_keypair($path);
    fwrite(STDOUT, base64_encode(sodium_crypto_box_publickey($kp)) . "\n");
    exit(0);
}

be_usage();
exit(1);
