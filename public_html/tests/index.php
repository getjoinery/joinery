<?php
/**
 * Test dashboard — the one superadmin web view over the whole test estate.
 *
 * Served by the /tests/* route (min_permission 10 in serve.php). Lists every
 * discovered test grouped by tier with its env and needs, and runs tests
 * through the tests_run API action (which spawns tests/run.php --json in a
 * subprocess). Page JS calls /api/v1 with the browser-session credential
 * (session cookie + X-Joinery-Csrf header read from the meta tag) — no /ajax/.
 *
 * Env awareness: on production (the `debug` setting off) dev-only tests render
 * locked; live-tier and prod-verify tests always require a confirm naming the
 * side effect before they run. Joinery System theme, vanilla JS, .jy-ui-style.
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('tests/lib/discovery.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // superadmin; redirects if not authorized

$root = rtrim(PathHelper::getIncludePath(''), '/');
$debug_on = (bool)Globalvars::get_instance()->get_setting('debug');
$discovered = harness_discover($root);

// Group declared tests by tier in a fixed order.
$tier_order = array('safe', 'db', 'test-db', 'live');
$by_tier = array_fill_keys($tier_order, array());
foreach ($discovered['declared'] as $d) {
	$by_tier[$d['meta']['tier']][] = array(
		'name' => $d['meta']['name'],
		'path' => harness_rel($d['path'], $root),
		'env'  => $d['meta']['env'],
		'needs' => $d['meta']['needs'],
		'timeout' => $d['meta']['timeout'] ?? 180,
		'timeout_explicit' => !empty($d['meta']['timeout_explicit']),
	);
}
$tier_blurb = array(
	'safe'    => 'Pure, mocked, or rolled back — no persistent side effects.',
	'db'      => 'Writes the dev database and self-cleans.',
	'test-db' => 'Runs against the copied test database.',
	'live'    => 'Real external effects (mail, buckets, Stripe keys, remote hosts).',
);
$total_declared = count($discovered['declared']);
$csrf = $session->get_api_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="joinery-api-csrf" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>Test Dashboard</title>
<style>
  :root {
    --bg:#f6f7f9; --card:#fff; --ink:#1e293b; --muted:#64748b; --line:#e2e8f0;
    --accent:#2563eb; --pass:#16a34a; --fail:#dc2626; --warn:#b45309; --lock:#94a3b8;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#0f172a; --card:#1e293b; --ink:#e2e8f0; --muted:#94a3b8; --line:#334155; }
  }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
         margin:0; background:var(--bg); color:var(--ink); line-height:1.5; }
  .wrap { max-width:1000px; margin:0 auto; padding:28px 20px 80px; }
  h1 { font-size:1.5rem; margin:0 0 4px; }
  .sub { color:var(--muted); margin:0 0 20px; font-size:.9rem; }
  .env-banner { padding:10px 14px; border-radius:8px; margin-bottom:20px; font-size:.9rem;
                border:1px solid var(--line); background:var(--card); }
  .env-prod { border-color:#f59e0b; }
  .tier { background:var(--card); border:1px solid var(--line); border-radius:10px;
          margin-bottom:18px; overflow:hidden; }
  .tier-head { display:flex; align-items:center; gap:12px; padding:14px 16px; border-bottom:1px solid var(--line); }
  .tier-head h2 { font-size:1.05rem; margin:0; text-transform:capitalize; }
  .tier-head .blurb { color:var(--muted); font-size:.82rem; flex:1; }
  .badge { display:inline-block; font-size:.7rem; font-weight:600; padding:2px 7px;
           border-radius:20px; border:1px solid var(--line); color:var(--muted); white-space:nowrap; }
  .badge.env-any { color:var(--pass); border-color:var(--pass); }
  .badge.env-prod-verify { color:var(--warn); border-color:var(--warn); }
  .badge.env-dev-only { color:var(--accent); border-color:var(--accent); }
  .badge.need { color:var(--warn); border-color:var(--warn); }
  .row { display:flex; align-items:center; gap:10px; padding:10px 16px; border-top:1px solid var(--line); }
  .row:first-child { border-top:none; }
  .row .id { flex:1; min-width:0; }
  .row .id .n { font-weight:600; }
  .row .id .p { color:var(--muted); font-size:.76rem; font-family:ui-monospace,Menlo,monospace;
                overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .row .badges { display:flex; gap:5px; flex-wrap:wrap; }
  .row .result { min-width:120px; text-align:right; font-size:.82rem; font-variant-numeric:tabular-nums; }
  .result .ok { color:var(--pass); font-weight:600; }
  .result .no { color:var(--fail); font-weight:600; }
  .result .running { color:var(--muted); }
  button { font:inherit; font-size:.82rem; padding:5px 12px; border-radius:7px; cursor:pointer;
           border:1px solid var(--accent); background:var(--accent); color:#fff; }
  button.ghost { background:transparent; color:var(--accent); }
  button:disabled { opacity:.5; cursor:not-allowed; }
  button.locked { border-color:var(--lock); color:var(--lock); background:transparent; }
  .fails { padding:0 16px 12px 16px; }
  .fails li { color:var(--fail); font-size:.8rem; font-family:ui-monospace,Menlo,monospace; }
  .undeclared { background:var(--card); border:1px dashed var(--line); border-radius:10px; padding:14px 16px; }
  .undeclared h2 { font-size:.95rem; margin:0 0 8px; }
  .undeclared code { font-size:.76rem; color:var(--muted); display:block; }
  a.docs { color:var(--accent); }
</style>
</head>
<body>
<div class="wrap">
  <h1>Test Dashboard</h1>
  <p class="sub"><?php echo $total_declared; ?> declared test<?php echo $total_declared === 1 ? '' : 's'; ?>
     · <?php echo count($discovered['undeclared']); ?> undeclared ·
     see <code>docs/testing.md</code></p>

  <div class="env-banner <?php echo $debug_on ? '' : 'env-prod'; ?>">
    <?php if ($debug_on): ?>
      Environment: <strong>dev</strong> (the <code>debug</code> setting is on). All tiers runnable.
    <?php else: ?>
      Environment: <strong>production</strong> (the <code>debug</code> setting is off).
      <strong>dev-only</strong> tests are locked; <strong>live</strong> and <strong>prod-verify</strong> tests require confirmation.
    <?php endif; ?>
  </div>

<?php foreach ($tier_order as $tier): $tests = $by_tier[$tier]; if (!$tests) continue; ?>
  <section class="tier" data-tier="<?php echo $tier; ?>">
    <div class="tier-head">
      <h2><?php echo htmlspecialchars($tier); ?></h2>
      <span class="blurb"><?php echo htmlspecialchars($tier_blurb[$tier]); ?></span>
      <button class="ghost" onclick="runTier('<?php echo $tier; ?>', this)">Run all</button>
    </div>
    <?php foreach ($tests as $t):
      $locked = (!$debug_on && $t['env'] === 'dev-only');
      $confirm = ($tier === 'live' || $t['env'] === 'prod-verify');
      // A test that EXPLICITLY declared a timeout beyond the proxy request
      // window (~100s) cannot finish inside one web request — CLI only. Tests on
      // the default cap stay web-runnable (they finish in seconds in practice).
      $cli_only = (!empty($t['timeout_explicit']) && $t['timeout'] > 90);
      $pj = htmlspecialchars(json_encode($t['path']), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="row" data-path="<?php echo htmlspecialchars($t['path'], ENT_QUOTES, 'UTF-8'); ?>"
         data-confirm="<?php echo $confirm ? '1' : '0'; ?>"
         data-runnable="<?php echo (!$locked && !$cli_only) ? '1' : '0'; ?>">
      <div class="id">
        <div class="n"><?php echo htmlspecialchars($t['name']); ?></div>
        <div class="p"><?php echo htmlspecialchars($t['path']); ?></div>
      </div>
      <div class="badges">
        <span class="badge env-<?php echo $t['env']; ?>"><?php echo htmlspecialchars($t['env']); ?></span>
        <?php foreach ($t['needs'] as $need): ?><span class="badge need"><?php echo htmlspecialchars($need); ?></span><?php endforeach; ?>
      </div>
      <div class="result">—</div>
      <?php if ($locked): ?>
        <button class="locked" disabled title="dev-only: locked because the debug setting is off">Locked</button>
      <?php elseif ($cli_only): ?>
        <button class="ghost" disabled title="timeout <?php echo (int)$t['timeout']; ?>s exceeds the web request window — run via php tests/run.php">CLI</button>
      <?php else: ?>
        <button onclick='runTest(<?php echo $pj; ?>, this, <?php echo $confirm ? 'true' : 'false'; ?>)'>Run</button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

<?php if ($discovered['undeclared']): ?>
  <div class="undeclared">
    <h2>Undeclared (<?php echo count($discovered['undeclared']); ?>) — no <code>@joinery-test</code> header, not run</h2>
    <?php foreach ($discovered['undeclared'] as $p): ?>
      <code><?php echo htmlspecialchars(harness_rel($p, $root)); ?></code>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<script>
const CSRF = document.querySelector('meta[name="joinery-api-csrf"]').content;

async function callRun(body) {
  const resp = await fetch('/api/v1/action/tests_run', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': CSRF },
    body: JSON.stringify(body)
  });
  const json = await resp.json();
  if (!resp.ok) throw new Error(json.error || ('HTTP ' + resp.status));
  return json.data.run; // aggregate contract from tests/run.php
}

function rowFor(path) {
  return document.querySelector('.row[data-path="' + CSS.escape(path) + '"]');
}

function renderResult(row, r) {
  const cell = row.querySelector('.result');
  const old = row.querySelector('.fails'); if (old) old.remove();
  if (!r) { cell.innerHTML = '<span class="no">no result</span>'; return; }
  const s = r.stats || {passed:0, failed:0, total:0};
  const cls = r.status === 'pass' ? 'ok' : 'no';
  const label = s.total > 0 ? (s.passed + '/' + s.total) : (r.status === 'pass' ? 'pass' : 'fail');
  cell.innerHTML = '<span class="' + cls + '">' + (r.status === 'pass' ? '✓ ' : '✗ ') + label + '</span>'
    + ' <span style="color:var(--muted)">' + (r.duration_ms||0) + 'ms</span>';
  if (r.status !== 'pass') {
    const fails = [];
    (r.sections || []).forEach(sec => (sec.checks || []).forEach(c => {
      if (c.passed === false) fails.push((c.label||'') + (c.detail ? ' — ' + c.detail : ''));
    }));
    if (r.note) fails.push(r.note);
    if (fails.length) {
      const ul = document.createElement('ul');
      ul.className = 'fails';
      fails.slice(0, 12).forEach(f => { const li = document.createElement('li'); li.textContent = f; ul.appendChild(li); });
      row.parentNode.insertBefore(ul, row.nextSibling);
    }
  }
}

// Run one test in its own request. Each request is bounded by that single
// test's timeout, so it can never outlive the proxy request window (the tests
// that could are rendered CLI-only, never runnable here).
async function runOneRow(row) {
  const path = row.getAttribute('data-path');
  const cell = row.querySelector('.result');
  cell.innerHTML = '<span class="running">running…</span>';
  const nx = row.nextSibling; if (nx && nx.classList && nx.classList.contains('fails')) nx.remove();
  try {
    // Pass confirm for real-effect tests — the user already acknowledged the
    // side effect in runTest()/runTier(); the server also requires this flag.
    const needsConfirm = row.getAttribute('data-confirm') === '1';
    const run = await callRun({ test: path, confirm: needsConfirm });
    const r = (run.results || []).find(x => x.path === path) || (run.results || [])[0];
    renderResult(row, r);
  } catch (e) {
    cell.innerHTML = '<span class="no">' + (e.message || 'error') + '</span>';
  }
}

async function runTest(path, btn, confirmFirst) {
  if (confirmFirst && !confirm('This test has real side effects (live / prod-verify). Run it now?')) return;
  btn.disabled = true;
  try { await runOneRow(rowFor(path)); }
  finally { btn.disabled = false; }
}

// "Run all" runs the section's runnable rows one request at a time, client-side,
// rendering each as it lands — so no single request spans the whole tier and
// results are never lost to a proxy timeout. Runs exactly what the section
// shows (the CLI keeps cumulative safe+db semantics; the dashboard does not).
async function runTier(tier, btn) {
  const section = document.querySelector('.tier[data-tier="' + tier + '"]');
  const rows = Array.from(section.querySelectorAll('.row')).filter(r => r.getAttribute('data-runnable') === '1');
  if (!rows.length) { alert('Nothing runnable in this tier from the dashboard (locked or CLI-only).'); return; }
  const anyConfirm = rows.some(r => r.getAttribute('data-confirm') === '1');
  if (anyConfirm && !confirm('Run all ' + rows.length + ' runnable ' + tier + '-tier tests? Some have real external side effects.')) return;
  btn.disabled = true;
  rows.forEach(r => { r.querySelector('.result').innerHTML = '<span class="running">queued…</span>'; });
  try {
    for (const row of rows) { await runOneRow(row); }
  } finally { btn.disabled = false; }
}
</script>
</body>
</html>
