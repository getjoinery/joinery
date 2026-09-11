<?php
/**
 * List Signup Component
 *
 * Renders a mailing list signup form that posts to the existing /list/{slug} or
 * /lists endpoints. Renders inside the `.jy-ui` kit; styling lives in the
 * `.jy-listsignup` section in joinery-styles.css. Background and text colour arrive
 * as --jy-ls-* custom properties (server-computed). Forms use FormWriter.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Data from list_signup_logic()
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 *
 * @version 1.5.0
 */

// Show configuration errors to admins, render nothing for regular visitors
$config_errors = $component_data['config_errors'] ?? [];
if (empty($component_data['is_active']) || empty($component_data['mailing_lists'])) {
	if (!empty($config_errors) && isset($_SESSION['permission']) && $_SESSION['permission'] >= 5) {
		echo '<div class="jy-ui jy-listsignup-error">';
		echo '<strong>List Signup Component — Configuration Issue</strong>';
		echo '<ul>';
		foreach ($config_errors as $err) {
			echo '<li>' . $err . '</li>';
		}
		echo '</ul></div>';
	}
	return;
}

// Extract config
$heading = $component_config['heading'] ?? 'Stay in Touch';
$subheading = $component_config['subheading'] ?? '';
$list_mode = $component_config['list_mode'] ?? 'default';
$button_text = $component_config['button_text'] ?? 'Subscribe';
$show_name_fields = $component_config['show_name_fields'] ?? true;
$compact_mode = $component_config['compact_mode'] ?? false;
$background_type = $component_config['background_type'] ?? 'none';
$background_color = $component_config['background_color'] ?? '#f8f9fa';
$gradient_start = $component_config['gradient_start'] ?? '#667eea';
$gradient_end = $component_config['gradient_end'] ?? '#764ba2';
$text_color = $component_config['text_color'] ?? '';
$text_align = $component_config['text_align'] ?? 'center';

// Extract data from logic function
$session = $component_data['session'];
$form_action = $component_data['form_action'];
$mailing_lists = $component_data['mailing_lists'];
$user_subscribed_list = $component_data['user_subscribed_list'] ?? [];
$member_of_list = $component_data['member_of_list'] ?? false;
$list_options = $component_data['list_options'] ?? [];

// Per-instance values as custom properties (server-computed inline)
$vars = '--jy-ls-align: ' . htmlspecialchars($text_align) . ';';
switch ($background_type) {
	case 'color':
		$vars .= ' --jy-ls-bg: ' . htmlspecialchars($background_color) . ';';
		break;
	case 'gradient':
		$vars .= ' --jy-ls-bg: linear-gradient(135deg, ' . htmlspecialchars($gradient_start) . ' 0%, ' . htmlspecialchars($gradient_end) . ' 100%);';
		break;
}
if ($text_color) {
	$vars .= ' --jy-ls-text: ' . htmlspecialchars($text_color) . ';';
}

// For single-list modes, if user is already subscribed, show message
$is_logged_in = $session && $session->get_user_id();
$already_subscribed = ($list_mode !== 'all') && $is_logged_in && $member_of_list;

// FormWriter setup
$form_id = 'list_signup' . ($component_slug ? '_' . $component_slug : '_' . uniqid());
$form_options = [
	'method' => 'POST',
	'action' => $form_action,
];
if ($compact_mode) {
	$form_options['class'] = 'lsu-compact';
}
$formwriter = new FormWriterV2HTML5($form_id, $form_options);

$settings = Globalvars::get_instance();
?>

