<?php
/** @joinery-test
 * name: outgoing_message_shape
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * What a message the platform sends actually looks like on the wire.
 *
 * PHPMailer's own defaults decide these two headers unless something says
 * otherwise, and both of its defaults are wrong for this platform — so they are
 * pinned here, where a library upgrade or a refactor of SmtpMailer's constructor
 * would otherwise change what every recipient sees with nothing failing.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));

/** The full MIME text SmtpMailer would put on the wire for this message. */
function outgoing_mime(EmailMessage $m): string {
	$mailer = new SmtpMailer();
	$mailer->applyMessage($m);
	$mailer->preSend();
	return $mailer->getSentMIMEMessage();
}

// Non-ASCII in every place a charset decision shows up: display name, subject,
// and both bodies.
$mime = outgoing_mime((new EmailMessage())
	->from('a@example.com', 'Zoë Müller')->to('b@example.com')
	->subject('Tëst — em dash')
	->html('<div>Héllo “world” 🌍</div>')->text('Héllo “world” 🌍'));

// ---------------------------------------------------------------------------
section('The platform does not fingerprint its own mail');
// ---------------------------------------------------------------------------
// PHPMailer stamps "X-Mailer: PHPMailer <version>" when the field is left at its
// empty default. Person-to-person mail carrying a library's name and version
// tells the recipient nothing and tells a spam filter something.

check(stripos($mime, 'X-Mailer') === false, 'no X-Mailer header is emitted');
check(stripos($mime, 'PHPMailer') === false, 'and the library is not named anywhere in the message');

// ---------------------------------------------------------------------------
section('Outgoing mail is UTF-8');
// ---------------------------------------------------------------------------
// PHPMailer defaults to iso-8859-1 and does not transcode — it only labels — so
// the default ships UTF-8 bytes under a label that cannot describe them and the
// recipient reads mojibake. The label has to match what the platform stores.

check(substr_count(strtolower($mime), 'charset=utf-8') >= 2,
	'both body parts declare utf-8');
check(stripos($mime, 'iso-8859-1') === false,
	'and nothing in the message claims iso-8859-1');
check(strpos($mime, '=?utf-8?') !== false,
	'encoded-word headers (subject, display name) carry the same charset');

harness_finish();
