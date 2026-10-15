<?php
/**
 * Admin: Support Desk — the threads sites have opened, and what the desk could
 * not answer (specs/support_chatbot.md, The admin page).
 *
 * Five views:
 *   threads   waiting on a person first, then by last activity: the site, its
 *             release, joined or connected, thumbs, reads spent today, state
 *   gaps      questions the docs could not answer: every turn that handed off
 *             or was thumbed down, with the searches the model ran; each has
 *             Answer once, which writes a FAQ entry and answers the thread
 *   words     observe words the model reached for that a site's agent does
 *             not offer, by word and agent version
 *   faq       the FAQ entries this desk wrote, oldest first, with the thread
 *             each came from and how often it has been read since
 *   index     the releases indexed here and what each one's fixed prefix
 *             weighs; Index now runs the sweep
 *
 * Everything shown that a site sent is untrusted text, escaped here.
 *
 * @version 0.1.0
 */
$session = SessionControl::get_instance();
$session->check_permission(9);
$session->set_return();

$base = '/plugins/support_desk/admin/admin_support_threads';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'index_now') {
	$messages = SupportDocIndex::sweep();
	$session->save_message(new DisplayMessage(implode(' ', $messages), 'Index', NULL, DisplayMessage::MESSAGE_ANNOUNCEMENT));
	header('Location: ' . $base . '?view=index');
	exit;
}

$views = array('threads' => 'Threads', 'gaps' => 'Questions the docs could not answer', 'words' => 'Words the site could not read',
	'faq' => 'FAQ entries', 'index' => 'Index');
$view = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : 'threads';
$tz = $session->get_timezone();
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$when = function ($t, $fmt = 'M j, g:i A') use ($tz) { return $t ? LibraryFunctions::convert_time($t, 'UTC', $tz, $fmt) : ''; };

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'support-desk',
	'page_title'     => 'Support Desk',
	'readable_title' => 'Support Desk',
	'breadcrumbs'    => array('Support Desk' => ''),
	'session'        => $session,
));

$tabs = array();
foreach ($views as $k => $label) {
	$tabs[$label] = $base . '?view=' . $k;
}
echo AdminPage::subtab_menu($tabs, $views[$view]);

