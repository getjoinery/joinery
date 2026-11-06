<?php
/**
 * VaultWindowHistory - a node's vault windows, from the rows log_table_tail
 * read with event=vault_windows (specs/agent_event_log_filter.md).
 *
 * The node sends rows; this pairs each vault_window_closed with the newest
 * earlier vault_window_opened of the same (member, scope, session handle) and
 * counts what it finds: windows by where the key was held (the daemon, the
 * pool, or unknown for rows written before the opened row recorded it),
 * closes by reason, opens by how they were made. The notes are read by
 * VaultAudit::parseNote(), which sits beside the code that writes them, so
 * the format and its reader change together.
 *
 * A window with no close in the rows read is still open, or ended without
 * anyone noticing yet; a close with no open in the rows read was opened
 * before them. Both are kept, with the missing half null.
 *
 * @version 1.0
 */
class VaultWindowHistory {

	/**
	 * @param array $rows log_table_tail rows: evl_event_log_id, evl_event,
	 *                    evl_usr_user_id, evl_create_time, evl_note
	 * @return array{windows: array, totals: array}
	 *   windows, newest first: user_id, scope, via, holder, fallback,
	 *   opened_at, closed_at, reason, open_seconds, unwraps, borrowed
	 *   totals: windows, by_holder, by_reason, by_via, still_open, open_before
	 */
	public static function fromRows(array $rows): array {
		$events = array();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$event = (string)($row['evl_event'] ?? '');
			if ($event !== VaultAudit::EVENT_OPENED && $event !== VaultAudit::EVENT_CLOSED) {
				continue;
			}
			$events[] = array(
				'id'    => (int)($row['evl_event_log_id'] ?? 0),
				'event' => $event,
				'user'  => (int)($row['evl_usr_user_id'] ?? 0),
				'time'  => (string)($row['evl_create_time'] ?? ''),
				'note'  => VaultAudit::parseNote($event, (string)($row['evl_note'] ?? '')),
			);
		}
		// Oldest first, so an open is seen before the close that ends it.
		usort($events, function ($a, $b) {
			return $a['id'] <=> $b['id'];
		});

		$open = array();     // pairing key => [window index, ...], newest last
		$windows = array();
		foreach ($events as $e) {
			$n = $e['note'];
			$key = $e['user'] . '|' . ($n['scope'] ?? '') . '|' . ($n['session'] ?? '');
			if ($e['event'] === VaultAudit::EVENT_OPENED) {
				$windows[] = array(
					'user_id'      => $e['user'],
					'scope'        => $n['scope'] ?? null,
					'via'          => $n['via'] ?? null,
					'holder'       => $n['holder'] ?? 'unknown',
					'fallback'     => $n['fallback'] ?? null,
					'opened_at'    => $e['time'],
					'closed_at'    => null,
					'reason'       => null,
					'open_seconds' => null,
					'unwraps'      => null,
					'borrowed'     => strpos((string)($n['session'] ?? ''), 'apk') === 0,
					'_id'          => $e['id'],
				);
				$open[$key][] = count($windows) - 1;
				continue;
			}
			if (!empty($open[$key])) {
				$i = array_pop($open[$key]);
			} else {
				// Opened before the rows read: the open half is unknown.
				$windows[] = array(
					'user_id'      => $e['user'],
					'scope'        => $n['scope'] ?? null,
					'via'          => null,
					'holder'       => 'unknown',
					'fallback'     => null,
					'opened_at'    => null,
					'borrowed'     => strpos((string)($n['session'] ?? ''), 'apk') === 0,
					'_id'          => $e['id'],
				);
				$i = count($windows) - 1;
			}
			$windows[$i]['_id'] = $e['id'];
			$windows[$i]['closed_at'] = $e['time'];
			$windows[$i]['reason'] = $n['reason'] ?? null;
			$windows[$i]['open_seconds'] = $n['open_seconds'] ?? null;
			$windows[$i]['unwraps'] = $n['unwraps'] ?? null;
			// A pool window shows no count; one the daemon held does, so a close
			// row tells a window opened before holder was recorded.
			if ($windows[$i]['holder'] === 'unknown' && is_int($windows[$i]['unwraps'])) {
				$windows[$i]['holder'] = VaultAudit::HOLDER_DAEMON;
			}
		}

		$totals = array('windows' => count($windows), 'by_holder' => array(), 'by_reason' => array(), 'by_via' => array(),
			'still_open' => 0, 'open_before' => 0);
		foreach ($windows as $w) {
			$totals['by_holder'][$w['holder']] = ($totals['by_holder'][$w['holder']] ?? 0) + 1;
			if ($w['reason'] !== null) {
				$totals['by_reason'][$w['reason']] = ($totals['by_reason'][$w['reason']] ?? 0) + 1;
			} else {
				$totals['still_open']++;
			}
			if ($w['opened_at'] === null) {
				$totals['open_before']++;
			} elseif ($w['via'] !== null) {
				$totals['by_via'][$w['via']] = ($totals['by_via'][$w['via']] ?? 0) + 1;
			}
		}
		foreach (array('by_holder', 'by_reason', 'by_via') as $k) {
			arsort($totals[$k]);
		}

		// Newest first, by the row that last touched each window.
		usort($windows, function ($a, $b) {
			return $b['_id'] <=> $a['_id'];
		});
		foreach ($windows as &$w) {
			unset($w['_id']);
		}
		unset($w);
		return array('windows' => $windows, 'totals' => $totals);
	}

	/**
	 * The totals in one line: "31 windows: 29 daemon, 2 pool; ended 18
	 * idle_expired, 9 explicit_lock; 4 still open".
	 */
	public static function summary(array $totals): string {
		$parts = array();
		foreach ($totals['by_holder'] as $holder => $n) {
			$parts[] = $n . ' ' . $holder;
		}
		$line = $totals['windows'] . ' ' . ($totals['windows'] === 1 ? 'window' : 'windows')
			. ($parts ? ': ' . implode(', ', $parts) : '');
		$ended = array();
		foreach ($totals['by_reason'] as $reason => $n) {
			$ended[] = $n . ' ' . $reason;
		}
		if ($ended) {
			$line .= '; ended ' . implode(', ', $ended);
		}
		if ($totals['still_open'] > 0) {
			$line .= '; ' . $totals['still_open'] . ' not closed yet';
		}
		if ($totals['open_before'] > 0) {
			$line .= '; ' . $totals['open_before'] . ' opened before the rows read';
		}
		return $line;
	}
}
