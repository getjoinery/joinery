<?php
/**
 * API action: content_pack_apply — apply a checked content pack.
 *
 * POST /api/v1/action/content_pack_apply (browser session, superadmin):
 *   apply_id   the record content_pack_check made
 *   plan_hash  the hash of the plan the owner was shown
 *   ticks      the plan lines the owner left ticked
 *
 * The plan is built again from the stored pack inside the apply's transaction
 * and refused if it no longer hashes to plan_hash, so what is written is what
 * the owner read. A failure leaves nothing written but the failure itself,
 * on the apply record.
 *
 * See specs/content_pack_feature.md.
 *
 * @version 1.0
 */

function content_pack_apply_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if (!Access::decide(10, $session)) {
		return LogicResult::error('Only a superadmin applies a content pack.');
	}

	$apply = new ContentPackApply((int)($input['apply_id'] ?? 0), TRUE);
	if (!$apply->key) {
		return LogicResult::error('There is no checked content pack with that id.');
	}
	if ($apply->get('cpa_status') !== ContentPackApply::STATUS_CHECKED) {
		return LogicResult::error('This upload was already ' . $apply->get('cpa_status') . '. Check the pack again to apply it again.');
	}
	$ticks = $input['ticks'] ?? array();
	if (!is_array($ticks)) {
		return LogicResult::error('"ticks" must be a list of plan lines.');
	}

	try {
		$pack = ContentPack::read($apply->file_bytes());
		$written = $pack->apply($apply, (string)($input['plan_hash'] ?? ''), $ticks, $session);
	} catch (Throwable $e) {
		// The transaction is rolled back; the failure is the one thing kept.
		$failed = new ContentPackApply($apply->key, TRUE);
		$failed->set('cpa_status', ContentPackApply::STATUS_FAILED);
		$failed->set('cpa_ticks', json_encode(array_values($ticks)));
		$failed->set('cpa_error', $e->getMessage());
		$failed->save();
		if (!($e instanceof ContentPackRefusal) && !($e instanceof DisplayableErrorMessage)) {
			error_log('content_pack_apply: apply #' . $apply->key . ' failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
		}
		return LogicResult::error('Nothing was applied. ' . $e->getMessage());
	}

	return LogicResult::render(array(
		'apply_id' => (int)$apply->key,
		'written' => $written,
	));
}

function content_pack_apply_logic_descriptor(): array {
	return [
		'description' => 'Apply a checked content pack: the lines ticked from the plan it was checked with, in one transaction.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
			'min_user_permission'      => 10,
		],
		'input'       => [
			'apply_id'  => ['type' => 'int', 'required' => true],
			'plan_hash' => ['type' => 'string', 'required' => true],
			'ticks'     => ['type' => 'array', 'required' => false],
		],
	];
}
