<?php
/**
 * AwsSigV4 — the platform's one AWS Signature Version 4 signer.
 *
 * Pure functions: no network, no clock of their own (the caller passes
 * 'time'). Used by S3Signer (backups and cloud storage), SesProvider and
 * Route53DnsDriver.
 *
 * Inputs common to both entry points:
 *   method, host           host includes a non-default port, as sent
 *   path                   the request path exactly as it goes on the wire
 *                          (already percent-encoded where it needs to be);
 *                          never a URL to re-parse
 *   query                  name => value, unencoded; the signer encodes
 *   headers                name => value, the headers to sign besides host
 *                          and x-amz-date
 *   service, region, access_key, secret_key, session_token (optional)
 *   time                   unix seconds, the caller's clock
 *   double_encode_path     true for every service but S3, which signs the
 *                          path as sent
 *
 * Which headers to sign is the caller's choice. S3 signs everything it sends
 * because S3Signer sends through curl itself. SES and Route 53 sign only
 * host, x-amz-date, x-amz-security-token and content-type, because Guzzle
 * adds User-Agent and Content-Length after signing.
 *
 * @version 1.0 - first version (specs/email_provider_sdk_removal.md, WP3)
 */
class AwsSigV4 {

    const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * Sign a request in its headers. Extra inputs:
     *   payload_hash          hex SHA-256 of the body, or 'UNSIGNED-PAYLOAD'
     *                         (default: the hash of an empty body)
     *   sign_content_sha256   sign and send x-amz-content-sha256 (S3)
     *
     * Returns the headers to add to the request: Authorization, x-amz-date,
     * and x-amz-content-sha256 / x-amz-security-token when they apply.
     */
    public static function signHeaders(array $in): array {
        self::requireInputs($in);
        $time = (int)$in['time'];
        $amz_date = gmdate('Ymd\THis\Z', $time);
        $payload_hash = (string)($in['payload_hash'] ?? hash('sha256', ''));

        $add = array('x-amz-date' => $amz_date);
        if (!empty($in['sign_content_sha256'])) {
            $add['x-amz-content-sha256'] = $payload_hash;
        }
        if (!empty($in['session_token'])) {
            $add['x-amz-security-token'] = (string)$in['session_token'];
        }

        $signed = array('host' => (string)$in['host']);
        foreach ((array)($in['headers'] ?? array()) as $k => $v) {
            $signed[strtolower(trim((string)$k))] = (string)$v;
        }
        foreach ($add as $k => $v) {
            $signed[$k] = $v;
        }
        list($canonical_headers, $signed_names) = self::canonicalHeaders($signed);

        $canonical_request = strtoupper((string)$in['method']) . "\n"
            . self::canonicalPath((string)$in['path'], !empty($in['double_encode_path'])) . "\n"
            . self::canonicalQuery((array)($in['query'] ?? array())) . "\n"
            . $canonical_headers . "\n"
            . $signed_names . "\n"
            . $payload_hash;

        $scope = self::scope($time, (string)$in['region'], (string)$in['service']);
        $signature = self::signature($in, $time, $scope, $canonical_request);

        $add['Authorization'] = self::ALGORITHM . ' Credential=' . $in['access_key'] . '/' . $scope
            . ', SignedHeaders=' . $signed_names . ', Signature=' . $signature;
        return $add;
    }

    /**
     * Sign a request in its query string (a link someone else uses). Extra
     * inputs: expires (seconds), payload_hash (default 'UNSIGNED-PAYLOAD').
     *
     * Returns the whole query string to send, the signature last.
     */
    public static function presignQuery(array $in): string {
        self::requireInputs($in);
        $time = (int)$in['time'];
        $scope = self::scope($time, (string)$in['region'], (string)$in['service']);

        $signed = array('host' => (string)$in['host']);
        foreach ((array)($in['headers'] ?? array()) as $k => $v) {
            $signed[strtolower(trim((string)$k))] = (string)$v;
        }
        list($canonical_headers, $signed_names) = self::canonicalHeaders($signed);

        $params = array(
            'X-Amz-Algorithm'     => self::ALGORITHM,
            'X-Amz-Credential'    => $in['access_key'] . '/' . $scope,
            'X-Amz-Date'          => gmdate('Ymd\THis\Z', $time),
            'X-Amz-Expires'       => (string)(int)($in['expires'] ?? 3600),
            'X-Amz-SignedHeaders' => $signed_names,
        );
        if (!empty($in['session_token'])) {
            $params['X-Amz-Security-Token'] = (string)$in['session_token'];
        }
        foreach ((array)($in['query'] ?? array()) as $k => $v) {
            $params[(string)$k] = (string)$v;
        }
        $canonical_qs = self::canonicalQuery($params);

        $canonical_request = strtoupper((string)$in['method']) . "\n"
            . self::canonicalPath((string)$in['path'], !empty($in['double_encode_path'])) . "\n"
            . $canonical_qs . "\n"
            . $canonical_headers . "\n"
            . $signed_names . "\n"
            . (string)($in['payload_hash'] ?? 'UNSIGNED-PAYLOAD');

        return $canonical_qs . '&X-Amz-Signature=' . self::signature($in, $time, $scope, $canonical_request);
    }

    /**
     * The canonical query string: names and values RFC 3986 encoded, sorted
     * by encoded name, then value. Also the form a caller sends, so what goes
     * on the wire is what was signed.
     */
    public static function canonicalQuery(array $query): string {
        $pairs = array();
        foreach ($query as $k => $v) {
            $pairs[] = array(rawurlencode((string)$k), rawurlencode((string)$v));
        }
        usort($pairs, function ($a, $b) {
            return strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]);
        });
        $out = array();
        foreach ($pairs as $p) {
            $out[] = $p[0] . '=' . $p[1];
        }
        return implode('&', $out);
    }

    // ── internals ───────────────────────────────────────────────────────

    private static function requireInputs(array $in): void {
        foreach (array('method', 'host', 'path', 'service', 'region', 'access_key', 'secret_key', 'time') as $f) {
            if (!isset($in[$f]) || $in[$f] === '') {
                throw new InvalidArgumentException('AwsSigV4: missing ' . $f);
            }
        }
    }

    /**
     * The path as signed. S3 signs the path as sent. Every other service
     * signs each segment encoded once more, so a %2F in a segment signs as
     * %252F.
     */
    private static function canonicalPath(string $path, bool $double_encode): string {
        if ($path === '') {
            return '/';
        }
        if (!$double_encode) {
            return $path;
        }
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** [canonical header lines, signed header names] from name => value. */
    private static function canonicalHeaders(array $headers): array {
        ksort($headers, SORT_STRING);
        $lines = '';
        foreach ($headers as $k => $v) {
            $lines .= $k . ':' . preg_replace('/\s+/', ' ', trim((string)$v)) . "\n";
        }
        return array($lines, implode(';', array_keys($headers)));
    }

    private static function scope(int $time, string $region, string $service): string {
        return gmdate('Ymd', $time) . '/' . $region . '/' . $service . '/aws4_request';
    }

    private static function signature(array $in, int $time, string $scope, string $canonical_request): string {
        $string_to_sign = self::ALGORITHM . "\n" . gmdate('Ymd\THis\Z', $time) . "\n" . $scope . "\n"
            . hash('sha256', $canonical_request);
        $k = hash_hmac('sha256', gmdate('Ymd', $time), 'AWS4' . $in['secret_key'], true);
        $k = hash_hmac('sha256', (string)$in['region'], $k, true);
        $k = hash_hmac('sha256', (string)$in['service'], $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        return hash_hmac('sha256', $string_to_sign, $k);
    }
}
