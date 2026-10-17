// Joinery Drive settings page.
//
// Served by the daemon on loopback and talks to nothing else. Two rules hold
// throughout, and they are why this file looks the way it does:
//
// - Every request carries the session in a header, never a cookie. A browser
//   attaches cookies to requests other sites make; it attaches a header only
//   where it was told to, so no other page can act as this one.
// - Everything shown is set as text, never parsed as markup. Issue summaries
//   and folder names carry names that another device, or another person, chose.
(function () {
  'use strict';

  var SESSION_KEY = 'joinery-drive-session';
  var STATUS_EVERY = 2000;
  var SETUP_EVERY = 1000;
  var FOLDERS_EVERY = 30000;
  var TITLES = {
    green: 'Up to date',
    working: 'Syncing',
    attention: 'Needs your attention',
    stopped: 'Not syncing'
  };
  var STAGES = ['instance', 'starting', 'approve', 'folder', 'unlinking'];
  var STEP_OF = { instance: 1, starting: 1, approve: 2, folder: 3, unlinking: 0 };
  var FOLDER_WORDS = {
    synced: '',
    pending: 'syncing',
    not_here: 'not on this computer',
    waiting_for_key: 'waiting for a key',
    unsyncable: 'cannot be kept here',
    conflict: 'conflict'
  };

  var session = null;
  var section = null;
  var scrolled = false;
  var pollTimer = null;
  var foldersAt = 0;
  var foldersKey = null;
  var lastStage = null;
  var localError = null;

  function $(id) { return document.getElementById(id); }
  function text(node, value) { node.textContent = value == null ? '' : String(value); }
  function show(node, on) { node.hidden = !on; }
  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
  function make(tag, cls, value) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (value != null) node.textContent = String(value);
    return node;
  }
  function callout(node, message) {
    text(node, message || '');
    show(node, !!message);
  }

  // ---------------------------------------------------------------------
  // Getting in
  // ---------------------------------------------------------------------

  // The tray opens this page as /settings#t=<ticket>&s=<section>. The ticket
  // is in the fragment because a browser sends a fragment nowhere; it is taken
  // out of the address bar at once, and is spent in a moment anyway.
  function takeFragment() {
    var hash = location.hash.replace(/^#/, '');
    if (!hash) return null;
    var params = new URLSearchParams(hash);
    var wanted = params.get('s') || (/^[a-z-]{1,32}$/.test(hash) ? hash : null);
    if (wanted && /^[a-z-]{1,32}$/.test(wanted)) section = wanted;
    history.replaceState(null, '', location.pathname + (section ? '#' + section : ''));
    return params.get('t');
  }

  function loadSession() {
    try { return sessionStorage.getItem(SESSION_KEY); } catch (e) { return null; }
  }

  function saveSession(value) {
    try {
      if (value) sessionStorage.setItem(SESSION_KEY, value);
      else sessionStorage.removeItem(SESSION_KEY);
    } catch (e) { /* storage refused: the session lasts as long as the tab */ }
  }

  function start() {
    wire();
    var ticket = takeFragment();
    session = loadSession();
    if (!ticket) { begin(); return; }
    fetch('/settings/session', {
      method: 'POST',
      headers: { 'X-Joinery-Ticket': ticket },
      cache: 'no-store',
      credentials: 'omit',
      redirect: 'error'
    }).then(function (response) {
      return response.ok ? response.json() : null;
    }).then(function (data) {
      if (data && data.session) {
        session = data.session;
        saveSession(session);
      }
      begin();
    }).catch(begin);
  }

  function begin() {
    if (!session) { noSession(); return; }
    poll();
  }

  function noSession() {
    clearTimeout(pollTimer);
    show($('connecting'), false);
    show($('setup'), false);
    show($('linked'), false);
    show($('unreachable'), false);
    show($('no-session'), true);
  }

  function endSession() {
    session = null;
    saveSession(null);
    noSession();
  }

  function api(method, path, body) {
    var headers = { 'X-Joinery-Session': session || '' };
    var init = {
      method: method,
      headers: headers,
      cache: 'no-store',
      credentials: 'omit',
      redirect: 'error'
    };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(path, init).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (response.status === 401) {
          endSession();
          var gone = new Error('This page has expired.');
          gone.session = true;
          throw gone;
        }
        if (!response.ok) {
          var failed = new Error(data.error || ('Joinery Drive answered ' + response.status + '.'));
          failed.status = response.status;
          throw failed;
        }
        return data;
      });
    });
  }

  // ---------------------------------------------------------------------
  // Keeping up
  // ---------------------------------------------------------------------

  function poll() {
    clearTimeout(pollTimer);
    api('GET', '/status').then(function (status) {
      show($('unreachable'), false);
      render(status);
      pollTimer = setTimeout(poll, status.linked === false ? SETUP_EVERY : STATUS_EVERY);
    }).catch(function (e) {
      if (e && e.session) return;
      show($('connecting'), false);
      show($('unreachable'), true);
      pollTimer = setTimeout(poll, STATUS_EVERY);
    });
  }

  function render(status) {
    show($('connecting'), false);
    text($('version'), status.version ? 'Version ' + status.version : '');
    if (status.linked === false) {
      show($('linked'), false);
      show($('setup'), true);
      renderSetup(status.setup || {});
    } else {
      show($('setup'), false);
      show($('linked'), true);
      lastStage = null;
      renderLinked(status);
    }
  }

  // ---------------------------------------------------------------------
  // First run
  // ---------------------------------------------------------------------

  function renderSetup(setup) {
    var stage = STAGES.indexOf(setup.stage) >= 0 ? setup.stage : 'instance';
    STAGES.forEach(function (s) { show($('stage-' + s), s === stage); });
    var step = STEP_OF[stage];
    [1, 2, 3].forEach(function (n) {
      $('step-' + n).className = n < step ? 'done' : (n === step ? 'current' : '');
    });
    if (stage !== lastStage) {
      localError = null;
      enterStage(stage, setup);
      lastStage = stage;
    }
    callout($('setup-notice'), stage === 'instance' ? setup.notice : null);
    callout($('setup-error'), localError || setup.error);

    if (stage === 'starting') text($('starting-url'), setup.base_url);
    if (stage === 'approve') {
      text($('approve-lead'), setup.browser_opened
        ? 'A page has opened in your browser on ' + setup.base_url + '. Sign in there if you need to, and approve this computer. It will show this code:'
        : 'Open the approval page below in a browser where you are signed in to ' + setup.base_url + ', and approve this computer. It will show this code:');
      text($('link-code'), setup.link_code);
      var link = $('verify-link');
      var safe = /^https?:\/\//i.test(setup.verify_url || '');
      if (safe) link.href = setup.verify_url;
      show(link.parentNode, safe);
    }
    if (stage === 'folder') {
      text($('folder-site'), setup.base_url);
      // The daemon says why when it is more than an unticked box: a session
      // that cannot open the credential store keeps no key to encrypted folders.
      text($('folder-vault'), setup.vault
        ? 'Encrypted folders are enabled on this computer.'
        : (setup.vault_note || 'Encrypted folders are not enabled on this computer. To have them, tick that box on the approval page the next time you link it.'));
    }
  }

  function enterStage(stage, setup) {
    setBusy(false);
    if (stage === 'instance') {
      var name = $('device-name');
      if (!name.value) name.value = setup.device_name || '';
      $('base-url').focus();
    }
    if (stage === 'folder') {
      var root = $('sync-root');
      if (!root.value) root.value = setup.suggested_root || '';
      show($('folder-confirm'), false);
    }
  }

  function setBusy(on) {
    $('begin').disabled = on;
    $('finish').disabled = on;
    $('folder-confirm-yes').disabled = on;
  }

  function setupError(message) {
    localError = message;
    callout($('setup-error'), message);
  }

  function beginLink(ev) {
    ev.preventDefault();
    setBusy(true);
    setupError(null);
    api('POST', '/setup/begin', {
      base_url: $('base-url').value,
      device_name: $('device-name').value
    }).then(function () {
      poll();
    }).catch(function (e) {
      if (!e.session) setupError(e.message);
    }).then(function () { setBusy(false); });
  }

  function finish(confirmed) {
    setBusy(true);
    setupError(null);
    api('POST', '/setup/finish', {
      sync_root: $('sync-root').value,
      confirm: !!confirmed,
      autostart: $('autostart-setup').checked
    }).then(function (data) {
      if (data.ok === false && data.confirm) {
        text($('folder-confirm-text'), data.confirm);
        show($('folder-confirm'), true);
      } else {
        show($('folder-confirm'), false);
        poll();
      }
    }).catch(function (e) {
      if (!e.session) setupError(e.message);
    }).then(function () { setBusy(false); });
  }

  function cancelSetup() {
    api('POST', '/setup/cancel').then(poll).catch(function (e) {
      if (!e.session) setupError(e.message);
    });
  }

  // ---------------------------------------------------------------------
  // Linked
  // ---------------------------------------------------------------------

  function renderLinked(s) {
    var indicator = TITLES[s.indicator] ? s.indicator : 'stopped';
    $('status-dot').className = 'dot ' + indicator;
    text($('status-title'), s.paused ? 'Paused' : TITLES[indicator]);
    text($('status-summary'), s.summary);
    callout($('status-blocker'), s.blocker && s.blocker !== s.summary ? s.blocker : null);
    facts($('status-facts'), [
      ['Items', s.tracked != null ? s.settled + ' of ' + s.tracked + ' settled' : null],
      ['Transfers waiting', s.pending_ops ? s.pending_ops : null],
      ['Waiting for a key', s.waiting_for_keys ? s.waiting_for_keys : null],
      ['Last checked', s.last_pass_ms ? ago(s.last_pass_ms) : null]
    ]);

    var pause = $('pause-toggle');
    text(pause, s.paused ? 'Resume syncing' : 'Pause syncing');
    pause.dataset.action = s.paused ? '/resume' : '/pause';
    // Checking while paused would do nothing, and a button that does nothing
    // is worse than one that is not there.
    show($('sync-now'), !s.paused);

    renderIssues(s);

    text($('sync-root-shown'), s.sync_root);
    facts($('account-facts'), [
      ['Site', s.base_url],
      ['This computer', s.device_name],
      ['Keys are stored', s.custody],
      ['Encrypted folders', vaultState(s)]
    ]);
    var auto = $('autostart');
    var knows = typeof s.autostart === 'boolean';
    show(auto.parentNode, knows);
    if (knows && document.activeElement !== auto) auto.checked = s.autostart;

    renderUpdate(s.update);
    text($('unlink-site'), s.base_url);

    if (Date.now() - foldersAt > FOLDERS_EVERY) loadFolders();
    if (section && !scrolled) {
      var target = $(section);
      if (target) target.scrollIntoView();
      scrolled = true;
    }
  }

  // Three states, not two: a computer holding a vault key the account has
  // since replaced is neither one that can open encrypted folders nor one that
  // never had them, and the fix for it is different from both.
  function vaultState(s) {
    if (s.vault_relink) {
      return 'The key was replaced in the browser. Link this computer again (joinery-drive login, from the desktop session) to open encrypted files saved since.';
    }
    return s.vault ? 'Can be opened on this computer' : 'Not enabled on this computer';
  }

  function facts(list, rows) {
    clear(list);
    rows.forEach(function (row) {
      if (row[1] == null || row[1] === '') return;
      list.appendChild(make('dt', null, row[0]));
      list.appendChild(make('dd', null, row[1]));
    });
  }

  function ago(ms) {
    var seconds = Math.round((Date.now() - ms) / 1000);
    if (seconds < 5) return 'just now';
    if (seconds < 90) return seconds + ' seconds ago';
    if (seconds < 90 * 60) return Math.round(seconds / 60) + ' minutes ago';
    return new Date(ms).toLocaleString();
  }

  function renderIssues(s) {
    var list = $('issues-list');
    var issues = s.issues || [];
    // Deletes the guard is holding come first, and come even when the capped
    // list left their issue out: nothing more is deleted until somebody
    // answers, and this is where they answer.
    var held = s.held_deletes && s.held_deletes.held_set ? s.held_deletes : null;
    var rest = held ? issues.filter(function (i) { return i.id !== held.issue_id; }) : issues;
    var key = (held ? held.issue_id + ':' + held.held_set + '|' : '') +
      rest.map(function (i) { return i.id; }).join(',');
    // Redrawn only when the list changed, so a button somebody is about to
    // press does not move out from under them every two seconds.
    if (list.dataset.key !== key) {
      list.dataset.key = key;
      clear(list);
      if (held) list.appendChild(heldItem(held));
      rest.forEach(function (issue) {
        var item = make('li');
        var body = make('div');
        body.appendChild(make('span', null, issue.summary));
        if (issue.created_at) body.appendChild(make('span', 'when', new Date(issue.created_at).toLocaleString()));
        var dismiss = make('button', 'small', 'Dismiss');
        dismiss.type = 'button';
        dismiss.addEventListener('click', function () {
          dismiss.disabled = true;
          act('/dismiss', { issue_id: issue.id });
        });
        item.appendChild(body);
        item.appendChild(dismiss);
        list.appendChild(item);
      });
    }
    // The full count, not the length of the list: the daemon sends at most
    // fifty, and saying fifty when there are three hundred is understating.
    var total = s.issues_total != null ? s.issues_total : issues.length;
    show($('issues-none'), total === 0);
    var more = total - rest.length - (held ? 1 : 0);
    show($('issues-more'), more > 0);
    if (more > 0) text($('issues-more'), 'And ' + more + ' more. Dealing with these will show the rest.');
  }

  // The held deletes, in the engine's words, with one button that approves
  // exactly the set those words name. Pressed once it asks again, as
  // unlinking does: deletes carried out are carried out. If what is held has
  // changed by then, the daemon refuses and the redrawn item shows the new
  // set.
  function heldItem(held) {
    var item = make('li');
    var body = make('div');
    body.appendChild(make('span', null, held.detail || held.summary));
    var approve = make('button', 'small danger', 'Approve these deletes');
    approve.type = 'button';
    approve.addEventListener('click', function () {
      if (approve.dataset.armed !== 'yes') {
        approve.dataset.armed = 'yes';
        text(approve, 'Yes, delete them');
        return;
      }
      approve.disabled = true;
      act('/approve-deletes', { fingerprint: held.held_set }).catch(function () {
        approve.disabled = false;
      });
    });
    item.appendChild(body);
    item.appendChild(approve);
    return item;
  }

  function loadFolders() {
    foldersAt = Date.now();
    api('GET', '/folders').then(renderTree).catch(function () { /* tried again later */ });
  }

  function renderTree(data) {
    var folders = data.folders || [];
    var key = JSON.stringify(folders);
    if (key === foldersKey) return;
    foldersKey = key;

    var tree = $('tree');
    var open = {};
    tree.querySelectorAll('details[open]').forEach(function (d) { open[d.dataset.id] = true; });

    var known = {};
    folders.forEach(function (f) { known[f.id] = true; });
    var children = {};
    folders.forEach(function (f) {
      var parent = f.parent != null && known[f.parent] ? f.parent : 'top';
      (children[parent] = children[parent] || []).push(f);
    });

    function label(f) {
      var words = FOLDER_WORDS[f.state] || '';
      if (f.encrypted) words = words ? words + ', encrypted' : 'encrypted';
      var parts = [make('span', null, f.name)];
      if (words) parts.push(make('span', 'state ' + f.state, words));
      return parts;
    }

    function build(parent, into, depth) {
      (children[parent] || []).forEach(function (f) {
        var item = make('li');
        var below = children[f.id];
        if (below && depth < 32) {
          var details = make('details');
          details.dataset.id = String(f.id);
          if (open[details.dataset.id]) details.open = true;
          var summary = make('summary');
          label(f).forEach(function (p) { summary.appendChild(p); });
          details.appendChild(summary);
          var nested = make('ul');
          build(f.id, nested, depth + 1);
          details.appendChild(nested);
          item.appendChild(details);
        } else {
          item.className = 'leaf';
          label(f).forEach(function (p) { item.appendChild(p); });
        }
        into.appendChild(item);
      });
    }

    clear(tree);
    build('top', tree, 0);
    show($('tree-empty'), folders.length === 0 && !data.loading);
    show($('tree-more'), !!data.truncated);
    if (data.truncated) text($('tree-more'), 'Showing ' + folders.length + ' of ' + data.total + ' folders.');
  }

  // Shown only when the daemon reports update state.
  function renderUpdate(update) {
    var card = $('updates');
    if (!update) { show(card, false); return; }
    show(card, true);
    var words = typeof update === 'string'
      ? update
      : (update.summary || update.message || update.state || '');
    text($('update-summary'), words);
  }

  function act(path, body) {
    callout($('action-error'), null);
    return api('POST', path, body).then(function (data) {
      if (data && data.ok === false && !data.confirm) {
        callout($('action-error'), 'Joinery Drive could not do that just now. Try again in a moment.');
      }
      poll();
      return data;
    }).catch(function (e) {
      if (!e.session) callout($('action-error'), e.message);
      throw e;
    });
  }

  // ---------------------------------------------------------------------
  // Buttons
  // ---------------------------------------------------------------------

  function wire() {
    $('instance-form').addEventListener('submit', beginLink);
    $('folder-form').addEventListener('submit', function (ev) { ev.preventDefault(); finish(false); });
    $('folder-confirm-yes').addEventListener('click', function () { finish(true); });
    $('folder-confirm-no').addEventListener('click', function () {
      show($('folder-confirm'), false);
      $('sync-root').focus();
    });
    $('cancel-approve').addEventListener('click', cancelSetup);
    $('cancel-folder').addEventListener('click', cancelSetup);

    $('pause-toggle').addEventListener('click', function () {
      act($('pause-toggle').dataset.action || '/pause').catch(function () {});
    });
    $('sync-now').addEventListener('click', function () { act('/sync-now').catch(function () {}); });
    $('autostart').addEventListener('change', function () {
      var box = $('autostart');
      act('/autostart', { on: box.checked }).catch(function () { box.checked = !box.checked; });
    });
    $('check-update').addEventListener('click', function () {
      var button = $('check-update');
      button.disabled = true;
      act('/check-update').catch(function (e) {
        // A daemon that reports update state but cannot be asked to check.
        if (e.status === 404 || e.status === 403) show(button, false);
      }).then(function () { button.disabled = false; });
    });

    $('unlink').addEventListener('click', function () {
      show($('unlink-ask'), false);
      show($('unlink-confirm'), true);
    });
    $('unlink-no').addEventListener('click', function () {
      show($('unlink-confirm'), false);
      show($('unlink-ask'), true);
    });
    $('unlink-yes').addEventListener('click', function () {
      $('unlink-yes').disabled = true;
      act('/unlink', { confirm: true }).catch(function () {}).then(function () {
        $('unlink-yes').disabled = false;
        show($('unlink-confirm'), false);
        show($('unlink-ask'), true);
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