<section class="jy-ui jy-listsignup" style="<?php echo $vars; ?>">
	<div class="jy-listsignup-inner">
		<?php if ($heading): ?>
			<h2><?php echo htmlspecialchars($heading); ?></h2>
		<?php endif; ?>

		<?php if ($subheading): ?>
			<p class="jy-listsignup-sub"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
		<?php endif; ?>

		<?php if ($already_subscribed): ?>
			<p>You are already subscribed.</p>
		<?php elseif ($compact_mode): ?>
			<?php
			$formwriter->begin_form();

			if ($is_logged_in): ?>
				<?php if ($list_mode === 'all'): ?>
					<div class="lsu-checkbox-list">
					<?php
					$formwriter->checkboxList('new_list_subscribes', 'Choose your lists:', [
						'options' => $list_options,
						'checked' => $user_subscribed_list,
					]);
					$formwriter->hiddeninput('form_submitted', '', ['value' => 1]);
					?>
					</div>
				<?php else: ?>
					<p class="lsu-list-name">Subscribe to <strong><?php echo htmlspecialchars($mailing_lists->get('mlt_name')); ?></strong></p>
					<?php
					$formwriter->hiddeninput('mlt_mailing_list_id', '', ['value' => $mailing_lists->key]);
					$formwriter->hiddeninput('mlt_mailing_list_id_subscribe', '', ['value' => 1]);
					?>
				<?php endif; ?>
				<div class="lsu-compact-row">
					<?php $formwriter->submitbutton('submit_button', $button_text); ?>
				</div>
			<?php else: ?>
				<?php
				if ($list_mode !== 'all' && $mailing_lists) {
					$formwriter->hiddeninput('mlt_mailing_list_id', '', ['value' => $mailing_lists->key]);
					$formwriter->hiddeninput('mlt_mailing_list_id_subscribe', '', ['value' => 1]);
				}
				?>
				<div class="lsu-compact-row">
					<div class="lsu-email-wrap">
					<?php
					$formwriter->textinput('usr_email', '', [
						'maxlength' => 64,
						'required' => true,
						'type' => 'email',
						'placeholder' => 'Your email address',
					]);
					?>
					</div>
					<?php $formwriter->submitbutton('submit_button', $button_text); ?>
				</div>
				<?php
				// Render antispam inline with placeholder instead of label
				$antispam_answer = strtolower($settings->get_setting('anti_spam_answer') ?: '');
				if ($antispam_answer) {
					echo '<div class="lsu-antispam">';
					$formwriter->textinput('antispam_question', '', [
						'placeholder' => "Type '" . $antispam_answer . "' here",
						'required' => true,
						'validation' => [
							'required' => true,
							'matches' => 'antispam_question_answer',
							'messages' => [
								'required' => 'This field is required.',
								'matches' => 'You must type the correct word here',
							],
						],
					]);
					$formwriter->hiddeninput('antispam_question_answer', '', ['value' => $antispam_answer]);
					echo '</div>';
				}
				$formwriter->honeypot_hidden_input();
				$formwriter->captcha_hidden_input();
				?>
			<?php endif;

			$formwriter->end_form();
			?>
		<?php else: ?>
			<?php
			$formwriter->begin_form();
			?>

			<?php if ($is_logged_in && $list_mode !== 'all' && $mailing_lists): ?>
				<p>Subscribe to <strong><?php echo htmlspecialchars($mailing_lists->get('mlt_name')); ?></strong></p>
			<?php endif; ?>

			<?php if (!$is_logged_in): ?>
				<?php if ($show_name_fields): ?>
					<?php
					$formwriter->textinput('usr_first_name', 'First Name', [
						'maxlength' => 32,
						'required' => true,
						'minlength' => 1,
						'validation' => ['messages' => ['required' => 'Please enter your first name.']],
					]);

					$formwriter->textinput('usr_last_name', 'Last Name', [
						'maxlength' => 32,
						'required' => true,
					]);

					$nickname_display = $settings->get_setting('nickname_display_as');
					if ($nickname_display) {
						$formwriter->textinput('usr_nickname', $nickname_display, [
							'maxlength' => 32,
						]);
					}
					?>
				<?php endif; ?>

				<?php
				$formwriter->textinput('usr_email', 'Email', [
					'maxlength' => 64,
					'required' => true,
					'type' => 'email',
				]);

				$optionvals = Address::get_timezone_drop_array();
				$default_timezone = $settings->get_setting('default_timezone');
				$formwriter->dropinput('usr_timezone', 'Your timezone', [
					'options' => $optionvals,
					'value' => $default_timezone,
				]);

				$formwriter->checkboxinput('privacy', 'I consent to the privacy policy.', [
					'required' => true,
					'checked' => true,
				]);
				?>
			<?php endif; ?>

			<?php if ($list_mode === 'all'): ?>
				<?php
				$formwriter->checkboxList('new_list_subscribes', 'Choose your lists:', [
					'options' => $list_options,
					'checked' => $user_subscribed_list,
				]);
				$formwriter->hiddeninput('form_submitted', '', ['value' => 1]);
				?>
			<?php else: ?>
				<?php
				$formwriter->hiddeninput('mlt_mailing_list_id', '', ['value' => $mailing_lists->key]);
				$formwriter->hiddeninput('mlt_mailing_list_id_subscribe', '', ['value' => 1]);
				?>
			<?php endif; ?>

			<?php
			if (!$is_logged_in) {
				$formwriter->antispam_question_input();
				$formwriter->honeypot_hidden_input();
				$formwriter->captcha_hidden_input();
			}
			?>

			<div class="jy-listsignup-submit">
			<?php
			$formwriter->submitbutton('submit_button', $button_text);
			?>
			</div>

			<?php $formwriter->end_form(); ?>
		<?php endif; ?>
	</div>
</section>
