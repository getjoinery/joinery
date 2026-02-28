<?php
/**
 * Newsletter Signup Component
 *
 * Renders a mailing list signup form that posts to the existing
 * /list/{slug} or /lists endpoints. Uses no framework-specific CSS
 * classes — only plain HTML5 and inline styles, plus whatever
 * FormWriter is loaded by the active theme.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Data from newsletter_signup_logic()
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 *
 * @version 1.1.0
 */

// If mailing lists feature is disabled or no lists resolved, render nothing
if (empty($component_data['is_active']) || empty($component_data['mailing_lists'])) {
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

// Build background style
$bg_style = '';
switch ($background_type) {
	case 'color':
		$bg_style = "background-color: " . htmlspecialchars($background_color) . ";";
		break;
	case 'gradient':
		$bg_style = "background: linear-gradient(135deg, " . htmlspecialchars($gradient_start) . " 0%, " . htmlspecialchars($gradient_end) . " 100%);";
		break;
}
if ($text_color) {
	$bg_style .= " color: " . htmlspecialchars($text_color) . ";";
}

$align = htmlspecialchars($text_align);

// For single-list modes, if user is already subscribed, show message
$is_logged_in = $session && $session->get_user_id();
$already_subscribed = ($list_mode !== 'all') && $is_logged_in && $member_of_list;

// FormWriter setup
require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
$form_id = 'newsletter_signup' . ($component_slug ? '_' . $component_slug : '_' . uniqid());
$formwriter = new FormWriter($form_id);

$settings = Globalvars::get_instance();
?>

<section class="newsletter-signup" style="padding: 1.5rem 0; <?php echo $bg_style; ?>">
	<div style="max-width: 600px; margin: 0 auto; padding: 0 1rem; text-align: <?php echo $align; ?>;">
		<?php if ($heading): ?>
			<h2 style="margin: 0 0 0.5rem;"><?php echo htmlspecialchars($heading); ?></h2>
		<?php endif; ?>

		<?php if ($subheading): ?>
			<p style="margin: 0 0 1rem; opacity: 0.85;"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
		<?php endif; ?>

		<?php if ($already_subscribed): ?>
			<p>You are already subscribed.</p>
		<?php elseif ($compact_mode): ?>
			<style>
			.nsu-compact .form-group,
			.nsu-compact .mb-3 { margin-bottom: 0 !important; }
			.nsu-compact-row { display: flex; gap: 0.5rem; justify-content: <?php echo $align; ?>; flex-wrap: wrap; }
			.nsu-compact-row .nsu-email-wrap { flex: 1; max-width: 300px; min-width: 200px; }
			.nsu-antispam { text-align: center; margin-top: 0.5rem; }
			.nsu-antispam .form-group,
			.nsu-antispam .mb-3 { margin: 0 !important; display: inline-block; }
			.nsu-antispam input[type="text"] { width: 140px !important; padding: 0.25rem 0.5rem; font-size: 0.85em; }
			</style>
			<?php
			$formwriter->begin_form([
				'method' => 'POST',
				'action' => $form_action,
				'class' => 'nsu-compact',
			]);

			if ($list_mode !== 'all' && $mailing_lists) {
				$formwriter->hiddeninput('mlt_mailing_list_id', ['value' => $mailing_lists->key]);
				$formwriter->hiddeninput('mlt_mailing_list_id_subscribe', ['value' => 1]);
			}
			?>
			<div class="nsu-compact-row">
				<div class="nsu-email-wrap">
				<?php
				$formwriter->textinput('usr_email', '', [
					'maxlength' => 64,
					'required' => true,
					'type' => 'email',
					'placeholder' => 'Your email address',
				]);
				?>
				</div>
				<?php
				$formwriter->submitbutton('submit_button', $button_text);
				?>
			</div>
			<?php
			if (!$is_logged_in) {
				// Render antispam inline with placeholder instead of label
				$antispam_answer = strtolower($settings->get_setting('anti_spam_answer') ?: '');
				if ($antispam_answer) {
					echo '<div class="nsu-antispam">';
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
					$formwriter->hiddeninput('antispam_question_answer', ['value' => $antispam_answer]);
					echo '</div>';
				}
				$formwriter->honeypot_hidden_input();
				$formwriter->captcha_hidden_input();
			}

			$formwriter->end_form();
			?>
		<?php else: ?>
			<?php
			$formwriter->begin_form([
				'method' => 'POST',
				'action' => $form_action,
			]);
			?>

			<?php if (!$is_logged_in): ?>
				<?php if ($show_name_fields): ?>
					<?php
					$formwriter->textinput('usr_first_name', 'First Name', [
						'maxlength' => 32,
						'required' => true,
						'minlength' => 1,
						'data-msg-required' => 'Please enter your first name.',
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
				$formwriter->hiddeninput('form_submitted', ['value' => 1]);
				?>
			<?php else: ?>
				<?php
				$formwriter->hiddeninput('mlt_mailing_list_id', ['value' => $mailing_lists->key]);
				$formwriter->hiddeninput('mlt_mailing_list_id_subscribe', ['value' => 1]);
				?>
			<?php endif; ?>

			<?php
			if (!$is_logged_in) {
				$formwriter->antispam_question_input();
				$formwriter->honeypot_hidden_input();
				$formwriter->captcha_hidden_input();
			}
			?>

			<div style="margin-top: 1rem;">
			<?php
			$formwriter->submitbutton('submit_button', $button_text);
			?>
			</div>

			<?php $formwriter->end_form(); ?>
		<?php endif; ?>
	</div>
</section>
