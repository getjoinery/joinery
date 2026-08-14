<?php
/**
 * Shared message-export rendering for the export endpoint
 * (/profile/mailbox/original) — the .eml download and the print sheet.
 *
 * Both emit their bytes and exit(). Like attachment_retrieval.php, rendering
 * here is authorization-free by design: the endpoint gates FIRST (mailbox-grant
 * scope via MailboxViewer) and only then renders.
 *
 * WHY THE PRINT SHEET SANITIZES INSTEAD OF SANDBOXING. Everywhere else,
 * received HTML renders inside a sandboxed iframe and is never trusted into a
 * document of ours. A printout cannot: a browser prints only the visible slice
 * of a scrollable frame, and the frame's opaque origin is exactly what stops us
 * measuring its content to size it. So the sheet inlines the body, run through
 * MailboxHtmlSanitizer::sanitizeForPrint(), under a Content-Security-Policy
 * that allows no script beyond this file's own nonce'd print call and no
 * network fetch beyond images. The sanitizer and the policy are independent —
 * a miss in one is still not an execution.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));

/**
 * Stream the stored original as a .eml download and exit(). message/rfc822 with
 * an attachment disposition and nosniff: the bytes are attacker-controlled, so
 * they are handed to the user's mail client, never rendered in our origin.
 */
function mailbox_stream_eml(InboundEmailMessage $message, string $raw): void {
	$name = mailbox_eml_filename((string)$message->get('iem_subject'), intval($message->key));

	header('Content-Type: message/rfc822');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	header('X-Content-Type-Options: nosniff');
	header('Content-Length: ' . strlen($raw));
	echo $raw;
	exit();
}

/**
 * A filename built from the subject: readable in a downloads folder, and safe
 * in a header (no quotes, slashes or newlines to inject with). The message id
 * keeps two same-subject downloads apart.
 */
function mailbox_eml_filename(string $subject, int $id): string {
	$name = preg_replace('/[^A-Za-z0-9 ._-]+/u', ' ', $subject);
	$name = trim(preg_replace('/\s+/', ' ', (string)$name));
	if ($name === '') {
		$name = 'message';
	}
	return substr($name, 0, 80) . ' (' . $id . ').eml';
}

/**
 * Emit the print sheet for one message and exit().
 *
 * The body is the HTML one where there is one — sanitized for print, with cid:
 * inline images resolved to short-lived signed URLs so the pictures print —
 * and the plaintext one otherwise. Attachments print as a list of names: a
 * printout is a record of what arrived, and their bytes are not on the page.
 *
 * @throws VaultLockedException reading a sealed body with the window closed
 */
function mailbox_print_message(InboundEmailMessage $message): void {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));

	$id = intval($message->key);
	$body_html = (string)$message->get('iem_body_html');
	$body_print = '';

	if ($body_html !== '') {
		$resolved = MailboxService::resolveInlineImages(array(
			array('id' => $id, 'body_html' => $body_html),
		));
		$body_print = MailboxHtmlSanitizer::sanitizeForPrint((string)$resolved[0]['body_html']);
	}
	if ($body_print === '') {
		$plain = trim((string)$message->get('iem_body_plain'));
		$body_print = $plain === ''
			? '<p class="mbx-print-empty">This message has no text body.</p>'
			: '<pre class="mbx-print-plain">' . htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5) . '</pre>';
	}

	$subject = trim((string)$message->get('iem_subject'));
	$rows = array(
		'From' => (string)$message->get('iem_sender'),
		'To'   => (string)$message->get('iem_recipient'),
		'Date' => $message->get_local('iem_received_time') ?: '',
	);

	// Non-inline parts only, the same list the reader shows as chips — an inline
	// cid: image belongs to the body and is already printed inside it.
	$attachments = array();
	$atts = new MultiInboundMessageAttachment(array(
		'message_id' => $id,
		'is_inline'  => false,
	));
	foreach ($atts as $att) {
		$attachments[] = (string)$att->get('ima_filename');
	}

	// One-shot nonce: our own print call runs, and nothing the message carried
	// can, whatever survived the sanitizer.
	$nonce = base64_encode(random_bytes(16));
	header('Content-Type: text/html; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'; img-src https: data:; "
		. "style-src 'unsafe-inline'; script-src 'nonce-" . $nonce . "'; "
		. "form-action 'none'; frame-ancestors 'none'; base-uri 'none'");

	$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5); };

	echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
	echo '<title>' . $esc($subject !== '' ? $subject : 'Message') . '</title>';
	echo '<style>' . mailbox_print_stylesheet() . '</style></head><body>';

	// A CDN in front of this site may rewrite every address on the page into a
	// script-decoded placeholder ("[email protected]") — and this page's CSP
	// refuses that script, so the addresses would print as the placeholder.
	// Cloudflare's opt-out marker is an HTML comment, inert everywhere else.
	echo '<!--email_off-->';

	echo '<header class="mbx-print-head">';
	echo '<h1>' . $esc($subject !== '' ? $subject : '(no subject)') . '</h1>';
	echo '<dl>';
	foreach ($rows as $label => $value) {
		if (trim((string)$value) === '') {
			continue;
		}
		echo '<dt>' . $esc($label) . '</dt><dd>' . $esc($value) . '</dd>';
	}
	echo '</dl></header>';

	echo '<main class="mbx-print-body">' . $body_print . '</main>';

	if (count($attachments)) {
		echo '<footer class="mbx-print-attachments"><h2>'
			. count($attachments) . (count($attachments) === 1 ? ' attachment' : ' attachments')
			. '</h2><ul>';
		foreach ($attachments as $name) {
			echo '<li>' . $esc($name) . '</li>';
		}
		echo '</ul></footer>';
	}

	echo '<!--/email_off-->';
	echo '<script nonce="' . $esc($nonce) . '">window.onload=function(){window.print();};</script>';
	echo '</body></html>';
	exit();
}

/** The print sheet's own styles — paper margins, a header block, tamed images. */
function mailbox_print_stylesheet(): string {
	return '
	@page { margin: 18mm 14mm; }
	body {
		margin: 0; padding: 0 4mm;
		font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
		color: #111;
		background: #fff;
	}
	.mbx-print-head { border-bottom: 1px solid #bbb; padding-bottom: 10px; margin-bottom: 16px; }
	.mbx-print-head h1 { margin: 0 0 8px; font-size: 18px; line-height: 1.3; }
	.mbx-print-head dl { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 2px 12px; }
	.mbx-print-head dt { font-weight: 600; color: #555; }
	.mbx-print-head dd { margin: 0; word-break: break-word; }
	.mbx-print-body { word-break: break-word; }
	/* An email is authored for a screen; hold it to the paper width so a wide
	   table crops nothing off the right edge. */
	.mbx-print-body img { max-width: 100% !important; height: auto !important; }
	.mbx-print-body table { max-width: 100% !important; }
	.mbx-print-plain { white-space: pre-wrap; font: inherit; margin: 0; }
	.mbx-print-empty { color: #666; font-style: italic; }
	.mbx-print-attachments {
		margin-top: 20px; padding-top: 10px; border-top: 1px solid #bbb;
		page-break-inside: avoid;
	}
	.mbx-print-attachments h2 { margin: 0 0 6px; font-size: 13px; color: #555; }
	.mbx-print-attachments ul { margin: 0; padding-left: 18px; }
	@media print {
		body { padding: 0; }
		a { text-decoration: none; color: inherit; }
		/* A printed link is unreachable unless the paper says where it goes. */
		.mbx-print-body a[href^="http"]::after { content: " <" attr(href) ">"; font-size: 10px; color: #555; }
	}';
}
?>
