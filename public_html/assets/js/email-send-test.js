/*
 * /utils/email_send_test, loopback: wait for the test message to come back
 * through inbound delivery and show it (utils/email_send_test.php).
 *
 * #email-send-test-config: { token }.
 *
 * @version 1.1 - Retry wait polls again for the same message instead of reloading (which re-sent the form)
 * @version 1.0
 */
(function () {
    var token = ((window.joineryPageData && joineryPageData('email-send-test-config')) || {}).token || '';
    var wait = document.getElementById('est-wait');
    var out  = document.getElementById('est-result');
    var tries = 0, max = 24; // ~24 x 4s ≈ 96s
    var waitHtml = wait ? wait.innerHTML : '', waitClass = wait ? wait.className : '';

    function badge(val, ok) {
        var cls = ok ? 'bg-success' : (val ? 'bg-warning text-dark' : 'bg-secondary');
        return '<span class="badge ' + cls + '">' + (val ? String(val).toUpperCase() : '—') + '</span>';
    }
    function esc(s){ var d=document.createElement('div'); d.textContent = s==null?'':s; return d.innerHTML; }

    function render(d) {
        wait.style.display = 'none';
        var a = d.auth || {};
        var verified = !!a.verified;
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
                  + 'The SPF/DKIM/DMARC <em>verdicts</em> read <strong>unverified</strong>: nothing checked this message '
                  + 'on the way in, so there is no result to report. Whatever receives your mail is what performs those '
                  + 'checks and records the answer in an <code>Authentication-Results</code> header — on a server that '
                  + 'hosts its own mail that is <code>rspamd</code>, which you can '
                  + 'install or repair from Inbound Email &rarr; Setup. Alternatively use <strong>External</strong> mode '
                  + 'and read the receiving provider\'s own verdict under "Show original".</p>';
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
                        + '<a href="/plugins/mailbox/admin/admin_mailbox_reader">Mailbox reader</a>. ';
                    var retry = document.createElement('button');
                    retry.type = 'button';
                    retry.className = 'btn btn-sm btn-outline-secondary ms-2';
                    retry.textContent = 'Retry wait';
                    // Waits again for the same message. A reload would post
                    // the form again and send a second one.
                    retry.addEventListener('click', function () {
                        tries = 0;
                        wait.className = waitClass;
                        wait.innerHTML = waitHtml;
                        poll();
                    });
                    wait.appendChild(retry);
                    return;
                }
                setTimeout(poll, 4000);
            })
            .catch(function (e) { wait.className = 'alert alert-danger'; wait.textContent = 'Poll error: ' + e; });
    }
    poll();
})();
