<?php
/** @joinery-test
 * name: log_redactor
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * LogRedactor masks what the agent's redact package masks, and leaves what it
 * leaves.
 *
 * The cases below are the agent's own redact_test.go fixtures, ported line for
 * line: the same input must come out the same on the node (Go) and on the
 * site (PHP), or a problem report and a node log would disagree about what is
 * private. Each class of thing masked is pinned, and so is each thing left
 * alone, because a redactor that eats the diagnosis is as useless as one that
 * leaks it.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/log_redactor_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$M = LogRedactor::MASK;

function lr_cases($title, array $cases) {
	section($title);
	foreach ($cases as $in => $want) {
		$got = LogRedactor::text($in);
		check($got === $want, $in, 'got ' . var_export($got, true) . ', want ' . var_export($want, true));
	}
}

lr_cases('Credential values are masked by key name', array(
	"'password' => 'hunter2'"                      => "'password' => '$M'",
	'"token": "abc.def"'                           => "\"token\": \"$M\"",
	'"secret_key":"s3cr3t"'                        => "\"secret_key\":\"$M\"",
	"'credentials_b64' => 'QUJD'"                  => "'credentials_b64' => '$M'",
	"'credentials' => 'x'"                         => "'credentials' => '$M'",
	'PGPASSWORD=hunter2 psql'                      => "PGPASSWORD=$M psql",
	'AWS_SECRET_ACCESS_KEY="a b"'                  => "AWS_SECRET_ACCESS_KEY=$M",
	"export GITHUB_TOKEN='t'"                      => "export GITHUB_TOKEN=$M",
	'API_KEY=k1'                                   => "API_KEY=$M",
	'password=hunter2 end'                         => "password=$M end",
	'dsn user=y;dbpassword=z;dbname=x'             => "dsn user=y;dbpassword=$M",
	'?csrf_token=abc&x=1'                          => "?csrf_token=$M",
	'api_key=sk_live_1'                            => "api_key=$M",
	'client_secret=s'                              => "client_secret=$M",
	'primary_key=usr_user_id'                      => 'primary_key=usr_user_id',
	'--key=/root/.ssh/id'                          => '--key=/root/.ssh/id',
	'cache_key=abc123'                             => 'cache_key=abc123',
	'ssh_key_path=/root/.ssh'                      => 'ssh_key_path=/root/.ssh',
	'secret-key: abcdef'                           => "secret-key: $M",
	'--clone-key=deadbeef'                         => "--clone-key=$M",
	'Authorization: Bearer eyJhbGciOi.payload.sig' => "Authorization: Bearer $M",
));

section('Every pinned key is masked');
foreach (LogRedactor::secretKeys() as $k) {
	$got = LogRedactor::text("'" . $k . "' => 'value'");
	check(strpos($got, $M) !== false && strpos($got, 'value') === false, "key $k", $got);
}

lr_cases('A URL keeps its user and loses its password', array(
	'pgsql://joinery:hunter2@db.example.com/site' => "pgsql://joinery:$M@db.example.com/site",
));

lr_cases('An email address keeps its domain', array(
	'could not send to jane.doe+x@Example.co.uk from bounce@mail.example.com'
		=> 'could not send to <email>@Example.co.uk from <email>@mail.example.com',
));

lr_cases('IP literals are masked', array(
	'client 203.0.113.9 denied'               => 'client <ip> denied',
	'listen 127.0.0.1:5432'                   => 'listen <ip>:5432',
	'from 2001:db8:85a3:0:0:8a2e:370:7334 ok' => 'from <ip> ok',
	'from 2001:db8::1 ok'                     => 'from <ip> ok',
	'from fe80::1%eth0'                       => 'from <ip>%eth0',
	'bind ::1'                                => 'bind <ip>',
));

lr_cases('Opaque tokens are masked', array(
	'sha 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08 done' => 'sha <token> done',
	'key=QWxhZGRpbjpvcGVuIHNlc2FtZTEyMzQ1Njc4OTA end'                           => 'key=<token> end',
	"key='QWxhZGRpbjpvcGVuIHNlc2FtZTEyMzQ1Njc4OTA=' end"                        => "key='<token>' end",
));

section('What is not masked');
$keep = array(
	'upgraded to 0.8.408 at 2026-09-17 23:10:00',
	'2026-09-17T23:10:00Z [error] PHP Warning',
	'in /var/www/html/joinerytest/public_html/includes/ThemeHelper.php on line 331',
	'setting server_manager_fleet_backup_window_start is unset',
	'class ManagedDomainNoticeRendererFactoryImplementation not found',
	'ssh_key_path=/root/.ssh/id_ed25519',
	'SSH_KEY_PATH=/root/.ssh/id_ed25519',
	'mac aa:bb:cc:dd:ee:ff',
	'release 1.34.0 running since 12:00:01',
	"the word 'token' appears here without a value",
	'abcdefghijklmnopqrstuvwxyzabcdefghijkl',
	'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
	'1234567890123456789012345678901234567890',
	'PathHelper::getThemeFilePath found it',
	'bind [::]:443 (the any-address names nobody)',
	'DbConnector::get_instance()->get_db_link()',
);
foreach ($keep as $in) {
	$got = LogRedactor::text($in);
	check($got === $in, 'left alone: ' . $in, $got);
}

section('Empty, plain and non-string input');
check(LogRedactor::text('') === '', 'empty in, empty out');
check(LogRedactor::text('nothing here') === 'nothing here', 'plain text passes through');
check(LogRedactor::text(null) === null && LogRedactor::text(42) === 42, 'a non-string is returned as is');

section('fields() recurses');
$m = LogRedactor::fields(array(
	'text'   => 'user jane@example.com',
	'count'  => 3,
	'ok'     => true,
	'nested' => array('note' => 'PGPASSWORD=x'),
	'rows'   => array(array('note' => 'from 10.0.0.1'), 'bare 10.0.0.2'),
));
check($m['text'] === 'user <email>@example.com', 'a top-level string is masked');
check($m['count'] === 3 && $m['ok'] === true, 'non-strings are untouched');
check($m['nested']['note'] === "PGPASSWORD=$M", 'a nested string is masked');
check($m['rows'][0]['note'] === 'from <ip>' && $m['rows'][1] === 'bare <ip>', 'a list is masked');

section('secrets() masks credentials only');
check(LogRedactor::secrets("'password' => 'x' jane@example.com 10.0.0.1")
		=== "'password' => '$M' jane@example.com 10.0.0.1",
	'secrets() leaves email addresses and IPs; text() is the full pass');

harness_finish();
