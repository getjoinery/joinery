<?php
/**
 * PushPanel — the "push notifications in this browser" box on the member and
 * admin notification preferences pages. assets/js/joinery-push.js drives it;
 * without JavaScript it says so and offers nothing.
 *
 * @version 1.0.0
 */
class PushPanel {

	public static function html(): string {
		$script = '/assets/js/joinery-push.js?v=' . (@filemtime(PathHelper::getIncludePath('assets/js/joinery-push.js')) ?: '1');
		return '<div class="jy-ui jy-push-panel" data-jy-push>'
			. '<p data-jy-push-status>Checking whether this browser can receive push notifications…</p>'
			. '<noscript><p>Push notifications need JavaScript.</p></noscript>'
			. '<p class="jy-push-actions">'
			. '<button type="button" class="btn btn-primary" data-jy-push-on hidden>Turn on in this browser</button> '
			. '<button type="button" class="btn btn-secondary" data-jy-push-off hidden>Turn off in this browser</button> '
			. '<button type="button" class="btn btn-outline" data-jy-push-test hidden>Send a test</button>'
			. '</p>'
			. '<p class="jy-push-help">Turned on, everything you are notified about here also appears on this computer or phone, even when the site is closed. Untick an event under "Push to my browsers" below to keep it off. Signing out of this browser turns push off in it.</p>'
			. '</div>'
			. '<script defer src="' . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . '"></script>';
	}
}
