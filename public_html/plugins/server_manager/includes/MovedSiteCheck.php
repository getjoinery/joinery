<?php
/**
 * MovedSiteCheck - where the domain of the old machine of a switch-over goes,
 * as its host proves it (moved_site_check, specs/site_copy.md WP14).
 *
 * The answer is the proof decommission_moved_site enforces before it removes
 * anything, run on its own: the host puts a one-time token in the container
 * and fetches each name it serves the container under. So what the page says
 * and what Permanently Delete Site would allow are one test.
 *
 * The proof is a job on the host (seconds, and a file written into the
 * container), so it is not run on every page view. The row keeps the last
 * answer (mgn_moved_check_*); the page shows it at once and asks again when it
 * is older than STALE_SECONDS, or when the operator presses Check again.
 *
 * @version 1.0
 */
class MovedSiteCheck {

	/** An answer older than this is asked again when the page is opened. */
	const STALE_SECONDS = 600;

	const JOB_TYPE = 'moved_site_check';

	/** The old container of a switch-over: the rows this check is for. */
	public static function applies($node): bool {
		return trim((string)$node->get('mgn_container_name')) !== ''
			&& JobCommandBuilder::decommission_is_moved($node);
	}

	/** The newest check filed for this row, or null. */
	public static function latest_job($node): ?ManagementJob {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_management_job_id FROM mjb_management_jobs
			 WHERE mjb_job_type = ? AND (mjb_parameters->>'victim_node_id')::bigint = ?
			 ORDER BY mjb_management_job_id DESC LIMIT 1");
		$q->execute([self::JOB_TYPE, (int)$node->key]);
		$id = (int)$q->fetchColumn();
		return $id ? new ManagementJob($id, TRUE) : null;
	}

	/**
	 * Fold a finished check the processor has not seen yet onto the row, and
	 * say whether one is still open.
	 */
	public static function settle($node): bool {
		$job = self::latest_job($node);
		if (!$job) {
			return false;
		}
		if (in_array((string)$job->get('mjb_status'), ['pending', 'running'], true)) {
			return true;
		}
		if (JobResultProcessor::process_if_due($job)) {
			$node->load();
		}
		return false;
	}

	/** Whether the stored answer should be asked again. A removed container stays removed. */
	public static function is_stale($node): bool {
		if ((string)$node->get('mgn_moved_check_state') === 'absent') {
			return false;
		}
		$when = (string)$node->get('mgn_moved_check_time');
		return $when === '' || time() - strtotime($when . ' UTC') >= self::STALE_SECONDS;
	}

	/**
	 * File a check on the host. Refuses what build_moved_site_check refuses
	 * (an agent without the word, a removal open on the host), with its reason.
	 */
	public static function start($node, $created_by): ManagementJob {
		$built = JobCommandBuilder::build_moved_site_check($node);
		$host_node = JobCommandBuilder::decommission_host_node_for($node);
		return ManagementJob::createFromBuild($host_node->key, self::JOB_TYPE, $built,
			['victim_node_id' => (int)$node->key, 'site' => JobCommandBuilder::decommission_site_name($node)],
			$created_by);
	}

	/**
	 * The line shown beside the site: the stored answer, or that a check is
	 * running. $refusal is why a check could not be started, when it could not.
	 */
	public static function label_html($node, bool $checking, string $timezone, string $refusal = ''): string {
		$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
		$state = (string)$node->get('mgn_moved_check_state');
		$detail = (string)$node->get('mgn_moved_check_detail');
		$when = (string)$node->get('mgn_moved_check_time');
		$ago = $when !== '' ? LibraryFunctions::time_ago_or_time($when, 'UTC', $timezone, 'M j, g:i A') : '';

		$labels = [
			'moved'  => ['text-success', '✓ The domain reaches another server'],
			'here'   => ['text-danger', '✗ The domain still reaches this container'],
			'unsure' => ['text-warning', '? Could not tell where the domain goes'],
			'absent' => ['text-muted', 'The container is gone from its host'],
			'failed' => ['text-warning', '? The check did not run'],
		];
		if (isset($labels[$state])) {
			[$class, $text] = $labels[$state];
			$out = '<span class="' . $class . '" title="' . $h($detail) . '">' . $h($text) . '</span>';
			if ($ago !== '') {
				$out .= ' <span class="text-muted">· checked ' . $h($ago) . '</span>';
			}
			if ($state !== 'moved' && $state !== 'absent' && $detail !== '') {
				$out .= '<div class="text-muted">' . $h($detail) . '</div>';
			}
		} else {
			$out = '<span class="text-muted">Not checked yet</span>';
		}
		if ($checking) {
			$out .= '<div class="text-muted">Checking where the domain goes…</div>';
		} elseif ($refusal !== '') {
			$out .= '<div class="text-warning">Cannot check: ' . $h($refusal) . '</div>';
		}
		return $out;
	}
}
