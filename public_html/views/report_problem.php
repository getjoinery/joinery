<?php
/**
 * Report a problem.
 *
 * Top to bottom: where the report goes, the form for the reporter's own words
 * and an optional image, and everything the report will carry (built live for
 * this reporter and this error, and exactly what is saved and sent). The form
 * posts to the report_problem_submit API action; the page says what happened.
 * Posted to this page instead (before its script runs, or with none), the
 * logic sends it through the same action.
 *
 * Renders under the platform's member chrome, so it looks the same in every
 * theme and inside the mobile apps' webview, whose native picker handles the
 * image.
 *
 * See specs/implemented/bug_reports.md.
 *
 * @version 1.0.3 - a form posted here natively is still sent (report_problem_logic 1.1.0); the script is not deferred
 * @version 1.0.2 - the form's script is assets/js/report-problem.js (no inline script)
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

			<div class="jy-panel" id="report-problem-form-panel"<?php echo $page_vars['submit_message'] !== null ? ' hidden' : ''; ?>>
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
				<div class="jy-callout jy-callout-danger" id="report-problem-error"<?php echo $page_vars['submit_error'] === null ? ' hidden' : ''; ?>><?php echo htmlspecialchars((string)$page_vars['submit_error']); ?></div>
			</div>

			<div class="jy-callout jy-callout-success" id="report-problem-done"<?php echo $page_vars['submit_message'] === null ? ' hidden' : ''; ?>><?php echo htmlspecialchars((string)$page_vars['submit_message']); ?></div>

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
<?php echo PageScript::src('assets/js/report-problem.js', false); ?>
<?php
$page->public_footer(['track' => TRUE]);
?>
