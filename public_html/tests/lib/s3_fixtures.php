<?php
/**
 * A local S3-compatible provider for the backup suites.
 *
 * `php -S` on a loopback port speaking just enough of the S3 REST surface for
 * S3Signer's callers: PutObject, GetObject, HeadObject, DeleteObject,
 * ListObjectsV2 (prefix, one page), and the multipart family — create returns
 * an UploadId, each part is stored under its number and answered with an
 * ETag, complete assembles the parts into the object, abort is recorded.
 *
 * Objects land under {dir}/objects/ so a test can read back exactly what the
 * bucket holds; every call family bumps a {name}.count file. Failures are
 * injected through the environment the server is started with:
 *
 *   FIXTURE_FAIL_PART=n        UploadPart n answers 400 (every attempt)
 *   FIXTURE_COMPLETE_ERRORS=n  the first n CompleteMultipartUpload calls answer
 *                              HTTP 200 with an <Error> body (the trap)
 *   FIXTURE_FAIL_PUT=1         every plain PutObject answers 400
 *   FIXTURE_ANON_READ=1        a GET/HEAD with no Authorization header and no
 *                              X-Amz-Signature answers as a public bucket would;
 *                              without it such a read is refused with 403
 *   FIXTURE_WRITE_ONLY_KEY=1   a DELETE signed by a key id starting "wo-" is
 *                              refused with 403 (a write-only key)
 *
 * Keep request bodies under 1 KB: above that curl sends `Expect: 100-continue`,
 * which the built-in server never answers, costing a second of dead wait each.
 *
 *   $fx = s3fx_start();               // null when no loopback server could start
 *   harness_defer(function() use ($fx) { s3fx_stop($fx); });
 *   $creds = s3fx_creds($fx);
 *   ... S3Signer::put_stream($creds, 'bucket', '/k', $fh) ...
 *   s3fx_object($fx, 'bucket', '/k');  // the bytes, or null
 *   s3fx_count($fx, 'complete');       // how many completes were seen
 *
 * @version 1.1 - anonymous reads refused unless FIXTURE_ANON_READ; write-only key by id prefix
 * @version 1.0
 */

function s3fx_start(array $env = array()) {
	$port = s3fx_free_port();
	if ($port === null) { return null; }

	$dir = sys_get_temp_dir() . '/s3fx_' . getmypid() . '_' . $port;
	@mkdir($dir . '/objects', 0777, true);
	@mkdir($dir . '/parts', 0777, true);
	if (!is_dir($dir . '/objects')) { return null; }

	$router = $dir . '/router.php';
	file_put_contents($router, s3fx_router_source());

	$env_str = '';
	foreach ($env as $k => $v) { $env_str .= $k . '=' . (int)$v . ' '; }
	$cmd = sprintf('%sexec php -S 127.0.0.1:%d %s', $env_str, $port, escapeshellarg($router));
	$descriptors = array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w'));
	$proc = proc_open(array('bash', '-c', $cmd), $descriptors, $pipes);
	if (!is_resource($proc)) { return null; }

	for ($i = 0; $i < 100; $i++) {
		$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
		if ($sock) { fclose($sock); return array('proc' => $proc, 'port' => $port, 'dir' => $dir); }
		usleep(50000);
	}
	proc_terminate($proc);
	return null;
}

function s3fx_stop($fixture) {
	if (!is_array($fixture)) { return; }
	if (is_resource($fixture['proc'])) { proc_terminate($fixture['proc']); proc_close($fixture['proc']); }
	foreach (array('/objects', '/parts') as $sub) {
		foreach (glob($fixture['dir'] . $sub . '/*') ?: array() as $f) { @unlink($f); }
		@rmdir($fixture['dir'] . $sub);
	}
	foreach (glob($fixture['dir'] . '/*') ?: array() as $f) { @unlink($f); }
	@rmdir($fixture['dir']);
}

