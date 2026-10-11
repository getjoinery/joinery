<?php
/**
 * Boards plugin bootstrap — the plugin's declared load point (the top-level
 * `bootstrap` key in plugin.json), loaded once per request by PluginBootstraps
 * whenever a registry needs the plugin's callbacks live.
 *
 * It registers the upload purpose that lets a large attachment travel over the
 * platform's resumable chunked upload. The upload lands as the uploader's own
 * private File; the boards_upload action then claims it onto a card, which
 * re-points it at the board so everyone on the board can open it.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));

UploadPurposeRegistry::register('boards_attachment', array(
	'source' => File::SOURCE_BOARDS_ATTACHMENT,
	'label'  => 'board attachment',

	// The attachment limit the operator set, in bytes.
	'max_bytes' => Boards::settingInt('boards_max_attachment_mb', 25) * 1024 * 1024,

	// Private to the uploader until boards_upload claims it onto a card.
	'restrictions' => array('fil_private' => true),

	// An upload is opened against a board the person can edit. The card is
	// chosen when the file is claimed, so only the board is checked here.
	'authorize' => function (int $user_id, array $input): ?string {
		try {
			$user_id = Boards::requireMember();
			$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, 'editor');
			$name = (string)($input['name'] ?? '');
			if (BoardsUploads::isBlockedName($name)) {
				return 'That kind of file cannot be attached to a card.';
			}
		} catch (BoardsRefusal $e) {
			return $e->getMessage();
		}
		return null;
	},
));
