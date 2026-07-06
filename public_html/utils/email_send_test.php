<?php
/**
 * Email Self-Test — outbound + (optional) inbound round-trip checker.
 *
 * Rewritten (v2): the old version required a Gmail App Password and polled
 * Gmail over IMAP to read authentication headers. That dependency is gone.
 * Because this platform now self-hosts inbound mail (inbound_email plugin),
 * the default test is a credential-free LOOPBACK:
 *
 *   send via the configured provider  →  to a local inbound address  →
 *   the message is received + stored by InboundEmailRouter (which records the
 *   SPF/DKIM/DMARC verdicts the verifying MTA stamped) →
 *   this page polls iem_inbound_email_messages and shows the result.
 *
 * That single action exercises outbound (provider), inbound (receive/store),
 * and the stored authentication verdicts — with no external mailbox, no IMAP, no
 * app password. The verdicts shown are the ones the router stored from the
 * message's Authentication-Results header (read by AuthenticationResults, never
 * recomputed here); without a verifying milter they read "unverified". A second
 * mode sends to any external address for a manual deliverability spot-check.
 *
 * @version 2.1
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));

if (method_exists('PathHelper', 'getComposerAutoloadPath')) {
    $autoload = PathHelper::getComposerAutoloadPath();
    if ($autoload && is_file($autoload)) {
        require_once($autoload);
    }
}

$session = SessionControl::get_instance();
$session->check_permission(5);
$settings = Globalvars::get_instance();
$db = DbConnector::get_instance()->get_db_link();

// ---------------------------------------------------------------- helpers

/** Pick an enabled inbound domain for the loopback, preferring a store catch-all. */
function est_pick_loopback_domain($db) {
    $sql = "SELECT ied_domain FROM ied_inbound_email_domains
            WHERE ied_is_enabled = true AND ied_delete_time IS NULL
            ORDER BY (ied_catch_all_mode = 'store') DESC, ied_domain ASC LIMIT 1";
    $val = $db->query($sql)->fetchColumn();
    return $val ?: null;
}

/**
 * Pull the most recent provider "Send failed: <reason>" lines from the error
 * log so a failed send can show what the provider actually said (e.g. a Mailgun
 * "Your credentials are incorrect."), instead of a generic message. Read-only,
 * guarded; returns up to 3 recent reasons or null.
 */
function est_recent_send_error() {
    $log = realpath(__DIR__ . '/../../logs/error.log');
    if (!$log || !is_readable($log)) {
        return null;
    }
    // Read only the tail — the error log can be enormous, so never file() it.
    $fh = @fopen($log, 'rb');
    if (!$fh) {
        return null;
    }
    $size = @filesize($log);
    $want = 65536;
    if ($size && $size > $want) {
        @fseek($fh, -$want, SEEK_END);
    }
    $chunk = @fread($fh, $want);
    @fclose($fh);
    if ($chunk === false || $chunk === '') {
        return null;
    }
    $lines = preg_split('/\r?\n/', $chunk);
    $hits = [];
    foreach (array_slice($lines, -80) as $l) {
        if (preg_match('/\[([A-Za-z0-9]+Provider|EmailSender)\][^:]*:\s*(.+?)(?:,\s*referer:|$)/', $l, $m)) {
            $reason = trim($m[2]);
            if ($reason !== '' && stripos($reason, 'trying fallback') === false) {
                $hits[] = $m[1] . ': ' . $reason;
            }
        }
    }
    return $hits ? array_values(array_slice($hits, -3)) : null;
}

/**
 * Whether the message carries a DKIM-Signature, and its signing domain/selector.
 * This is a FACT we can read off the header — unlike a pass/fail verdict, which
 * the self-hosted inbound path can't compute reliably (see note in the UI).
 */
function est_dkim_info($raw) {
    $info = ['signed' => false, 'domain' => null, 'selector' => null];
    if (!preg_match('/^DKIM-Signature:(.*(?:\n[ \t].*)*)/im', (string)$raw, $m)) {
        return $info;
    }
    $info['signed'] = true;
    $sig = preg_replace('/\s+/', '', $m[1]);
    foreach (explode(';', $sig) as $pair) {
        if (strpos($pair, '=') === false) { continue; }
        list($k, $v) = explode('=', $pair, 2);
        if ($k === 'd') { $info['domain'] = $v; }
        if ($k === 's') { $info['selector'] = $v; }
    }
    return $info;
}

// ------------------------------------------------ JSON poll endpoint (?check=)

