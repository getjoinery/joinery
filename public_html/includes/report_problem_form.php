<?php
/**
 * The Report a problem page body, shared by its two pages: /report_problem
 * (member chrome, for members and the mobile apps) and
 * /admin/admin_report_problem (admin chrome, for administrators). Top to
 * bottom: where the report goes, the form for the reporter's own words and an
 * optional image, and everything the report will carry. The form posts to the
 * report_problem_submit API action through assets/js/report-problem.js; posted
 * to the page instead (before its script runs, or with none), the page's logic
 * sends it through the same action.
 *
 * @version 1.0.0
 */

/**
 * @param object $page      the page (PublicPage or AdminPage), for its FormWriter
 * @param array  $page_vars report_problem_logic()'s values
 * @param string $action    where the form posts when it is sent without its script
 */
function report_problem_render_body($page, array $page_vars, string $action): void {
	$inputs = $page_vars['inputs'];
	?>
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
			'action'  => $action,
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
	<?php
	echo PageScript::src('assets/js/report-problem.js', false);
}
?>
