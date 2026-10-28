<?php
/**
 * The Content Packs page: upload a pack, read its plan, tick and apply.
 *
 * The check and the apply are the content_pack_check and content_pack_apply
 * API actions; this page posts its forms to itself and calls them.
 *
 * See specs/content_pack_feature.md.
 *
 * @version 1.0
 */

function admin_content_packs_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('logic/content_pack_check_logic.php'));
	require_once(PathHelper::getIncludePath('logic/content_pack_apply_logic.php'));
	require_once(PathHelper::getIncludePath('logic/content_pack_history_logic.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	$say = function ($message, $title, $ok, $to) use ($session) {
		$session->save_message(new DisplayMessage($message, $title, '/\/admin\/admin_content_packs/',
			$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($to);
	};

	if (LibraryFunctions::isFormSubmission() && isset($input['action'])) {
		if ($input['action'] === 'check') {
			$result = content_pack_check_logic($input);
			if ($result->error) {
				return $say($result->error, 'Pack refused', false, '/admin/admin_content_packs');
			}
			return LogicResult::redirect('/admin/admin_content_packs?cpa_id=' . (int)$result->data['apply_id']);
		}
		if ($input['action'] === 'apply') {
			$apply_id = (int)($input['cpa_id'] ?? 0);
			$result = content_pack_apply_logic(array(
				'apply_id' => $apply_id,
				'plan_hash' => (string)($input['plan_hash'] ?? ''),
				'ticks' => is_array($input['ticks'] ?? null) ? $input['ticks'] : array(),
			));
			if ($result->error) {
				return $say($result->error, 'Not applied', false, '/admin/admin_content_packs?cpa_id=' . $apply_id);
			}
			$count = count($result->data['written']);
			return $say($count ? 'Applied: ' . $count . ' ' . ($count === 1 ? 'row or setting' : 'rows and settings') . ' written.'
					: 'Applied. Nothing needed writing.',
				'Content pack applied', true, '/admin/admin_content_packs?cpa_id=' . $apply_id);
		}
	}

	$page_vars = array('session' => $session, 'settings' => $settings, 'apply' => null, 'plan' => null, 'plan_error' => null);

	if (!empty($input['cpa_id'])) {
		$apply = new ContentPackApply((int)$input['cpa_id'], TRUE);
		if (!$apply->key) {
			return LogicResult::error('There is no content pack upload with that id.');
		}
		$page_vars['apply'] = $apply;
		if ($apply->get('cpa_status') === ContentPackApply::STATUS_CHECKED) {
			// Built fresh on every view: the plan is what the site holds now.
			try {
				$page_vars['plan'] = ContentPack::read($apply->file_bytes())->plan();
			} catch (ContentPackRefusal $e) {
				$page_vars['plan_error'] = $e->getMessage();
			}
		} else {
			$page_vars['plan'] = json_decode((string)$apply->get('cpa_plan'), true);
		}
		return LogicResult::render($page_vars);
	}

	$history = content_pack_history_logic(array());
	$page_vars['history'] = $history->error ? array() : $history->data['applies'];
	return LogicResult::render($page_vars);
}
