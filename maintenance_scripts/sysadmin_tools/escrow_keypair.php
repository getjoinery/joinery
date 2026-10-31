<?php
/**
 * escrow_keypair.php — offline recovery-keypair tool for backup-key escrow.
 *
 * The management node can SEAL a node's backup key to a public key, but only this
 * tool — holding the private key kept in the operator's password manager, never
 * on the management node — can UNSEAL it. That is the whole security property:
 * a stolen B2 bucket or a dumped management-node database yields only sealed blobs.
 *
 * Keys are X-Wing (specs/post_quantum_sealing.md): the private key is a 32-byte
 * seed, the public key 1216 bytes. Runs on any machine with PHP's sodium and FFI
 * extensions and OpenSSL 3.5 or later (Ubuntu 26.04 ships it), from a copy of
 * the Joinery tree: it loads includes/MlKem768.php, XWing.php and SealedBox.php
 * from it, three classes with no platform bootstrap, so it works during
 * disaster recovery when the platform is gone. `unseal` also opens a blob made
 * for an old X25519 key, with that key, on libsodium alone. Validate with
 * `php -l` only — never the file validator (this is a CLI with a run-on-include
 * body).
 *
 * Usage:
 *   generate:  php escrow_keypair.php generate --private-out /secure/recovery.key
 *              Writes the private key (base64, mode 0600) to the path you give and
 *              prints ONLY the public key (base64) to stdout. Paste that public key
 *              into the server_manager_escrow_public_key setting, then move the
 *              private key into your password manager and delete the local file.
 *
 *   unseal:    php escrow_keypair.php unseal --private /secure/recovery.key [--in blob.b64] [--scope SCOPE]
 *              Reads a sealed blob (base64) from --in or stdin, decrypts it with the
 *              private key, and prints the recovered backup key to stdout. This is
 *              the DR tool; run it on your own machine, never on the management node.
 *
 * @version 1.1 - X-Wing keys and v2 seals; unseal opens both versions; --scope names a v2 box's
 *                scope (the recovery key's command-line proof has its own; the default is a key seal's, dek)
 * @version 1.0
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "ERROR: the sodium extension is required.\n");
    exit(1);
}

/**
 * Load the tree's X-Wing classes; null when they load and ML-KEM works here,
 * otherwise why not (what is missing, and the floor).
 */
function ek_load_pq(): ?string {
    $inc = dirname(__DIR__, 2) . '/public_html/includes/';
    foreach (['MlKem768.php', 'XWing.php', 'SealedBox.php'] as $f) {
        if (!is_file($inc . $f)) {
            return "{$inc}{$f} is not here: run this tool from a copy of the Joinery tree.";
        }
        require_once $inc . $f;
    }
    $why = MlKem768::unavailableReason();
    return $why === null ? null : $why . ' ' . MlKem768::FLOOR_MESSAGE;
}

function ek_usage($stream = STDERR) {
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php escrow_keypair.php generate --private-out PATH\n");
    fwrite($stream, "  php escrow_keypair.php unseal   --private PATH [--in BLOB_FILE] [--scope SCOPE]\n");
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

    $why = ek_load_pq();
    if ($why !== null) {
        fwrite(STDERR, "ERROR: cannot make a post-quantum key on this machine: {$why}\n");
        exit(1);
    }
    $secret = random_bytes(XWing::SEED_BYTES);
    $public = (new XWing())->publicKey($secret);

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
    if ($secret === false || strlen($secret) !== 32) {
        fwrite(STDERR, "ERROR: private key file is not a valid base64 recovery private key.\n");
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

    // The 32 bytes do not say whether they are an X-Wing seed or an old
    // X25519 key, and the blob carries no frame. Both opens are authenticated,
    // so trying each is safe: a wrong guess fails its tag and opens nothing.
    $plain = false;
    $why = ek_load_pq();
    if ($why === null) {
        try {
            $scope = (isset($opts['scope']) && is_string($opts['scope']) && $opts['scope'] !== '') ? $opts['scope'] : 'dek';
            $plain = SealedBox::openV2($cipher, $secret, $scope);
        } catch (RuntimeException $e) {
            $plain = false;
        }
    }
    if ($plain === false) {
        $public  = sodium_crypto_box_publickey_from_secretkey($secret);
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $public);
        $plain = sodium_crypto_box_seal_open($cipher, $keypair);
        sodium_memzero($keypair);
    }
    sodium_memzero($secret);
    if ($plain === false) {
        fwrite(STDERR, "ERROR: unseal failed — wrong recovery key for this blob, or corrupt blob."
            . ($why !== null ? " A post-quantum blob cannot be opened on this machine: {$why}" : '') . "\n");
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
