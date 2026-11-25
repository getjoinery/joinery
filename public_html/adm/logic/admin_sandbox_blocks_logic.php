<?php
/**
 * Logic for the page of a sandboxed plugin's rows that stop a site record
 * being deleted (SandboxBlockingRows, spec sandboxed_plugin_model_files
 * section 8).
 *
 * GET ?plugin= lists its blocking links, each with how many rows hold a link.
 * GET &model=&column=[&id=] lists those rows, at the model's permanent delete
 * level. POST action=delete with row= deletes one row permanently; with
 * row=all, every row listed. Each is permanent_delete() on the site's own
 * class, so the plugin's own rules apply. It works for a plugin installed but
 * not active, and with its folder gone: it reads only the stored
 * declarations.
 *
 * @version 1.1 - only a blocking link in force, at the model's read and permanent delete levels; any failure of a
 *                row is reported, the rest go on (reviewer1 R3, N1)
 * @version 1.0
 */

function admin_sandbox_blocks_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	$plugin = (string)($input['plugin'] ?? '');
	$row = preg_match(SandboxManifest::NAME_PATTERN, $plugin) ? Plugin::get_by_plugin_name($plugin) : null;
	if (!$row || $row->is_uninstalled() || !SandboxModels::declarations($plugin)) {
		return LogicResult::error('That is not an installed sandboxed plugin with models.');
	}
	$title = SandboxBlockingRows::titleOf($plugin);
	$model = isset($input['model']) ? (string)$input['model'] : null;
	$column = isset($input['column']) ? (string)$input['column'] : null;
	$id = isset($input['id']) && ctype_digit((string)$input['id']) ? (int)$input['id'] : null;

	if ($model === null || $column === null) {
		Access::demand(SandboxPluginManager::ADMIN_PERMISSION);
		return LogicResult::render(array(
			'session' => $session,
			'plugin'  => $plugin,
			'title'   => $title,
			'links'   => SandboxBlockingRows::links($plugin),
			'view'    => 'links',
		));
	}

	// The page has one job: the rows of a blocking link in force (reviewer1 R3).
	$class = null;
	foreach (SandboxBlockingRows::links($plugin) as $link) {
		if ($link['model'] === $model && $link['column'] === $column) {
			$class = $link['class'];
		}
	}
	if ($class === null) {
		return LogicResult::error('That is not a link of the plugin\'s that stops a delete.');
	}
	// It shows the rows and deletes them: the model's read and permanent delete levels both.
	Access::demand(max($class::access_level('read'), $class::access_level('permanent_delete')));
	$here = SandboxBlockingRows::url($plugin, $model, $column, $id);

	if (LibraryFunctions::isFormSubmission() && ($input['action'] ?? '') === 'delete') {
		$which = (string)($input['row'] ?? '');
		$rows = SandboxBlockingRows::rows($class, $column, $id);
		if ($which !== 'all') {
			$rows = array_values(array_filter($rows, function ($r) use ($which) {
				return (string)$r->key === $which;
			}));
		}
		$deleted = 0;
		$refused = array();
		foreach ($rows as $r) {
			try {
				$r->permanent_delete();
				$deleted++;
			} catch (SystemDisplayableError | SystemAuthenticationError $e) {
				$refused[] = '#' . $r->key . ': ' . $e->getMessage();
			} catch (Throwable $e) {
				// Anything else (a database error in its cascade): this row
				// stays, said so, and the rest go on (reviewer1 N1).
				error_log('[admin_sandbox_blocks] ' . $plugin . ' ' . $model . ' #' . $r->key . ': ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
				$refused[] = '#' . $r->key . ': it could not be deleted (the error is logged).';
			}
		}
		$session->save_message(new DisplayMessage($deleted . ' ' . ($deleted === 1 ? 'row' : 'rows') . ' deleted permanently.'
			. ($refused ? ' Not deleted: ' . implode(' ', $refused) : ''), '', NULL,
			$refused ? DisplayMessage::MESSAGE_WARNING : DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($here);
	}

	return LogicResult::render(array(
		'session' => $session,
		'plugin'  => $plugin,
		'title'   => $title,
		'model'   => $model,
		'column'  => $column,
		'id'      => $id,
		'class'   => $class,
		'here'    => $here,
		'rows'    => SandboxBlockingRows::rows($class, $column, $id),
		'total'   => SandboxBlockingRows::count($class, $column, $id),
		'view'    => 'rows',
	));
}