/** Credentials S3Signer accepts, pointed at the fixture. */
function s3fx_creds($fixture) {
	return array(
		'access_key' => 'TESTKEY', 'secret_key' => 'TESTSECRET',
		'region' => 'us-east-005', 'endpoint' => 'http://127.0.0.1:' . $fixture['port'],
	);
}

function s3fx_count($fixture, $name) {
	return (int)@file_get_contents($fixture['dir'] . '/' . $name . '.count');
}

/** The object's bytes as the fixture holds them, or null when there is no such object. */
function s3fx_object($fixture, $bucket, $path) {
	$v = @file_get_contents(s3fx_object_file($fixture['dir'], $bucket, $path));
	return ($v === false) ? null : $v;
}

/** Every stored key ('bucket/key'), sorted. */
function s3fx_keys($fixture) {
	$out = array();
	foreach (glob($fixture['dir'] . '/objects/*.key') ?: array() as $k) {
		$out[] = (string)file_get_contents($k);
	}
	sort($out);
	return $out;
}

function s3fx_object_file($dir, $bucket, $path) {
	return $dir . '/objects/' . sha1($bucket . '/' . ltrim($path, '/'));
}

function s3fx_free_port() {
	$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if (!$sock) { return null; }
	$name = stream_socket_get_name($sock, false);
	fclose($sock);
	$port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
	return $port > 0 ? $port : null;
}

