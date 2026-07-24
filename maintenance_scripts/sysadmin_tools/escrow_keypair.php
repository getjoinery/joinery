<?php
/**
 * escrow_keypair.php — offline recovery-keypair tool for backup-key escrow.
 *
 * The control plane can SEAL a node's backup key to a public key, but only this
 * tool — holding the private key kept in the operator's password manager, never
 * on the control plane — can UNSEAL it. That is the whole security property:
 * a stolen B2 bucket or a dumped control-plane database yields only sealed blobs.
 *
 * Runs on any machine with PHP + libsodium. It is intentionally standalone (no
 * platform bootstrap) so it works during disaster recovery when the platform is
 * gone. Validate with `php -l` only — never the file validator (this is a CLI
 * with a run-on-include body).
 *
 * Usage:
 *   generate:  php escrow_keypair.php generate --private-out /secure/recovery.key
 *              Writes the private key (base64, mode 0600) to the path you give and
 *              prints ONLY the public key (base64) to stdout. Paste that public key
 *              into the server_manager_escrow_public_key setting, then move the
 *              private key into your password manager and delete the local file.
 *
 *   unseal:    php escrow_keypair.php unseal --private /secure/recovery.key [--in blob.b64]
 *              Reads a sealed blob (base64) from --in or stdin, decrypts it with the
 *              private key, and prints the recovered backup key to stdout. This is
 *              the DR tool; run it on your own machine, never on the control plane.
 *
 * @version 1.0
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "ERROR: the sodium extension is required.\n");
    exit(1);
}

function ek_usage($stream = STDERR) {
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php escrow_keypair.php generate --private-out PATH\n");
    fwrite($stream, "  php escrow_keypair.php unseal   --private PATH [--in BLOB_FILE]\n");
}

/** Minimal --flag value parser. */
function ek_opts(array $argv) {
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

$mode = $argv[1] ?? '';

if ($mode === 'generate') {
    $opts = ek_opts($argv);
    $out  = $opts['private-out'] ?? null;
    if (!is_string($out) || $out === '') {
        fwrite(STDERR, "ERROR: --private-out PATH is required.\n");
        ek_usage();
        exit(2);
    }
    if (file_exists($out)) {
        fwrite(STDERR, "ERROR: refusing to overwrite existing file: {$out}\n");
        exit(2);
    }

    $keypair = sodium_crypto_box_keypair();
    $secret  = sodium_crypto_box_secretkey($keypair);
    $public  = sodium_crypto_box_publickey($keypair);

    // Create the file with 0600 BEFORE writing any key material into it, so the
    // secret is never briefly world-readable.
    $fh = fopen($out, 'wb');
    if ($fh === false) {
        fwrite(STDERR, "ERROR: cannot open {$out} for writing.\n");
        exit(1);
    }
    fclose($fh);
    if (!chmod($out, 0600)) {
        @unlink($out);
        fwrite(STDERR, "ERROR: cannot set 0600 on {$out}.\n");
        exit(1);
    }
    if (file_put_contents($out, base64_encode($secret)) === false) {
        @unlink($out);
        fwrite(STDERR, "ERROR: failed to write private key to {$out}.\n");
        exit(1);
    }

    // Public key is the ONLY thing printed. The private key never touches stdout.
    echo base64_encode($public) . "\n";
    fwrite(STDERR, "Private key written to {$out} (mode 0600).\n");
    fwrite(STDERR, "Paste the public key above into server_manager_escrow_public_key,\n");
    fwrite(STDERR, "then move {$out} into your password manager and delete the local file.\n");
    sodium_memzero($secret);
    sodium_memzero($keypair);
    exit(0);
}

if ($mode === 'unseal') {
    $opts     = ek_opts($argv);
    $priv_path = $opts['private'] ?? null;
    if (!is_string($priv_path) || !is_file($priv_path)) {
        fwrite(STDERR, "ERROR: --private PATH to the recovery private key is required.\n");
        ek_usage();
        exit(2);
    }

    $priv_b64 = trim((string)file_get_contents($priv_path));
    $secret   = base64_decode($priv_b64, true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
        fwrite(STDERR, "ERROR: private key file is not a valid base64 box secret key.\n");
        exit(1);
    }

    if (isset($opts['in']) && is_string($opts['in'])) {
        $blob_b64 = (string)file_get_contents($opts['in']);
    } else {
        $blob_b64 = (string)stream_get_contents(STDIN);
    }
    $blob_b64 = trim($blob_b64);
    $cipher   = base64_decode($blob_b64, true);
    if ($cipher === false || $cipher === '') {
        fwrite(STDERR, "ERROR: no valid base64 sealed blob provided (--in FILE or stdin).\n");
        exit(1);
    }

    $public  = sodium_crypto_box_publickey_from_secretkey($secret);
    $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $public);

    $plain = sodium_crypto_box_seal_open($cipher, $keypair);
    sodium_memzero($secret);
    sodium_memzero($keypair);
    if ($plain === false) {
        fwrite(STDERR, "ERROR: unseal failed — wrong recovery key for this blob, or corrupt blob.\n");
        exit(1);
    }

    // The recovered backup key is the deliverable — print it (this tool exists to
    // surface it during recovery). Nothing else goes to stdout.
    echo $plain . "\n";
    sodium_memzero($plain);
    exit(0);
}

ek_usage();
exit(2);
