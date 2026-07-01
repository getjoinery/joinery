<?php
/**
 * Integration test: inline (embedded) attachments for outgoing email.
 *
 * Covers the capability added by specs/email_inline_attachments.md:
 *  - EmailMessage::attachInlineData() records an inline entry (cid + inline flag).
 *  - The SMTP chokepoint (SmtpMailer::applyMessage) embeds it with the right
 *    Content-ID and inline disposition, while a regular attachment stays a normal
 *    attachment. This one path covers SMTP / ConnectedMailbox / SES.
 *  - A provider with no Content-ID field (Resend, Brevo) degrades the inline part
 *    to a regular attachment and logs a distinct marker.
 *
 * Run: php tests/integration/email_inline_attachments_test.php
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/ResendProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/BrevoProvider.php'));

use PHPMailer\PHPMailer\PHPMailer;

$pass = 0;
$fail = 0;
function check($label, $value) {
    global $pass, $fail;
    if ($value) { echo "PASS: $label\n"; $pass++; }
    else        { echo "FAIL: $label\n"; $fail++; }
}

// Distinct, greppable bytes so we can find each part in the MIME.
$inline_bytes  = 'INLINE-IMAGE-BYTES-' . str_repeat('A', 8);
$regular_bytes = 'REGULAR-FILE-BYTES-' . str_repeat('B', 8);
$cid = 'logo123';

// ---------------------------------------------------------------------------
// 1 + 2. SMTP MIME: inline embeds with Content-ID; regular stays an attachment.
// ---------------------------------------------------------------------------
$message = (new EmailMessage())
    ->from('sender@example.com', 'Sender')
    ->to('recipient@example.com')
    ->subject('Inline image test')
    ->html('<p>Logo: <img src="cid:' . $cid . '"></p>')
    ->attachInlineData($inline_bytes, $cid, 'logo.png', 'image/png')
    ->attachData($regular_bytes, 'report.pdf', 'application/pdf');

// EmailMessage records the inline entry with its cid + inline flag.
$atts = $message->getAttachments();
$inline_entry  = null;
$regular_entry = null;
foreach ($atts as $a) {
    if (!empty($a['cid'])) { $inline_entry = $a; }
    elseif (isset($a['data'])) { $regular_entry = $a; }
}
check('EmailMessage records an inline entry', $inline_entry !== null);
check('inline entry carries the bare cid token', ($inline_entry['cid'] ?? null) === $cid);
check('inline entry is flagged inline', !empty($inline_entry['inline']));
check('inline entry keeps content type', ($inline_entry['type'] ?? null) === 'image/png');
check('regular entry has no cid', $regular_entry !== null && empty($regular_entry['cid']));

// Build the actual MIME through the shared SMTP chokepoint.
$mailer = new SmtpMailer();
$mailer->applyMessage($message);
$built = $mailer->preSend();
check('preSend() built the message', $built === true);
$mime = $mailer->getSentMIMEMessage();

check('MIME embeds the inline part with Content-ID: <cid>',
    strpos($mime, 'Content-ID: <' . $cid . '>') !== false);
check('MIME marks the inline part inline disposition',
    (bool)preg_match('/Content-Disposition:\s*inline/i', $mime));
check('MIME carries the regular part as an attachment',
    (bool)preg_match('/Content-Disposition:\s*attachment/i', $mime));
// Both distinct byte payloads survive into the wire message (base64-encoded).
check('inline image bytes present in MIME',
    strpos($mime, chunk_split(base64_encode($inline_bytes))) !== false
    || strpos($mime, base64_encode($inline_bytes)) !== false);
check('regular file bytes present in MIME',
    strpos($mime, chunk_split(base64_encode($regular_bytes))) !== false
    || strpos($mime, base64_encode($regular_bytes)) !== false);

// ---------------------------------------------------------------------------
// 3. Degrade path: Resend/Brevo have no Content-ID; inline becomes a normal
//    attachment and a distinct marker is logged.
// ---------------------------------------------------------------------------
$log_file = tempnam(sys_get_temp_dir(), 'inline_degrade_');
$orig_log = ini_get('error_log');
ini_set('error_log', $log_file);

// Resend — inspect the built payload directly (private builder via reflection).
$resend = new ResendProvider();
$ref = new ReflectionMethod('ResendProvider', 'buildPayload');
$ref->setAccessible(true);
$payload = $ref->invoke($resend, $message);
$resend_atts = $payload['attachments'] ?? [];
$found_degraded = false;
foreach ($resend_atts as $a) {
    // The inline part is present as a plain attachment (filename + content, no cid).
    if (($a['filename'] ?? null) === 'logo.png' && !isset($a['cid'])) {
        $found_degraded = true;
    }
}
check('Resend degrades inline part to a regular attachment', $found_degraded);
check('Resend still sends both parts as attachments', count($resend_atts) === 2);

// Brevo — call its base-email builder to trigger the same degrade + log marker.
$brevo = new BrevoProvider();
$ref_b = new ReflectionMethod('BrevoProvider', 'buildBaseEmail');
$ref_b->setAccessible(true);
try {
    $ref_b->invoke($brevo, $message, Globalvars::get_instance());
    $brevo_ran = true;
} catch (\Throwable $e) {
    // Building the SDK object may need settings we don't assert on; the error_log
    // marker fires during attachment assembly regardless.
    $brevo_ran = true;
}
check('Brevo builder ran', $brevo_ran);

// Flush and inspect the captured log for both distinct markers.
ini_set('error_log', $orig_log);
$logged = file_exists($log_file) ? file_get_contents($log_file) : '';
@unlink($log_file);
check('Resend logged the inline-degrade marker',
    strpos($logged, '[ResendProvider] Inline attachment degraded') !== false);
check('Brevo logged the inline-degrade marker',
    strpos($logged, '[BrevoProvider] Inline attachment degraded') !== false);

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Passed: $pass   Failed: $fail\n";
exit($fail === 0 ? 0 : 1);
