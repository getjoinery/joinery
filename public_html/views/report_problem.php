<?php
/**
 * Report a problem.
 *
 * Top to bottom: where the report goes, the form for the reporter's own words
 * and an optional image, and everything the report will carry (built live for
 * this reporter and this error, and exactly what is saved and sent). The form
 * posts to the report_problem_submit API action; the page says what happened.
 *
 * Renders under the platform's member chrome, so it looks the same in every
 * theme and inside the mobile apps' webview, whose native picker handles the
 * image.
 *
 * See specs/implemented/bug_reports.md.
 *
 * @version 1.0.1
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/report_problem_logic.php'));

$page_vars = process_logic(report_problem_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
	'title' => $page_vars['page_title'],
]);

$inputs = $page_vars['inputs'];
?>
<div class="jy-ui">
<section class="jy-content-section">
	<div class="jy-container">
		<div class="jy-settings-shell">

			<div class="jy-page-header">
				<div class="jy-page-header-bar">
					<h1><?php echo htmlspecialchars($page_vars['page_title']); ?></h1>
				</div>
			</div>

			<?php if ($page_vars['sending_enabled']): ?>
			<div class="jy-callout jy-callout-info">
				<p>Your report goes to <strong><?php echo htmlspecialchars($page_vars['destination']); ?></strong>, the site this software is upgraded from, so the people who maintain it can fix the problem.</p>
				<p>Nothing private is included. Email addresses, network addresses and secrets are masked on this site before anything is sent. Everything below is exactly what will be sent, together with your description and image.</p>
			</div>
			<?php else: ?>
			<div class="jy-callout jy-callout-warning">
				<p>This site's administrator has switched off sending problem reports. Your report will be kept on this site, where the administrator can read it.</p>
			</div>
			<?php endif; ?>

			<div class="jy-panel" id="report-problem-form-panel">
				<h2>What went wrong?</h2>
				<p class="jy-muted">Say what you were trying to do and the steps that led to the problem, what you expected to happen, and what happened instead. Name the page you were on, and say whether it happens every time or only sometimes.</p>
				<?php
				$formwriter = $page->getFormWriter('report_problem_form', [
					'action'  => '/report_problem',
					'enctype' => 'multipart/form-data',
				]);
				$formwriter->begin_form();
				if ($inputs['ref'] !== null) {
					$formwriter->hiddeninput('ref', '', ['value' => $inputs['ref']]);
				}
				$formwriter->hiddeninput('from', '', ['value' => $inputs['from']]);
				$formwriter->hiddeninput('msg', '', ['value' => $inputs['msg']]);
				$formwriter->textarea('comment', 'Describe the problem', [
					'rows'        => 8,
					'placeholder' => 'For example: On my profile I changed my time zone and pressed Save. I expected it to save, but I got an error page instead. It happens every time.',
					'helptext'    => 'Up to ' . number_format($page_vars['comment_max']) . ' characters. This is sent as you write it.',
				]);
				$formwriter->fileinput('image', 'Screenshot (optional)', [
					'accept'   => 'image/png,image/jpeg,image/webp,image/gif',
					'helptext' => 'PNG, JPEG, WebP or GIF, up to 5 MB. Check that it shows nothing you would not want to send.',
				]);
				$formwriter->submitbutton('btn_submit', $page_vars['sending_enabled'] ? 'Send report' : 'Save report');
				$formwriter->end_form();
				?>
				<div class="jy-callout jy-callout-danger" id="report-problem-error" hidden></div>
			</div>

			<div class="jy-callout jy-callout-success" id="report-problem-done" hidden></div>

			<div class="jy-panel">
				<h2>What will be sent</h2>
				<?php if (!$page_vars['operator']): ?>
				<p class="jy-muted">Reports from this site's administrators also carry the site's recent errors, versions and settings names. Yours carries only what is shown here.</p>
				<?php endif; ?>
				<?php foreach ($page_vars['sections'] as $section): ?>
				<h3><?php echo htmlspecialchars($section[0]); ?></h3>
				<table class="data">
					<tbody>
					<?php foreach ($section[1] as $row): ?>
						<tr>
							<th scope="row" style="width: 35%"><?php echo htmlspecialchars($row[0]); ?></th>
							<td style="white-space: pre-wrap; overflow-wrap: anywhere; font-family: var(--jy-font-mono, monospace); font-size: .85em"><?php echo htmlspecialchars($row[1]); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endforeach; ?>
			</div>

		</div>
	</div>
</section>
</div>
<script>
(function () {
	var form = document.getElementById('report_problem_form');
	if (!form) return;
	var errorBox = document.getElementById('report-problem-error');
	var doneBox = document.getElementById('report-problem-done');
	var panel = document.getElementById('report-problem-form-panel');
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var button = form.querySelector('[type="submit"]');
		if (button) { button.disabled = true; }
		errorBox.hidden = true;
		window.joineryApi.postForm('report_problem_submit', new FormData(form)).then(function (data) {
			doneBox.textContent = data.message || 'Thank you. Your report is saved.';
			doneBox.hidden = false;
			panel.hidden = true;
			doneBox.scrollIntoView({ block: 'nearest' });
		}).catch(function (err) {
			errorBox.textContent = (err && err.message) || 'The report could not be saved. Please try again.';
			errorBox.hidden = false;
			if (button) { button.disabled = false; }
		});
	});
})();
</script>
<?php
$page->public_footer(['track' => TRUE]);
?>