if ($view === 'threads') {
	echo '<p>Questions from the administrators of sites that upgrade from this node, answered by the model from the documentation of each site\'s release and, '
		. 'for a site joined here that allowed it, from reads of the site. Threads waiting for a person come first. '
		. 'Everything a site sent is shown as it arrived; treat it as untrusted.</p>';
	$state = isset(SupportThread::STATES[$_GET['state'] ?? '']) ? $_GET['state'] : null;
	$filter = array();
	if ($state) {
		$filter['state'] = $state;
	}
	$links = array('<a href="' . $base . '">All</a>');
	foreach (SupportThread::STATES as $k => $label) {
		$links[] = '<a href="' . $base . '?state=' . $k . '">' . $h($label) . '</a>';
	}
	echo '<p>' . implode(' · ', $links) . '</p>';
	$numperpage = 30;
	$offset = max(0, (int)($_GET['offset'] ?? 0));
	$threads = new MultiSupportThread($filter, array('sth_last_activity_time' => 'DESC'), $numperpage, $offset);
	$rows = array();
	foreach ($threads as $t) {
		$rows[] = $t;
	}
	usort($rows, function ($a, $b) {
		$wa = (string)$a->get('sth_state') === SupportThread::STATE_WAITING;
		$wb = (string)$b->get('sth_state') === SupportThread::STATE_WAITING;
		return $wa === $wb ? strcmp((string)$b->get('sth_last_activity_time'), (string)$a->get('sth_last_activity_time')) : ($wa ? -1 : 1);
	});
	$thumbs = SupportAdmin::thumbs(array_map(function ($t) { return (int)$t->key; }, $rows));
	$pager = new Pager(array('numrecords' => $threads->count_all(), 'numperpage' => $numperpage));
	$page->tableheader(array('Thread', 'Site', 'Release', 'Joined', 'Connected', 'Thumbs', 'Reads today', 'Tokens', 'State', 'Last activity'),
		array('title' => $state ? SupportThread::STATES[$state] : 'Threads'), $pager);
	foreach ($rows as $t) {
		$th = $thumbs[(int)$t->key] ?? array(0, 0);
		$page->disprow(array(
			'<a href="/plugins/support_desk/admin/admin_support_thread?sth_support_thread_id=' . (int)$t->key . '">#' . (int)$t->key . ' '
				. $h(mb_substr((string)$t->get('sth_title'), 0, 70)) . '</a>',
			$t->get('sth_surface') === SupportThread::SURFACE_WIDGET ? 'public widget' : $h($t->get('sth_host')),
			$h($t->get('sth_version')),
			$t->isJoined() ? 'yes' : '',
			$t->isConnected() ? 'yes' : '',
			($th[0] || $th[1]) ? $th[0] . ' up / ' . $th[1] . ' down' : '',
			$t->nodeReadsToday() ?: '',
			number_format((int)$t->get('sth_input_tokens') + (int)$t->get('sth_output_tokens')),
			$h($t->stateLabel()) . ((int)$t->get('sth_pinned_user_id') ? ' (taken)' : ''),
			$h($when($t->get('sth_last_activity_time'))),
		));
	}
	$page->endtable($pager);
} elseif ($view === 'gaps') {
	echo '<p>Every answer that handed the thread to a person or was thumbed down, newest first, with the searches the model ran. '
		. 'This is where the documentation is missing something. <strong>Answer once</strong> writes the general question and its answer as a FAQ entry '
		. 'the model reads from then on, and sends the answer to that thread as a reply from you.</p>';
	$page->tableheader(array('Question', 'Searches the model ran', 'Release', 'Site', 'Why', 'When', ''), array('title' => 'Questions the docs could not answer'));
	foreach (SupportGaps::unanswered() as $g) {
		$page->disprow(array(
			'<a href="/plugins/support_desk/admin/admin_support_thread?sth_support_thread_id=' . $g['thread_id'] . '">#' . $g['thread_id'] . '</a> '
				. $h(mb_substr($g['question'], 0, 200)),
			$g['queries'] ? '<small>' . $h(implode(' | ', $g['queries'])) . '</small>' : '<small>none</small>',
			$h($g['release']),
			$h($g['host']),
			$h($g['reason']),
			$h($when($g['time'])),
			$g['answered'] ? 'Answered' : '<a class="btn btn-outline-secondary btn-sm" href="/plugins/support_desk/admin/admin_support_faq_edit?sth_support_thread_id='
				. $g['thread_id'] . '">Answer once</a>',
		));
	}
	$page->endtable();
} elseif ($view === 'words') {
	echo '<p>Reads the model reached for that a joined site\'s agent does not offer, counted by word and agent version. '
		. 'A word asked for often on an old agent says the version floor should rise; one asked for on every agent says it belongs in the agent\'s vocabulary.</p>';
	$page->tableheader(array('Word', 'Agent version', 'Times', 'First', 'Last'), array('title' => 'Words the site could not read'));
	foreach (new MultiSupportWordGap(array(), array('swg_count' => 'DESC')) as $w) {
		$page->disprow(array($h($w->get('swg_word')), $h($w->get('swg_agent_version')), (int)$w->get('swg_count'),
			$h($when($w->get('swg_first_time'), 'M j, Y')), $h($when($w->get('swg_last_time')))));
	}
	$page->endtable();
} elseif ($view === 'faq') {
	echo '<p>Questions and answers this desk wrote. The model searches them like the documentation and carries them whole in every thread; nobody else sees them. '
		. 'Each says the release it was written on, oldest first so the stale ones come up for a reread. An entry every site should have belongs in '
		. '<code>docs/faq/</code> in the release.</p>';
	echo '<p><a class="btn btn-primary" href="/plugins/support_desk/admin/admin_support_faq_edit">New entry</a></p>';
	$page->tableheader(array('Question', 'Topic', 'Written on', 'From thread', 'Read since', 'Updated'), array('title' => 'FAQ entries'));
	foreach (new MultiSupportFaqEntry(array('deleted' => false), array('sfq_create_time' => 'ASC')) as $e) {
		$tid = (int)$e->get('sfq_sth_support_thread_id');
		$page->disprow(array(
			'<a href="/plugins/support_desk/admin/admin_support_faq_edit?sfq_faq_entry_id=' . (int)$e->key . '">' . $h($e->get('sfq_question')) . '</a>',
			$h($e->get('sfq_topic')),
			$h($e->get('sfq_release')) . ' <small>' . $h($when($e->get('sfq_create_time'), 'M j, Y')) . '</small>',
			$tid ? '<a href="/plugins/support_desk/admin/admin_support_thread?sth_support_thread_id=' . $tid . '">#' . $tid . '</a>' : '',
			(int)$e->get('sfq_cited_count'),
			$h($when($e->get('sfq_update_time'), 'M j, Y')),
		));
	}
	$page->endtable();
} else {
	echo '<p>Each release this node holds is indexed once: its documentation and shipped FAQ, split by heading. A thread searches the release its site runs '
		. '(or the nearest indexed one) and this node\'s public pages. The fixed prefix is what every thread on a release carries before its own facts: '
		. 'the voice, the rules, the documentation map and the whole FAQ. The provider caches it for an hour; the first thread after that pays to write it again.</p>';
	echo '<p>' . AdminPage::action_button('Index now', $base, array('hidden' => array('action' => 'index_now'))) . '</p>';
	$model = trim((string)Globalvars::get_instance()->get_setting('support_desk_model', true, true));
	$page->tableheader(array('Release', 'From', 'Sections', 'Fixed prefix (about)', 'Writing it to cache costs (about)', 'Indexed'), array('title' => 'Indexed releases'));
	foreach (new MultiSupportDocRelease(array(), array('sdr_built_time' => 'DESC')) as $r) {
		$fake = new SupportThread(NULL);
		$fake->set('sth_surface', SupportThread::SURFACE_SITE);
		$tokens = (int)ceil(mb_strlen(SupportPrompt::fixedPrefix($fake, (string)$r->get('sdr_release'))) / 4);
		$cost = '';
		if ($model !== '') {
			try {
				$usd = AiModelResolution::costFor($model, array('cache_creation_input_tokens' => $tokens));
				$cost = $usd > 0 ? '$' . number_format($usd, 2) . ' on ' . $model : '';
			} catch (\Throwable $e) {
				$cost = '';
			}
		}
		$page->disprow(array($h($r->get('sdr_release')), $h($r->get('sdr_source')), number_format((int)$r->get('sdr_sections')),
			number_format($tokens) . ' tokens', $h($cost !== '' ? $cost : 'set the model to see'), $h($when($r->get('sdr_built_time')))));
	}
	$page->endtable();
}

$page->admin_footer();