function s3fx_router_source() {
	return <<<'ROUTER'
<?php
$dir = __DIR__;
$q = $_GET;
$method = $_SERVER["REQUEST_METHOD"];
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
// Path-style: /{bucket}/{key}. The bucket is the first segment.
$trim = ltrim($uri, "/");
$slash = strpos($trim, "/");
$bucket = $slash === false ? $trim : substr($trim, 0, $slash);
$key = $slash === false ? "" : substr($trim, $slash + 1);
$key = rawurldecode($key);
$full = $bucket . "/" . $key;
$file = $dir . "/objects/" . sha1($full);
$bump = function($name) use ($dir) {
	$n = (int)@file_get_contents($dir . "/" . $name . ".count") + 1;
	file_put_contents($dir . "/" . $name . ".count", (string)$n);
	return $n;
};
$store = function($bytes) use ($file, $full) {
	file_put_contents($file, $bytes);
	file_put_contents($file . ".key", $full);
};
header("Content-Type: application/xml");

// Who is asking: a SigV4 header names the key id; a presigned link carries
// X-Amz-Signature; anything else is anonymous.
$auth = (string)($_SERVER["HTTP_AUTHORIZATION"] ?? "");
$key_id = preg_match("#Credential=([^/]+)/#", $auth, $cm) ? $cm[1] : "";
$anonymous = $auth === "" && !isset($q["X-Amz-Signature"]);
if ($anonymous && ($method === "GET" || $method === "HEAD") && (int)getenv("FIXTURE_ANON_READ") !== 1) {
	$bump("anonymous");
	http_response_code(403);
	echo "<?xml version=\"1.0\"?><Error><Code>AccessDenied</Code><Message>anonymous read refused</Message></Error>";
	return true;
}

if ($method === "POST" && array_key_exists("uploads", $q)) {
	$n = $bump("create");
	echo "<?xml version=\"1.0\"?><InitiateMultipartUploadResult><UploadId>fixture-upload-" . $n . "</UploadId></InitiateMultipartUploadResult>";
	return true;
}
if ($method === "PUT" && isset($q["partNumber"], $q["uploadId"])) {
	$n = (int)$q["partNumber"];
	$bump("part");
	if ($n === (int)getenv("FIXTURE_FAIL_PART")) {
		http_response_code(400);
		echo "<?xml version=\"1.0\"?><Error><Code>InvalidPart</Code><Message>part refused</Message></Error>";
		return true;
	}
	file_put_contents($dir . "/parts/" . $q["uploadId"] . "." . $n, file_get_contents("php://input"));
	header("ETag: \"etag-part-" . $n . "\"");
	return true;
}
if ($method === "POST" && isset($q["uploadId"])) {
	$n = $bump("complete");
	if ($n <= (int)getenv("FIXTURE_COMPLETE_ERRORS")) {
		echo "<?xml version=\"1.0\"?><Error><Code>InternalError</Code><Message>We encountered an internal error. Please try again.</Message></Error>";
		return true;
	}
	$assembled = "";
	for ($i = 1; is_file($dir . "/parts/" . $q["uploadId"] . "." . $i); $i++) {
		$assembled .= file_get_contents($dir . "/parts/" . $q["uploadId"] . "." . $i);
		unlink($dir . "/parts/" . $q["uploadId"] . "." . $i);
	}
	$store($assembled);
	// Kept for suites written against the older single-object fixture.
	file_put_contents($dir . "/assembled", $assembled);
	echo "<?xml version=\"1.0\"?><CompleteMultipartUploadResult><ETag>\"final\"</ETag></CompleteMultipartUploadResult>";
	return true;
}
if ($method === "DELETE" && isset($q["uploadId"])) {
	$bump("abort");
	foreach (glob($dir . "/parts/" . $q["uploadId"] . ".*") ?: array() as $p) { unlink($p); }
	http_response_code(204);
	return true;
}
if ($method === "PUT") {
	$bump("put");
	if ((int)getenv("FIXTURE_FAIL_PUT") === 1) {
		http_response_code(400);
		echo "<?xml version=\"1.0\"?><Error><Code>AccessDenied</Code><Message>put refused</Message></Error>";
		return true;
	}
	$store(file_get_contents("php://input"));
	header("ETag: \"" . md5_file($file) . "\"");
	return true;
}
if ($method === "GET" && $key === "" && isset($q["list-type"])) {
	$bump("list");
	$prefix = (string)($q["prefix"] ?? "");
	$xml = "<?xml version=\"1.0\"?><ListBucketResult><IsTruncated>false</IsTruncated>";
	$keys = array();
	foreach (glob($dir . "/objects/*.key") ?: array() as $k) {
		$stored = (string)file_get_contents($k);
		if (strpos($stored, $bucket . "/") !== 0) { continue; }
		$rel = substr($stored, strlen($bucket) + 1);
		if ($prefix !== "" && strpos($rel, $prefix) !== 0) { continue; }
		$keys[$rel] = substr($k, 0, -4);
	}
	ksort($keys);
	foreach ($keys as $rel => $f) {
		$xml .= "<Contents><Key>" . htmlspecialchars($rel, ENT_XML1) . "</Key><Size>" . filesize($f) . "</Size>"
			. "<LastModified>" . gmdate("Y-m-d\\TH:i:s.000\\Z", filemtime($f)) . "</LastModified></Contents>";
	}
	echo $xml . "</ListBucketResult>";
	return true;
}
if ($method === "GET") {
	$bump("get");
	if (!is_file($file)) {
		http_response_code(404);
		echo "<?xml version=\"1.0\"?><Error><Code>NoSuchKey</Code><Message>no such key</Message></Error>";
		return true;
	}
	header("Content-Type: application/octet-stream");
	header("Content-Length: " . filesize($file));
	readfile($file);
	return true;
}
if ($method === "HEAD") {
	$bump("head");
	if (!is_file($file)) { http_response_code(404); return true; }
	header("Content-Length: " . filesize($file));
	header("ETag: \"" . md5_file($file) . "\"");
	return true;
}
if ($method === "DELETE") {
	$bump("delete");
	if ((int)getenv("FIXTURE_WRITE_ONLY_KEY") === 1 && strpos($key_id, "wo-") === 0) {
		http_response_code(403);
		echo "<?xml version=\"1.0\"?><Error><Code>AccessDenied</Code><Message>this key cannot delete</Message></Error>";
		return true;
	}
	@unlink($file);
	@unlink($file . ".key");
	http_response_code(204);
	return true;
}
http_response_code(500);
echo "<?xml version=\"1.0\"?><Error><Code>Unexpected</Code><Message>fixture saw an unexpected request</Message></Error>";
return true;
ROUTER;
}
