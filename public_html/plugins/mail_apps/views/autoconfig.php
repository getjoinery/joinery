<?php
/**
 * Thunderbird autoconfig (specs/mail_apps_plugin.md § Finding the server).
 *
 * A mail program given only an address looks for its settings at
 *   https://autoconfig.{maildomain}/mail/config-v1.1.xml
 *   https://{maildomain}/.well-known/autoconfig/mail/config-v1.1.xml
 * (core routes both here while this plugin is active). Answers only for a mail
 * domain this site hosts and only while mail apps are allowed; anything else
 * is a plain 404, so the file says nothing about other domains. No sign-in:
 * the settings are the ones the Mail apps page shows anyone holding a
 * mailbox here — this site's host name, IMAP 993 and SMTP 465 over TLS, the
 * mailbox address as username, a normal password (the app password).
 *
 * @version 1.0.0
 */

$settings = Globalvars::get_instance();
$address = strtolower(trim((string)($_GET['emailaddress'] ?? '')));
$domain = '';
if ($address !== '' && strpos($address, '@') !== false) {
	$domain = substr(strrchr($address, '@'), 1);
} else {
	$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
	$host = preg_replace('/:\d+$/', '', $host);
	$domain = strpos($host, 'autoconfig.') === 0 ? substr($host, strlen('autoconfig.')) : $host;
}

$known = false;
if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/', $domain) && $settings->get_setting('mail_apps_enabled')) {
	$row = InboundEmailDomain::GetByDomain($domain);
	$known = $row && $row->key && $row->get('ied_is_enabled');
}
if (!$known) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	echo "Not found\n";
	exit;
}

$server = MailAppGateway::servedName(); // the mail host name programs connect to
$site_name = trim((string)$settings->get_setting('site_name')) ?: $server;
$x = function ($v) { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<clientConfig version="1.1">
  <emailProvider id="<?php echo $x($domain); ?>">
    <domain><?php echo $x($domain); ?></domain>
    <displayName><?php echo $x($site_name); ?></displayName>
    <displayShortName><?php echo $x($site_name); ?></displayShortName>
    <incomingServer type="imap">
      <hostname><?php echo $x($server); ?></hostname>
      <port>993</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <outgoingServer type="smtp">
      <hostname><?php echo $x($server); ?></hostname>
      <port>465</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
    <documentation url="<?php echo $x(LibraryFunctions::get_absolute_url('/profile/mail_apps')); ?>">
      <descr lang="en">Make an app password on your Mail apps page, and use it as the password.</descr>
    </documentation>
  </emailProvider>
</clientConfig>
<?php
exit;
