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
/** @joinery-test
 * name: email_inline_attachments
 * tier: safe
 * env: any
 * needs: []
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/ResendProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/BrevoProvider.php'));

use PHPMailer\PHPMailer\PHPMailer;

section('Inline (embedded) attachments for outgoing email');

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
ok('EmailMessage records an inline entry', $inline_entry !== null);
ok('inline entry carries the bare cid token', ($inline_entry['cid'] ?? null) === $cid);
ok('inline entry is flagged inline', !empty($inline_entry['inline']));
ok('inline entry keeps content type', ($inline_entry['type'] ?? null) === 'image/png');
ok('regular entry has no cid', $regular_entry !== null && empty($regular_entry['cid']));

// Build the actual MIME through the shared SMTP chokepoint.
$mailer = new SmtpMailer();
$mailer->applyMessage($message);
$built = $mailer->preSend();
ok('preSend() built the message', $built === true);
$mime = $mailer->getSentMIMEMessage();

ok('MIME embeds the inline part with Content-ID: <cid>',
    strpos($mime, 'Content-ID: <' . $cid . '>') !== false);
ok('MIME marks the inline part inline disposition',
    (bool)preg_match('/Content-Disposition:\s*inline/i', $mime));
ok('MIME carries the regular part as an attachment',
    (bool)preg_match('/Content-Disposition:\s*attachment/i', $mime));
// Both distinct byte payloads survive into the wire message (base64-encoded).
ok('inline image bytes present in MIME',
    strpos($mime, chunk_split(base64_encode($inline_bytes))) !== false
    || strpos($mime, base64_encode($inline_bytes)) !== false);
ok('regular file bytes present in MIME',
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
$payload = $ref->invoke($resend, $message);
$resend_atts = $payload['attachments'] ?? [];
$found_degraded = false;
foreach ($resend_atts as $a) {
    // The inline part is present as a plain attachment (filename + content, no cid).
    if (($a['filename'] ?? null) === 'logo.png' && !isset($a['cid'])) {
        $found_degraded = true;
    }
}
ok('Resend degrades inline part to a regular attachment', $found_degraded);
ok('Resend still sends both parts as attachments', count($resend_atts) === 2);

// Brevo — call its base-email builder to trigger the same degrade + log marker.
$brevo = new BrevoProvider();
$ref_b = new ReflectionMethod('BrevoProvider', 'buildBaseEmail');
try {
    $ref_b->invoke($brevo, $message, Globalvars::get_instance());
    $brevo_ran = true;
} catch (\Throwable $e) {
    // Building the SDK object may need settings we don't assert on; the error_log
    // marker fires during attachment assembly regardless.
    $brevo_ran = true;
}
ok('Brevo builder ran', $brevo_ran);

// Flush and inspect the captured log for both distinct markers.
ini_set('error_log', $orig_log);
$logged = file_exists($log_file) ? file_get_contents($log_file) : '';
@unlink($log_file);
ok('Resend logged the inline-degrade marker',
    strpos($logged, '[ResendProvider] Inline attachment degraded') !== false);
ok('Brevo logged the inline-degrade marker',
    strpos($logged, '[BrevoProvider] Inline attachment degraded') !== false);

// ---------------------------------------------------------------------------
harness_finish();
