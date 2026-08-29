<?php
/**
 * The remembered Setup verdict — one operator's last answer to "is this mailbox
 * set up?", kept in their session.
 *
 * It lives apart from the checks that produce it (mailbox_setup_scope.php)
 * because remembering an answer costs nothing and knows nothing: it is reachable
 * from a feed being saved on a cron poll without dragging the DNS/host probe
 * suite in behind it.
 *
 * @version 1.0
 */

// ---------------------------------------------------------------------------
// The remembered verdict
//
// Running the checks costs DNS lookups and host probes, so the reader does not
// re-run them every time an operator clicks a mailbox — it reads the last answer
// out of their session. That makes freshness the interesting problem: an
// operator who has just fixed a DNS record must not be told it is still broken.
//
// So the answer is written by whoever last learned it, and the Setup tab is the
// biggest such writer: rendering it runs the full battery for that mailbox
// anyway, so it stamps the result on the way past. Fixing a record and going
// back to the mailbox therefore shows the truth immediately — no waiting for a
// TTL to lapse. The TTL is only the backstop for a mailbox nobody has looked at.
// ---------------------------------------------------------------------------

/** How long a remembered verdict stays good, in seconds. */
const MAILBOX_SETUP_STATUS_TTL = 300;

/** The wire shape both the reader and the writers use. */
function mailbox_setup_status_payload(int $alias_id, array $verdict): array {
	return array(
		'status' => $verdict['status'],
		'reason' => $verdict['reason'],
		'label'  => $verdict['label'],
		'url'    => '/plugins/mailbox/admin/admin_mailbox_setup?alias_id=' . $alias_id,
	);
}

/**
 * Remember a verdict for this operator. Call it wherever the checks have just
 * run for real — the answer is free at that point, and every surface that would
 * otherwise show a stale banner gets it.
 */
function mailbox_setup_status_remember(int $alias_id, array $verdict): array {
	$payload = mailbox_setup_status_payload($alias_id, $verdict);
	// An unknown verdict is an absence of information, not news. Overwriting a
	// real answer with it would make the banner flap on one failed lookup.
	if ($verdict['status'] === 'unknown') {
		return $payload;
	}
	if (session_status() === PHP_SESSION_ACTIVE) {
		if (!isset($_SESSION['mailbox_setup_status']) || !is_array($_SESSION['mailbox_setup_status'])) {
			$_SESSION['mailbox_setup_status'] = array();
		}
		$_SESSION['mailbox_setup_status'][$alias_id] = array('checked' => time(), 'payload' => $payload);
	}
	return $payload;
}

/** The remembered verdict, or null when there is none or it has aged out. */
function mailbox_setup_status_recall(int $alias_id): ?array {
	$entry = $_SESSION['mailbox_setup_status'][$alias_id] ?? null;
	if (!is_array($entry) || !isset($entry['payload'])) {
		return null;
	}
	if ((time() - intval($entry['checked'] ?? 0)) >= MAILBOX_SETUP_STATUS_TTL) {
		return null;
	}
	return $entry['payload'];
}

/**
 * Drop the remembered verdict for a mailbox, so the next ask re-runs the checks.
 *
 * Call it from anything that CHANGES what the answer would be. A remembered
 * verdict is a record of what was true when it was reached; the moment an
 * operator reconnects a feed, switches one off, or a poll flags a token as
 * broken, that record is a guess — and a banner that keeps telling an operator
 * to fix what they have just fixed is worse than no banner, because it teaches
 * them to ignore the one that matters.
 */
function mailbox_setup_status_forget(int $alias_id): void {
	if ($alias_id <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
		return;
	}
	unset($_SESSION['mailbox_setup_status'][$alias_id]);
}
