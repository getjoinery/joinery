<?php
/**
 * CloudStoreInventoryPanel — what the cloud-storage page and the Backups page
 * say about the daily file-store check, in one place so both say the same.
 *
 * The check (CloudStoreInventory) HEADs every offloaded file in the file
 * bucket once a day and writes down the ones it cannot serve. This turns that
 * record into the block both pages show:
 *
 *   - when it last looked and how many files it checked;
 *   - "N offloaded files are missing from the file store; the backup holds M
 *     of them" when any are, with the one action that follows — Bring them
 *     back — which on a site with a backup target of its own runs here
 *     (BackupObjectRestoreLauncher), and on a site backed up only by its
 *     management node is a job that node starts from its Backups tab, so the
 *     block says so and points there;
 *   - what the last Bring them back did, or that one is running.
 *
 * Pure over the summary it is handed; nothing here reads a setting or a row.
 *
 * @version 1.0.1 - the button hides while a Bring them back is running by the launcher's own rule
 *                  (BackupObjectRestoreLauncher::in_progress), not for ever after one dies unreported
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventory.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectRestoreLauncher.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));

class CloudStoreInventoryPanel {

	/** Who brings a missing file back on this site. */
	const SOURCE_SITE    = 'site';      // this site, from its own backup storage
	const SOURCE_MANAGER = 'manager';   // the management node, as a job from its Backups tab
	const SOURCE_NONE    = 'none';      // nobody: no backup of this site holds offloaded files

	/**
	 * The action name both pages accept. The page posts it to itself and calls
	 * BackupObjectRestoreLauncher::start_newest().
	 */
	const ACTION = 'bring_back_objects';

	/**
	 * Which source this site has: its own backup storage when it has a backup of its own
	 * to read from, the management node when one manages it, otherwise none.
	 */
	public static function source($is_managed) {
		try {
			if (BackupObjectRestoreLauncher::newest_run() !== null) {
				return self::SOURCE_SITE;
			}
		} catch (\Throwable $e) {
			// No history to read is no source of this site's own.
		}
		return $is_managed ? self::SOURCE_MANAGER : self::SOURCE_NONE;
	}

	/**
	 * Is there anything to show? A check that has never run and a bring back
	 * that has never happened is a blank the page should not spend a box on.
	 */
	public static function has_content(array $summary) {
		return $summary['checked_at'] !== null || $summary['running'] || is_array($summary['bring_back'] ?? null);
	}

	/**
	 * The block. $post_url is where the page's own Bring them back posts;
	 * $manager_url the management node's URL when the source is the manager.
	 */
	public static function render(array $summary, $source, $post_url, $manager_url = '') {
		$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
		$out = '';

		// When it last looked.
		if ($summary['checked_at'] !== null) {
			$line = 'Checked ' . number_format((int)$summary['checked']) . ' offloaded file' . ((int)$summary['checked'] === 1 ? '' : 's')
				. ' in the file store ' . BackupVerifier::when_words((string)$summary['checked_at']);
			if ((int)$summary['unchecked'] > 0) {
				$line .= '; ' . number_format((int)$summary['unchecked']) . ' could not be checked (no store is configured for them)';
			}
			$line .= (int)$summary['missing_count'] === 0 ? '. All present.' : '.';
			$out .= '<p class="mb-1' . ((int)$summary['missing_count'] === 0 ? ' text-muted' : '') . '">' . $h($line) . '</p>';
		} else {
			$out .= '<p class="mb-1 text-muted">The file store has not been checked yet. The check runs once a day while any file is offloaded.</p>';
		}
		if ($summary['running']) {
			$out .= '<p class="mb-1 text-muted small">A check is running now (started ' . $h(BackupVerifier::when_words((string)$summary['running_since']))
				. '; ' . number_format((int)$summary['running_checked']) . ' checked so far).</p>';
		}

		// The sentence, and the action.
		if ((int)$summary['missing_count'] > 0) {
			$out .= '<div class="alert alert-warning mb-2"><strong>' . $h(CloudStoreInventory::sentence($summary)) . '</strong>';
			switch ($source) {
				case self::SOURCE_SITE:
					$out .= ' Bringing them back reads each one off this site\'s own backup storage, opens it with this site\'s key, and puts it '
						. 'back where the site expects it; a file the file store can serve again by then is left alone. Nothing is overwritten.';
					break;
				case self::SOURCE_MANAGER:
					$out .= ' This site\'s backups are taken by '
						. ($manager_url !== '' ? '<code>' . $h($manager_url) . '</code>' : 'a management node')
						. ', so bringing them back is a job that management node runs: open this site there and use '
						. '<strong>Bring them back</strong> on its Backups tab.';
					break;
				default:
					$out .= ' No backup of this site holds offloaded files, so there is nothing to bring them back from. '
						. 'A backup target of this site\'s own, or a management node, keeps a copy of every offloaded file from its next run on.';
			}
			$out .= '</div>';
			if ($source === self::SOURCE_SITE) {
				if (!BackupObjectRestoreLauncher::in_progress($summary['bring_back'] ?? null)) {
					$out .= AdminPage::action_button('Bring them back', $post_url, array(
						'hidden'  => array('action' => self::ACTION),
						'confirm' => 'Bring the missing offloaded files back from this site\'s newest backup? Only files the file store '
							. 'cannot serve are touched; nothing already on disk is overwritten and nothing in any bucket is deleted.',
						'class'   => 'btn btn-sm btn-primary',
					));
				}
			}
		}

		// The last Bring them back.
		$last = BackupObjectRestoreLauncher::describe_last($summary['bring_back'] ?? null);
		if ($last !== '') {
			$failed = is_array($summary['bring_back']) && ($summary['bring_back']['result'] ?? '') === BackupObjectRestore::RESULT_FAIL
				&& !empty($summary['bring_back']['finished']);
			$out .= '<p class="mt-2 mb-0 small' . ($failed ? ' text-danger' : ' text-muted') . '">' . $h($last) . '</p>';
		}
		return $out;
	}
}