if (isset($_GET['check']) && $_GET['check'] !== '') {
    header('Content-Type: application/json');
    $token = preg_replace('/[^a-z0-9]/i', '', (string)$_GET['check']);
    if ($token === '') { echo json_encode(['found' => false]); exit; }

    $like = '%' . $token . '%';
    $stmt = $db->prepare(
        "SELECT iem_inbound_email_message_id, iem_sender, iem_subject, iem_received_time,
                iem_dkim_result, iem_spf_result, iem_dmarc_result, iem_auth_source, iem_raw_message
         FROM iem_inbound_email_messages
         WHERE iem_recipient ILIKE ? OR iem_subject ILIKE ?
         ORDER BY iem_received_time DESC LIMIT 1"
    );
    $stmt->execute([$like, $like]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { echo json_encode(['found' => false]); exit; }

    // The SPF/DKIM/DMARC verdicts are the ones the router already stored —
    // read from the message's Authentication-Results header by
    // AuthenticationResults at receive time, never recomputed here. auth_source
    // tells the client whether a verifying milter stamped them ('milter') or
    // the message is honestly 'unverified' (source 'none').
    echo json_encode([
        'found'       => true,
        'id'          => (int)$row['iem_inbound_email_message_id'],
        'sender'      => $row['iem_sender'],
        'subject'     => $row['iem_subject'],
        'received'    => $row['iem_received_time'],
        'dkim'        => est_dkim_info($row['iem_raw_message']),
        'auth'        => [
            'spf'    => $row['iem_spf_result'],
            'dkim'   => $row['iem_dkim_result'],
            'dmarc'  => $row['iem_dmarc_result'],
            'source' => $row['iem_auth_source'] ?: 'none',
        ],
        'reader_url'  => '/plugins/mailbox/admin/admin_mailbox_message?iem_inbound_email_message_id=' . (int)$row['iem_inbound_email_message_id'],
    ]);
    exit;
}

// ------------------------------------------------------------ handle a send

$result = null; // ['mode','ok','error','to','token','subject']
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode  = ($_POST['mode'] ?? 'loopback') === 'external' ? 'external' : 'loopback';
    $token = 'est' . bin2hex(random_bytes(5));

    if ($mode === 'external') {
        $to = trim((string)($_POST['external_email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $result = ['mode' => $mode, 'ok' => false, 'error' => 'Enter a valid email address.', 'to' => $to, 'token' => $token];
        }
    } else {
        $domain = est_pick_loopback_domain($db);
        if (!$domain || (string)$settings->get_setting('mailbox_enabled') !== '1') {
            $result = ['mode' => $mode, 'ok' => false, 'token' => $token, 'to' => '',
                'error' => 'Loopback needs inbound email enabled with an inbound domain (ideally a store catch-all). Use "Send to an external address" instead.'];
        } else {
            $to = 'emailtest-' . $token . '@' . $domain;
        }
    }

    if ($result === null) {
        $subject = 'Joinery email self-test ' . $token;
        $body = "Automated email self-test.\nMode: $mode\nToken: $token\nSent (UTC): " . gmdate('c') . "\n";
        $ok = false; $err = null;
        // Buffer the send so any stray provider output can't trip a later
        // "headers already sent" when admin_header runs.
        ob_start();
        try {
            $message = EmailMessage::create($to, $subject, $body);
            $sender  = new EmailSender();
            // queue_on_failure=false: this is a diagnostic, and the retry-queue
            // insert requires a recipient name we don't set (would error noisily).
            $ok = (bool)$sender->send($message, false);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        ob_end_clean();

        if (!$ok && !$err) {
            $log_errs = est_recent_send_error();
            $err = $log_errs ? implode("\n", $log_errs) : 'The configured provider rejected the message (no detail captured).';
        }
        $result = ['mode' => $mode, 'ok' => $ok, 'error' => $err, 'to' => $to, 'token' => $token, 'subject' => $subject];
    }
}

// --------------------------------------------------------------- render

$page = new AdminPage();
$page->admin_header([
    'title'          => 'Email Self-Test',
    'menu-id'        => 'email-deliverability',
    'readable_title' => 'Email Self-Test',
    'session'        => $session,
]);

// Context: what the platform will actually do.
$svc = $settings->get_setting('email_service') ?: '(unset)';
$dry = (string)$settings->get_setting('email_dry_run') === '1';
$tst = (string)$settings->get_setting('email_test_mode') === '1';
echo '<div class="card mb-3"><div class="card-body py-2">';
echo '<strong>Mailer:</strong> service <code>' . htmlspecialchars($svc) . '</code>';
$mgd = $settings->get_setting('mailgun_domain');
if ($svc === 'mailgun' && $mgd) { echo ' &middot; domain <code>' . htmlspecialchars($mgd) . '</code>'; }
echo ' &middot; from <code>' . htmlspecialchars($settings->get_setting('defaultemail') ?: '-') . '</code>';
if ($dry) { echo ' &middot; <span class="badge bg-warning text-dark">DRY RUN — nothing actually sends</span>'; }
if ($tst) { echo ' &middot; <span class="badge bg-warning text-dark">TEST MODE on</span>'; }
echo '</div></div>';

if ($result === null) {
    // ---- the form ----
    echo '<div class="alert alert-info"><strong>Two ways to test:</strong> '
        . '<em>Loopback</em> sends to a local inbox and reads the result back automatically — outbound + inbound + DKIM in one click, no credentials. '
        . '<em>External</em> sends to any address so you can eyeball deliverability with "Show original".</div>';

    $loop_domain = est_pick_loopback_domain($db);
    $inbound_on  = (string)$settings->get_setting('mailbox_enabled') === '1';

    $formwriter = $page->getFormWriter('email_selftest_form', ['action' => '/utils/email_send_test', 'method' => 'POST']);
    echo $formwriter->begin_form();

    if ($inbound_on && $loop_domain) {
        echo '<p class="text-muted mb-2">Loopback target: <code>emailtest-&lt;token&gt;@' . htmlspecialchars($loop_domain) . '</code> '
            . '(auto-generated; captured by the inbound store and shown in the Mailbox reader).</p>';
        $mode_options = ['loopback' => 'Loopback self-test (recommended)', 'external' => 'Send to an external address'];
    } else {
        echo '<div class="alert alert-warning">Inbound email isn\'t enabled with a usable domain, so loopback is unavailable — only external send is offered.</div>';
        $mode_options = ['external' => 'Send to an external address'];
    }

    $formwriter->dropinput('mode', 'Test type', [
        'options' => $mode_options,
        'visibility_rules' => [
            'loopback' => ['show' => [], 'hide' => ['external_email']],
            'external' => ['show' => ['external_email'], 'hide' => []],
        ],
    ]);

    $formwriter->textinput('external_email', 'External email address', [
        'placeholder' => 'you@example.com',
        'helptext'    => 'Where to send the deliverability test. After it arrives, open it and use your mail client\'s "Show original" to read SPF / DKIM / DMARC.',
    ]);

    $formwriter->submitbutton('btn_submit', 'Run test');
    echo $formwriter->end_form();

    $page->admin_footer();
    exit;
}

// ---- result of a send ----
if (!$result['ok']) {
    echo '<div class="alert alert-danger"><strong>Send failed.</strong><br><pre class="mb-0" style="white-space:pre-wrap">'
        . htmlspecialchars((string)$result['error']) . '</pre></div>';

    // Targeted hint for the most common cause — provider auth failure.
    $e = strtolower((string)$result['error']);
    if (strpos($e, 'credential') !== false || strpos($e, 'incorrect') !== false
        || strpos($e, 'forbidden') !== false || strpos($e, 'unauthor') !== false || strpos($e, '401') !== false) {
        $mgd = $settings->get_setting('mailgun_domain');
        echo '<div class="alert alert-warning">This is an <strong>authentication failure from the provider</strong>, not a problem with this page. '
            . 'For Mailgun, update <code>mailgun_api_key</code> to a key valid for '
            . '<code>' . htmlspecialchars($mgd ?: '(unset)') . '</code> (and confirm that domain is <em>Verified</em> in the Mailgun account) on the '
            . '<a href="/admin/admin_settings_email">Email settings</a> page, then re-run this test.</div>';
    }

    echo '<p class="mt-2"><a class="btn btn-sm btn-outline-secondary" href="/utils/email_send_test">Back</a> '
        . '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_debug_email_logs">Email debug logs</a></p>';
    $page->admin_footer();
    exit;
}

echo '<div class="alert alert-success"><strong>✓ Sent</strong> to <code>' . htmlspecialchars($result['to']) . '</code> via the <code>'
    . htmlspecialchars($svc) . '</code> service.</div>';

if ($result['mode'] === 'external') {
    echo '<div class="card"><div class="card-body">';
    echo '<p>Now open that message in the recipient\'s mailbox and use <strong>"Show original"</strong> (Gmail) or '
        . '<strong>"View source / message headers"</strong> (others). You want to see, in the <code>Authentication-Results</code> line:</p>';
    echo '<ul><li><code>spf=pass</code></li><li><code>dkim=pass</code> (signed by your sending domain)</li><li><code>dmarc=pass</code></li></ul>';
    echo '<p class="mb-0 text-muted">If it never arrives, check the Mailbox <a href="/plugins/mailbox/admin/admin_mailbox_logs">Logs</a> and your spam folder.</p>';
    echo '</div></div>';
    echo '<a href="/utils/email_send_test" class="btn btn-outline-secondary mt-3">Run another</a>';
    $page->admin_footer();
    exit;
}

// ---- loopback: poll the inbound store for arrival ----
echo '<div id="est-wait" class="alert alert-primary d-flex align-items-center">'
    . '<div class="spinner-border spinner-border-sm me-3" role="status"><span class="visually-hidden">Waiting…</span></div>'
    . '<div>Waiting for the message to come back through inbound delivery (Mailgun → DNS → Postfix → store). This usually takes a few seconds to a minute…</div></div>';
echo '<div id="est-result"></div>';
echo '<a href="/utils/email_send_test" class="btn btn-outline-secondary mt-3">Run another</a>';
?>
<script>
(function () {
    var token = <?php echo json_encode($result['token']); ?>;
    var wait = document.getElementById('est-wait');
    var out  = document.getElementById('est-result');
    var tries = 0, max = 24; // ~24 x 4s ≈ 96s

    function badge(val, ok) {
        var cls = ok ? 'bg-success' : (val ? 'bg-warning text-dark' : 'bg-secondary');
        return '<span class="badge ' + cls + '">' + (val ? String(val).toUpperCase() : '—') + '</span>';
    }
    function esc(s){ var d=document.createElement('div'); d.textContent = s==null?'':s; return d.innerHTML; }

    function render(d) {
        wait.style.display = 'none';
        var a = d.auth || {};
        var verified = (a.source === 'milter' || a.source === 'mailgun');
        var html = '<div class="card"><div class="card-header bg-success text-white">✓ Round-trip complete — message received and stored</div><div class="card-body">';
        html += '<p><strong>From:</strong> ' + esc(d.sender) + '<br><strong>Subject:</strong> ' + esc(d.subject) + '<br><strong>Received:</strong> ' + esc(d.received) + '</p>';
        var dk = d.dkim || {};
        var dkimCell = dk.signed
            ? '<span class="badge bg-success">present</span> <span class="text-muted">signed by ' + esc(dk.domain || '?') + (dk.selector ? ' (s=' + esc(dk.selector) + ')' : '') + '</span>'
            : '<span class="badge bg-secondary">no signature</span>';
        html += '<table class="table table-sm" style="max-width:560px"><tbody>';
        html += '<tr><td>DKIM-Signature</td><td>' + dkimCell + '</td></tr>';
        if (verified) {
            html += '<tr><td>SPF verdict</td><td>' + badge(a.spf, a.spf === 'pass') + '</td></tr>';
            html += '<tr><td>DKIM verdict</td><td>' + badge(a.dkim, a.dkim === 'pass') + '</td></tr>';
            html += '<tr><td>DMARC verdict</td><td>' + badge(a.dmarc, a.dmarc === 'pass') + '</td></tr>';
        }
        html += '</tbody></table>';
        if (verified) {
            html += '<p class="text-muted small">These SPF/DKIM/DMARC verdicts were read from the message\'s '
                  + '<code>Authentication-Results</code> header, stamped by the verifying mail server (source: '
                  + esc(a.source) + '). The app never computes them itself.</p>';
        } else {
            html += '<p class="text-muted small">This shows a DKIM signature is present and which domain signed it. '
                  + 'SPF/DKIM/DMARC <em>verdicts</em> read <strong>unverified</strong> because no verifying milter stamped an '
                  + '<code>Authentication-Results</code> header on receipt — install/repair the opendkim-verify + opendmarc '
                  + 'milters (Inbound Email &rarr; Setup), or use <strong>External</strong> mode and check the message\'s "Show original".</p>';
        }
        html += '<a class="btn btn-sm btn-outline-primary" href="' + d.reader_url + '">Open the stored message</a>';
        html += '</div></div>';
        out.innerHTML = html;
    }

    function poll() {
        fetch('/utils/email_send_test?check=' + encodeURIComponent(token), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.found) { render(d); return; }
                if (++tries >= max) {
                    wait.className = 'alert alert-warning';
                    wait.innerHTML = 'Not received within ~90 seconds. The send succeeded, so this points at the inbound path — check the inbound '
                        + '<a href="/plugins/mailbox/admin/admin_mailbox_logs">Logs</a> and the '
                        + '<a href="/plugins/mailbox/admin/admin_mailbox_reader">Mailbox reader</a>. '
                        + '<button class="btn btn-sm btn-outline-secondary ms-2" onclick="location.reload()">Retry wait</button>';
                    return;
                }
                setTimeout(poll, 4000);
            })
            .catch(function (e) { wait.className = 'alert alert-danger'; wait.textContent = 'Poll error: ' + e; });
    }
    poll();
})();
</script>
<?php
$page->admin_footer();
?>
